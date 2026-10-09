-- 011: LINKS and FILE REQUESTS — the doors for someone with no account (design §4; D11).
--
-- A link opens exactly one folder (read-only, its subtree) or one document to whoever holds it; a file request (kind
-- `request`) opens one folder for upload only and the uploader sees nothing afterwards. The token: 48 hex, shown once; only
-- its sha256 is kept; the same nothing for every reason it does not open. Expiry defaults to link_default_days (30); "never"
-- only when the admin allows (link_no_expiry_allowed). An optional password (password_hash, PHP's password_hash()). Every
-- open is a link_opens row (an address hash, never the address) and a counted view; the rate per address is link_rate.
-- Making one pauses for an agent (external_send) — the kernel's hook, not this file.
--
-- THE ESTATE'S DATA MODEL (design §6.4): share_links REUSES the secure-link shape (GL invoice_links_secure = Inventory
-- order_links_secure: token_hash, expires_at, rotated_at, last_used_at, view_count, created_at) APPENDING folder_id,
-- document_id, kind, label, allow_download, password_hash, made_by, note, revoked_at, revoked_by, upload_count; link_opens and
-- link_rate are new.
BEGIN;

CREATE TABLE share_links (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    token_hash      text NOT NULL UNIQUE,
    expires_at      timestamptz,
    rotated_at      timestamptz,
    last_used_at    timestamptz,
    view_count      integer NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §4)
    folder_id       bigint REFERENCES folders(id) ON DELETE CASCADE,
    document_id     bigint REFERENCES documents(id) ON DELETE CASCADE,
    kind            text NOT NULL DEFAULT 'view' CHECK (kind IN ('view', 'request')),
    label           text NOT NULL CHECK (length(label) BETWEEN 1 AND 120),     -- what the log and the Clerk call it
    allow_download  boolean NOT NULL DEFAULT true,
    password_hash   text,
    made_by         bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,  -- the request's uploads are owned by them
    note            text CHECK (note IS NULL OR length(note) <= 1000),         -- shown to the holder (a request's message)
    revoked_at      timestamptz,
    revoked_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    upload_count    integer NOT NULL DEFAULT 0,
    CHECK ((folder_id IS NULL) <> (document_id IS NULL)),
    CHECK (kind <> 'request' OR folder_id IS NOT NULL)
);
CREATE INDEX share_links_folder_idx ON share_links (folder_id) WHERE rotated_at IS NULL AND revoked_at IS NULL;
CREATE INDEX share_links_document_idx ON share_links (document_id) WHERE rotated_at IS NULL AND revoked_at IS NULL;
CREATE INDEX share_links_made_by_idx ON share_links (made_by, created_at DESC);
CREATE INDEX share_links_expiry_idx ON share_links (expires_at) WHERE rotated_at IS NULL AND revoked_at IS NULL;

CREATE TABLE link_opens (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    link_id       bigint NOT NULL REFERENCES share_links(id) ON DELETE CASCADE,
    opened_at     timestamptz NOT NULL DEFAULT now(),
    address_hash  text NOT NULL,                                               -- sha256 of the address and a daily salt, never the address
    what          text NOT NULL CHECK (what IN ('view', 'download', 'upload', 'password_refused', 'rate_refused')),
    document_id   bigint,                                                      -- which one, in a folder link
    byte_size     bigint
);
CREATE INDEX link_opens_link_idx ON link_opens (link_id, opened_at DESC);
CREATE INDEX link_opens_address_idx ON link_opens (link_id, address_hash);

CREATE TABLE link_rate (
    address_hash  text NOT NULL,
    bucket_kind   text NOT NULL CHECK (bucket_kind IN ('open_minute', 'upload_hour')),
    bucket_start  timestamptz NOT NULL,
    calls         integer NOT NULL DEFAULT 0,
    PRIMARY KEY (address_hash, bucket_kind, bucket_start)
);

ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_link_fk FOREIGN KEY (link_id) REFERENCES share_links(id) ON DELETE SET NULL;

-- Mint (or rotate): the raw token is returned ONCE. p_days NULL = the default; 0 = never (only when the admin allows).
CREATE OR REPLACE FUNCTION dc_link_mint(p_folder_id bigint, p_document_id bigint, p_kind text, p_label text, p_days integer, p_allow_download boolean,
                                        p_password_hash text, p_by bigint, p_note text DEFAULT NULL, p_rotate_of bigint DEFAULT NULL)
    RETURNS TABLE (link_id bigint, raw_token text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text; exp timestamptz; days integer := p_days; lid bigint;
BEGIN
    IF days IS NULL THEN days := dc_setting_int('link_default_days')::integer; END IF;
    IF days = 0 THEN
        IF NOT dc_setting_bool('link_no_expiry_allowed') THEN RAISE EXCEPTION 'A link that never expires is not allowed here' USING ERRCODE = 'check_violation'; END IF;
        exp := NULL;
    ELSE
        exp := now() + make_interval(days => days);
    END IF;
    raw := encode(gen_random_bytes(24), 'hex');
    IF p_rotate_of IS NOT NULL THEN UPDATE share_links SET rotated_at = now() WHERE id = p_rotate_of AND rotated_at IS NULL; END IF;
    INSERT INTO share_links (token_hash, expires_at, folder_id, document_id, kind, label, allow_download, password_hash, made_by, note)
    VALUES (encode(sha256(raw::bytea), 'hex'), exp, p_folder_id, p_document_id, p_kind, p_label, p_allow_download, p_password_hash, p_by, p_note)
    RETURNING id INTO lid;
    RETURN QUERY SELECT lid, raw;
END$$;

-- The link a presented token opens: live, not rotated, not revoked, not expired, its maker still admitted. NULL otherwise —
-- the same nothing for every reason. Does NOT count the open (dc_link_open does, once the password is right).
CREATE OR REPLACE FUNCTION dc_link_resolve(p_raw text)
    RETURNS TABLE (link_id bigint, kind text, folder_id bigint, document_id bigint, label text, allow_download boolean, has_password boolean, password_hash text, note text, made_by bigint, expires_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.kind, l.folder_id, l.document_id, l.label, l.allow_download, l.password_hash IS NOT NULL, l.password_hash, l.note, l.made_by, l.expires_at
      FROM share_links l JOIN members m ON m.id = l.made_by
     WHERE p_raw ~ '^[a-f0-9]{48}$' AND l.token_hash = encode(sha256(p_raw::bytea), 'hex')
       AND l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now())
       AND m.status = 'active' AND m.capability IS NOT NULL
       AND (l.document_id IS NULL OR EXISTS (SELECT 1 FROM documents d WHERE d.id = l.document_id AND d.trashed_at IS NULL))
       AND (l.folder_id IS NULL OR EXISTS (SELECT 1 FROM folders f WHERE f.id = l.folder_id AND f.trashed_at IS NULL));
$$;

-- Count an open (or an upload, or a refusal).
CREATE OR REPLACE FUNCTION dc_link_open(p_link_id bigint, p_what text, p_address_hash text, p_document_id bigint DEFAULT NULL, p_bytes bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    INSERT INTO link_opens (link_id, what, address_hash, document_id, byte_size) VALUES (p_link_id, p_what, p_address_hash, p_document_id, p_bytes);
    IF p_what IN ('view', 'download') THEN
        UPDATE share_links SET view_count = view_count + 1, last_used_at = now() WHERE id = p_link_id;
    ELSIF p_what = 'upload' THEN
        UPDATE share_links SET upload_count = upload_count + 1, last_used_at = now() WHERE id = p_link_id;
    END IF;
END$$;

-- The per-address rule: 60 opens a minute, 20 uploads an hour (settings). Counts the call; false = refuse.
CREATE OR REPLACE FUNCTION dc_link_rate_ok(p_address_hash text, p_kind text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE start timestamptz; n integer; lim integer;
BEGIN
    IF p_kind = 'open_minute' THEN start := date_trunc('minute', now()); lim := dc_setting_int('link_opens_per_minute')::integer;
    ELSE start := date_trunc('hour', now()); lim := dc_setting_int('request_uploads_per_hour')::integer; END IF;
    INSERT INTO link_rate (address_hash, bucket_kind, bucket_start, calls) VALUES (p_address_hash, p_kind, start, 1)
    ON CONFLICT (address_hash, bucket_kind, bucket_start) DO UPDATE SET calls = link_rate.calls + 1 RETURNING calls INTO n;
    RETURN n <= lim;
END$$;

-- A document a folder link reaches: live, in the link's subtree.
CREATE OR REPLACE FUNCTION dc_link_reaches_document(p_link_id bigint, p_document_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (
        SELECT 1 FROM share_links l JOIN documents d ON d.id = p_document_id AND d.trashed_at IS NULL
         WHERE l.id = p_link_id AND l.kind = 'view' AND l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now())
           AND (l.document_id = d.id
                OR (l.folder_id IS NOT NULL AND d.folder_id IN (SELECT dc_folder_subtree_ids(l.folder_id)))));
$$;

-- Links opened from many addresses this week (the Clerk's "suspicious"): more than p_addresses distinct hashes.
CREATE OR REPLACE FUNCTION dc_links_suspicious(p_addresses integer DEFAULT 10, p_days integer DEFAULT 7)
    RETURNS TABLE (link_id bigint, label text, addresses bigint, opens bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.label, count(DISTINCT o.address_hash), count(*)
      FROM share_links l JOIN link_opens o ON o.link_id = l.id AND o.opened_at > now() - make_interval(days => p_days)
     WHERE l.rotated_at IS NULL AND l.revoked_at IS NULL
     GROUP BY l.id, l.label HAVING count(DISTINCT o.address_hash) > p_addresses
     ORDER BY count(DISTINCT o.address_hash) DESC;
$$;

CREATE OR REPLACE FUNCTION dc_prune_link_rate() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM link_rate WHERE bucket_start < now() - interval '2 days';
    GET DIAGNOSTICS n = ROW_COUNT; RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON share_links, link_opens, link_rate TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_link_reaches_document(bigint, bigint), dc_links_suspicious(integer, integer) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_link_mint(bigint, bigint, text, text, integer, boolean, text, bigint, text, bigint), dc_link_resolve(text), dc_link_open(bigint, text, text, bigint, bigint),
    dc_link_rate_ok(text, text), dc_prune_link_rate() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_link_mint(bigint, bigint, text, text, integer, boolean, text, bigint, text, bigint), dc_link_resolve(text), dc_link_open(bigint, text, text, bigint, bigint),
    dc_link_rate_ok(text, text), dc_prune_link_rate() TO doccloud_rw;

COMMIT;
