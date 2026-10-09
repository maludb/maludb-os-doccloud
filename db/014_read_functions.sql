-- 014: THE READS THAT MATTER (one SQL function each, filtered by the walls) and THE WORKER'S PASSES (design §6.3, §7, §14).
--
-- Reads: the folder listing (one page, one statement, the wall per row), shared-with-me, storage by library and owner, the
-- quota state, duplicates, untagged, stale guest shares, links expiring, the trash due, locks, extraction failures, access
-- history (who could see this on a day), search gaps. Passes: versions and trash windows, blobs freed, locks, links, upload
-- sessions, exports, outbox pruning — each idempotent, each returns a count (or the paths to unlink), each the worker's alone.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Reads.
-- ---------------------------------------------------------------------------------------------
-- One folder's listing: subfolders then documents the caller may see, sorted, paged, with tags and the caller's level on each.
CREATE OR REPLACE FUNCTION dc_folder_listing(p_folder_id bigint, p_sort text DEFAULT 'name', p_dir text DEFAULT 'asc', p_limit integer DEFAULT 100, p_offset integer DEFAULT 0, p_kind text DEFAULT NULL)
    RETURNS TABLE (kind text, id bigint, name text, mime_kind text, byte_size bigint, owner_member_id bigint, owner_name text, updated_at timestamptz,
                   tags text[], level text, locked_by bigint, version_count integer, has_thumbnail boolean, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE my_rank smallint; me bigint := app_current_member_id(); is_admin boolean := dc_is_admin();
BEGIN
    my_rank := dc_folder_rank(p_folder_id);
    IF my_rank = 0 THEN RETURN; END IF;
    RETURN QUERY
    WITH rows_ AS (
        SELECT 'folder'::text AS kind, f.id, f.name, NULL::text AS mime_kind, NULL::bigint AS byte_size, f.owner_member_id, f.updated_at,
               '{}'::text[] AS tags, dc_folder_level(f.id) AS level, NULL::bigint AS locked_by, NULL::integer AS version_count, false AS has_thumbnail
          FROM folders f WHERE f.parent_id = p_folder_id AND f.trashed_at IS NULL AND (p_kind IS NULL OR p_kind = 'folder') AND dc_can_see_folder(f.id)
        UNION ALL
        SELECT 'document', d.id, d.name, d.mime_kind, d.byte_size, d.owner_member_id, d.updated_at,
               dc_document_tag_slugs(d.id),
               -- a plain document's level IS the folder's (upload = none for another's; the owner manages); only a document with rows of its own asks
               CASE WHEN d.owner_member_id = me OR is_admin THEN 'manage'
                    WHEN d.own_access THEN dc_document_level(d.id)
                    WHEN my_rank = 2 THEN 'none' ELSE dc_rank_level(my_rank) END,
               d.locked_by, d.version_count, d.mime_kind IN ('image', 'pdf')
          FROM documents d WHERE d.folder_id = p_folder_id AND d.trashed_at IS NULL AND (p_kind IS NULL OR p_kind = 'document' OR d.mime_kind = p_kind)
           AND (is_admin OR d.owner_member_id = me OR (NOT d.own_access AND my_rank >= 1 AND my_rank <> 2) OR (d.own_access AND dc_can_see_document(d.id))))
    SELECT r.kind, r.id, r.name, r.mime_kind, r.byte_size, r.owner_member_id, m.display_name, r.updated_at, r.tags, r.level, r.locked_by, r.version_count, r.has_thumbnail,
           count(*) OVER ()
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

-- The libraries the caller reaches, with their level (personal: own; group: a member's; shared: everyone's; admin: all).
CREATE OR REPLACE FUNCTION dc_my_libraries()
    RETURNS TABLE (library_id bigint, kind text, name text, group_id bigint, root_folder_id bigint, level text, used_bytes bigint, quota_bytes bigint, graph_policy text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.kind, l.name, l.group_id, l.root_folder_id, dc_rank_level(GREATEST(dc_library_base_level(l.id, app_current_member_id()), CASE WHEN dc_is_admin() THEN 4 ELSE 0 END)::smallint),
           l.used_bytes, l.quota_bytes, l.graph_policy
      FROM libraries l
     WHERE l.archived_at IS NULL
       AND (dc_is_admin() OR dc_library_base_level(l.id, app_current_member_id()) > 0)
     ORDER BY CASE l.kind WHEN 'personal' THEN 0 WHEN 'group' THEN 1 ELSE 2 END, l.name;
$$;

-- Shared with me: folders and documents with an explicit row naming me, my groups or my departments (never the library base),
-- newest share first.
CREATE OR REPLACE FUNCTION dc_shared_with_me(p_limit integer DEFAULT 100, p_offset integer DEFAULT 0)
    RETURNS TABLE (kind text, id bigint, name text, level text, shared_by bigint, shared_at timestamptz, via text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH p AS (SELECT * FROM dc_my_principals()),
         s AS (
        SELECT 'folder'::text AS kind, fa.folder_id AS id, fa.level, fa.granted_by, fa.created_at, fa.principal_kind AS via
          FROM folder_access fa JOIN p ON p.kind = fa.principal_kind AND (p.id = fa.principal_id OR (fa.principal_kind = 'everyone' AND p.kind = 'everyone'))
         WHERE fa.principal_kind NOT IN ('everyone', 'application') AND fa.level <> 'none'
        UNION ALL
        SELECT 'document', da.document_id, da.level, da.granted_by, da.created_at, da.principal_kind
          FROM document_access da JOIN p ON p.kind = da.principal_kind AND p.id = da.principal_id
         WHERE da.principal_kind NOT IN ('everyone', 'application') AND da.level <> 'none')
    SELECT s.kind, s.id, COALESCE(f.name, d.name), s.level, s.granted_by, s.created_at, s.via
      FROM s LEFT JOIN folders f ON s.kind = 'folder' AND f.id = s.id LEFT JOIN documents d ON s.kind = 'document' AND d.id = s.id
     WHERE (f.id IS NOT NULL AND f.trashed_at IS NULL AND f.owner_member_id IS DISTINCT FROM app_current_member_id() AND dc_can_see_folder(f.id))
        OR (d.id IS NOT NULL AND d.trashed_at IS NULL AND d.owner_member_id <> app_current_member_id() AND dc_can_see_document(d.id))
     ORDER BY s.created_at DESC
     LIMIT greatest(1, least(p_limit, 500)) OFFSET greatest(0, p_offset);
$$;

-- What I shared out, and to whom (rows I granted).
CREATE OR REPLACE FUNCTION dc_my_shares()
    RETURNS TABLE (kind text, id bigint, name text, principal_kind text, principal_id bigint, principal_ref text, principal_name text, level text, created_at timestamptz, is_guest boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'folder', fa.folder_id, f.name, fa.principal_kind, fa.principal_id, fa.principal_ref, dc_principal_name(fa.principal_kind, fa.principal_id, fa.principal_ref), fa.level, fa.created_at,
           fa.principal_kind IN ('member', 'guest') AND dc_member_is_guest(fa.principal_id)
      FROM folder_access fa JOIN folders f ON f.id = fa.folder_id WHERE fa.granted_by = app_current_member_id()
    UNION ALL
    SELECT 'document', da.document_id, d.name, da.principal_kind, da.principal_id, da.principal_ref, dc_principal_name(da.principal_kind, da.principal_id, da.principal_ref), da.level, da.created_at,
           da.principal_kind IN ('member', 'guest') AND dc_member_is_guest(da.principal_id)
      FROM document_access da JOIN documents d ON d.id = da.document_id WHERE da.granted_by = app_current_member_id()
    ORDER BY 9 DESC;
$$;

-- Shares to guests older than N days (the Clerk's "stale"), over everything (curators) — callers filter by what they manage.
CREATE OR REPLACE FUNCTION dc_stale_guest_shares(p_days integer DEFAULT NULL)
    RETURNS TABLE (kind text, id bigint, name text, guest_member_id bigint, guest_name text, level text, created_at timestamptz, granted_by bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH cut AS (SELECT now() - make_interval(days => COALESCE(p_days, dc_setting_int('stale_guest_share_days')::integer)) AS t)
    SELECT 'folder', fa.folder_id, f.name, fa.principal_id, m.display_name, fa.level, fa.created_at, fa.granted_by
      FROM folder_access fa JOIN folders f ON f.id = fa.folder_id JOIN members m ON m.id = fa.principal_id, cut
     WHERE fa.principal_kind IN ('member', 'guest') AND dc_member_is_guest(fa.principal_id) AND fa.created_at < cut.t AND f.trashed_at IS NULL AND dc_can_manage_folder(f.id)
    UNION ALL
    SELECT 'document', da.document_id, d.name, da.principal_id, m.display_name, da.level, da.created_at, da.granted_by
      FROM document_access da JOIN documents d ON d.id = da.document_id JOIN members m ON m.id = da.principal_id, cut
     WHERE da.principal_kind IN ('member', 'guest') AND dc_member_is_guest(da.principal_id) AND da.created_at < cut.t AND d.trashed_at IS NULL AND dc_can_manage_document(d.id)
    ORDER BY 7;
$$;

CREATE OR REPLACE FUNCTION dc_storage_by_library()
    RETURNS TABLE (library_id bigint, kind text, name text, used_bytes bigint, quota_bytes bigint, pct numeric, documents bigint, versions bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH dc AS (SELECT d.library_id, count(*) AS n FROM documents d WHERE d.trashed_at IS NULL GROUP BY d.library_id),
         vc AS (SELECT d.library_id, count(*) AS n FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE v.purged_at IS NULL GROUP BY d.library_id)
    SELECT l.id, l.kind, l.name, l.used_bytes, l.quota_bytes, round(100.0 * l.used_bytes / NULLIF(l.quota_bytes, 0), 1), COALESCE(dc.n, 0), COALESCE(vc.n, 0)
      FROM libraries l LEFT JOIN dc ON dc.library_id = l.id LEFT JOIN vc ON vc.library_id = l.id
     WHERE l.archived_at IS NULL AND ((SELECT dc_has_right('reports.storage')) OR dc_library_base_level(l.id, app_current_member_id()) = 4)
     ORDER BY l.used_bytes DESC;
$$;

CREATE OR REPLACE FUNCTION dc_storage_by_owner(p_limit integer DEFAULT 50)
    RETURNS TABLE (member_id bigint, display_name text, documents bigint, live_bytes bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.owner_member_id, m.display_name, count(*), COALESCE(sum(d.byte_size), 0)
      FROM documents d JOIN members m ON m.id = d.owner_member_id
     WHERE d.trashed_at IS NULL AND dc_has_right('reports.storage')
     GROUP BY d.owner_member_id, m.display_name ORDER BY sum(d.byte_size) DESC LIMIT greatest(1, least(p_limit, 500));
$$;

CREATE OR REPLACE FUNCTION dc_quota_state(p_library_id bigint)
    RETURNS TABLE (used_bytes bigint, quota_bytes bigint, pct numeric, near boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.used_bytes, l.quota_bytes, round(100.0 * l.used_bytes / NULLIF(l.quota_bytes, 0), 1), l.quota_bytes IS NOT NULL AND l.used_bytes >= 0.9 * l.quota_bytes
      FROM libraries l WHERE l.id = p_library_id AND (dc_is_admin() OR dc_library_base_level(l.id, app_current_member_id()) > 0);
$$;

CREATE OR REPLACE FUNCTION dc_is_clerk_or_admin() RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT dc_is_admin() OR (app_member_kind() = 'agent' AND dc_has_right('groups.manage'));   -- db/016 narrows the agent to the Clerk (dc_is_clerk)
$$;

-- The same bytes in two places: the curator's report (reports.storage) and the Clerk's — names and ids across the store, never
-- content, so no per-row wall (200,000 wall calls were 25 s); anyone else gets the duplicates among their OWN documents.
CREATE OR REPLACE FUNCTION dc_duplicates(p_limit integer DEFAULT 100)
    RETURNS TABLE (sha256 text, byte_size bigint, copies bigint, document_ids bigint[], names text[])
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.sha256, max(d.byte_size), count(*), array_agg(d.id ORDER BY d.id), array_agg(d.name ORDER BY d.id)
      FROM documents d
     WHERE d.trashed_at IS NULL AND d.sha256 IS NOT NULL
       AND ((SELECT dc_has_right('reports.storage') OR dc_is_clerk_or_admin()) OR d.owner_member_id = app_current_member_id())
     GROUP BY d.sha256 HAVING count(*) > 1
     ORDER BY max(d.byte_size) * (count(*) - 1) DESC LIMIT greatest(1, least(p_limit, 1000));
$$;

CREATE OR REPLACE FUNCTION dc_untagged(p_folder_id bigint DEFAULT NULL, p_limit integer DEFAULT 200)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, owner_member_id bigint, created_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.name, d.folder_id, d.owner_member_id, d.created_at
      FROM documents d
     WHERE d.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id)
       AND (p_folder_id IS NULL OR d.folder_id IN (SELECT dc_folder_subtree_ids(p_folder_id)))
       AND dc_can_see_document(d.id)
     ORDER BY d.created_at DESC LIMIT greatest(1, least(p_limit, 1000));
$$;

CREATE OR REPLACE FUNCTION dc_links_expiring(p_days integer DEFAULT 7)
    RETURNS TABLE (link_id bigint, label text, kind text, folder_id bigint, document_id bigint, expires_at timestamptz, made_by bigint, view_count integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.label, l.kind, l.folder_id, l.document_id, l.expires_at, l.made_by, l.view_count
      FROM share_links l
     WHERE l.rotated_at IS NULL AND l.revoked_at IS NULL AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN now() AND now() + make_interval(days => p_days)
       AND (dc_has_right('links.all') OR l.made_by = app_current_member_id()
            OR (l.folder_id IS NOT NULL AND dc_can_manage_folder(l.folder_id)) OR (l.document_id IS NOT NULL AND dc_can_manage_document(l.document_id)))
     ORDER BY l.expires_at;
$$;

CREATE OR REPLACE FUNCTION dc_find_links(p_folder_id bigint DEFAULT NULL, p_document_id bigint DEFAULT NULL, p_kind text DEFAULT NULL, p_live_only boolean DEFAULT true)
    RETURNS TABLE (link_id bigint, label text, kind text, folder_id bigint, document_id bigint, allow_download boolean, has_password boolean, expires_at timestamptz,
                   view_count integer, upload_count integer, last_used_at timestamptz, made_by bigint, created_at timestamptz, revoked_at timestamptz, rotated_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.label, l.kind, l.folder_id, l.document_id, l.allow_download, l.password_hash IS NOT NULL, l.expires_at, l.view_count, l.upload_count, l.last_used_at,
           l.made_by, l.created_at, l.revoked_at, l.rotated_at
      FROM share_links l
     WHERE (p_folder_id IS NULL OR l.folder_id = p_folder_id) AND (p_document_id IS NULL OR l.document_id = p_document_id) AND (p_kind IS NULL OR l.kind = p_kind)
       AND (NOT p_live_only OR (l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now())))
       AND (dc_has_right('links.all') OR l.made_by = app_current_member_id()
            OR (l.folder_id IS NOT NULL AND dc_can_manage_folder(l.folder_id)) OR (l.document_id IS NOT NULL AND dc_can_manage_document(l.document_id)))
     ORDER BY l.created_at DESC;
$$;

CREATE OR REPLACE FUNCTION dc_trash(p_folder_id bigint DEFAULT NULL)
    RETURNS TABLE (kind text, id bigint, name text, trashed_at timestamptz, trashed_by bigint, purge_at timestamptz, from_folder_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'document', d.id, d.name, d.trashed_at, d.trashed_by, d.trashed_at + make_interval(days => dc_setting_int('trash_days')::integer), d.trashed_from_folder_id
      FROM documents d
     WHERE d.trashed_at IS NOT NULL AND (p_folder_id IS NULL OR d.trashed_from_folder_id = p_folder_id OR d.folder_id = p_folder_id)
       AND (d.owner_member_id = app_current_member_id() OR d.trashed_by = app_current_member_id() OR dc_can_manage_document(d.id))
    UNION ALL
    SELECT 'folder', f.id, f.name, f.trashed_at, f.trashed_by, f.trashed_at + make_interval(days => dc_setting_int('trash_days')::integer), f.parent_id
      FROM folders f
     WHERE f.trashed_at IS NOT NULL AND (p_folder_id IS NULL OR f.parent_id = p_folder_id)
       AND NOT EXISTS (SELECT 1 FROM folders p WHERE p.id = f.parent_id AND p.trashed_at = f.trashed_at)   -- the top of a trashed subtree only
       AND (f.trashed_by = app_current_member_id() OR dc_can_manage_folder(f.id))
    ORDER BY 4 DESC;
$$;

CREATE OR REPLACE FUNCTION dc_trash_due(p_days integer DEFAULT 7)
    RETURNS TABLE (kind text, id bigint, name text, purge_at timestamptz, owner_member_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT t.kind, t.id, t.name, t.purge_at, d.owner_member_id FROM dc_trash() t LEFT JOIN documents d ON t.kind = 'document' AND d.id = t.id
     WHERE t.purge_at < now() + make_interval(days => p_days) ORDER BY t.purge_at;
$$;

CREATE OR REPLACE FUNCTION dc_locked_documents()
    RETURNS TABLE (document_id bigint, name text, locked_by bigint, locked_at timestamptz, lock_expires_at timestamptz, folder_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.name, d.locked_by, d.locked_at, d.lock_expires_at, d.folder_id FROM documents d
     WHERE d.locked_by IS NOT NULL AND d.trashed_at IS NULL AND dc_can_see_document(d.id) ORDER BY d.locked_at;
$$;

CREATE OR REPLACE FUNCTION dc_extraction_failures(p_limit integer DEFAULT 200)
    RETURNS TABLE (document_id bigint, name text, version_id bigint, status text, error text, uploaded_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.name, v.id, v.extracted_status, t.error, v.uploaded_at
      FROM documents d JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN document_text t ON t.version_id = v.id
     WHERE d.trashed_at IS NULL AND v.extracted_status IN ('no_text', 'unsupported', 'failed') AND dc_can_see_document(d.id)
     ORDER BY v.uploaded_at DESC LIMIT greatest(1, least(p_limit, 1000));
$$;

-- Who could see a document (or folder) on a day: the access rows as they stood, replayed from the trail (the explicit rows
-- only — the library base is a membership question the directory answers).
CREATE OR REPLACE FUNCTION dc_access_history(p_document_id bigint, p_folder_id bigint, p_at timestamptz)
    RETURNS TABLE (principal_kind text, principal_id bigint, principal_ref text, principal_name text, level text, since timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH last AS (
        SELECT DISTINCT ON (c.principal_kind, COALESCE(c.principal_id, 0), COALESCE(c.principal_ref, '')) c.*
          FROM access_changes c
         WHERE ((p_document_id IS NOT NULL AND c.document_id = p_document_id) OR (p_folder_id IS NOT NULL AND c.folder_id = p_folder_id)) AND c.changed_at <= p_at
         ORDER BY c.principal_kind, COALESCE(c.principal_id, 0), COALESCE(c.principal_ref, ''), c.changed_at DESC)
    SELECT l.principal_kind, l.principal_id, l.principal_ref, dc_principal_name(l.principal_kind, l.principal_id, l.principal_ref), l.new_level, l.changed_at
      FROM last l WHERE l.new_level IS NOT NULL AND l.new_level <> 'none'
       AND ((p_document_id IS NOT NULL AND dc_can_manage_document(p_document_id)) OR (p_folder_id IS NOT NULL AND dc_can_manage_folder(p_folder_id)) OR dc_has_right('reports.search'));
$$;

CREATE OR REPLACE FUNCTION dc_search_gaps(p_since timestamptz DEFAULT now() - interval '30 days', p_limit integer DEFAULT 50)
    RETURNS TABLE (query text, times bigint, last_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT lower(s.query), count(*), max(s.created_at) FROM search_log s
     WHERE s.results = 0 AND s.created_at >= p_since AND dc_has_right('reports.search')
     GROUP BY lower(s.query) ORDER BY count(*) DESC, max(s.created_at) DESC LIMIT greatest(1, least(p_limit, 500));
$$;

-- Recent and favourites for the home.
CREATE OR REPLACE FUNCTION dc_recent_documents(p_limit integer DEFAULT 20)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, mime_kind text, updated_at timestamptz, last_opened_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id, c.name, c.folder_id, c.mime_kind, c.updated_at, c.last_opened_at
      FROM (SELECT d.* FROM documents d WHERE d.trashed_at IS NULL AND d.owner_member_id = app_current_member_id() ORDER BY d.updated_at DESC LIMIT 100) c
     WHERE dc_can_see_document(c.id)
     ORDER BY GREATEST(c.updated_at, COALESCE(c.last_opened_at, c.updated_at)) DESC LIMIT greatest(1, least(p_limit, 100));
$$;

-- ---------------------------------------------------------------------------------------------
-- The worker's passes.
-- ---------------------------------------------------------------------------------------------
-- Versions: all kept for versions_keep_days, then the last versions_keep_count (the current always). Returns how many purged.
CREATE OR REPLACE FUNCTION dc_pass_versions_expire(p_limit integer DEFAULT 1000) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer; keep_days integer := dc_setting_int('versions_keep_days')::integer; keep_n integer := dc_setting_int('versions_keep_count')::integer;
BEGIN
    WITH ranked AS (
        SELECT v.id, row_number() OVER (PARTITION BY v.document_id ORDER BY v.version_no DESC) AS rn, v.uploaded_at, d.current_version_id
          FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE v.purged_at IS NULL),
         victims AS (
        SELECT id FROM ranked WHERE id <> current_version_id AND rn > keep_n AND uploaded_at < now() - make_interval(days => keep_days) LIMIT p_limit)
    UPDATE document_versions v SET purged_at = now() FROM victims WHERE v.id = victims.id;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- The trash: documents and folders past trash_days are purged (documents through dc_document_purge so the engine forgets them).
CREATE OR REPLACE FUNCTION dc_pass_trash_purge(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record; days integer := dc_setting_int('trash_days')::integer;
BEGIN
    FOR r IN SELECT id FROM documents WHERE trashed_at IS NOT NULL AND trashed_at < now() - make_interval(days => days) ORDER BY trashed_at LIMIT p_limit LOOP
        IF dc_document_purge(r.id) THEN n := n + 1; END IF;
    END LOOP;
    DELETE FROM folders f WHERE f.trashed_at IS NOT NULL AND f.trashed_at < now() - make_interval(days => days)
       AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.folder_id = f.id);
    RETURN n;
END$$;

-- Blobs nobody holds: the paths to unlink (marked deleted first, so a crash never leaves a path twice).
CREATE OR REPLACE FUNCTION dc_pass_blobs_free(p_limit integer DEFAULT 500) RETURNS SETOF text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        WITH old AS (SELECT b.id, b.storage_path FROM blobs b WHERE b.ref_count = 0 AND b.deleted_at IS NULL AND b.created_at < now() - interval '1 hour' LIMIT p_limit FOR UPDATE SKIP LOCKED),
             upd AS (UPDATE blobs b SET deleted_at = now() FROM old WHERE b.id = old.id RETURNING old.storage_path AS path)
        SELECT path FROM upd;
END$$;

CREATE OR REPLACE FUNCTION dc_pass_locks_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE documents SET locked_by = NULL, locked_at = NULL, lock_expires_at = NULL WHERE locked_by IS NOT NULL AND lock_expires_at <= now();
    GET DIAGNOSTICS n = ROW_COUNT; RETURN n;
END$$;

-- Upload sessions abandoned: expired (the parts on disk are the worker's to remove: returns the session ids).
CREATE OR REPLACE FUNCTION dc_pass_uploads_expire() RETURNS SETOF bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        WITH upd AS (UPDATE upload_sessions SET status = 'expired' WHERE status IN ('open', 'assembling') AND expires_at < now() RETURNING id)
        SELECT id FROM upd;
END$$;

CREATE OR REPLACE FUNCTION dc_pass_exports_expire() RETURNS SETOF text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        WITH old AS (SELECT x.id, x.storage_path FROM exports x WHERE x.storage_path IS NOT NULL AND x.expires_at < now() FOR UPDATE),
             upd AS (UPDATE exports e SET storage_path = NULL FROM old WHERE e.id = old.id RETURNING old.storage_path AS path)
        SELECT path FROM upd;
END$$;

-- Clerk proposals nobody decided in 30 days expire; search_log older than 90 days goes; link opens older than a year go.
CREATE OR REPLACE FUNCTION dc_pass_housekeeping() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; k integer;
BEGIN
    UPDATE clerk_proposals SET status = 'expired' WHERE status = 'proposed' AND created_at < now() - interval '30 days'; GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    DELETE FROM search_log WHERE created_at < now() - interval '90 days'; GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    DELETE FROM link_opens WHERE opened_at < now() - interval '365 days'; GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    DELETE FROM notification_outbox WHERE status IN ('sent', 'skipped') AND created_at < now() - interval '30 days'; GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    n := n + dc_prune_key_usage() + dc_prune_link_rate();
    RETURN n;
END$$;

-- Documents the graph should hold but does not (a bulk load, a failed pass): queue them, p_limit at a time.
CREATE OR REPLACE FUNCTION dc_pass_graph_catch_up(p_limit integer DEFAULT 200) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN SELECT d.id FROM documents d JOIN document_text t ON t.version_id = d.current_version_id AND t.char_count > 0
              LEFT JOIN graph_documents g ON g.document_id = d.id
             WHERE d.trashed_at IS NULL AND (g.document_id IS NULL OR g.version_id IS DISTINCT FROM d.current_version_id)
               AND NOT EXISTS (SELECT 1 FROM graph_jobs j WHERE j.document_id = d.id AND j.status IN ('queued', 'running'))
             LIMIT p_limit LOOP
        IF dc_graph_queue_document(r.id) IN ('index', 'full') THEN n := n + 1; END IF;
    END LOOP;
    RETURN n;
END$$;

GRANT EXECUTE ON FUNCTION dc_is_clerk_or_admin(), dc_folder_listing(bigint, text, text, integer, integer, text), dc_my_libraries(), dc_shared_with_me(integer, integer), dc_my_shares(),
    dc_stale_guest_shares(integer), dc_storage_by_library(), dc_storage_by_owner(integer), dc_quota_state(bigint), dc_duplicates(integer), dc_untagged(bigint, integer),
    dc_links_expiring(integer), dc_find_links(bigint, bigint, text, boolean), dc_trash(bigint), dc_trash_due(integer), dc_locked_documents(), dc_extraction_failures(integer),
    dc_access_history(bigint, bigint, timestamptz), dc_search_gaps(timestamptz, integer), dc_recent_documents(integer)
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_pass_versions_expire(integer), dc_pass_trash_purge(integer), dc_pass_blobs_free(integer), dc_pass_locks_expire(), dc_pass_uploads_expire(),
    dc_pass_exports_expire(), dc_pass_housekeeping(), dc_pass_graph_catch_up(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_pass_versions_expire(integer), dc_pass_trash_purge(integer), dc_pass_blobs_free(integer), dc_pass_locks_expire(), dc_pass_uploads_expire(),
    dc_pass_exports_expire(), dc_pass_housekeeping(), dc_pass_graph_catch_up(integer) TO doccloud_rw;

COMMIT;
