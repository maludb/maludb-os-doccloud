-- 019: a defect slice 3's proof found in db/008 (docs/build-specs/access-sharing-links.md): a NEW folder under a folder that has explicit rows was
-- born with access_root_id NULL — the roots were recomputed only when rows changed or a folder MOVED, so a subfolder made under a shared folder
-- fell back to the library's base until the next change above it. The same recompute now runs on INSERT too (additive: a trigger; db/008 stays).
BEGIN;
CREATE TRIGGER folders_after_insert_roots AFTER INSERT ON folders FOR EACH ROW EXECUTE FUNCTION dc_folders_after_move_roots();
-- the folders already born under a root: fixed once
UPDATE folders f SET access_root_id = p.access_root_id FROM folders p WHERE p.id = f.parent_id AND f.access_root_id IS NULL AND p.access_root_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM folder_access fa WHERE fa.folder_id = f.id);
COMMIT;

-- A second defect the same proof found in db/014: dc_access_history() picked each principal's LAST change by changed_at alone, and a restrict
-- deletes and re-inserts a kept row inside one transaction — the same now() — so the delete could win and a kept row vanish from the history.
-- The id breaks the tie (the re-insert's trail row has the higher id). The same signature and grants.
BEGIN;
CREATE OR REPLACE FUNCTION dc_access_history(p_document_id bigint, p_folder_id bigint, p_at timestamptz)
    RETURNS TABLE (principal_kind text, principal_id bigint, principal_ref text, principal_name text, level text, since timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH last AS (
        SELECT DISTINCT ON (c.principal_kind, COALESCE(c.principal_id, 0), COALESCE(c.principal_ref, '')) c.*
          FROM access_changes c
         WHERE ((p_document_id IS NOT NULL AND c.document_id = p_document_id) OR (p_folder_id IS NOT NULL AND c.folder_id = p_folder_id)) AND c.changed_at <= p_at
         ORDER BY c.principal_kind, COALESCE(c.principal_id, 0), COALESCE(c.principal_ref, ''), c.changed_at DESC, c.id DESC)
    SELECT l.principal_kind, l.principal_id, l.principal_ref, dc_principal_name(l.principal_kind, l.principal_id, l.principal_ref), l.new_level, l.changed_at
      FROM last l WHERE l.new_level IS NOT NULL AND l.new_level <> 'none'
       AND ((p_document_id IS NOT NULL AND dc_can_manage_document(p_document_id)) OR (p_folder_id IS NOT NULL AND dc_can_manage_folder(p_folder_id)) OR dc_has_right('reports.search'));
$$;
COMMIT;
