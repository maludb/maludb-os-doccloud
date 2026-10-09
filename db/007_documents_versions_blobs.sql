-- 007: DOCUMENTS, VERSIONS, BLOBS, TEXT and UPLOAD SESSIONS — the estate's file store (design §6.1, §6.2; D9, D10).
--
-- A DOCUMENT is the record: a name in a folder, an owner, its current version, its lock, its place in the trash. A VERSION is
-- one upload; every same-named upload is a new version (PHP asks "keep both?" — then it is a new document named "x (2)").
-- A BLOB is the bytes, content-addressed by sha256 under DC_STORAGE_DIR, de-duplicated with a reference count the versions
-- keep by trigger; a blob whose count reaches zero is freed by the worker (dc_pass_blobs_free, db/014). TEXT is what the
-- extractor read from a version (db/010 indexes it; db/012 sends it to the graph). An UPLOAD SESSION is a file arriving in
-- parts (8 MB, resumable); finish assembles, hashes and makes the version.
-- `libraries.used_bytes` follows the versions (a blob in two libraries counts in both — honest to the person; the disk is the
-- admin's storage report). The trash: `trashed_at` on the document (and the folder it was in, so a restore returns it there
-- or to the root when that folder is gone); purge is final (db/014).
--
-- THE ESTATE'S DATA MODEL (design §6.4): NEW and canonical — a document here IS the record; GL's `attachments` is a file ON a
-- record, Knowledge's `sources`/`sections` an ingested document with anchors (no versions, no bytes of its own).
BEGIN;

CREATE TABLE blobs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sha256        text NOT NULL UNIQUE CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    storage_path  text NOT NULL,                                       -- relative to DC_STORAGE_DIR: blobs/<aa>/<bb>/<sha256>
    ref_count     integer NOT NULL DEFAULT 0 CHECK (ref_count >= 0),  -- live versions pointing here (trigger)
    created_at    timestamptz NOT NULL DEFAULT now(),
    deleted_at    timestamptz                                          -- freed: the worker unlinked the file
);
CREATE INDEX blobs_free_idx ON blobs (ref_count) WHERE ref_count = 0 AND deleted_at IS NULL;

CREATE OR REPLACE FUNCTION dc_mime_kind(p_mime text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN p_mime LIKE 'image/%' THEN 'image'
        WHEN p_mime = 'application/pdf' THEN 'pdf'
        WHEN p_mime IN ('application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.oasis.opendocument.text',
                        'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation', 'application/rtf') THEN 'office'
        WHEN p_mime LIKE 'text/%' OR p_mime IN ('application/json', 'application/xml') THEN 'text'
        WHEN p_mime LIKE 'audio/%' THEN 'audio'
        WHEN p_mime LIKE 'video/%' THEN 'video'
        WHEN p_mime IN ('application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-tar', 'application/x-7z-compressed', 'application/vnd.rar') THEN 'archive'
        ELSE 'other' END;
$$;

CREATE TABLE documents (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    library_id             bigint NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
    folder_id              bigint NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    name                   text NOT NULL CHECK (length(name) BETWEEN 1 AND 255 AND name !~ '[/\\]' AND name NOT IN ('.', '..')),
    description            text CHECK (description IS NULL OR length(description) <= 4000),
    owner_member_id        bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,   -- the ownership rule: transferred, never orphaned
    current_version_id     bigint,                                                       -- FK below
    own_access             boolean NOT NULL DEFAULT false,                               -- has access rows of its own (db/008)
    mime_type              text NOT NULL DEFAULT 'application/octet-stream',
    mime_kind              text NOT NULL DEFAULT 'other' CHECK (mime_kind IN ('image', 'pdf', 'office', 'text', 'audio', 'video', 'archive', 'other')),
    byte_size              bigint NOT NULL DEFAULT 0 CHECK (byte_size >= 0),
    sha256                 text,
    version_count          integer NOT NULL DEFAULT 0 CHECK (version_count >= 0),
    locked_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    locked_at              timestamptz,
    lock_expires_at        timestamptz,
    trashed_at             timestamptz,
    trashed_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    trashed_from_folder_id bigint,
    created_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    last_opened_at         timestamptz,
    CHECK ((locked_by IS NULL) = (locked_at IS NULL) AND (locked_by IS NULL) = (lock_expires_at IS NULL)),
    CHECK ((trashed_at IS NULL) = (trashed_by IS NULL))
);
CREATE UNIQUE INDEX documents_folder_name_live ON documents (folder_id, lower(name)) WHERE trashed_at IS NULL;
CREATE INDEX documents_folder_idx ON documents (folder_id, trashed_at, lower(name));
CREATE INDEX documents_library_idx ON documents (library_id) WHERE trashed_at IS NULL;
CREATE INDEX documents_owner_idx ON documents (owner_member_id, updated_at DESC);
CREATE INDEX documents_sha_idx ON documents (sha256) WHERE sha256 IS NOT NULL;
CREATE INDEX documents_name_trgm_idx ON documents USING gin (name gin_trgm_ops);
CREATE INDEX documents_trashed_idx ON documents (trashed_at) WHERE trashed_at IS NOT NULL;
CREATE INDEX documents_locked_idx ON documents (lock_expires_at) WHERE locked_by IS NOT NULL;
CREATE INDEX documents_updated_idx ON documents (updated_at DESC) WHERE trashed_at IS NULL;
CREATE INDEX documents_own_access_idx ON documents (id) WHERE own_access;
CREATE INDEX documents_opened_idx ON documents (last_opened_at DESC) WHERE last_opened_at IS NOT NULL AND trashed_at IS NULL;
CREATE TRIGGER documents_touch BEFORE UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE document_versions (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id       bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version_no        integer NOT NULL CHECK (version_no >= 1),
    blob_id           bigint NOT NULL REFERENCES blobs(id) ON DELETE RESTRICT,
    filename          text NOT NULL,                                           -- as uploaded
    mime_type         text NOT NULL,
    byte_size         bigint NOT NULL CHECK (byte_size >= 0),
    sha256            text NOT NULL,
    uploaded_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    uploaded_at       timestamptz NOT NULL DEFAULT now(),
    source            text NOT NULL DEFAULT 'web' CHECK (source IN ('web', 'api', 'agent', 'request', 'restore', 'copy')),
    note              text CHECK (note IS NULL OR length(note) <= 500),
    extracted_status  text NOT NULL DEFAULT 'pending' CHECK (extracted_status IN ('pending', 'ready', 'no_text', 'unsupported', 'failed')),
    purged_at         timestamptz,                                             -- the window expired it: bytes released, row kept
    UNIQUE (document_id, version_no)
);
CREATE INDEX document_versions_blob_idx ON document_versions (blob_id) WHERE purged_at IS NULL;
CREATE INDEX document_versions_pending_idx ON document_versions (uploaded_at) WHERE extracted_status = 'pending' AND purged_at IS NULL;
CREATE INDEX document_versions_expiry_idx ON document_versions (document_id, uploaded_at) WHERE purged_at IS NULL;
ALTER TABLE documents ADD CONSTRAINT documents_current_version_fk FOREIGN KEY (current_version_id) REFERENCES document_versions(id) ON DELETE SET NULL;

CREATE TABLE document_text (
    version_id    bigint PRIMARY KEY REFERENCES document_versions(id) ON DELETE CASCADE,
    document_id   bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    text          text NOT NULL DEFAULT '',
    pages         jsonb NOT NULL DEFAULT '[]'::jsonb,                        -- [{page_no, char_from, char_to}]
    extractor     text,
    char_count    integer NOT NULL DEFAULT 0 CHECK (char_count >= 0),
    truncated     boolean NOT NULL DEFAULT false,
    extracted_at  timestamptz NOT NULL DEFAULT now(),
    error         text
);
CREATE INDEX document_text_document_idx ON document_text (document_id);

CREATE TABLE upload_sessions (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    folder_id      bigint NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    document_id    bigint REFERENCES documents(id) ON DELETE CASCADE,         -- a new version of this one (NULL = a new document)
    link_id        bigint,                                                     -- a file request's upload (FK in db/011)
    filename       text NOT NULL,
    mime_hint      text,
    byte_size      bigint NOT NULL CHECK (byte_size >= 0),
    part_bytes     integer NOT NULL CHECK (part_bytes BETWEEN 1048576 AND 67108864),
    parts_total    integer NOT NULL CHECK (parts_total >= 1),
    parts_done     integer NOT NULL DEFAULT 0 CHECK (parts_done >= 0),
    sha256_claimed text CHECK (sha256_claimed IS NULL OR sha256_claimed ~ '^[0-9a-f]{64}$'),
    status         text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'assembling', 'done', 'failed', 'expired')),
    version_id     bigint REFERENCES document_versions(id) ON DELETE SET NULL,
    error          text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    expires_at     timestamptz NOT NULL DEFAULT now() + interval '24 hours',
    CHECK (parts_done <= parts_total)
);
CREATE INDEX upload_sessions_member_idx ON upload_sessions (member_id, status);
CREATE INDEX upload_sessions_expiry_idx ON upload_sessions (expires_at) WHERE status IN ('open', 'assembling');
CREATE TRIGGER upload_sessions_touch BEFORE UPDATE ON upload_sessions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE upload_parts (
    session_id   bigint NOT NULL REFERENCES upload_sessions(id) ON DELETE CASCADE,
    part_no      integer NOT NULL CHECK (part_no >= 1),
    byte_size    integer NOT NULL CHECK (byte_size >= 0),
    received_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (session_id, part_no)
);

-- ---------------------------------------------------------------------------------------------
-- The referee's rules.
-- ---------------------------------------------------------------------------------------------
-- A document belongs to its folder's library; its folder is live (a document may be made in a trashed folder only by a
-- restore); a lock is coherent.
CREATE OR REPLACE FUNCTION dc_documents_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE f folders%ROWTYPE;
BEGIN
    SELECT * INTO f FROM folders WHERE id = NEW.folder_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such folder' USING ERRCODE = 'foreign_key_violation'; END IF;
    NEW.library_id := f.library_id;
    IF f.trashed_at IS NOT NULL AND NEW.trashed_at IS NULL AND (TG_OP = 'INSERT' OR NEW.folder_id <> OLD.folder_id) THEN
        RAISE EXCEPTION 'That folder is in the trash' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.folder_id <> OLD.folder_id AND OLD.locked_by IS NOT NULL AND OLD.locked_by <> app_current_member_id() AND NOT dc_is_admin() THEN
        RAISE EXCEPTION 'The document is locked' USING ERRCODE = 'check_violation';
    END IF;
    NEW.mime_kind := dc_mime_kind(NEW.mime_type);
    RETURN NEW;
END$$;
CREATE TRIGGER documents_before BEFORE INSERT OR UPDATE OF folder_id, mime_type, trashed_at, locked_by ON documents FOR EACH ROW EXECUTE FUNCTION dc_documents_before();

-- A version numbers itself, becomes current, stamps the document, holds its blob, and counts into the library.
CREATE OR REPLACE FUNCTION dc_versions_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT COALESCE(max(version_no), 0) + 1 INTO NEW.version_no FROM document_versions WHERE document_id = NEW.document_id;
    ELSIF NEW.document_id <> OLD.document_id OR NEW.blob_id <> OLD.blob_id OR NEW.version_no <> OLD.version_no THEN
        RAISE EXCEPTION 'A version is immutable' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER document_versions_before BEFORE INSERT OR UPDATE ON document_versions FOR EACH ROW EXECUTE FUNCTION dc_versions_before();

CREATE OR REPLACE FUNCTION dc_versions_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lib bigint; bulk boolean := COALESCE(current_setting('app.dc_bulk', true), '') = '1';   -- a bulk load recounts afterwards (dc_recount_bulk)
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NOT bulk THEN UPDATE blobs SET ref_count = ref_count + 1, deleted_at = NULL WHERE id = NEW.blob_id; END IF;
        UPDATE documents SET current_version_id = NEW.id, mime_type = NEW.mime_type, byte_size = NEW.byte_size, sha256 = NEW.sha256,
               version_count = version_count + 1, updated_at = now()
         WHERE id = NEW.document_id;
        IF NOT bulk THEN
            SELECT library_id INTO lib FROM documents WHERE id = NEW.document_id;
            UPDATE libraries SET used_bytes = used_bytes + NEW.byte_size WHERE id = lib;
        END IF;
    ELSIF TG_OP = 'UPDATE' AND NEW.purged_at IS NOT NULL AND OLD.purged_at IS NULL THEN
        UPDATE blobs SET ref_count = ref_count - 1 WHERE id = NEW.blob_id;
        SELECT library_id INTO lib FROM documents WHERE id = NEW.document_id;
        UPDATE libraries SET used_bytes = GREATEST(0, used_bytes - NEW.byte_size) WHERE id = lib;
        DELETE FROM document_text WHERE version_id = NEW.id;
    ELSIF TG_OP = 'DELETE' THEN
        IF OLD.purged_at IS NULL THEN
            UPDATE blobs SET ref_count = ref_count - 1 WHERE id = OLD.blob_id;
            SELECT library_id INTO lib FROM documents WHERE id = OLD.document_id;
            UPDATE libraries SET used_bytes = GREATEST(0, used_bytes - OLD.byte_size) WHERE id = lib;
        END IF;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER document_versions_after AFTER INSERT OR UPDATE OF purged_at OR DELETE ON document_versions FOR EACH ROW EXECUTE FUNCTION dc_versions_after();

-- A document moved across libraries takes its live bytes with it.
CREATE OR REPLACE FUNCTION dc_documents_after_move() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b bigint;
BEGIN
    IF NEW.library_id <> OLD.library_id THEN
        SELECT COALESCE(sum(byte_size), 0) INTO b FROM document_versions WHERE document_id = NEW.id AND purged_at IS NULL;
        UPDATE libraries SET used_bytes = GREATEST(0, used_bytes - b) WHERE id = OLD.library_id;
        UPDATE libraries SET used_bytes = used_bytes + b WHERE id = NEW.library_id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER documents_after_move AFTER UPDATE OF library_id ON documents FOR EACH ROW EXECUTE FUNCTION dc_documents_after_move();

-- The current version is never purged while it is current.
CREATE OR REPLACE FUNCTION dc_versions_guard_current() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.purged_at IS NOT NULL AND EXISTS (SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.current_version_id = NEW.id AND d.trashed_at IS NULL) THEN
        RAISE EXCEPTION 'The current version is never purged' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER document_versions_guard_current BEFORE UPDATE OF purged_at ON document_versions FOR EACH ROW EXECUTE FUNCTION dc_versions_guard_current();

-- ---------------------------------------------------------------------------------------------
-- The operations PHP calls (each one transaction; PHP has judged the level first — the functions re-check nothing of the
-- caller but the invariants).
-- ---------------------------------------------------------------------------------------------
-- A blob by its hash: existing (de-duplicated) or new. storage_path = blobs/<aa>/<bb>/<sha256>.
CREATE OR REPLACE FUNCTION dc_blob_claim(p_sha256 text, p_byte_size bigint) RETURNS TABLE (blob_id bigint, storage_path text, existed boolean)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b blobs%ROWTYPE; nid bigint; npath text;
BEGIN
    SELECT * INTO b FROM blobs WHERE sha256 = p_sha256 FOR UPDATE;
    IF FOUND THEN
        IF b.byte_size <> p_byte_size THEN RAISE EXCEPTION 'A blob of that hash has another size' USING ERRCODE = 'check_violation'; END IF;
        RETURN QUERY SELECT b.id, b.storage_path, b.deleted_at IS NULL; RETURN;   -- existed = the file is still on disk
    END IF;
    INSERT INTO blobs (sha256, byte_size, storage_path) VALUES (p_sha256, p_byte_size, 'blobs/' || left(p_sha256, 2) || '/' || substr(p_sha256, 3, 2) || '/' || p_sha256)
    RETURNING blobs.id, blobs.storage_path INTO nid, npath;
    RETURN QUERY SELECT nid, npath, false;
END$$;

-- Quota: may this many bytes be added to this library?
CREATE OR REPLACE FUNCTION dc_quota_allows(p_library_id bigint, p_bytes bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT quota_bytes IS NULL OR used_bytes + p_bytes <= quota_bytes FROM libraries WHERE id = p_library_id;
$$;

-- A new document with its first version (or a new version of an existing document when p_document_id is given).
-- Refuses over the per-file ceiling and the library's quota; the same-name rule is the unique index (PHP catches it and asks).
CREATE OR REPLACE FUNCTION dc_version_add(p_document_id bigint, p_folder_id bigint, p_name text, p_blob_id bigint, p_filename text, p_mime text,
                                          p_byte_size bigint, p_sha256 text, p_by bigint, p_source text DEFAULT 'web', p_note text DEFAULT NULL)
    RETURNS TABLE (document_id bigint, version_id bigint, version_no integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did bigint := p_document_id; vid bigint; vno integer; lib bigint;
BEGIN
    IF p_byte_size > dc_setting_int('max_document_bytes') THEN RAISE EXCEPTION 'Over the per-file ceiling' USING ERRCODE = 'check_violation'; END IF;
    IF did IS NULL THEN
        SELECT library_id INTO lib FROM folders WHERE id = p_folder_id;
        IF NOT dc_quota_allows(lib, p_byte_size) THEN RAISE EXCEPTION 'The library is over its quota' USING ERRCODE = 'check_violation'; END IF;
        INSERT INTO documents (library_id, folder_id, name, owner_member_id, mime_type, created_by) VALUES (lib, p_folder_id, p_name, p_by, p_mime, p_by) RETURNING id INTO did;
    ELSE
        SELECT library_id INTO lib FROM documents WHERE id = did;
        IF NOT dc_quota_allows(lib, p_byte_size) THEN RAISE EXCEPTION 'The library is over its quota' USING ERRCODE = 'check_violation'; END IF;
        IF EXISTS (SELECT 1 FROM documents d WHERE d.id = did AND d.locked_by IS NOT NULL AND d.locked_by <> p_by) THEN
            RAISE EXCEPTION 'The document is locked by someone else' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    INSERT INTO document_versions (document_id, blob_id, filename, mime_type, byte_size, sha256, uploaded_by, source, note)
    VALUES (did, p_blob_id, p_filename, p_mime, p_byte_size, p_sha256, p_by, p_source, p_note) RETURNING id, document_versions.version_no INTO vid, vno;
    RETURN QUERY SELECT did, vid, vno;
END$$;

-- Restore an old version: a new version from the old blob (the old one stays in history).
CREATE OR REPLACE FUNCTION dc_version_restore(p_version_id bigint, p_by bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v document_versions%ROWTYPE; nid bigint;
BEGIN
    SELECT * INTO v FROM document_versions WHERE id = p_version_id;
    IF NOT FOUND OR v.purged_at IS NOT NULL THEN RAISE EXCEPTION 'That version is gone' USING ERRCODE = 'no_data_found'; END IF;
    INSERT INTO document_versions (document_id, blob_id, filename, mime_type, byte_size, sha256, uploaded_by, source, note)
    VALUES (v.document_id, v.blob_id, v.filename, v.mime_type, v.byte_size, v.sha256, p_by, 'restore', 'restored from version ' || v.version_no) RETURNING id INTO nid;
    RETURN nid;
END$$;

-- The trash.
CREATE OR REPLACE FUNCTION dc_document_trash(p_document_id bigint, p_by bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE documents SET trashed_at = now(), trashed_by = p_by, trashed_from_folder_id = folder_id, locked_by = NULL, locked_at = NULL, lock_expires_at = NULL
     WHERE id = p_document_id AND trashed_at IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;

CREATE OR REPLACE FUNCTION dc_document_restore(p_document_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d documents%ROWTYPE; target bigint; nm text; i integer := 1;
BEGIN
    SELECT * INTO d FROM documents WHERE id = p_document_id FOR UPDATE;
    IF NOT FOUND OR d.trashed_at IS NULL THEN RETURN false; END IF;
    target := d.folder_id;
    IF EXISTS (SELECT 1 FROM folders f WHERE f.id = target AND f.trashed_at IS NOT NULL) THEN
        SELECT root_folder_id INTO target FROM libraries WHERE id = d.library_id;
    END IF;
    nm := d.name;
    WHILE EXISTS (SELECT 1 FROM documents x WHERE x.folder_id = target AND lower(x.name) = lower(nm) AND x.trashed_at IS NULL AND x.id <> d.id) LOOP
        i := i + 1; nm := regexp_replace(d.name, '(\.[^.]{1,12})?$', ' (' || i || ')\1');
    END LOOP;
    UPDATE documents SET trashed_at = NULL, trashed_by = NULL, trashed_from_folder_id = NULL, folder_id = target, name = nm WHERE id = d.id;
    RETURN true;
END$$;

-- Purge: final. Versions purged (blobs released), text gone, the row deleted; db/012's trigger queues the engine's forget.
CREATE OR REPLACE FUNCTION dc_document_purge(p_document_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE documents SET current_version_id = NULL WHERE id = p_document_id AND trashed_at IS NOT NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n = 0 THEN RETURN false; END IF;      -- only a trashed document is purged
    UPDATE document_versions SET purged_at = now() WHERE document_id = p_document_id AND purged_at IS NULL;
    DELETE FROM documents WHERE id = p_document_id;
    RETURN true;
END$$;

-- Locks.
CREATE OR REPLACE FUNCTION dc_document_lock(p_document_id bigint, p_by bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE documents SET locked_by = p_by, locked_at = now(), lock_expires_at = now() + make_interval(hours => dc_setting_int('lock_hours')::integer)
     WHERE id = p_document_id AND trashed_at IS NULL AND (locked_by IS NULL OR locked_by = p_by OR lock_expires_at <= now());
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;

CREATE OR REPLACE FUNCTION dc_document_unlock(p_document_id bigint, p_by bigint, p_force boolean DEFAULT false) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE documents SET locked_by = NULL, locked_at = NULL, lock_expires_at = NULL
     WHERE id = p_document_id AND locked_by IS NOT NULL AND (locked_by = p_by OR p_force);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;

-- After a bulk load (app.dc_bulk = '1' while loading): the counts the per-row triggers skipped — blobs' ref_count and the
-- libraries' used_bytes — recomputed in two statements.
CREATE OR REPLACE FUNCTION dc_recount_bulk() RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE blobs b SET ref_count = c.n FROM (SELECT blob_id, count(*) AS n FROM document_versions WHERE purged_at IS NULL GROUP BY blob_id) c WHERE c.blob_id = b.id AND b.ref_count <> c.n;
    UPDATE libraries l SET used_bytes = c.b FROM (SELECT d.library_id, COALESCE(sum(v.byte_size), 0) AS b FROM documents d JOIN document_versions v ON v.document_id = d.id AND v.purged_at IS NULL GROUP BY d.library_id) c
     WHERE c.library_id = l.id AND l.used_bytes <> c.b;
END$$;

-- Ownership transfer (a curator's; a leaver's documents to someone).
CREATE OR REPLACE FUNCTION dc_ownership_transfer(p_from bigint, p_to bigint, p_library_id bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM members WHERE id = p_to AND status = 'active' AND capability IS NOT NULL) THEN
        RAISE EXCEPTION 'The new owner must be admitted here' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE documents SET owner_member_id = p_to WHERE owner_member_id = p_from AND (p_library_id IS NULL OR library_id = p_library_id);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON blobs, documents, document_versions, document_text, upload_sessions, upload_parts TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_mime_kind(text), dc_quota_allows(bigint, bigint) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_blob_claim(text, bigint), dc_version_add(bigint, bigint, text, bigint, text, text, bigint, text, bigint, text, text),
    dc_version_restore(bigint, bigint), dc_document_trash(bigint, bigint), dc_document_restore(bigint), dc_document_purge(bigint),
    dc_document_lock(bigint, bigint), dc_document_unlock(bigint, bigint, boolean), dc_ownership_transfer(bigint, bigint, bigint), dc_recount_bulk() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_blob_claim(text, bigint), dc_version_add(bigint, bigint, text, bigint, text, text, bigint, text, bigint, text, text),
    dc_version_restore(bigint, bigint), dc_document_trash(bigint, bigint), dc_document_restore(bigint), dc_document_purge(bigint),
    dc_document_lock(bigint, bigint), dc_document_unlock(bigint, bigint, boolean), dc_ownership_transfer(bigint, bigint, bigint), dc_recount_bulk() TO doccloud_rw;

COMMIT;
