-- 017: what Phase 2's shell reads that the views lacked (docs/build-specs/sso-shell.md) — additive: a view re-created with columns
-- appended LAST.
--   mcp_activity_log gains actor_name and actor_is_agent (the trail shows who, in words; the activity server answers the same).
BEGIN;

CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, a.source, a.action, a.screen, a.route, a.entity_type, a.entity_id, a.before, a.after, a.request_id,
       a.agent_run_id, a.department_id, a.library_id, a.document_id, a.folder_id, a.token_id,
       (SELECT m.display_name FROM members m WHERE m.id = a.actor_member_id) AS actor_name,
       COALESCE((SELECT m.is_agent FROM members m WHERE m.id = a.actor_member_id), false) AS actor_is_agent
  FROM activity_log a
 WHERE (SELECT dc_is_admin())
    OR a.actor_member_id = app_current_member_id()
    OR (a.document_id IS NOT NULL AND dc_can_see_document(a.document_id))
    OR (a.document_id IS NULL AND a.folder_id IS NOT NULL AND dc_can_see_folder(a.folder_id))
    OR (a.entity_type = 'document' AND a.document_id IS NULL AND dc_can_see_document(a.entity_id))
    OR (a.entity_type = 'folder' AND a.folder_id IS NULL AND dc_can_see_folder(a.entity_id));

GRANT SELECT ON mcp_activity_log TO doccloud_activity_ro, doccloud_records_ro, doccloud_rw;

COMMIT;
