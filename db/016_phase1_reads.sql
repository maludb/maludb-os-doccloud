-- 016: what Phase 1 found the reads lacked (docs/doccloud-mcp-tool-surface.md "Owed to a migration"; docs/build-specs/document-graph.md §11) —
-- additive: new functions, one view, two columns appended last.
--   dc_folder_path_text(folder)                       the folder resolver's label ("SMOKE Board › Contracts › 2026")
--   dc_document_page(version, page), dc_document_excerpt(version, quote, window)   one page of a version's text; the text around a quote
--   mcp_notification_prefs                            a person's own preferences
--   dc_links_suspicious()                             now answers only a holder of links.all (the Clerk's report; the admin)
--   dc_application_folders(app), dc_application_documents(app, folder, since, limit, offset)   the K7 shares' reads (the consumer from X-OS-Consumer)
--   mcp_documents.owner_name, mcp_folders.document_count   appended LAST
--   dc_graph_filter(candidates, limit, filters)       the find's filters (library, folder subtree, kind, owner, tag) inside the wall's one statement
--   dc_graph_job_skip(job, reason)                    the no_edges case (the engine kept nothing; not an error)
--   dc_graph_subjects()                               prepends doc:<id> (the design's "the document itself"); the cap of eight stays
--   dc_pass_graph_catch_up()                          skips a document whose last job failed within a day
BEGIN;

CREATE OR REPLACE FUNCTION dc_folder_path_text(p_folder_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT string_agg(name, ' › ' ORDER BY depth) FROM dc_folder_path(p_folder_id);
$$;

CREATE OR REPLACE FUNCTION dc_document_page(p_version_id bigint, p_page integer)
    RETURNS TABLE (page_no integer, char_from integer, char_to integer, text text, page_count integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT (pg->>'page_no')::integer, (pg->>'char_from')::integer, (pg->>'char_to')::integer,
           substr(t.text, (pg->>'char_from')::integer + 1, GREATEST(0, (pg->>'char_to')::integer - (pg->>'char_from')::integer)),
           jsonb_array_length(t.pages)
      FROM document_text t, jsonb_array_elements(t.pages) pg
     WHERE t.version_id = p_version_id AND (pg->>'page_no')::integer = p_page AND dc_can_see_document(t.document_id);
$$;

CREATE OR REPLACE FUNCTION dc_document_excerpt(p_version_id bigint, p_quote text, p_window integer DEFAULT 300)
    RETURNS TABLE (found boolean, char_from integer, excerpt text, page_no integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE t document_text%ROWTYPE; pos integer; w integer := GREATEST(50, LEAST(p_window, 2000)); pg integer;
BEGIN
    SELECT * INTO t FROM document_text WHERE version_id = p_version_id;
    IF NOT FOUND OR NOT dc_can_see_document(t.document_id) THEN RETURN; END IF;
    pos := position(p_quote IN t.text);
    IF pos = 0 THEN pos := position(lower(p_quote) IN lower(t.text)); END IF;
    IF pos = 0 THEN RETURN QUERY SELECT false, NULL::integer, left(t.text, w), NULL::integer; RETURN; END IF;
    SELECT (p->>'page_no')::integer INTO pg FROM jsonb_array_elements(t.pages) p WHERE (p->>'char_from')::integer < pos AND (p->>'char_to')::integer >= pos LIMIT 1;
    RETURN QUERY SELECT true, pos - 1, substr(t.text, GREATEST(1, pos - w / 2), w + length(p_quote)), pg;
END$$;

CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.updated_at FROM notification_prefs p WHERE p.member_id = app_current_member_id();

CREATE OR REPLACE FUNCTION dc_links_suspicious(p_addresses integer DEFAULT 10, p_days integer DEFAULT 7)
    RETURNS TABLE (link_id bigint, label text, addresses bigint, opens bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.id, l.label, count(DISTINCT o.address_hash), count(*)
      FROM share_links l JOIN link_opens o ON o.link_id = l.id AND o.opened_at > now() - make_interval(days => p_days)
     WHERE l.rotated_at IS NULL AND l.revoked_at IS NULL AND (SELECT dc_has_right('links.all'))
     GROUP BY l.id, l.label HAVING count(DISTINCT o.address_hash) > p_addresses
     ORDER BY count(DISTINCT o.address_hash) DESC;
$$;

-- The folders a sibling application was given (the access root carrying its row), with the subtree reachable from each.
CREATE OR REPLACE FUNCTION dc_application_folders(p_app_key text)
    RETURNS TABLE (folder_id bigint, path text, library_id bigint, level text, document_count bigint, updated_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT f.id, dc_folder_path_text(f.id), f.library_id, fa.level,
           (SELECT count(*) FROM documents d WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(f.id)) AND d.trashed_at IS NULL AND NOT d.own_access),
           f.updated_at
      FROM folder_access fa JOIN folders f ON f.id = fa.folder_id
     WHERE fa.principal_kind = 'application' AND fa.principal_ref = p_app_key AND fa.level <> 'none' AND f.trashed_at IS NULL
     ORDER BY f.path;
$$;

CREATE OR REPLACE FUNCTION dc_application_documents(p_app_key text, p_folder_id bigint, p_since timestamptz DEFAULT NULL, p_limit integer DEFAULT 200, p_offset integer DEFAULT 0)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, folder_path text, mime_type text, mime_kind text, byte_size bigint, sha256 text, tags text[],
                   owner_member_id bigint, version_id bigint, version_no integer, updated_at timestamptz, total bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.name, d.folder_id, dc_folder_path_text(d.folder_id), d.mime_type, d.mime_kind, d.byte_size, d.sha256, dc_document_tag_slugs(d.id),
           d.owner_member_id, d.current_version_id, (SELECT v.version_no FROM document_versions v WHERE v.id = d.current_version_id), d.updated_at, count(*) OVER ()
      FROM documents d
     WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(p_folder_id)) AND d.trashed_at IS NULL
       AND (p_since IS NULL OR d.updated_at > p_since)
       AND dc_application_can_see_document(d.id, p_app_key)
     ORDER BY d.updated_at, d.id
     LIMIT greatest(1, least(p_limit, 500)) OFFSET greatest(0, p_offset);
$$;

-- Appended last (a view's columns are only ever appended).
CREATE OR REPLACE VIEW mcp_documents WITH (security_barrier = true) AS
SELECT d.id AS document_id, d.library_id, d.folder_id, d.name, d.description, d.owner_member_id, d.current_version_id, d.own_access, d.mime_type, d.mime_kind,
       d.byte_size, d.sha256, d.version_count, d.locked_by, d.locked_at, d.lock_expires_at, d.trashed_at, d.trashed_by, d.trashed_from_folder_id, d.created_by,
       d.created_at, d.updated_at, d.last_opened_at, dc_document_tag_slugs(d.id) AS tags, dc_document_level(d.id) AS my_level,
       EXISTS (SELECT 1 FROM graph_documents g WHERE g.document_id = d.id) AS in_graph,
       (SELECT m.display_name FROM members m WHERE m.id = d.owner_member_id) AS owner_name
  FROM documents d WHERE dc_can_see_document(d.id);

CREATE OR REPLACE VIEW mcp_folders WITH (security_barrier = true) AS
SELECT f.id AS folder_id, f.library_id, f.parent_id, f.name, f.path, f.depth, f.access_root_id, f.owner_member_id, f.auto_tags, f.graph_policy, f.colour,
       f.trashed_at, f.trashed_by, f.created_by, f.created_at, f.updated_at, dc_folder_level(f.id) AS my_level,
       (SELECT count(*) FROM documents d WHERE d.folder_id = f.id AND d.trashed_at IS NULL) AS document_count
  FROM folders f WHERE dc_can_see_folder(f.id);

-- The wall with the find's filters inside it (the two-argument form of db/012 replaced: one function, the filters optional).
DROP FUNCTION IF EXISTS dc_graph_filter(jsonb, integer);
CREATE OR REPLACE FUNCTION dc_graph_filter(p_candidates jsonb, p_limit integer DEFAULT 50, p_filters jsonb DEFAULT '{}'::jsonb)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, library_id bigint, owner_member_id bigint, mime_kind text, score numeric, subjects text[], hits integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE f jsonb := COALESCE(p_filters, '{}'::jsonb); lib bigint; fld bigint; fpath text; own bigint; tg text; kd text;
BEGIN
    IF (f->>'library') ~ '^[0-9]+$' THEN lib := (f->>'library')::bigint; END IF;
    IF (f->>'folder') ~ '^[0-9]+$' THEN fld := (f->>'folder')::bigint; SELECT path INTO fpath FROM folders WHERE id = fld; END IF;
    IF (f->>'owner') ~ '^[0-9]+$' THEN own := (f->>'owner')::bigint; END IF;
    IF COALESCE((f->>'mine')::boolean, false) THEN own := app_current_member_id(); END IF;
    IF f->>'tag' IS NOT NULL THEN tg := (SELECT slug FROM tags WHERE id = dc_tag_resolve(f->>'tag')); IF tg IS NULL THEN tg := '-'; END IF; END IF;
    kd := f->>'kind';
    RETURN QUERY
    WITH c AS (
        SELECT COALESCE((x->>'document_id')::bigint, g.document_id) AS did,
               COALESCE((x->>'similarity')::numeric, 0.5) AS sim, x->>'subject' AS subj
          FROM jsonb_array_elements(COALESCE(p_candidates, '[]'::jsonb)) x
          LEFT JOIN graph_documents g ON g.maludb_document_id = x->>'maludb_document_id'),
         agg AS (
        SELECT did, sum(sim) AS score, array_remove(array_agg(DISTINCT subj), NULL) AS subjects, count(*)::integer AS hits
          FROM c WHERE did IS NOT NULL GROUP BY did)
    SELECT d.id, d.name, d.folder_id, d.library_id, d.owner_member_id, d.mime_kind, a.score, a.subjects, a.hits
      FROM agg a JOIN documents d ON d.id = a.did
     WHERE d.trashed_at IS NULL
       AND (lib IS NULL OR d.library_id = lib)
       AND (fpath IS NULL OR d.folder_id IN (SELECT fo.id FROM folders fo WHERE fo.path LIKE fpath || '%'))
       AND (own IS NULL OR d.owner_member_id = own)
       AND (kd IS NULL OR d.mime_kind = kd)
       AND (tg IS NULL OR tg = ANY (dc_document_tag_slugs(d.id)))
       AND dc_can_see_document(d.id)
     ORDER BY a.score DESC, d.updated_at DESC
     LIMIT greatest(1, least(p_limit, 200));
END$$;

CREATE OR REPLACE FUNCTION dc_graph_job_skip(p_job_id bigint, p_reason text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE j graph_jobs%ROWTYPE;
BEGIN
    UPDATE graph_jobs SET status = 'skipped', finished_at = now(), error = left(p_reason, 500) WHERE id = p_job_id RETURNING * INTO j;
    -- the engine kept nothing: the document is "current" at this version so the catch-up stops offering it
    IF j.document_id IS NOT NULL AND j.kind IN ('index', 'full') THEN
        INSERT INTO graph_documents (document_id, version_id, maludb_document_id, policy, subjects, chars)
        VALUES (j.document_id, j.version_id, 'none:' || j.id, j.kind, '{}', 0)
        ON CONFLICT (document_id) DO UPDATE SET version_id = EXCLUDED.version_id, maludb_document_id = EXCLUDED.maludb_document_id, policy = EXCLUDED.policy, subjects = '{}', chars = 0, indexed_at = now();
    END IF;
END$$;

CREATE OR REPLACE FUNCTION dc_graph_subjects(p_document_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH d AS (SELECT * FROM documents WHERE id = p_document_id),
         s AS (
            SELECT 'doc:' || d.id AS subj, 0 AS prio, 0 AS ord FROM d
            UNION ALL SELECT 'tag:' || t.slug, 1 + CASE WHEN t.kind = 'managed' THEN 0 ELSE 1 END, -t.usage_count FROM document_tags dt JOIN tags t ON t.id = dt.tag_id, d WHERE dt.document_id = d.id
            UNION ALL SELECT 'folder:' || f.name, 3, 0 FROM folders f, d WHERE f.id = d.folder_id AND f.parent_id IS NOT NULL
            UNION ALL SELECT 'library:' || l.name, 4, 0 FROM libraries l, d WHERE l.id = d.library_id
            UNION ALL SELECT 'owner:' || m.display_name, 5, 0 FROM members m, d WHERE m.id = d.owner_member_id
            UNION ALL SELECT 'kind:' || d.mime_kind, 6, 0 FROM d
            UNION ALL SELECT 'year:' || to_char(d.created_at, 'YYYY'), 7, 0 FROM d)
    SELECT COALESCE((SELECT array_agg(subj ORDER BY prio, ord, subj) FROM (SELECT subj, prio, ord FROM s ORDER BY prio, ord, subj LIMIT 8) x), '{}');
$$;

CREATE OR REPLACE FUNCTION dc_pass_graph_catch_up(p_limit integer DEFAULT 200) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN SELECT d.id FROM documents d JOIN document_text t ON t.version_id = d.current_version_id AND t.char_count > 0
              LEFT JOIN graph_documents g ON g.document_id = d.id
             WHERE d.trashed_at IS NULL AND (g.document_id IS NULL OR g.version_id IS DISTINCT FROM d.current_version_id)
               AND NOT EXISTS (SELECT 1 FROM graph_jobs j WHERE j.document_id = d.id AND j.status IN ('queued', 'running'))
               AND NOT EXISTS (SELECT 1 FROM graph_jobs j WHERE j.document_id = d.id AND j.status = 'failed' AND j.finished_at > now() - interval '1 day')
             LIMIT p_limit LOOP
        IF dc_graph_queue_document(r.id) IN ('index', 'full') THEN n := n + 1; END IF;
    END LOOP;
    RETURN n;
END$$;

GRANT SELECT ON mcp_notification_prefs, mcp_documents, mcp_folders TO doccloud_records_ro, doccloud_rw;
GRANT SELECT ON mcp_documents, mcp_folders TO doccloud_activity_ro;
GRANT EXECUTE ON FUNCTION dc_folder_path_text(bigint), dc_document_page(bigint, integer), dc_document_excerpt(bigint, text, integer), dc_links_suspicious(integer, integer),
    dc_application_folders(text), dc_application_documents(text, bigint, timestamptz, integer, integer), dc_graph_filter(jsonb, integer, jsonb), dc_graph_subjects(bigint)
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_graph_job_skip(bigint, text), dc_pass_graph_catch_up(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_graph_job_skip(bigint, text), dc_pass_graph_catch_up(integer) TO doccloud_rw;

COMMIT;

-- ---------------------------------------------------------------------------------------------
-- Slice 8 (docs/build-specs/agents-clerk-notifications.md): the Clerk's settings and its one read — a REPORT of names, counts
-- and ids across the store, never content, as a SECURITY DEFINER function the Clerk's member id alone may call (the decision
-- taken in the specs, to confirm with the owner: the Clerk is not made a member of every library).
-- ---------------------------------------------------------------------------------------------
BEGIN;
ALTER TABLE dc_settings
    ADD COLUMN clerk_member_id         bigint,
    ADD COLUMN untagged_batch_size     integer NOT NULL DEFAULT 40 CHECK (untagged_batch_size BETWEEN 1 AND 1000),
    ADD COLUMN duplicate_copies        integer NOT NULL DEFAULT 2 CHECK (duplicate_copies BETWEEN 2 AND 100),
    ADD COLUMN dispatch_retry_minutes  integer NOT NULL DEFAULT 30 CHECK (dispatch_retry_minutes BETWEEN 1 AND 1440),
    ADD COLUMN clerk_report_enabled    boolean NOT NULL DEFAULT true;

CREATE OR REPLACE FUNCTION dc_is_clerk() RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT app_is_active_member() AND app_member_kind() = 'agent' AND app_current_member_id() = (SELECT clerk_member_id FROM dc_settings WHERE id = 1);
$$;

-- The Monday report's data, one row per finding kind: counts and the ids the Clerk's proposals name. The admin may read it too.
CREATE OR REPLACE FUNCTION dc_clerk_report(p_days integer DEFAULT 7)
    RETURNS TABLE (kind text, count bigint, ids bigint[], detail jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE batch integer := dc_setting_int('untagged_batch_size')::integer; stale integer := dc_setting_int('stale_guest_share_days')::integer;
BEGIN
    IF NOT (dc_is_clerk() OR dc_is_admin()) OR NOT dc_setting_bool('clerk_report_enabled') THEN RETURN; END IF;
    RETURN QUERY SELECT 'untagged', count(*), (array_agg(d.id ORDER BY d.created_at DESC))[1:batch], jsonb_build_object('by_folder', (SELECT jsonb_object_agg(f.id, c) FROM (SELECT x.folder_id, count(*) c FROM documents x WHERE x.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = x.id) GROUP BY x.folder_id ORDER BY c DESC LIMIT 20) q JOIN folders f ON f.id = q.folder_id))
      FROM documents d WHERE d.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = d.id);
    RETURN QUERY SELECT 'duplicate', count(*), array_agg((g.ids)[1]), jsonb_agg(jsonb_build_object('sha256', g.sha256, 'copies', g.copies, 'ids', g.ids)) FROM (
        SELECT d.sha256, count(*) AS copies, array_agg(d.id ORDER BY d.id) AS ids FROM documents d WHERE d.trashed_at IS NULL AND d.sha256 IS NOT NULL GROUP BY d.sha256 HAVING count(*) >= dc_setting_int('duplicate_copies')) g;
    RETURN QUERY SELECT 'stale_share', count(*), array_agg(COALESCE(fa.folder_id, 0)), jsonb_agg(jsonb_build_object('folder_id', fa.folder_id, 'guest', fa.principal_id, 'since', fa.created_at, 'by', fa.granted_by))
      FROM folder_access fa WHERE fa.principal_kind IN ('member', 'guest') AND dc_member_is_guest(fa.principal_id) AND fa.created_at < now() - make_interval(days => stale);
    RETURN QUERY SELECT 'link_expiring', count(*), array_agg(l.id), jsonb_agg(jsonb_build_object('link_id', l.id, 'label', l.label, 'expires_at', l.expires_at, 'made_by', l.made_by))
      FROM share_links l WHERE l.rotated_at IS NULL AND l.revoked_at IS NULL AND l.expires_at BETWEEN now() AND now() + make_interval(days => p_days);
    RETURN QUERY SELECT 'link_suspicious', count(*), array_agg(s.link_id), jsonb_agg(jsonb_build_object('link_id', s.link_id, 'label', s.label, 'addresses', s.addresses, 'opens', s.opens))
      FROM (SELECT l.id AS link_id, l.label, count(DISTINCT o.address_hash) AS addresses, count(*) AS opens FROM share_links l JOIN link_opens o ON o.link_id = l.id AND o.opened_at > now() - make_interval(days => p_days)
             WHERE l.rotated_at IS NULL AND l.revoked_at IS NULL GROUP BY l.id, l.label HAVING count(DISTINCT o.address_hash) > 10) s;
    RETURN QUERY SELECT 'request_open', count(*), array_agg(l.id), jsonb_agg(jsonb_build_object('link_id', l.id, 'label', l.label, 'uploads', l.upload_count, 'made_by', l.made_by))
      FROM share_links l WHERE l.kind = 'request' AND l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now());
    RETURN QUERY SELECT 'trash_due', count(*), array_agg(d.id), jsonb_agg(jsonb_build_object('document_id', d.id, 'owner', d.owner_member_id, 'purge_at', d.trashed_at + make_interval(days => dc_setting_int('trash_days')::integer)))
      FROM documents d WHERE d.trashed_at IS NOT NULL AND d.trashed_at + make_interval(days => dc_setting_int('trash_days')::integer) < now() + make_interval(days => p_days);
    RETURN QUERY SELECT 'locked', count(*), array_agg(d.id), jsonb_agg(jsonb_build_object('document_id', d.id, 'locked_by', d.locked_by, 'since', d.locked_at))
      FROM documents d WHERE d.locked_by IS NOT NULL AND d.locked_at < now() - interval '1 day';
    RETURN QUERY SELECT 'no_text', count(*), array_agg(d.id), jsonb_build_object('by_status', (SELECT jsonb_object_agg(s, c) FROM (SELECT v.extracted_status s, count(*) c FROM documents x JOIN document_versions v ON v.id = x.current_version_id WHERE x.trashed_at IS NULL AND v.extracted_status IN ('no_text', 'unsupported', 'failed') GROUP BY v.extracted_status) q))
      FROM documents d JOIN document_versions v ON v.id = d.current_version_id WHERE d.trashed_at IS NULL AND v.extracted_status IN ('no_text', 'unsupported', 'failed');
    RETURN QUERY SELECT 'quota', count(*), array_agg(l.id), jsonb_agg(jsonb_build_object('library_id', l.id, 'name', l.name, 'used', l.used_bytes, 'quota', l.quota_bytes))
      FROM libraries l WHERE l.quota_bytes IS NOT NULL AND l.used_bytes >= 0.9 * l.quota_bytes AND l.archived_at IS NULL;
    RETURN QUERY SELECT 'budget', CASE WHEN b.pct >= 80 THEN 1 ELSE 0 END::bigint, '{}'::bigint[], to_jsonb(b) FROM dc_graph_budget_state() b;
    RETURN QUERY SELECT 'orphaned', count(*), array_agg(d.id), jsonb_build_object('owners', (SELECT jsonb_object_agg(m.id, c) FROM (SELECT x.owner_member_id o, count(*) c FROM documents x JOIN members mm ON mm.id = x.owner_member_id AND mm.status = 'inactive' WHERE x.trashed_at IS NULL GROUP BY x.owner_member_id) q JOIN members m ON m.id = q.o))
      FROM documents d JOIN members m ON m.id = d.owner_member_id AND m.status = 'inactive' WHERE d.trashed_at IS NULL;
END$$;

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.max_document_bytes, s.max_text_bytes, s.versions_keep_days, s.versions_keep_count, s.trash_days, s.lock_hours, s.link_default_days,
       s.link_no_expiry_allowed, s.upload_session_hours, s.upload_part_bytes, s.graph_monthly_text_mb, s.graph_default_policy, s.graph_text_chars, s.graph_full_chunk_chars,
       s.key_rate_per_minute, s.key_rate_per_day, s.key_rotation_overlap_hours, s.link_opens_per_minute, s.request_uploads_per_hour, s.free_tag_proposal_threshold,
       s.stale_guest_share_days, CASE WHEN (SELECT dc_is_admin()) THEN s.model_alias END AS model_alias, CASE WHEN (SELECT dc_is_admin()) THEN s.embedding_model END AS embedding_model,
       s.timezone, s.updated_at,
       s.clerk_member_id, s.untagged_batch_size, s.duplicate_copies, s.dispatch_retry_minutes, s.clerk_report_enabled
  FROM dc_settings s WHERE (SELECT app_is_active_member());

GRANT SELECT ON mcp_settings TO doccloud_records_ro, doccloud_rw;
CREATE OR REPLACE FUNCTION dc_is_clerk_or_admin() RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_is_admin() OR dc_is_clerk(); $$;

GRANT EXECUTE ON FUNCTION dc_is_clerk(), dc_clerk_report(integer) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
COMMIT;

-- ---------------------------------------------------------------------------------------------
-- THE WALL FOR A BATCH (the timing set's lesson: a query matching 20,000 of 200,000 documents cost 21 s when the wall was asked
-- row by row — dc_document_rank() is a dozen small queries). dc_visible_among(ids) answers the visible subset of a candidate
-- SET in one statement: the caller's facts once (admin, principals), the libraries' base levels once per library, the access
-- roots' rows once per root, then the same rules as dc_document_rank() applied set-wise; a document with rows of its own (rare)
-- still asks dc_document_rows_rank(). dc_search() and dc_search_names() cut their candidates by the index FIRST (the top 2,000 by
-- rank), then wall the batch, then page — `total` is the visible count among those candidates and `capped` says when the cut hit.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_visible_among(p_ids bigint[]) RETURNS TABLE (document_id bigint, rank smallint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); adm boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() OR p_ids IS NULL THEN RETURN; END IF;
    adm := dc_is_admin();
    RETURN QUERY
    WITH d AS (SELECT x.id, x.folder_id, x.library_id, x.owner_member_id, x.own_access, x.trashed_at FROM documents x WHERE x.id = ANY (p_ids)),
         f AS (SELECT fo.id, fo.access_root_id, fo.trashed_at, fo.owner_member_id, fo.library_id FROM folders fo WHERE fo.id IN (SELECT folder_id FROM d)),
         l AS (SELECT li.id, li.kind, li.group_id, dc_library_base_level(li.id, me) AS base FROM libraries li WHERE li.id IN (SELECT library_id FROM d)),
         p AS (SELECT * FROM dc_my_principals()),
         rr AS (SELECT fa.folder_id AS root, max(dc_level_rank(fa.level)) AS r
                  FROM folder_access fa
                 WHERE fa.folder_id IN (SELECT access_root_id FROM f WHERE access_root_id IS NOT NULL)
                   AND ((fa.principal_kind = 'everyone' AND EXISTS (SELECT 1 FROM p WHERE p.kind = 'everyone'))
                        OR (fa.principal_kind NOT IN ('everyone', 'application') AND EXISTS (SELECT 1 FROM p WHERE p.kind = fa.principal_kind AND p.id = fa.principal_id)))
                 GROUP BY fa.folder_id),
         bk AS (SELECT fa.folder_id AS root FROM folder_access fa JOIN f ON f.access_root_id = fa.folder_id JOIN l ON l.id = f.library_id
                 WHERE (l.kind = 'group' AND fa.principal_kind = 'group' AND fa.principal_id = l.group_id) OR (l.kind = 'shared' AND fa.principal_kind = 'everyone')
                 GROUP BY fa.folder_id),
         fr AS (SELECT f.id AS folder_id,
                       CASE WHEN adm THEN 4
                            WHEN f.owner_member_id = me AND f.trashed_at IS NULL THEN 4
                            WHEN f.access_root_id IS NULL THEN CASE WHEN f.trashed_at IS NOT NULL AND l.base < 4 THEN 0 ELSE l.base END
                            WHEN l.kind = 'personal' OR l.base = 4 THEN GREATEST(l.base, COALESCE(rr.r, 0))
                            WHEN rr.r IS NULL THEN CASE WHEN bk.root IS NOT NULL AND f.trashed_at IS NULL THEN l.base ELSE 0 END
                            ELSE CASE WHEN f.trashed_at IS NOT NULL AND rr.r < 4 THEN 0 ELSE rr.r END END::smallint AS r
                  FROM f JOIN l ON l.id = f.library_id LEFT JOIN rr ON rr.root = f.access_root_id LEFT JOIN bk ON bk.root = f.access_root_id)
    SELECT d.id,
           (CASE WHEN adm OR d.owner_member_id = me THEN 4
                 WHEN d.own_access THEN GREATEST(dc_document_rows_rank(d.id), CASE WHEN fr.r = 4 THEN 4 ELSE 0 END)
                 WHEN fr.r = 2 THEN 0 ELSE fr.r END)::smallint AS rk
      FROM d JOIN fr ON fr.folder_id = d.folder_id
     WHERE (d.trashed_at IS NULL OR adm OR d.owner_member_id = me)
       AND (CASE WHEN adm OR d.owner_member_id = me THEN 4
                 WHEN d.own_access THEN GREATEST(dc_document_rows_rank(d.id), CASE WHEN fr.r = 4 THEN 4 ELSE 0 END)
                 WHEN fr.r = 2 THEN 0 ELSE fr.r END) > 0;
END$$;

CREATE OR REPLACE FUNCTION dc_search(p_q text, p_filters jsonb DEFAULT '{}'::jsonb, p_limit integer DEFAULT 25, p_offset integer DEFAULT 0)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, library_id bigint, owner_member_id bigint, mime_kind text, byte_size bigint,
                   tags text[], excerpt text, occurred_at timestamptz, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery; f jsonb := COALESCE(p_filters, '{}'::jsonb); me bigint := app_current_member_id();
        lib bigint; fld bigint; fpath text; own bigint; tg text; kd text; minb bigint; maxb bigint; before_d timestamptz; after_d timestamptz;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF p_q IS NULL OR btrim(p_q) = '' THEN q := NULL; ELSE q := websearch_to_tsquery('simple', unaccent(p_q)); END IF;
    IF (f->>'library') ~ '^[0-9]+$' THEN lib := (f->>'library')::bigint; END IF;
    IF (f->>'folder') ~ '^[0-9]+$' THEN fld := (f->>'folder')::bigint; SELECT path INTO fpath FROM folders WHERE id = fld; END IF;
    IF (f->>'owner') ~ '^[0-9]+$' THEN own := (f->>'owner')::bigint; END IF;
    IF COALESCE((f->>'mine')::boolean, false) THEN own := me; END IF;
    IF f->>'tag' IS NOT NULL THEN tg := (SELECT slug FROM tags WHERE id = dc_tag_resolve(f->>'tag')); IF tg IS NULL THEN tg := '-'; END IF; END IF;
    kd := f->>'kind';
    IF (f->>'min_bytes') ~ '^[0-9]+$' THEN minb := (f->>'min_bytes')::bigint; END IF;
    IF (f->>'max_bytes') ~ '^[0-9]+$' THEN maxb := (f->>'max_bytes')::bigint; END IF;
    IF f->>'before' IS NOT NULL THEN before_d := (f->>'before')::date::timestamptz; END IF;
    IF f->>'after' IS NOT NULL THEN after_d := ((f->>'after')::date + 1)::timestamptz; END IF;
    RETURN QUERY
    WITH cand AS (
        SELECT si.*, CASE WHEN q IS NULL THEN 0::real ELSE ts_rank(si.tsv, q) END AS r
          FROM search_index si
         WHERE si.entity_kind = 'document'
           AND (q IS NULL OR si.tsv @@ q)
           AND (lib IS NULL OR si.library_id = lib)
           AND (fld IS NULL OR si.folder_id IN (SELECT fo.id FROM folders fo WHERE fo.path LIKE fpath || '%'))
           AND (own IS NULL OR si.owner_member_id = own)
           AND (tg IS NULL OR si.tags @> ARRAY[tg])
           AND (kd IS NULL OR si.mime_kind = kd)
           AND (minb IS NULL OR si.byte_size >= minb)
           AND (maxb IS NULL OR si.byte_size <= maxb)
           AND (before_d IS NULL OR si.occurred_at < before_d)
           AND (after_d IS NULL OR si.occurred_at >= after_d)
           AND (NOT COALESCE((f->>'shared_with_me')::boolean, false) OR si.owner_member_id <> me)
         ORDER BY CASE WHEN q IS NULL THEN 0::real ELSE ts_rank(si.tsv, q) END DESC, si.occurred_at DESC
         LIMIT 2000),                                                                      -- the index's cut, then the wall on the batch
         vis AS (SELECT v.document_id FROM dc_visible_among((SELECT array_agg(c.document_id) FROM cand c)) v),
         hits AS (SELECT c.* FROM cand c JOIN vis v ON v.document_id = c.document_id)
    SELECT h.document_id, h.title, h.folder_id, h.library_id, h.owner_member_id, h.mime_kind, h.byte_size, h.tags,
           CASE WHEN q IS NULL OR h.body IS NULL THEN left(COALESCE(h.body, ''), 200)
                ELSE ts_headline('simple', h.body, q, 'MaxFragments=2, MaxWords=24, MinWords=8, StartSel=<mark>, StopSel=</mark>') END,
           h.occurred_at, h.r, count(*) OVER () AS total
      FROM hits h
     ORDER BY h.r DESC, h.occurred_at DESC
     LIMIT greatest(1, least(p_limit, 100)) OFFSET greatest(0, p_offset);
END$$;

CREATE OR REPLACE FUNCTION dc_search_names(p_q text, p_limit integer DEFAULT 10)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, similarity real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH c AS (SELECT d.id, d.name, d.folder_id, similarity(d.name, p_q) AS sim
                 FROM documents d WHERE d.trashed_at IS NULL AND d.name % p_q
                ORDER BY similarity(d.name, p_q) DESC, d.updated_at DESC LIMIT 200)
    SELECT c.id, c.name, c.folder_id, c.sim FROM c JOIN dc_visible_among((SELECT array_agg(id) FROM c)) v ON v.document_id = c.id
     ORDER BY c.sim DESC LIMIT greatest(1, least(p_limit, 50));
$$;

GRANT EXECUTE ON FUNCTION dc_visible_among(bigint[]) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

-- A misspelt name over 200,000 documents: the GIN trigram index answers "similar enough" but not "the 200 most similar"; a GiST
-- trigram index answers the nearest by distance (`<->`), so the candidates are an index-ordered scan (the timing set: 2 s → ms).
CREATE INDEX IF NOT EXISTS documents_name_trgm_gist_idx ON documents USING gist (name gist_trgm_ops);
CREATE OR REPLACE FUNCTION dc_search_names(p_q text, p_limit integer DEFAULT 10)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, similarity real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH c AS (SELECT d.id, d.name, d.folder_id, similarity(d.name, p_q) AS sim
                 FROM documents d WHERE d.trashed_at IS NULL
                ORDER BY d.name <-> p_q LIMIT 200)
    SELECT c.id, c.name, c.folder_id, c.sim FROM c JOIN dc_visible_among((SELECT array_agg(id) FROM c)) v ON v.document_id = c.id
     WHERE c.sim >= 0.2
     ORDER BY c.sim DESC LIMIT greatest(1, least(p_limit, 50));
$$;
