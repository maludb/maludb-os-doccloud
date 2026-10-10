-- 023 (the grid view, thumbnails, video and hidden previews — docs/build-specs/grid-and-media.md, the owner 2026-10-10): the worker's
-- thumbnail queue moves from files on disk to a column (videos join images and PDFs, and a version waiting on a missing tool is retried
-- once the tool exists), a video's length is kept, and a document's preview can be HIDDEN for everyone by anyone who may edit it.
-- Hidden is a row in a table of its own, never a column on documents: every UPDATE of documents moves updated_at (the "Modified" date)
-- and wakes the search trigger, and hiding a preview must do neither. The listing appends what the grid needs; the two views append
-- their columns LAST. Additive; nothing else changes.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Thumbnails and a video's length, per version (the bytes decide both).
-- ---------------------------------------------------------------------------------------------
ALTER TABLE document_versions
    ADD COLUMN thumb_state text CHECK (thumb_state IN ('made', 'none', 'no_tool')),
    ADD COLUMN duration_ms integer CHECK (duration_ms >= 0);
COMMENT ON COLUMN document_versions.thumb_state IS 'The worker''s thumbnail: NULL still to do, made, none (not possible for these bytes), no_tool (ffmpeg or pdftoppm missing — retried once it exists).';
COMMENT ON COLUMN document_versions.duration_ms IS 'A video''s length (ffprobe), when known.';
-- the worker's queue: versions of the kinds that get a thumbnail and are still waiting
CREATE INDEX document_versions_thumb_todo_idx ON document_versions (uploaded_at DESC)
    WHERE purged_at IS NULL AND (thumb_state IS NULL OR thumb_state = 'no_tool')
      AND (mime_type LIKE 'image/%' OR mime_type LIKE 'video/%' OR mime_type = 'application/pdf');

-- ---------------------------------------------------------------------------------------------
-- A hidden preview (NEW canonical — no sibling keeps one): a row = the document's image or video shows a placeholder to everyone.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE document_preview_hidden (
    document_id bigint PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
    hidden_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    hidden_at   timestamptz NOT NULL DEFAULT now()
);
COMMENT ON TABLE document_preview_hidden IS 'A document whose preview (thumbnail, inline image or player) shows a placeholder to every viewer until someone who may edit it shows it again. A screen''s privacy, not a wall: a viewer may still open the file.';
GRANT SELECT, INSERT, UPDATE, DELETE ON document_preview_hidden TO doccloud_rw;

-- ---------------------------------------------------------------------------------------------
-- The listing: three columns appended (mime_type, preview_hidden, duration_ms); has_thumbnail = a thumbnail was made.
-- The OUT columns change, so the function is dropped and made again, and its grant restored.
-- ---------------------------------------------------------------------------------------------
DROP FUNCTION dc_folder_listing(bigint, text, text, integer, integer, text);
CREATE FUNCTION dc_folder_listing(p_folder_id bigint, p_sort text DEFAULT 'name', p_dir text DEFAULT 'asc', p_limit integer DEFAULT 100, p_offset integer DEFAULT 0, p_kind text DEFAULT NULL)
    RETURNS TABLE (kind text, id bigint, name text, mime_kind text, byte_size bigint, owner_member_id bigint, owner_name text, updated_at timestamptz,
                   tags text[], level text, locked_by bigint, version_count integer, has_thumbnail boolean, total bigint,
                   mime_type text, preview_hidden boolean, duration_ms integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE my_rank smallint; me bigint := app_current_member_id(); is_admin boolean := dc_is_admin();
BEGIN
    my_rank := dc_folder_rank(p_folder_id);
    IF my_rank = 0 THEN RETURN; END IF;
    RETURN QUERY
    WITH rows_ AS (
        SELECT 'folder'::text AS kind, f.id, f.name, NULL::text AS mime_kind, NULL::bigint AS byte_size, f.owner_member_id, f.updated_at,
               '{}'::text[] AS tags, dc_folder_level(f.id) AS level, NULL::bigint AS locked_by, NULL::integer AS version_count, false AS has_thumbnail,
               NULL::text AS mime_type, false AS preview_hidden, NULL::integer AS duration_ms
          FROM folders f WHERE f.parent_id = p_folder_id AND f.trashed_at IS NULL AND (p_kind IS NULL OR p_kind = 'folder') AND dc_can_see_folder(f.id)
        UNION ALL
        SELECT 'document', d.id, d.name, d.mime_kind, d.byte_size, d.owner_member_id, d.updated_at,
               dc_document_tag_slugs(d.id),
               -- a plain document's level IS the folder's (upload = none for another's; the owner manages); only a document with rows of its own asks
               CASE WHEN d.owner_member_id = me OR is_admin THEN 'manage'
                    WHEN d.own_access THEN dc_document_level(d.id)
                    WHEN my_rank = 2 THEN 'none' ELSE dc_rank_level(my_rank) END,
               d.locked_by, d.version_count, COALESCE(v.thumb_state = 'made', false),
               d.mime_type, EXISTS (SELECT 1 FROM document_preview_hidden h WHERE h.document_id = d.id), v.duration_ms
          FROM documents d LEFT JOIN document_versions v ON v.id = d.current_version_id
         WHERE d.folder_id = p_folder_id AND d.trashed_at IS NULL AND (p_kind IS NULL OR p_kind = 'document' OR d.mime_kind = p_kind)
           AND (is_admin OR d.owner_member_id = me OR (NOT d.own_access AND my_rank >= 1 AND my_rank <> 2) OR (d.own_access AND dc_can_see_document(d.id))))
    SELECT r.kind, r.id, r.name, r.mime_kind, r.byte_size, r.owner_member_id, m.display_name, r.updated_at, r.tags, r.level, r.locked_by, r.version_count, r.has_thumbnail,
           count(*) OVER (), r.mime_type, r.preview_hidden, r.duration_ms
      FROM rows_ r LEFT JOIN members m ON m.id = r.owner_member_id
     ORDER BY CASE WHEN r.kind = 'folder' THEN 0 ELSE 1 END,
              CASE WHEN p_sort = 'name' AND p_dir = 'asc' THEN lower(r.name) END ASC,
              CASE WHEN p_sort = 'name' AND p_dir = 'desc' THEN lower(r.name) END DESC,
              CASE WHEN p_sort = 'updated' AND p_dir = 'asc' THEN r.updated_at END ASC,
              CASE WHEN p_sort = 'updated' AND p_dir = 'desc' THEN r.updated_at END DESC,
              CASE WHEN p_sort = 'size' AND p_dir = 'asc' THEN r.byte_size END ASC,
              CASE WHEN p_sort = 'size' AND p_dir = 'desc' THEN r.byte_size END DESC,
              lower(r.name)
     LIMIT greatest(1, least(p_limit, 500)) OFFSET greatest(0, p_offset);
END$$;
GRANT EXECUTE ON FUNCTION dc_folder_listing(bigint, text, text, integer, integer, text) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

-- ---------------------------------------------------------------------------------------------
-- The read views: their new columns LAST.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_documents WITH (security_barrier = true) AS
SELECT d.id AS document_id, d.library_id, d.folder_id, d.name, d.description, d.owner_member_id, d.current_version_id, d.own_access, d.mime_type, d.mime_kind,
       d.byte_size, d.sha256, d.version_count, d.locked_by, d.locked_at, d.lock_expires_at, d.trashed_at, d.trashed_by, d.trashed_from_folder_id, d.created_by,
       d.created_at, d.updated_at, d.last_opened_at, dc_document_tag_slugs(d.id) AS tags, dc_document_level(d.id) AS my_level,
       EXISTS (SELECT 1 FROM graph_documents g WHERE g.document_id = d.id) AS in_graph,
       (SELECT m.display_name FROM members m WHERE m.id = d.owner_member_id) AS owner_name,
       EXISTS (SELECT 1 FROM document_preview_hidden h WHERE h.document_id = d.id) AS preview_hidden
  FROM documents d WHERE dc_can_see_document(d.id);

CREATE OR REPLACE VIEW mcp_document_versions WITH (security_barrier = true) AS
SELECT v.id AS version_id, v.document_id, v.version_no, v.filename, v.mime_type, v.byte_size, v.sha256, v.uploaded_by, v.uploaded_at, v.source, v.note, v.extracted_status, v.purged_at,
       v.id = (SELECT current_version_id FROM documents WHERE id = v.document_id) AS is_current,
       v.thumb_state, v.duration_ms
  FROM document_versions v WHERE dc_can_see_document(v.document_id);

COMMIT;
