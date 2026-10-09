-- 018: slice 2 (docs/build-specs/uploads-versions.md, uploads-and-versions.md §2) — what an upload session must remember beyond db/007:
-- the DOCUMENT NAME the finish will give (the file name by default; the "(2)" name after "keep both"), the MODE the conflict was answered
-- with, the SOURCE and the NOTE the version will carry. Additive: three columns appended to upload_sessions; mcp_upload_sessions re-created
-- with them LAST. Also the next free name for "keep both" (the rule dc_document_restore uses, as one function PHP and the trigger share).
BEGIN;

ALTER TABLE upload_sessions
    ADD COLUMN document_name text,
    ADD COLUMN mode          text NOT NULL DEFAULT 'new' CHECK (mode IN ('new', 'version', 'keep_both')),
    ADD COLUMN source        text NOT NULL DEFAULT 'web' CHECK (source IN ('web', 'api', 'agent', 'request')),
    ADD COLUMN note          text CHECK (note IS NULL OR length(note) <= 500);

-- The next free name in a folder for "keep both": name.ext → name (2).ext, name (3).ext …
CREATE OR REPLACE FUNCTION dc_free_name(p_folder_id bigint, p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE nm text := p_name; i integer := 1;
BEGIN
    WHILE EXISTS (SELECT 1 FROM documents x WHERE x.folder_id = p_folder_id AND lower(x.name) = lower(nm) AND x.trashed_at IS NULL) LOOP
        i := i + 1; nm := regexp_replace(p_name, '(\.[^.]{1,12})?$', ' (' || i || ')\1');
    END LOOP;
    RETURN nm;
END$$;

CREATE OR REPLACE VIEW mcp_upload_sessions WITH (security_barrier = true) AS
SELECT u.id AS upload_session_id, u.member_id, u.folder_id, u.document_id, u.filename, u.mime_hint, u.byte_size, u.part_bytes, u.parts_total, u.parts_done, u.status, u.version_id,
       u.error, u.created_at, u.updated_at, u.expires_at,
       u.document_name, u.mode, u.source
  FROM upload_sessions u WHERE u.member_id = app_current_member_id() OR (SELECT dc_is_admin());

GRANT SELECT ON mcp_upload_sessions TO doccloud_records_ro, doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_free_name(bigint, text) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

COMMIT;
