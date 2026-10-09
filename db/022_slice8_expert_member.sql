-- 022 (slice 8, the agents, the Filing Clerk and notifications): db/016 gave the settings the Clerk's member (clerk_member_id) but not the expert's —
-- and an agent_dispatches row names the agent it is sent to (agent_member_id NOT NULL), the action manifest's agents_settings_save takes `expert` and `clerk`, and
-- the admin's agents screen shows both. The expert is therefore NAMED, never guessed: one column appended to dc_settings, written by agents_settings_save (the
-- installer's hire step), and mcp_settings re-created with it LAST (a view only ever gains columns at the end). Additive; nothing else changes.
BEGIN;

ALTER TABLE dc_settings ADD COLUMN expert_member_id bigint;

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.max_document_bytes, s.max_text_bytes, s.versions_keep_days, s.versions_keep_count, s.trash_days, s.lock_hours, s.link_default_days,
       s.link_no_expiry_allowed, s.upload_session_hours, s.upload_part_bytes, s.graph_monthly_text_mb, s.graph_default_policy, s.graph_text_chars, s.graph_full_chunk_chars,
       s.key_rate_per_minute, s.key_rate_per_day, s.key_rotation_overlap_hours, s.link_opens_per_minute, s.request_uploads_per_hour, s.free_tag_proposal_threshold,
       s.stale_guest_share_days, CASE WHEN (SELECT dc_is_admin()) THEN s.model_alias END AS model_alias, CASE WHEN (SELECT dc_is_admin()) THEN s.embedding_model END AS embedding_model,
       s.timezone, s.updated_at,
       s.clerk_member_id, s.untagged_batch_size, s.duplicate_copies, s.dispatch_retry_minutes, s.clerk_report_enabled,
       s.expert_member_id
  FROM dc_settings s WHERE (SELECT app_is_active_member());
GRANT SELECT ON mcp_settings TO doccloud_records_ro, doccloud_rw;

-- A DEFECT in db/016 found by the slice-8 proof (tests/phase3/slice8/agents.php): with NO Clerk named, clerk_member_id is NULL, `app_current_member_id() = NULL` is NULL, so dc_is_clerk()
-- answered NULL and dc_clerk_report()'s `IF NOT (dc_is_clerk() OR dc_is_admin())` was NULL — the guard did not fire and ANY caller (a Member, even an agent) read the whole report: counts, ids,
-- names of links and libraries, who owns what. dc_is_clerk() now answers false, never NULL, and the report's guard is written so a NULL cannot slip through again.
CREATE OR REPLACE FUNCTION dc_is_clerk() RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(app_is_active_member() AND app_member_kind() = 'agent' AND app_current_member_id() = (SELECT clerk_member_id FROM dc_settings WHERE id = 1), false);
$$;
-- dc_clerk_report() itself, db/016's text with the guard NULL-safe (nothing else changed).
CREATE OR REPLACE FUNCTION dc_clerk_report(p_days integer DEFAULT 7)
    RETURNS TABLE (kind text, count bigint, ids bigint[], detail jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE batch integer := dc_setting_int('untagged_batch_size')::integer; stale integer := dc_setting_int('stale_guest_share_days')::integer;
BEGIN
    IF NOT COALESCE(dc_is_clerk() OR dc_is_admin(), false) OR NOT COALESCE(dc_setting_bool('clerk_report_enabled'), false) THEN RETURN; END IF;
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
GRANT EXECUTE ON FUNCTION dc_is_clerk(), dc_clerk_report(integer) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

COMMIT;
