-- 015: WHO SEES WHAT — the mcp_* views, the only thing the two read roles see (memory.md §1; design §3, §6.3). Every WHERE clause
-- IS the row rule; the servers never filter in Python. The caller's cheap facts (admin, guest, me) are scalar subqueries tested
-- once per statement; the per-row wall is dc_can_see_document() / dc_can_see_folder() — one indexed EXISTS each (THE SCALE RULE:
-- no view joins a set of every visible document).
--
-- The primary key is re-aliased <entity>_id; raw columns that hide internals are not selected: a token's hash NEVER, a link's
-- hash or password NEVER, a blob's storage_path NEVER, an outbox body, a session hash. A document's TEXT is content a viewer may
-- read (mcp_document_text) — the same wall as the document.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO doccloud_records_ro, doccloud_activity_ro, doccloud_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO doccloud_records_ro, doccloud_activity_ro, doccloud_rw;

-- The people the caller may see: everyone admitted, for a member of the business; for a guest, themselves and the people who
-- shared with them or own what is shared.
CREATE OR REPLACE FUNCTION dc_visible_member_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF NOT dc_is_guest() THEN
        RETURN QUERY SELECT m.id FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT me
        UNION SELECT fa.granted_by FROM folder_access fa WHERE fa.principal_kind IN ('member', 'guest') AND fa.principal_id = me AND fa.granted_by IS NOT NULL
        UNION SELECT da.granted_by FROM document_access da WHERE da.principal_kind IN ('member', 'guest') AND da.principal_id = me AND da.granted_by IS NOT NULL
        UNION SELECT d.owner_member_id FROM document_access da JOIN documents d ON d.id = da.document_id WHERE da.principal_kind IN ('member', 'guest') AND da.principal_id = me;
END$$;
GRANT EXECUTE ON FUNCTION dc_visible_member_ids() TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

-- ---------------------------------------------------------------------------------------------
-- People, departments, settings.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, dc_member_is_guest(m.id) AS is_guest,
       m.status, m.capability, m.roles, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids,
       (SELECT array_agg(g) FROM dc_member_group_ids(m.id) g) AS group_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND m.id IN (SELECT dc_visible_member_ids());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT dc_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.max_document_bytes, s.max_text_bytes, s.versions_keep_days, s.versions_keep_count, s.trash_days, s.lock_hours, s.link_default_days,
       s.link_no_expiry_allowed, s.upload_session_hours, s.upload_part_bytes, s.graph_monthly_text_mb, s.graph_default_policy, s.graph_text_chars, s.graph_full_chunk_chars,
       s.key_rate_per_minute, s.key_rate_per_day, s.key_rotation_overlap_hours, s.link_opens_per_minute, s.request_uploads_per_hour, s.free_tag_proposal_threshold,
       s.stale_guest_share_days, CASE WHEN (SELECT dc_is_admin()) THEN s.model_alias END AS model_alias, CASE WHEN (SELECT dc_is_admin()) THEN s.embedding_model END AS embedding_model,
       s.timezone, s.updated_at
  FROM dc_settings s WHERE (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- Libraries, groups, folders.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_libraries WITH (security_barrier = true) AS
SELECT l.id AS library_id, l.kind, l.name, l.description, l.owner_member_id, l.group_id, l.everyone_level, l.graph_policy, l.quota_bytes, l.used_bytes, l.root_folder_id,
       l.archived_at, l.created_by, l.created_at, l.updated_at,
       dc_rank_level(GREATEST(dc_library_base_level(l.id, app_current_member_id()), CASE WHEN (SELECT dc_is_admin()) THEN 4 ELSE 0 END)::smallint) AS my_level
  FROM libraries l
 WHERE (SELECT dc_is_admin()) OR dc_library_base_level(l.id, app_current_member_id()) > 0
    OR EXISTS (SELECT 1 FROM folders f WHERE f.library_id = l.id AND f.access_root_id IS NOT NULL AND dc_folder_rows_rank(f.access_root_id) > 0 LIMIT 1);

CREATE OR REPLACE VIEW mcp_groups WITH (security_barrier = true) AS
SELECT g.id AS group_id, g.key, g.name, g.description, g.kind, g.department_id, g.member_level, g.created_by, g.archived_at, g.created_at, g.updated_at,
       (SELECT count(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.left_at IS NULL) AS member_count,
       EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = g.id AND gm.member_id = app_current_member_id() AND gm.left_at IS NULL) AS i_am_member,
       dc_is_group_owner(g.id, app_current_member_id()) AS i_am_owner
  FROM groups g WHERE (SELECT dc_is_member_here());

CREATE OR REPLACE VIEW mcp_group_members WITH (security_barrier = true) AS
SELECT gm.group_id, gm.member_id, gm.role, gm.added_by, gm.joined_at, gm.left_at, m.display_name
  FROM group_members gm JOIN members m ON m.id = gm.member_id WHERE (SELECT dc_is_member_here());

CREATE OR REPLACE VIEW mcp_folders WITH (security_barrier = true) AS
SELECT f.id AS folder_id, f.library_id, f.parent_id, f.name, f.path, f.depth, f.access_root_id, f.owner_member_id, f.auto_tags, f.graph_policy, f.colour,
       f.trashed_at, f.trashed_by, f.created_by, f.created_at, f.updated_at, dc_folder_level(f.id) AS my_level
  FROM folders f WHERE dc_can_see_folder(f.id);

-- ---------------------------------------------------------------------------------------------
-- Documents.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_documents WITH (security_barrier = true) AS
SELECT d.id AS document_id, d.library_id, d.folder_id, d.name, d.description, d.owner_member_id, d.current_version_id, d.own_access, d.mime_type, d.mime_kind,
       d.byte_size, d.sha256, d.version_count, d.locked_by, d.locked_at, d.lock_expires_at, d.trashed_at, d.trashed_by, d.trashed_from_folder_id, d.created_by,
       d.created_at, d.updated_at, d.last_opened_at, dc_document_tag_slugs(d.id) AS tags, dc_document_level(d.id) AS my_level,
       EXISTS (SELECT 1 FROM graph_documents g WHERE g.document_id = d.id) AS in_graph
  FROM documents d WHERE dc_can_see_document(d.id);

CREATE OR REPLACE VIEW mcp_document_versions WITH (security_barrier = true) AS
SELECT v.id AS version_id, v.document_id, v.version_no, v.filename, v.mime_type, v.byte_size, v.sha256, v.uploaded_by, v.uploaded_at, v.source, v.note, v.extracted_status, v.purged_at,
       v.id = (SELECT current_version_id FROM documents WHERE id = v.document_id) AS is_current
  FROM document_versions v WHERE dc_can_see_document(v.document_id);

CREATE OR REPLACE VIEW mcp_document_text WITH (security_barrier = true) AS
SELECT t.version_id, t.document_id, t.text, t.pages, t.extractor, t.char_count, t.truncated, t.extracted_at, t.error
  FROM document_text t WHERE dc_can_see_document(t.document_id);

CREATE OR REPLACE VIEW mcp_upload_sessions WITH (security_barrier = true) AS
SELECT u.id AS upload_session_id, u.member_id, u.folder_id, u.document_id, u.filename, u.mime_hint, u.byte_size, u.part_bytes, u.parts_total, u.parts_done, u.status, u.version_id,
       u.error, u.created_at, u.updated_at, u.expires_at
  FROM upload_sessions u WHERE u.member_id = app_current_member_id() OR (SELECT dc_is_admin());

-- ---------------------------------------------------------------------------------------------
-- Tags.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_tags WITH (security_barrier = true) AS
SELECT t.id AS tag_id, t.slug, t.label, t.usage_count, t.kind, t.colour, t.created_by, t.archived_at, t.created_at,
       (SELECT array_agg(a.alias) FROM tag_aliases a WHERE a.tag_id = t.id) AS aliases
  FROM tags t WHERE (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_document_tags WITH (security_barrier = true) AS
SELECT dt.document_id, dt.tag_id, t.slug, t.label, dt.added_by, dt.source, dt.created_at
  FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dc_can_see_document(dt.document_id);

CREATE OR REPLACE VIEW mcp_tag_proposals WITH (security_barrier = true) AS
SELECT p.id AS tag_proposal_id, p.kind, p.tag_id, p.into_tag_id, p.document_id, p.proposed_by, p.note, p.status, p.decided_by, p.decided_at, p.created_at
  FROM tag_proposals p
 WHERE (SELECT dc_has_right('tags.manage')) OR (p.document_id IS NOT NULL AND dc_can_edit_document(p.document_id));

-- ---------------------------------------------------------------------------------------------
-- Access, links.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_folder_access WITH (security_barrier = true) AS
SELECT fa.id AS folder_access_id, fa.folder_id, fa.principal_kind, fa.principal_id, fa.principal_ref, dc_principal_name(fa.principal_kind, fa.principal_id, fa.principal_ref) AS principal_name,
       fa.level, fa.granted_by, fa.created_at
  FROM folder_access fa WHERE dc_can_manage_folder(fa.folder_id);

CREATE OR REPLACE VIEW mcp_document_access WITH (security_barrier = true) AS
SELECT da.id AS document_access_id, da.document_id, da.principal_kind, da.principal_id, da.principal_ref, dc_principal_name(da.principal_kind, da.principal_id, da.principal_ref) AS principal_name,
       da.level, da.granted_by, da.created_at
  FROM document_access da WHERE dc_can_manage_document(da.document_id);

CREATE OR REPLACE VIEW mcp_access_changes WITH (security_barrier = true) AS
SELECT c.id AS access_change_id, c.folder_id, c.document_id, c.principal_kind, c.principal_id, c.principal_ref, c.old_level, c.new_level, c.changed_by, c.changed_at
  FROM access_changes c
 WHERE (c.folder_id IS NOT NULL AND dc_can_manage_folder(c.folder_id)) OR (c.document_id IS NOT NULL AND dc_can_manage_document(c.document_id)) OR (SELECT dc_has_right('reports.search'));

CREATE OR REPLACE VIEW mcp_share_links WITH (security_barrier = true) AS
SELECT l.id AS link_id, l.kind, l.label, l.folder_id, l.document_id, l.allow_download, l.password_hash IS NOT NULL AS has_password, l.expires_at, l.rotated_at, l.revoked_at,
       l.revoked_by, l.last_used_at, l.view_count, l.upload_count, l.made_by, l.note, l.created_at
  FROM share_links l
 WHERE (SELECT dc_has_right('links.all')) OR l.made_by = app_current_member_id()
    OR (l.folder_id IS NOT NULL AND dc_can_manage_folder(l.folder_id)) OR (l.document_id IS NOT NULL AND dc_can_manage_document(l.document_id));

CREATE OR REPLACE VIEW mcp_link_opens WITH (security_barrier = true) AS
SELECT o.id AS link_open_id, o.link_id, o.opened_at, o.what, o.document_id, o.byte_size, left(o.address_hash, 8) AS address_key
  FROM link_opens o WHERE o.link_id IN (SELECT link_id FROM mcp_share_links);

-- ---------------------------------------------------------------------------------------------
-- A person's own things; notes; notifications; the graph; the Clerk; dispatches; exports; tokens.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_favourites WITH (security_barrier = true) AS
SELECT f.member_id, f.document_id, f.folder_id, f.created_at FROM favourites f WHERE f.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_saved_searches WITH (security_barrier = true) AS
SELECT s.id AS saved_search_id, s.owner_id, s.name, s.query, s.filters, s.kind, s.is_shared, s.created_at, s.updated_at
  FROM saved_searches s WHERE s.owner_id = app_current_member_id() OR (s.is_shared AND (SELECT dc_is_member_here()));

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, n.body, n.created_at
  FROM notes n
 WHERE (n.record_type = 'document' AND dc_can_see_document(n.record_id)) OR (n.record_type = 'folder' AND dc_can_see_folder(n.record_id));

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at, n.document_id, n.folder_id, n.actor_member_id
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_graph_documents WITH (security_barrier = true) AS
SELECT g.document_id, g.version_id, g.policy, g.subjects, g.chars, g.indexed_at FROM graph_documents g WHERE dc_can_see_document(g.document_id);

CREATE OR REPLACE VIEW mcp_graph_jobs WITH (security_barrier = true) AS
SELECT j.id AS graph_job_id, j.document_id, j.version_id, j.kind, j.chars, j.status, j.attempts, j.error, j.usage, j.created_at, j.started_at, j.finished_at
  FROM graph_jobs j WHERE (SELECT dc_has_right('graph.manage'));

CREATE OR REPLACE VIEW mcp_graph_usage WITH (security_barrier = true) AS
SELECT u.month, u.kind, u.calls, u.chars, u.documents FROM graph_usage u WHERE (SELECT dc_has_right('costs.read'));

CREATE OR REPLACE VIEW mcp_clerk_proposals WITH (security_barrier = true) AS
SELECT p.id AS clerk_proposal_id, p.kind, p.document_id, p.folder_id, p.library_id, p.link_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.created_at
  FROM clerk_proposals p
 WHERE p.for_member_id = app_current_member_id() OR p.proposed_by = app_current_member_id() OR (SELECT dc_has_right('groups.manage'));

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT a.id AS agent_dispatch_id, a.record_type, a.record_id, a.agent_member_id, a.kind, a.via, a.acting_member_id, a.run_id, a.request_id, a.status, a.reply_excerpt, a.detail,
       a.attempts, a.created_at, a.answered_at, a.document_id, a.folder_id
  FROM agent_dispatches a WHERE (SELECT dc_has_right('agents.settings')) OR a.agent_member_id = app_current_member_id() OR a.acting_member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT e.id AS export_id, e.kind, e.format, e.folder_id, e.document_ids, e.status, e.byte_size, e.item_count, e.log, e.created_by, e.created_at, e.finished_at, e.expires_at,
       e.downloaded_at, e.storage_path IS NOT NULL AS available, e.params
  FROM exports e WHERE e.created_by = app_current_member_id() OR (SELECT dc_is_admin());

CREATE OR REPLACE VIEW mcp_tokens WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.member_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at, t.rate_per_minute, t.rate_per_day, t.rotated_from,
       dc_key_calls_today(t.id) AS calls_today
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id() OR (SELECT dc_is_admin());

CREATE OR REPLACE VIEW mcp_search_log WITH (security_barrier = true) AS
SELECT s.id AS search_log_id, s.member_id, s.kind, s.query, s.filters, s.results, s.took_ms, s.source, s.created_at
  FROM search_log s WHERE s.member_id = app_current_member_id() OR (SELECT dc_has_right('reports.search'));

-- The activity trail (the activity server): rows about things the caller may see, or their own.
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, a.source, a.action, a.screen, a.route, a.entity_type, a.entity_id, a.before, a.after, a.request_id,
       a.agent_run_id, a.department_id, a.library_id, a.document_id, a.folder_id, a.token_id
  FROM activity_log a
 WHERE (SELECT dc_is_admin())
    OR a.actor_member_id = app_current_member_id()
    OR (a.document_id IS NOT NULL AND dc_can_see_document(a.document_id))
    OR (a.document_id IS NULL AND a.folder_id IS NOT NULL AND dc_can_see_folder(a.folder_id))
    OR (a.entity_type = 'document' AND a.document_id IS NULL AND dc_can_see_document(a.entity_id))
    OR (a.entity_type = 'folder' AND a.folder_id IS NULL AND dc_can_see_folder(a.entity_id));

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_libraries, mcp_groups, mcp_group_members, mcp_folders, mcp_documents, mcp_document_versions,
    mcp_document_text, mcp_upload_sessions, mcp_tags, mcp_document_tags, mcp_tag_proposals, mcp_folder_access, mcp_document_access, mcp_access_changes, mcp_share_links,
    mcp_link_opens, mcp_favourites, mcp_saved_searches, mcp_notes, mcp_notifications, mcp_graph_documents, mcp_graph_jobs, mcp_graph_usage, mcp_clerk_proposals,
    mcp_agent_dispatches, mcp_exports, mcp_tokens, mcp_search_log, mcp_app_roles TO doccloud_records_ro, doccloud_rw;
GRANT SELECT ON mcp_activity_log, mcp_members, mcp_departments, mcp_documents, mcp_folders, mcp_libraries, mcp_groups TO doccloud_activity_ro;

COMMIT;
