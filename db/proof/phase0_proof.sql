-- Phase 0 proof of the schema (docs/doccloud-design.md §10). Everything happens inside one transaction that is ROLLED BACK:
-- fixtures written as doccloud_rw, reads as the two read roles, acting members set through app.member_id exactly as PHP and
-- the MCP servers set it. Fixtures are synthetic. Run as postgres on a SCRATCH database — never the installed one — after loading
-- every migration in order (tests/phase0/run.sh does all of it):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d dc_dev0 -f db/proof/phase0_proof.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
-- a document the easy way: a blob by hash, then dc_version_add
CREATE OR REPLACE FUNCTION pg_temp.doc(k text, p_folder bigint, p_name text, p_by bigint, p_bytes bigint DEFAULT 1000, p_mime text DEFAULT 'application/pdf', p_sha text DEFAULT NULL) RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE sha text := COALESCE(p_sha, encode(sha256((k || p_name)::bytea), 'hex')); b record; r record;
BEGIN
    SELECT * INTO b FROM dc_blob_claim(sha, p_bytes);
    SELECT * INTO r FROM dc_version_add(NULL, p_folder, p_name, b.blob_id, p_name, p_mime, p_bytes, sha, p_by);
    PERFORM pg_temp.keep(k, r.document_id);
    RETURN r.document_id;
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint),
    pg_temp.doc(text, bigint, text, bigint, bigint, text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer: every actor of §2
SET ROLE doccloud_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),     -- the DocCloud admin
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),             -- a Member, Design
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', false, 'active', 'write', '{curator,user}'),     -- a Curator, Design (admin of it)
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', false, 'active', 'write', '{}'),                 -- a Member whose roles have not arrived, Accounting
 (29, 'human', 'SMOKE Ann',     'ann@client.invalid',    'user', true,  'active', 'read',  '{guest}'),            -- a guest (external)
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user', false, 'active', 'write', '{user}'),             -- Engineering
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user', false, 'active', 'write', '{user}'),             -- Engineering
 (40, 'agent', 'SMOKE Seamus',  NULL,                    'user', false, 'active', 'write', '{user}'),             -- an agent granted Member
 (41, 'agent', 'SMOKE Watcher', NULL,                    'user', false, 'active', NULL,    '{}'),                 -- an agent not admitted
 (42, 'agent', 'SMOKE Clerk',   NULL,                    'user', false, 'active', 'write', '{user}');
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES
 (3, 'Accounting', true, 'accounting', NULL), (5, 'IT', true, 'it', 1), (7, 'Design', false, NULL, 27), (8, 'Engineering', false, NULL, 30);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES
 (1, 5, true, true), (26, 7, false, true), (27, 7, true, true), (28, 3, false, true), (30, 8, false, true), (31, 8, false, true);
UPDATE dc_settings SET business_name = 'SMOKE Business';

-- ---------------------------------------------------------------- the mirror and the acting member
SELECT pg_temp.check((SELECT is_agent FROM members WHERE id = 40) AND NOT (SELECT is_agent FROM members WHERE id = 26), 'members.is_agent follows member_kind (generated)');
SELECT pg_temp.check(dc_current_member_id() = 1 AND app_current_member_id() = 1, 'dc_current_member_id() is app_current_member_id()');

-- ---------------------------------------------------------------- roles and rights (§3, D2)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(dc_is_admin() AND dc_has_right('settings.manage') AND dc_has_right('library.admin_view') AND dc_has_right('documents.upload') AND NOT dc_is_guest(), 'the super-admin holds the admin role: every right');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_has_right('library.personal') AND dc_has_right('documents.upload') AND dc_has_right('links.make') AND dc_has_right('graph.use') AND NOT dc_has_right('groups.manage') AND NOT dc_has_right('trash.purge') AND NOT dc_is_admin(), 'a Member uploads, shares, links, searches; makes no group, purges nothing');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(dc_has_right('groups.manage') AND dc_has_right('libraries.shared') AND dc_has_right('tags.manage') AND dc_has_right('ownership.transfer') AND dc_has_right('reports.storage') AND NOT dc_has_right('settings.manage') AND NOT dc_has_right('links.all'), 'a Curator makes groups and shared libraries, manages tags, transfers ownership; none of the admin''s');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_has_right('documents.upload') AND NOT dc_has_right('groups.manage') AND NOT dc_is_guest() AND dc_is_member_here(), 'a member whose roles have not arrived is read from the capability (write = Member)');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(dc_is_guest() AND dc_has_right('documents.guest') AND NOT dc_has_right('documents.upload') AND NOT dc_is_member_here(), 'a guest (external + Guest) holds nothing by default, is not a member here');
SELECT pg_temp.as_member(40);
SELECT pg_temp.check(dc_has_right('documents.upload') AND app_member_kind() = 'agent' AND NOT dc_is_guest() AND dc_is_member_here(), 'an agent granted Member has a Member''s rights and belongs here');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check(NOT app_is_active_member() AND NOT dc_has_right('documents.guest') AND (SELECT count(*) FROM dc_my_principals()) = 0, 'an agent not yet admitted sees nothing and is no principal');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM dc_my_principals()) = 0 AND NOT dc_has_right('documents.guest'), 'no acting member: every rule denies');
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 4 AND (SELECT count(*) FROM mcp_app_roles WHERE is_admin) = 1
    AND (SELECT rights FROM mcp_app_roles WHERE role_key = 'guest') = '{documents.guest}'
    AND (SELECT capability FROM mcp_app_roles WHERE role_key = 'user') = 'write'
    AND (SELECT cardinality(rights) FROM mcp_app_roles WHERE role_key = 'admin') = (SELECT count(*) FROM dc_rights), 'app_roles answers the four roles, one admin, Guest with one right, Member keyed user/write');

-- ---------------------------------------------------------------- settings (§6.3)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT max_document_bytes = 2147483648 AND versions_keep_days = 30 AND versions_keep_count = 10 AND trash_days = 30 AND lock_hours = 24 AND link_default_days = 30
    AND NOT link_no_expiry_allowed AND graph_monthly_text_mb = 100 AND graph_default_policy = 'index' AND key_rate_per_minute = 60 AND key_rate_per_day = 5000 FROM dc_settings),
    'the settings row is seeded with the design''s defaults (2 GB, 30 days/10, trash 30, locks 24 h, links 30 days, 100 MB, index, 60/min, 5,000/day)');
RESET ROLE;
SELECT pg_temp.refused($$INSERT INTO dc_settings (id) VALUES (2)$$, 'violates check', 'the settings are one row');
SET ROLE doccloud_rw;
SELECT pg_temp.check(dc_setting_int('trash_days') = 30 AND dc_setting_bool('link_no_expiry_allowed') = false, 'dc_setting_int / dc_setting_bool read them');

-- ---------------------------------------------------------------- libraries: personal on first contact, group with the group, shared by a curator (§6.2, D6)
SELECT pg_temp.keep('lib_priya', dc_library_ensure_personal(26));
SELECT pg_temp.check(pg_temp.i('lib_priya') IS NOT NULL AND dc_library_ensure_personal(26) = pg_temp.i('lib_priya'), 'a personal library is made once per member (idempotent)');
SELECT pg_temp.check((SELECT root_folder_id IS NOT NULL FROM libraries WHERE id = pg_temp.i('lib_priya')) AND (SELECT parent_id IS NULL AND depth = 0 FROM folders WHERE id = (SELECT root_folder_id FROM libraries WHERE id = pg_temp.i('lib_priya'))), '...with its root folder (depth 0, no parent)');
SELECT pg_temp.check(dc_library_ensure_personal(29) IS NULL, 'a guest gets no personal library');
SELECT pg_temp.keep('lib_marco', dc_library_ensure_personal(27)); SELECT pg_temp.keep('lib_dana', dc_library_ensure_personal(30)); SELECT pg_temp.keep('lib_seamus', dc_library_ensure_personal(40));
-- a custom group made by Marco; a department group mirroring Engineering
SELECT pg_temp.as_member(27);
INSERT INTO groups (key, name, kind, created_by) VALUES ('board', 'SMOKE Board', 'custom', 27);
SELECT pg_temp.keep('g_board', (SELECT id FROM groups WHERE key = 'board'));
SELECT pg_temp.check((SELECT count(*) FROM libraries WHERE kind = 'group' AND group_id = pg_temp.i('g_board')) = 1, 'a group''s library is created with the group');
SELECT pg_temp.keep('lib_board', (SELECT id FROM libraries WHERE group_id = pg_temp.i('g_board')));
SELECT pg_temp.check(dc_is_group_owner(pg_temp.i('g_board'), 27) AND (SELECT count(*) FROM group_members WHERE group_id = pg_temp.i('g_board')) = 1, 'the maker of a custom group owns it');
INSERT INTO group_members (group_id, member_id, role, added_by) VALUES (pg_temp.i('g_board'), 26, 'member', 27), (pg_temp.i('g_board'), 40, 'member', 27);
INSERT INTO groups (key, name, kind, department_id, created_by) VALUES ('engineering', 'SMOKE Engineering', 'department', 8, 27);
SELECT pg_temp.keep('g_eng', (SELECT id FROM groups WHERE key = 'engineering'));
SELECT pg_temp.check((SELECT array_agg(member_id ORDER BY member_id) FROM group_members WHERE group_id = pg_temp.i('g_eng') AND left_at IS NULL) = '{30,31}'
    AND dc_is_group_owner(pg_temp.i('g_eng'), 30), 'a department group''s members follow the directory; the manager owns it');
INSERT INTO department_members (member_id, department_id) VALUES (26, 8);
UPDATE department_members SET left_at = now() WHERE member_id = 31 AND department_id = 8;
SELECT dc_groups_sync_departments();
SELECT pg_temp.check((SELECT array_agg(member_id ORDER BY member_id) FROM group_members WHERE group_id = pg_temp.i('g_eng') AND left_at IS NULL) = '{26,30}'
    AND (SELECT left_at IS NOT NULL FROM group_members WHERE group_id = pg_temp.i('g_eng') AND member_id = 31), '...a person joining the department joins the group; one leaving is dated out, never erased');
SELECT pg_temp.refused($$INSERT INTO groups (key, name, kind, department_id) VALUES ('eng2', 'Dup', 'department', 8)$$, 'duplicate key', 'one live group per department');
SELECT pg_temp.keep('lib_eng', (SELECT id FROM libraries WHERE group_id = pg_temp.i('g_eng')));
-- a shared library: everyone at view
INSERT INTO libraries (kind, name, owner_member_id, everyone_level, created_by) VALUES ('shared', 'SMOKE Policies', 27, 'view', 27);
SELECT pg_temp.keep('lib_pol', (SELECT id FROM libraries WHERE name = 'SMOKE Policies'));
SELECT pg_temp.check((SELECT root_folder_id IS NOT NULL FROM libraries WHERE id = pg_temp.i('lib_pol')), 'a shared library gets its root by trigger');
SELECT pg_temp.refused($$INSERT INTO libraries (kind, name, owner_member_id, everyone_level) VALUES ('personal', 'Dup', 26, 'none')$$, 'duplicate key', 'one personal library per member');
SELECT pg_temp.refused($$INSERT INTO libraries (kind, name, group_id, everyone_level) VALUES ('group', 'X', NULL, 'none')$$, 'violates check', 'a group library names its group');

-- ---------------------------------------------------------------- folders: the tree, paths, moves, names (§6.2)
SELECT pg_temp.keep('root_priya', (SELECT root_folder_id FROM libraries WHERE id = pg_temp.i('lib_priya')));
SELECT pg_temp.keep('root_board', (SELECT root_folder_id FROM libraries WHERE id = pg_temp.i('lib_board')));
SELECT pg_temp.keep('root_eng', (SELECT root_folder_id FROM libraries WHERE id = pg_temp.i('lib_eng')));
SELECT pg_temp.keep('root_pol', (SELECT root_folder_id FROM libraries WHERE id = pg_temp.i('lib_pol')));
SELECT pg_temp.as_member(27);
INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES (pg_temp.i('lib_board'), pg_temp.i('root_board'), 'Contracts', 27, 27);
SELECT pg_temp.keep('f_contracts', (SELECT id FROM folders WHERE name = 'Contracts'));
INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES (pg_temp.i('lib_board'), pg_temp.i('f_contracts'), '2026', 27, 27);
SELECT pg_temp.keep('f_2026', (SELECT id FROM folders WHERE name = '2026'));
INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES (pg_temp.i('lib_board'), pg_temp.i('root_board'), 'Minutes', 27, 27);
SELECT pg_temp.keep('f_minutes', (SELECT id FROM folders WHERE name = 'Minutes'));
SELECT pg_temp.check((SELECT path = '/' || pg_temp.i('root_board') || '/' || pg_temp.i('f_contracts') || '/' || pg_temp.i('f_2026') || '/' AND depth = 2 FROM folders WHERE id = pg_temp.i('f_2026')), 'a folder''s path is its ancestors'' ids and its depth their count');
SELECT pg_temp.check((SELECT array_agg(name ORDER BY depth) FROM dc_folder_path(pg_temp.i('f_2026'))) = ARRAY['SMOKE Board', 'Contracts', '2026'], 'dc_folder_path walks the crumbs');
SELECT pg_temp.refused($$INSERT INTO folders (library_id, parent_id, name) VALUES ($$ || pg_temp.i('lib_board') || $$, $$ || pg_temp.i('root_board') || $$, 'contracts')$$, 'duplicate key', 'a sibling name is unique case-insensitively');
SELECT pg_temp.refused($$INSERT INTO folders (library_id, parent_id, name) VALUES ($$ || pg_temp.i('lib_board') || $$, $$ || pg_temp.i('root_board') || $$, 'a/b')$$, 'violates check', 'a name holds no slash');
SELECT pg_temp.refused($$UPDATE folders SET parent_id = $$ || pg_temp.i('f_2026') || $$ WHERE id = $$ || pg_temp.i('f_contracts'), 'under itself', 'a folder cannot move under its own subtree');
SELECT pg_temp.refused($$UPDATE folders SET parent_id = $$ || pg_temp.i('root_priya') || $$ WHERE id = $$ || pg_temp.i('f_minutes'), 'within its library', 'a folder moves within its library only');
SELECT pg_temp.refused($$UPDATE folders SET parent_id = $$ || pg_temp.i('f_contracts') || $$ WHERE id = $$ || pg_temp.i('root_board'), 'root cannot be moved', 'a root never moves');
UPDATE folders SET parent_id = pg_temp.i('f_minutes') WHERE id = pg_temp.i('f_contracts');
SELECT pg_temp.check((SELECT path = '/' || pg_temp.i('root_board') || '/' || pg_temp.i('f_minutes') || '/' || pg_temp.i('f_contracts') || '/' || pg_temp.i('f_2026') || '/' AND depth = 3 FROM folders WHERE id = pg_temp.i('f_2026')), 'a move rewrites the subtree''s paths and depths in one statement');
UPDATE folders SET parent_id = pg_temp.i('root_board') WHERE id = pg_temp.i('f_contracts');
SELECT pg_temp.check((SELECT depth = 2 FROM folders WHERE id = pg_temp.i('f_2026')) AND (SELECT count(*) FROM dc_folder_subtree_ids(pg_temp.i('f_contracts'))) = 2, '...and back; the subtree is a range query');

-- ---------------------------------------------------------------- documents, versions, blobs (§6.1, D9, D10)
SELECT pg_temp.as_member(27);
SELECT pg_temp.doc('d_acme', pg_temp.i('f_2026'), 'acme-msa.pdf', 27, 5000);
SELECT pg_temp.check((SELECT library_id = pg_temp.i('lib_board') AND owner_member_id = 27 AND version_count = 1 AND byte_size = 5000 AND mime_kind = 'pdf' AND current_version_id IS NOT NULL FROM documents WHERE id = pg_temp.i('d_acme')), 'a document takes its folder''s library, its uploader as owner, its first version as current, its kind from the mime');
SELECT pg_temp.check((SELECT used_bytes FROM libraries WHERE id = pg_temp.i('lib_board')) = 5000, 'the library counts the bytes');
-- dedupe: the same bytes again in another library
SELECT pg_temp.doc('d_acme_copy', pg_temp.i('root_priya'), 'acme (copy).pdf', 26, 5000, 'application/pdf', (SELECT sha256 FROM documents WHERE id = pg_temp.i('d_acme')));
SELECT pg_temp.check((SELECT count(*) FROM blobs) = 1 AND (SELECT ref_count FROM blobs LIMIT 1) = 2 AND (SELECT used_bytes FROM libraries WHERE id = pg_temp.i('lib_priya')) = 5000, 'the same bytes twice: one blob, ref_count 2, counted in both libraries (honest to the person)');
SELECT pg_temp.check((SELECT storage_path FROM blobs LIMIT 1) ~ '^blobs/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$', 'a blob''s path is content-addressed');
-- versions
SELECT pg_temp.keep('b2', (SELECT blob_id FROM dc_blob_claim(encode(sha256('v2'::bytea), 'hex'), 7000)));
SELECT pg_temp.keep('v2', (SELECT version_id FROM dc_version_add(pg_temp.i('d_acme'), NULL, NULL, pg_temp.i('b2'), 'acme-msa.pdf', 'application/pdf', 7000, encode(sha256('v2'::bytea), 'hex'), 27)));
SELECT pg_temp.check((SELECT version_count = 2 AND byte_size = 7000 AND current_version_id = pg_temp.i('v2') FROM documents WHERE id = pg_temp.i('d_acme')) AND (SELECT used_bytes FROM libraries WHERE id = pg_temp.i('lib_board')) = 12000, 'a second upload is version 2, current, the bytes added');
SELECT pg_temp.keep('v3', dc_version_restore((SELECT id FROM document_versions WHERE document_id = pg_temp.i('d_acme') AND version_no = 1), 27));
SELECT pg_temp.check((SELECT version_no = 3 AND source = 'restore' AND sha256 = (SELECT sha256 FROM document_versions WHERE document_id = pg_temp.i('d_acme') AND version_no = 1) FROM document_versions WHERE id = pg_temp.i('v3')), 'a restore is a NEW version from the old blob');
SELECT pg_temp.refused($$UPDATE document_versions SET purged_at = now() WHERE id = $$ || pg_temp.i('v3'), 'never purged', 'the current version is never purged');
SELECT pg_temp.refused($$UPDATE document_versions SET version_no = 9 WHERE id = $$ || pg_temp.i('v2'), 'immutable', 'a version is immutable');
SELECT pg_temp.refused($$SELECT pg_temp.doc('d_dup', $$ || pg_temp.i('f_2026') || $$, 'ACME-MSA.pdf', 27)$$, 'duplicate key', 'a same-named live document in a folder is refused (PHP then asks: a new version, or keep both)');
SELECT pg_temp.refused($$SELECT pg_temp.doc('d_big', $$ || pg_temp.i('f_2026') || $$, 'huge.bin', 27, 3000000000)$$, 'per-file ceiling', 'over the per-file ceiling is refused');
UPDATE libraries SET quota_bytes = 20000 WHERE id = pg_temp.i('lib_board');
SELECT pg_temp.refused($$SELECT pg_temp.doc('d_quota', $$ || pg_temp.i('f_2026') || $$, 'overflow.bin', 27, 9000)$$, 'over its quota', 'a quota refuses at upload');
SELECT pg_temp.check((SELECT near FROM dc_quota_state(pg_temp.i('lib_board'))) = false AND (SELECT pct FROM dc_quota_state(pg_temp.i('lib_board'))) = 85.0, 'dc_quota_state: 17,000 of 20,000 = 85%, not yet near');
UPDATE libraries SET quota_bytes = NULL WHERE id = pg_temp.i('lib_board');
-- the windows
UPDATE document_versions SET uploaded_at = now() - interval '40 days' WHERE document_id = pg_temp.i('d_acme') AND version_no = 1;
SELECT pg_temp.check(dc_pass_versions_expire() = 0, 'versions under the count are kept past the days');
UPDATE dc_settings SET versions_keep_count = 1;
SELECT pg_temp.keep('n_exp', dc_pass_versions_expire());
SELECT pg_temp.check(pg_temp.i('n_exp') = 1 AND (SELECT purged_at IS NOT NULL FROM document_versions WHERE document_id = pg_temp.i('d_acme') AND version_no = 1)
    AND (SELECT purged_at IS NULL FROM document_versions WHERE document_id = pg_temp.i('d_acme') AND version_no = 2), 'the window purges the old version past the days and over the count, keeps the young one');
UPDATE dc_settings SET versions_keep_count = 10;
SELECT pg_temp.check((SELECT used_bytes FROM libraries WHERE id = pg_temp.i('lib_board')) = 12000 AND (SELECT ref_count FROM blobs WHERE id = (SELECT blob_id FROM document_versions WHERE id = pg_temp.i('v3'))) = 2, 'a purged version releases its bytes (17,000 → 12,000); the blob is still held by the restore and the copy');

-- ---------------------------------------------------------------- the walls (§3, D5)
SELECT pg_temp.doc('d_min', pg_temp.i('f_minutes'), 'minutes-jan.docx', 27, 800, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
SELECT pg_temp.doc('d_pol', pg_temp.i('root_pol'), 'handbook.pdf', 27, 900);
SELECT pg_temp.doc('d_priv', pg_temp.i('root_priya'), 'my-notes.md', 26, 100, 'text/markdown');
SELECT pg_temp.doc('d_eng', pg_temp.i('root_eng'), 'design.pdf', 30, 300);
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'manage' AND dc_folder_level(pg_temp.i('f_2026')) = 'manage', 'the owner manages their document; the maker their folder');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'edit' AND dc_folder_level(pg_temp.i('f_2026')) = 'edit', 'a group member edits in the group library (member_level edit)');
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_pol')) = 'view' AND dc_document_level(pg_temp.i('d_eng')) = 'edit', 'everyone views the shared library; a department-group member edits in its library');
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_priv')) = 'manage', 'one''s own personal library: manage');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'none' AND dc_document_level(pg_temp.i('d_priv')) = 'none' AND dc_document_level(pg_temp.i('d_pol')) = 'view', 'a member outside the group sees nothing of it, nothing of another''s personal library, the shared library at view');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_pol')) = 'none' AND dc_document_level(pg_temp.i('d_acme')) = 'none' AND (SELECT count(*) FROM dc_my_principals() WHERE kind IN ('everyone', 'department')) = 0, 'a guest: not even the shared library; never everyone, never a department');
SELECT pg_temp.as_member(40);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'edit', 'an agent in the group is a member like any other');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_priv')) = 'manage' AND dc_folder_level(pg_temp.i('root_priya')) = 'manage', 'the admin manages everything, personal libraries included (PHP logs it)');
-- explicit rows: Bea gets view on Contracts; it inherits to 2026 and its document
SELECT pg_temp.as_member(27);
INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES (pg_temp.i('f_contracts'), 'member', 28, 'view', 27);
SELECT pg_temp.check((SELECT count(*) FROM folder_access WHERE folder_id = pg_temp.i('f_contracts')) = 2
    AND EXISTS (SELECT 1 FROM folder_access WHERE folder_id = pg_temp.i('f_contracts') AND principal_kind = 'group' AND principal_id = pg_temp.i('g_board') AND level = 'edit'), 'sharing is additive: the first explicit row carried the group''s base along');
SELECT pg_temp.check((SELECT access_root_id FROM folders WHERE id = pg_temp.i('f_2026')) = pg_temp.i('f_contracts') AND (SELECT access_root_id FROM folders WHERE id = pg_temp.i('f_contracts')) = pg_temp.i('f_contracts')
    AND (SELECT access_root_id IS NULL FROM folders WHERE id = pg_temp.i('f_minutes')), 'the access root is the nearest ancestor-or-self with rows; a sibling keeps the library base');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'view' AND dc_folder_level(pg_temp.i('f_2026')) = 'view' AND dc_document_level(pg_temp.i('d_min')) = 'none', 'Bea views Contracts and what is under it, not Minutes');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'edit', 'the group still edits (the row raised Bea, lowered nobody)');
SELECT pg_temp.check((SELECT count(*) FROM access_changes WHERE folder_id = pg_temp.i('f_contracts')) = 2, 'every access change is in the trail');
-- restrict: only Bea and Marco on 2026
SELECT pg_temp.as_member(27);
SELECT dc_folder_restrict(pg_temp.i('f_2026'), '[{"principal_kind":"member","principal_id":28,"level":"view"},{"principal_kind":"member","principal_id":27,"level":"manage"}]'::jsonb, 27);
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'none' AND dc_folder_level(pg_temp.i('f_2026')) = 'none' AND dc_folder_level(pg_temp.i('f_contracts')) = 'edit', 'restrict: the group no longer reaches 2026 (the inherited principal is gone) but still edits Contracts');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'view', 'Bea still views 2026 by name');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_acme')) = 'manage', 'the admin is never restricted out');
-- the ownership rule: Priya's upload into the restricted folder is hers
SELECT pg_temp.as_member(27);
INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES (pg_temp.i('f_2026'), 'member', 26, 'upload', 27);
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_folder_level(pg_temp.i('f_2026')) = 'upload' AND dc_can_add_to_folder(pg_temp.i('f_2026')) AND dc_document_level(pg_temp.i('d_acme')) = 'none', 'an upload holder may add and sees nobody else''s document');
SELECT pg_temp.doc('d_priya_up', pg_temp.i('f_2026'), 'priya-draft.pdf', 26, 400);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_priya_up')) = 'manage', '...and sees — manages — their own upload there (the ownership rule)');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_priya_up')) = 'view', 'a viewer of the folder views it');
-- a document's own rows: one file shared with the guest
SELECT pg_temp.as_member(27);
INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by) VALUES (pg_temp.i('d_min'), 'guest', 29, 'view', 27);
SELECT pg_temp.check((SELECT own_access FROM documents WHERE id = pg_temp.i('d_min')), 'a document with rows of its own is marked');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min')) = 'view' AND dc_folder_level(pg_temp.i('f_minutes')) = 'none' AND (SELECT count(*) FROM dc_shared_with_me()) = 1, 'the guest sees that one document, not its folder; it is in Shared with me');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min')) = 'none', 'a document with its own rows no longer follows the folder for others (the group is not on it)');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min')) = 'manage', '...but the folder''s managers keep managing it');
SELECT pg_temp.check((SELECT string_agg(reason || ':' || level, ',' ORDER BY reason) FROM dc_access_explain(pg_temp.i('d_acme'), NULL, 28)) = 'folder:view', 'dc_access_explain: Bea sees acme because of the row on 2026');
SELECT pg_temp.check((SELECT string_agg(reason || ':' || level, ',' ORDER BY reason) FROM dc_access_explain(pg_temp.i('d_acme'), NULL, 27)) = 'folder:manage,library:manage,owner:manage', '...Marco because he owns it, owns the group and is on the restricted folder');
SELECT pg_temp.check((SELECT level FROM dc_access_history(NULL, pg_temp.i('f_2026'), now()) WHERE principal_id = 28) = 'view', 'who could see 2026 now, from the trail');
-- a sibling's reach (K7)
INSERT INTO folder_access (folder_id, principal_kind, principal_ref, level, granted_by) VALUES (pg_temp.i('f_minutes'), 'application', 'knowledge', 'view', 27);
SELECT pg_temp.check(dc_application_can_see_document(pg_temp.i('d_min'), 'knowledge') = false AND dc_application_folder_rank(pg_temp.i('f_minutes'), 'knowledge') = 1, 'an application row reaches the folder; the document with its own rows needs its own');
SELECT pg_temp.doc('d_min2', pg_temp.i('f_minutes'), 'minutes-feb.docx', 27, 700, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
SELECT pg_temp.check(dc_application_can_see_document(pg_temp.i('d_min2'), 'knowledge') AND NOT dc_application_can_see_document(pg_temp.i('d_min2'), 'helpdesk'), '...a plain document under it is shared with knowledge, not with helpdesk');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min2')) = 'edit' AND (SELECT count(*) FROM dc_my_principals() WHERE kind = 'application') = 0, 'a person never matches an application row (the group still edits)');
-- the listing
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT array_agg(name ORDER BY name) FROM dc_folder_listing(pg_temp.i('f_2026'))) = ARRAY['acme-msa.pdf', 'priya-draft.pdf'] AND (SELECT max(total) FROM dc_folder_listing(pg_temp.i('f_2026'))) = 2, 'the listing shows a viewer what they may see, with the count');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT array_agg(name) FROM dc_folder_listing(pg_temp.i('f_2026'))) = ARRAY['priya-draft.pdf'], '...and an upload holder only their own');
SELECT pg_temp.check((SELECT count(*) FROM dc_folder_listing(pg_temp.i('root_priya'))) = 2 AND (SELECT count(*) FROM dc_my_libraries()) = 4, 'Priya lists her two files and reaches four libraries: hers, the Board, Engineering (through the department) and Policies');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check((SELECT count(*) FROM dc_folder_listing(pg_temp.i('root_pol'))) = 0 AND (SELECT count(*) FROM dc_my_libraries()) = 0, 'an agent not admitted lists nothing');

-- ---------------------------------------------------------------- the batch wall agrees with the row wall, for every actor
SELECT pg_temp.as_member(1);
CREATE TEMP TABLE all_docs AS SELECT array_agg(id) AS ids FROM documents; GRANT ALL ON all_docs TO PUBLIC;
DO $$ DECLARE m bigint; bad integer := 0; BEGIN
    FOREACH m IN ARRAY ARRAY[1, 26, 27, 28, 29, 30, 31, 40, 41, 42] LOOP
        PERFORM set_config('app.member_id', m::text, true);
        SELECT count(*) INTO bad FROM (
            SELECT d.id, dc_can_see_document(d.id) AS row_wall, EXISTS (SELECT 1 FROM dc_visible_among((SELECT ids FROM all_docs)) v WHERE v.document_id = d.id) AS batch_wall FROM documents d) x
         WHERE x.row_wall <> x.batch_wall;
        IF bad > 0 THEN RAISE EXCEPTION 'dc_visible_among disagrees with dc_can_see_document for member % on % documents', m, bad; END IF;
    END LOOP;
END $$;
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(true, 'dc_visible_among() agrees with dc_can_see_document() for every document and all ten actors');

-- ---------------------------------------------------------------- the trash and the purge (D10)
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('t1', dc_document_trash(pg_temp.i('d_min2'), 27)::int);
SELECT pg_temp.check(pg_temp.i('t1') = 1 AND (SELECT trashed_from_folder_id = pg_temp.i('f_minutes') FROM documents WHERE id = pg_temp.i('d_min2')), 'a document goes to the trash remembering its folder');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min2')) = 'none' AND (SELECT count(*) FROM dc_folder_listing(pg_temp.i('f_minutes')) WHERE name = 'minutes-feb.docx') = 0, 'a trashed document is gone from the folder for a group member');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(dc_document_level(pg_temp.i('d_min2')) = 'manage' AND (SELECT count(*) FROM dc_trash()) >= 1, '...the owner still reaches it in the trash');
SELECT pg_temp.doc('d_min3', pg_temp.i('f_minutes'), 'minutes-feb.docx', 27, 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
SELECT pg_temp.keep('r1', dc_document_restore(pg_temp.i('d_min2'))::int);
SELECT pg_temp.check(pg_temp.i('r1') = 1 AND (SELECT name FROM documents WHERE id = pg_temp.i('d_min2')) = 'minutes-feb (2).docx', 'a restore into a folder holding the name again is renamed "(2)"');
SELECT dc_document_trash(pg_temp.i('d_min3'), 27);
UPDATE documents SET trashed_at = now() - interval '31 days' WHERE id = pg_temp.i('d_min3');
SELECT pg_temp.keep('blob_min3', (SELECT blob_id FROM document_versions WHERE document_id = pg_temp.i('d_min3')));
SELECT pg_temp.keep('p1', dc_pass_trash_purge());
SELECT pg_temp.check(pg_temp.i('p1') = 1 AND NOT EXISTS (SELECT 1 FROM documents WHERE id = pg_temp.i('d_min3')) AND (SELECT ref_count FROM blobs WHERE id = pg_temp.i('blob_min3')) = 0, 'the trash purges past its window; the blob is released');
UPDATE blobs SET created_at = now() - interval '2 hours' WHERE id = pg_temp.i('blob_min3');
SELECT pg_temp.keep('bf', (SELECT count(*) FROM dc_pass_blobs_free()));
SELECT pg_temp.check(pg_temp.i('bf') = 1 AND (SELECT deleted_at IS NOT NULL FROM blobs WHERE id = pg_temp.i('blob_min3')), 'a blob nobody holds is freed once (its path answered to the worker)');
SELECT pg_temp.check((SELECT count(*) FROM dc_pass_blobs_free()) = 0, '...and never twice');
-- a folder to the trash and back
SELECT dc_folder_trash(pg_temp.i('f_contracts'), 27);
SELECT pg_temp.check((SELECT trashed_at IS NOT NULL FROM folders WHERE id = pg_temp.i('f_2026')) AND (SELECT trashed_at IS NOT NULL FROM documents WHERE id = pg_temp.i('d_acme')), 'a trashed folder takes its subtree and documents');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(dc_folder_level(pg_temp.i('f_2026')) = 'none', 'a viewer loses a trashed folder');
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('fr', dc_folder_restore(pg_temp.i('f_contracts')));
SELECT pg_temp.check(pg_temp.i('fr') >= 2 AND (SELECT trashed_at IS NULL FROM documents WHERE id = pg_temp.i('d_acme')), 'the restore brings the subtree and its documents back');

-- ---------------------------------------------------------------- locks
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('lk', dc_document_lock(pg_temp.i('d_priya_up'), 26)::int);
SELECT pg_temp.check(pg_temp.i('lk') = 1 AND (SELECT lock_expires_at > now() + interval '23 hours' FROM documents WHERE id = pg_temp.i('d_priya_up')), 'a lock lasts the setting''s hours');
SELECT pg_temp.check(NOT dc_document_lock(pg_temp.i('d_priya_up'), 28) AND NOT dc_document_unlock(pg_temp.i('d_priya_up'), 28), 'another cannot take or drop it');
SELECT pg_temp.refused($$SELECT dc_version_add($$ || pg_temp.i('d_priya_up') || $$, NULL, NULL, $$ || pg_temp.i('b2') || $$, 'x', 'application/pdf', 7000, 'abc', 28)$$, 'locked by someone else', 'a locked document takes no version from another');
SELECT pg_temp.check(dc_document_unlock(pg_temp.i('d_priya_up'), 27, true), 'a manager breaks it');
SELECT dc_document_lock(pg_temp.i('d_priya_up'), 26);
UPDATE documents SET lock_expires_at = now() - interval '1 minute' WHERE id = pg_temp.i('d_priya_up');
SELECT pg_temp.check(dc_pass_locks_expire() = 1, 'the worker expires a stale lock');

-- ---------------------------------------------------------------- tags (D12)
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('t_contract', dc_tag_ensure('Contract', 27, 'managed'));
SELECT pg_temp.keep('t_acme', dc_tag_ensure('Acme Corp.', 27));
SELECT pg_temp.check((SELECT slug = 'acme-corp' AND kind = 'free' FROM tags WHERE id = pg_temp.i('t_acme')), 'a label becomes a slug; anyone''s tag is free');
SELECT pg_temp.keep('ta1', dc_tag_apply(pg_temp.i('d_acme'), pg_temp.i('t_contract'), 27)::int);
SELECT pg_temp.keep('ta2', dc_tag_apply(pg_temp.i('d_acme'), pg_temp.i('t_contract'), 27)::int);
SELECT pg_temp.check(pg_temp.i('ta1') = 1 AND pg_temp.i('ta2') = 0 AND (SELECT usage_count FROM tags WHERE id = pg_temp.i('t_contract')) = 1, 'a tag applies once and counts');
SELECT dc_tag_apply(pg_temp.i('d_acme'), pg_temp.i('t_acme'), 27); SELECT dc_tag_apply(pg_temp.i('d_min'), pg_temp.i('t_acme'), 26); SELECT dc_tag_apply(pg_temp.i('d_pol'), pg_temp.i('t_acme'), 28);
SELECT pg_temp.check((SELECT count(*) FROM tag_proposals WHERE kind = 'promote' AND tag_id = pg_temp.i('t_acme') AND status = 'proposed') = 1, 'a free tag used by three people is proposed for the vocabulary');
UPDATE folders SET auto_tags = '{contract,board}' WHERE id = pg_temp.i('f_contracts');
SELECT pg_temp.doc('d_auto', pg_temp.i('f_2026'), 'auto.pdf', 27, 50);
SELECT pg_temp.check(dc_document_tag_slugs(pg_temp.i('d_auto')) @> '{contract,board}' AND (SELECT source FROM document_tags WHERE document_id = pg_temp.i('d_auto') AND tag_id = pg_temp.i('t_contract')) = 'rule', 'a folder''s auto-tags (the nearest upward) apply on upload, source rule');
SELECT pg_temp.keep('t_acme2', dc_tag_ensure('ACME', 26));
SELECT dc_tag_apply(pg_temp.i('d_eng'), pg_temp.i('t_acme2'), 30);
SELECT pg_temp.keep('tm', dc_tag_merge(pg_temp.i('t_acme2'), pg_temp.i('t_acme'), 27));
SELECT pg_temp.check(pg_temp.i('tm') = 1 AND dc_tag_resolve('acme') = pg_temp.i('t_acme') AND (SELECT archived_at IS NOT NULL FROM tags WHERE id = pg_temp.i('t_acme2'))
    AND (SELECT usage_count FROM tags WHERE id = pg_temp.i('t_acme')) = 4, 'a merge moves the documents, keeps the old slug as an alias, archives the old tag, recounts');
SELECT pg_temp.keep('tr', dc_tag_remove(pg_temp.i('d_pol'), pg_temp.i('t_acme'))::int);
SELECT pg_temp.check(pg_temp.i('tr') = 1 AND (SELECT usage_count FROM tags WHERE id = pg_temp.i('t_acme')) = 3, 'a removal recounts');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT count(*) FROM dc_untagged(pg_temp.i('f_2026'))) = 1, 'untagged in a folder, as the caller sees it');

-- ---------------------------------------------------------------- the keyword index and the wall (D7)
SELECT pg_temp.as_member(27);
INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES
 ((SELECT current_version_id FROM documents WHERE id = pg_temp.i('d_acme')), pg_temp.i('d_acme'), 'Master services agreement between Acme Corporation and the business. Termination clause: ninety days notice. Governing law Illinois.', 130, 'pdftotext'),
 ((SELECT current_version_id FROM documents WHERE id = pg_temp.i('d_min')), pg_temp.i('d_min'), 'Board minutes, January. Acme renewal discussed; termination risk noted; budget approved.', 90, 'ooxml'),
 ((SELECT current_version_id FROM documents WHERE id = pg_temp.i('d_pol')), pg_temp.i('d_pol'), 'Employee handbook. Leave policy, expenses policy, termination of employment procedure.', 85, 'pdftotext'),
 ((SELECT current_version_id FROM documents WHERE id = pg_temp.i('d_priv')), pg_temp.i('d_priv'), 'My private notes about the acme negotiation and termination terms.', 70, 'plain');
UPDATE document_versions SET extracted_status = 'ready' WHERE id IN (SELECT version_id FROM document_text);
SELECT pg_temp.check((SELECT count(*) FROM search_index WHERE entity_kind = 'document') = (SELECT count(*) FROM documents WHERE trashed_at IS NULL), 'every live document has one index row (by trigger)');
SELECT pg_temp.check((SELECT tags @> '{contract}' AND title = 'acme-msa.pdf' FROM search_index WHERE document_id = pg_temp.i('d_acme')), 'the row carries the name and the tags');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT array_agg(name ORDER BY name) FROM dc_search('termination')) = ARRAY['acme-msa.pdf', 'handbook.pdf'], 'Bea searching "termination" finds the contract she may view and the handbook — never the minutes or Priya''s private notes');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT array_agg(name ORDER BY name) FROM dc_search('termination')) = ARRAY['handbook.pdf', 'my-notes.md'], 'Priya finds the handbook (everyone) and her notes — not acme (restricted out of 2026), not the minutes (own rows now)');
SELECT pg_temp.check((SELECT count(*) FROM dc_search('termination', '{"mine": true}')) = 1 AND (SELECT count(*) FROM dc_search('termination', '{"tag": "contract"}')) = 0 AND (SELECT count(*) FROM dc_search('', ('{"library": ' || pg_temp.i('lib_pol') || '}')::jsonb)) = 1, 'filters: mine, a tag, a library (an empty query lists)');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT excerpt LIKE '%<mark>ninety</mark>%' FROM dc_search('"ninety days"')), 'a phrase search, with the excerpt marked');
SELECT pg_temp.check((SELECT count(*) FROM dc_search_names('acme msa')) >= 1, 'a misspelt name is found by trigram');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT array_agg(name) FROM dc_search('termination')) = ARRAY['minutes-jan.docx'] AND (SELECT count(*) FROM dc_search('acme')) = 1 AND (SELECT count(*) FROM dc_search('handbook')) = 0, 'the guest''s search answers only the one document shared with them');
UPDATE documents SET updated_at = now() + interval '1 second' WHERE id = pg_temp.i('d_pol');
DELETE FROM search_index WHERE document_id = pg_temp.i('d_pol');
SELECT pg_temp.keep('cu', dc_search_catch_up());
SELECT pg_temp.check(pg_temp.i('cu') >= 1 AND (SELECT count(*) FROM search_index WHERE document_id = pg_temp.i('d_pol')) = 1, 'the catch-up re-indexes what is missing');

-- ---------------------------------------------------------------- links and file requests (D11)
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('l1', (SELECT link_id FROM dc_link_mint(NULL, pg_temp.i('d_acme'), 'view', 'for the auditor', NULL, true, NULL, 27)));
CREATE TEMP TABLE raw_links (k text, raw text); GRANT ALL ON raw_links TO PUBLIC;
INSERT INTO raw_links SELECT 'l1', raw_token FROM dc_link_mint(NULL, pg_temp.i('d_acme'), 'view', 'for the auditor', NULL, true, NULL, 27, NULL, pg_temp.i('l1'));
SELECT pg_temp.check((SELECT rotated_at IS NOT NULL FROM share_links WHERE id = pg_temp.i('l1')), 'a rotation retires the old link');
SELECT pg_temp.keep('l2', (SELECT id FROM share_links WHERE document_id = pg_temp.i('d_acme') AND rotated_at IS NULL));
SELECT pg_temp.check((SELECT expires_at BETWEEN now() + interval '29 days' AND now() + interval '31 days' FROM share_links WHERE id = pg_temp.i('l2')), 'a link expires in 30 days by default');
SELECT pg_temp.check((SELECT raw FROM raw_links WHERE k = 'l1') ~ '^[a-f0-9]{48}$' AND (SELECT count(*) FROM share_links WHERE token_hash = (SELECT raw FROM raw_links WHERE k = 'l1')) = 0, 'the raw token is 48 hex and is never stored');
SELECT pg_temp.check((SELECT document_id FROM dc_link_resolve((SELECT raw FROM raw_links WHERE k = 'l1'))) = pg_temp.i('d_acme'), 'the token opens its document');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM dc_link_resolve('000000000000000000000000000000000000000000000000')) AND NOT EXISTS (SELECT 1 FROM dc_link_resolve('nonsense')), 'a wrong token opens nothing');
SELECT dc_link_open(pg_temp.i('l2'), 'view', 'addr-a'); SELECT dc_link_open(pg_temp.i('l2'), 'download', 'addr-b', pg_temp.i('d_acme'), 7000);
SELECT pg_temp.check((SELECT view_count = 2 AND last_used_at IS NOT NULL FROM share_links WHERE id = pg_temp.i('l2')) AND (SELECT count(*) FROM link_opens WHERE link_id = pg_temp.i('l2')) = 2, 'opens are counted and logged by address hash');
SELECT pg_temp.refused($$SELECT dc_link_mint(NULL, $$ || pg_temp.i('d_acme') || $$, 'view', 'forever', 0, true, NULL, 27)$$, 'never expires is not allowed', 'a link that never expires is refused unless the admin allows it');
UPDATE share_links SET expires_at = now() - interval '1 minute' WHERE id = pg_temp.i('l2');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM dc_link_resolve((SELECT raw FROM raw_links WHERE k = 'l1'))), 'an expired link opens nothing');
UPDATE share_links SET expires_at = now() + interval '3 days' WHERE id = pg_temp.i('l2');
SELECT pg_temp.check((SELECT count(*) FROM dc_links_expiring(7)) = 1, 'links expiring this week');
-- a folder link reaches the subtree; a request link uploads only
INSERT INTO raw_links SELECT 'lf', raw_token FROM dc_link_mint(pg_temp.i('f_contracts'), NULL, 'view', 'contracts folder', 7, true, NULL, 27);
SELECT pg_temp.keep('lf', (SELECT link_id FROM dc_link_resolve((SELECT raw FROM raw_links WHERE k = 'lf'))));
SELECT pg_temp.check(dc_link_reaches_document(pg_temp.i('lf'), pg_temp.i('d_acme')) AND NOT dc_link_reaches_document(pg_temp.i('lf'), pg_temp.i('d_min')), 'a folder link reaches a document in its subtree and no other');
INSERT INTO raw_links SELECT 'lr', raw_token FROM dc_link_mint(pg_temp.i('f_minutes'), NULL, 'request', 'supplier uploads', 14, false, NULL, 27, 'Drop the invoices here');
SELECT pg_temp.keep('lr', (SELECT link_id FROM dc_link_resolve((SELECT raw FROM raw_links WHERE k = 'lr'))));
SELECT pg_temp.check(NOT dc_link_reaches_document(pg_temp.i('lr'), pg_temp.i('d_min')) AND (SELECT kind = 'request' AND note = 'Drop the invoices here' FROM share_links WHERE id = pg_temp.i('lr')), 'a request link reads nothing');
SELECT pg_temp.refused($$SELECT dc_link_mint(NULL, $$ || pg_temp.i('d_acme') || $$, 'request', 'x', 7, true, NULL, 27)$$, 'violates check', 'a request is on a folder, never a document');
SELECT pg_temp.check((SELECT bool_and(dc_link_rate_ok('addr-z', 'open_minute')) FROM generate_series(1, 60)) AND NOT dc_link_rate_ok('addr-z', 'open_minute'), 'an address opens 60 links a minute; the 61st is refused');
UPDATE share_links SET revoked_at = now(), revoked_by = 27 WHERE id = pg_temp.i('lf');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM dc_link_resolve((SELECT raw FROM raw_links WHERE k = 'lf'))), 'a revoked link opens nothing');

-- ---------------------------------------------------------------- the graph's bookkeeping (D8)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(dc_effective_graph_policy(pg_temp.i('f_2026')) = 'index', 'the default policy is the library''s: index');
SELECT pg_temp.check((SELECT count(*) FROM graph_jobs WHERE kind = 'index' AND status = 'queued') = 4, 'text extracted queues an index job per document (four texts)');
UPDATE folders SET graph_policy = 'full' WHERE id = pg_temp.i('f_contracts');
SELECT pg_temp.check(dc_effective_graph_policy(pg_temp.i('f_2026')) = 'full' AND dc_effective_graph_policy(pg_temp.i('f_minutes')) = 'index', 'a folder''s policy overrides for its subtree only');
SELECT pg_temp.keep('rq', dc_graph_requeue_folder(pg_temp.i('f_contracts')));
SELECT pg_temp.check(pg_temp.i('rq') >= 1 AND (SELECT array_agg(kind || ':' || status ORDER BY id) FROM graph_jobs WHERE document_id = pg_temp.i('d_acme')) = '{index:skipped,full:queued}', 'raising the policy requeues as full (the index job skipped)');
SELECT pg_temp.check((SELECT documents = 1 AND calls = 1 AND NOT over_budget FROM dc_graph_estimate(pg_temp.i('f_contracts'))), 'the estimate counts documents, characters and calls against the budget');
SELECT pg_temp.keep('j1', (SELECT id FROM dc_graph_next_job()));
SELECT pg_temp.check((SELECT status = 'running' AND attempts = 1 AND kind = 'index' FROM graph_jobs WHERE id = pg_temp.i('j1')), 'the worker takes one job, marked running');
SELECT pg_temp.keep('j1_doc', (SELECT document_id FROM graph_jobs WHERE id = pg_temp.i('j1')));
SELECT dc_graph_job_done(pg_temp.i('j1'), 'maludb-doc-1', ARRAY['tag:acme-corp', 'folder:Minutes']);
SELECT pg_temp.check((SELECT count(*) FROM graph_documents WHERE maludb_document_id = 'maludb-doc-1') = 1 AND (SELECT documents FROM graph_usage WHERE kind = 'index') = 1, 'a done job records the engine''s id and counts the month');
SELECT pg_temp.keep('j2', (SELECT id FROM dc_graph_next_job()));
SELECT dc_graph_job_fail(pg_temp.i('j2'), 'timeout'); SELECT dc_graph_job_fail((SELECT id FROM dc_graph_next_job() WHERE id = pg_temp.i('j2')), 'timeout'); SELECT dc_graph_job_fail((SELECT id FROM dc_graph_next_job() WHERE id = pg_temp.i('j2')), 'timeout');
SELECT pg_temp.check((SELECT status = 'failed' AND attempts = 3 FROM graph_jobs WHERE id = pg_temp.i('j2')), 'three failures and a job is failed');
UPDATE dc_settings SET graph_monthly_text_mb = 1;
UPDATE graph_jobs SET chars = 2000000 WHERE document_id = pg_temp.i('d_acme') AND status = 'queued';
SELECT pg_temp.check((SELECT count(*) FROM dc_graph_next_job() WHERE kind = 'full') = 0, 'a full job over the month''s budget waits');
UPDATE dc_settings SET graph_monthly_text_mb = 100;
SELECT pg_temp.check((SELECT array_length(dc_graph_subjects(pg_temp.i('d_acme')), 1)) <= 8 AND dc_graph_subjects(pg_temp.i('d_acme')) @> ARRAY['tag:contract', 'kind:pdf'], 'a document''s known subjects: its tags first, then folder, library, owner, kind, year — at most eight');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT array_agg(name ORDER BY name) FROM dc_graph_filter(('[{"maludb_document_id":"maludb-doc-1","similarity":0.9,"subject":"tag:acme-corp"},{"document_id":' || pg_temp.i('d_acme') || ',"similarity":0.7},{"document_id":' || pg_temp.i('d_priv') || ',"similarity":0.99}]')::jsonb)) = ARRAY['acme-msa.pdf'],
    'THE WALL over the engine: Bea gets the contract (by id) and neither the minutes (mapped by the engine id, not hers) nor Priya''s notes (highest similarity, not hers)');
SELECT pg_temp.as_member(27);
SELECT pg_temp.keep('t2', dc_document_trash(pg_temp.i('j1_doc'), 27)::int);
SELECT pg_temp.check(pg_temp.i('t2') = 1 AND (SELECT count(*) FROM graph_jobs WHERE document_id = pg_temp.i('j1_doc') AND kind = 'forget' AND status = 'queued') = 1, 'a trashed document the engine holds queues a forget');
SELECT dc_document_restore(pg_temp.i('j1_doc'));

-- ---------------------------------------------------------------- keys and rates (D13)
SELECT pg_temp.as_member(27);
INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (27, 'SMOKE key', encode(sha256('key1'::bytea), 'hex'), 'api');
SELECT pg_temp.keep('k1', (SELECT id FROM mcp_access_tokens WHERE label = 'SMOKE key'));
SELECT pg_temp.check((SELECT rate_per_minute = 60 AND rate_per_day = 5000 FROM mcp_access_tokens WHERE id = pg_temp.i('k1')), 'a key takes the settings'' rates');
SELECT pg_temp.check((SELECT member_id FROM dc_resolve_key(encode(sha256('key1'::bytea), 'hex'))) = 27, 'a key resolves to its person');
SELECT pg_temp.check((SELECT count(*) FROM (SELECT (dc_rate_ok(pg_temp.i('k1'))).ok AS ok FROM generate_series(1, 61)) s WHERE s.ok) = 60, 'the 61st call in a minute is refused');

-- ---------------------------------------------------------------- notifications, notes, favourites, saved searches, the Clerk
SELECT pg_temp.keep('nt', dc_notify(26, 'share_received', 'Marco shared Contracts with you', NULL, 'folder', pg_temp.i('f_contracts'), NULL, pg_temp.i('f_contracts'), 27, 'share:' || pg_temp.i('f_contracts') || ':26'));
SELECT pg_temp.check(pg_temp.i('nt') IS NOT NULL
    AND (SELECT count(*) FROM notification_outbox WHERE member_id = 26 AND channel = 'email') = 1, 'a notification rings the bell and queues the email the person''s preferences ask for');
SELECT pg_temp.check(dc_notify(26, 'share_received', 'again', NULL, 'folder', pg_temp.i('f_contracts'), NULL, NULL, 27, 'share:' || pg_temp.i('f_contracts') || ':26') IS NULL, '...and dedupes by key');
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('document', pg_temp.i('d_acme'), 27, 'SMOKE comment');
INSERT INTO favourites (member_id, document_id) VALUES (27, pg_temp.i('d_acme'));
INSERT INTO saved_searches (owner_id, name, query, filters) VALUES (27, 'Acme things', 'acme', '{"tag":"contract"}');
INSERT INTO search_log (member_id, kind, query, results) VALUES (27, 'keyword', 'nothing here', 0), (26, 'keyword', 'Nothing Here', 0);
SELECT pg_temp.check((SELECT times FROM dc_search_gaps() LIMIT 1) = 2, 'the search report clusters what found nothing');
INSERT INTO clerk_proposals (kind, document_id, for_member_id, title, proposed_by) VALUES ('untagged', pg_temp.i('d_priya_up'), 26, 'SMOKE: tag priya-draft.pdf', 42);
UPDATE clerk_proposals SET created_at = now() - interval '31 days';
SELECT pg_temp.keep('hk', dc_pass_housekeeping());
SELECT pg_temp.check(pg_temp.i('hk') >= 1 AND (SELECT status FROM clerk_proposals LIMIT 1) = 'expired', 'housekeeping expires an undecided proposal');

-- ---------------------------------------------------------------- the read roles see only the views, and the views honour the walls
RESET ROLE;
SET ROLE doccloud_rw;
SELECT pg_temp.as_member(28);
CREATE TEMP TABLE bea_sees AS SELECT d.id FROM documents d WHERE d.trashed_at IS NULL AND dc_can_see_document(d.id);
CREATE TEMP TABLE bea_text AS SELECT t.document_id FROM document_text t JOIN bea_sees b ON b.id = t.document_id;
GRANT SELECT ON bea_sees, bea_text TO PUBLIC;
RESET ROLE;
SET ROLE doccloud_records_ro;
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT count(*) FROM mcp_documents) = (SELECT count(*) FROM bea_sees) AND (SELECT count(*) FROM mcp_documents) = 4, 'mcp_documents is exactly what Bea may see (four documents)');
SELECT pg_temp.check((SELECT count(*) FROM mcp_document_text) = (SELECT count(*) FROM bea_text), 'mcp_document_text follows the same wall');
SELECT pg_temp.check((SELECT count(*) FROM mcp_folder_access) = 0 AND (SELECT count(*) FROM mcp_share_links) = 0 AND (SELECT count(*) FROM mcp_graph_jobs) = 0, 'a viewer sees no access rows, no links, no graph queue');
SELECT pg_temp.refused('SELECT token_hash FROM mcp_tokens', 'does not exist', 'no view carries a token hash');
SELECT pg_temp.refused('SELECT password_hash FROM mcp_share_links', 'does not exist', 'nor a link''s password');
SELECT pg_temp.refused('SELECT storage_path FROM mcp_documents', 'does not exist', 'nor a blob''s path');
SELECT pg_temp.refused('SELECT count(*) FROM documents', 'permission denied', 'the read role reaches no base table');
SELECT pg_temp.refused('SELECT count(*) FROM blobs', 'permission denied', 'nor the blobs');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM mcp_folder_access WHERE folder_id = pg_temp.i('f_2026')) = 3 AND (SELECT count(*) FROM mcp_share_links) = 4 AND (SELECT count(*) FROM mcp_tokens) = 1, 'a manager sees the rows, the links and their own key');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 2 AND (SELECT count(*) FROM mcp_documents) = 1 AND (SELECT count(*) FROM mcp_libraries) = 0, 'a guest sees themselves and who shared, the one document, no library');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM mcp_documents) = 9 AND (SELECT count(*) FROM mcp_graph_jobs) > 0 AND (SELECT model_alias IS NULL FROM mcp_settings), 'the admin sees every document (nine, the trashed one included), the queue, the model setting');
RESET ROLE;
SET ROLE doccloud_activity_ro;
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0 AND (SELECT count(*) FROM mcp_members) > 0, 'the activity role reads the trail and names');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_document_text', 'permission denied', 'the activity role sees no content');
RESET ROLE;

-- ---------------------------------------------------------------- the one that proves the proof: nothing committed
SET ROLE doccloud_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 10 AND (SELECT count(*) FROM libraries) = 7, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
SELECT count(*) FILTER (WHERE NOT ok) > 0 AS anyfail FROM proof \gset
ROLLBACK;
\if :anyfail
    \echo 'PHASE 0 PROOF FAILED'
    \set ON_ERROR_STOP on
    SELECT 1 / 0;
\endif
