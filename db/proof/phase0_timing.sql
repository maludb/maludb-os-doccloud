-- THE SCALE RULE, measured (design §3, §10 Phase 0): 200,000 documents in 2,000 folders across 60 libraries (40 personal, 15 group,
-- 5 shared), 300 groups' worth of memberships, explicit access rows on a tenth of the folders; then, as an ordinary member, a folder
-- listing page, a keyword search page, a graph filter over 150 candidates, Shared with me, and the libraries — each timed. Run on a
-- scratch database after the migrations (tests/phase0/timing.sh does it); prints the timings and the row counts and FAILS any step
-- over 2,000 ms (the planning host does them in well under 200 ms; the ceiling is generous so a loaded host still passes).
\set QUIET on
\timing off
\pset tuples_only on
\o /dev/null
BEGIN;
SET LOCAL work_mem = '256MB';
SELECT set_config('app.dc_bulk', '1', true);           -- index by catch-up, not by trigger
SET ROLE doccloud_rw;
-- members: 1 admin, 400 members, 5 guests; departments 10
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles)
SELECT i, 'human', 'T Member ' || i, 'm' || i || '@t.invalid', CASE WHEN i = 1 THEN 'super_admin' ELSE 'user' END, i > 400, 'active', CASE WHEN i = 1 THEN 'admin' WHEN i > 400 THEN 'read' ELSE 'write' END,
       CASE WHEN i = 1 THEN '{admin}' WHEN i > 400 THEN '{guest}' ELSE '{user}' END::text[]
  FROM generate_series(1, 405) i;
INSERT INTO departments (id, name) SELECT i, 'T Dept ' || i FROM generate_series(1, 10) i;
INSERT INTO department_members (member_id, department_id) SELECT i, 1 + (i % 10) FROM generate_series(2, 400) i;
SELECT set_config('app.member_id', '1', true);
-- libraries: 40 personal, 15 groups (each with 20 members), 5 shared
SELECT dc_library_ensure_personal(i) FROM generate_series(2, 41) i;
INSERT INTO groups (key, name, kind, created_by) SELECT 'tg' || i, 'T Group ' || i, 'custom', 2 FROM generate_series(1, 15) i;
INSERT INTO group_members (group_id, member_id, role) SELECT g.id, 2 + ((g.id * 7 + m) % 399), 'member' FROM groups g, generate_series(1, 20) m ON CONFLICT DO NOTHING;
INSERT INTO libraries (kind, name, owner_member_id, everyone_level, created_by) SELECT 'shared', 'T Shared ' || i, 2, 'view', 2 FROM generate_series(1, 5) i;
-- folders: 2,000 — 10 under each library root, then 3 levels of children
INSERT INTO folders (library_id, parent_id, name, owner_member_id)
SELECT l.id, l.root_folder_id, 'F' || l.id || '-' || i, 2 FROM libraries l, generate_series(1, 10) i;
INSERT INTO folders (library_id, parent_id, name, owner_member_id)
SELECT f.library_id, f.id, f.name || '.' || i, 2 FROM folders f, generate_series(1, 2) i WHERE f.parent_id IS NOT NULL AND f.depth = 1;
INSERT INTO folders (library_id, parent_id, name, owner_member_id)
SELECT f.library_id, f.id, f.name || '.' || i, 2 FROM folders f, generate_series(1, 1) i WHERE f.depth = 2 AND f.id % 2 = 0;
-- explicit rows on a tenth of the folders: a member and a department each
INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by)
SELECT f.id, 'member', 2 + (f.id % 399), 'view', 2 FROM folders f WHERE f.parent_id IS NOT NULL AND f.id % 10 = 0;
INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by)
SELECT f.id, 'department', 1 + (f.id % 10), 'edit', 2 FROM folders f WHERE f.parent_id IS NOT NULL AND f.id % 10 = 0;
-- blobs and documents: 200,000, spread over the non-root folders, owned by 400 members
INSERT INTO blobs (sha256, byte_size, storage_path)
SELECT encode(sha256(('blob' || i)::bytea), 'hex'), 1000 + (i % 50000), 'blobs/xx/yy/' || i FROM generate_series(1, 20000) i;   -- one blob per ten documents (dedupe)
WITH f AS (SELECT id, library_id, row_number() OVER (ORDER BY id) AS rn, count(*) OVER () AS n FROM folders WHERE parent_id IS NOT NULL)
INSERT INTO documents (library_id, folder_id, name, owner_member_id, mime_type, created_by)
SELECT f.library_id, f.id, 'doc-' || i || CASE i % 4 WHEN 0 THEN '.pdf' WHEN 1 THEN '.docx' WHEN 2 THEN '.txt' ELSE '.png' END, 2 + (i % 399),
       CASE i % 4 WHEN 0 THEN 'application/pdf' WHEN 1 THEN 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' WHEN 2 THEN 'text/plain' ELSE 'image/png' END, 2
  FROM generate_series(1, 200000) i JOIN f ON f.rn = 1 + (i % f.n);
INSERT INTO document_versions (document_id, blob_id, filename, mime_type, byte_size, sha256, uploaded_by)
SELECT d.id, b.id, d.name, d.mime_type, b.byte_size, b.sha256, d.owner_member_id FROM documents d JOIN blobs b ON b.id = 1 + (d.id % 20000);
INSERT INTO document_text (version_id, document_id, text, char_count, extractor)
SELECT d.current_version_id, d.id, 'Document ' || d.id || ' about ' || (ARRAY['contracts','invoices','minutes','designs','policies','budgets','termination','acme','warranty','shipping'])[1 + d.id % 10]
       || ' and ' || (ARRAY['Illinois','Chicago','Dublin','Lagos','Tokyo'])[1 + d.id % 5] || ' with clause ' || (d.id % 97) || '. ' || repeat('filler words here ', 20), 400, 'plain'
  FROM documents d WHERE d.id % 2 = 0;      -- half have text
SELECT dc_recount_bulk();
SELECT set_config('app.dc_bulk', '', true);
-- the index, in bulk (what the worker's catch-up does, here in one statement)
INSERT INTO search_index (entity_kind, entity_id, author_member_id, title, body, tsv, has_file, occurred_at, document_id, version_id, library_id, folder_id, owner_member_id, mime_kind, byte_size, tags)
SELECT 'document', d.id, d.owner_member_id, d.name, t.text, dc_search_tsv(d.name, '', t.text), true, d.updated_at, d.id, d.current_version_id, d.library_id, d.folder_id, d.owner_member_id, d.mime_kind, d.byte_size, '{}'
  FROM documents d LEFT JOIN document_text t ON t.version_id = d.current_version_id;
-- a graph map for a third
INSERT INTO graph_documents (document_id, version_id, maludb_document_id, policy, subjects, chars)
SELECT d.id, d.current_version_id, 'm' || d.id, 'index', '{}', 400 FROM documents d WHERE d.id % 3 = 0;
RESET ROLE;
ANALYZE folders; ANALYZE documents; ANALYZE folder_access; ANALYZE search_index; ANALYZE graph_documents; ANALYZE document_versions; ANALYZE group_members;

CREATE TEMP TABLE timings (step text, ms numeric, rows_ bigint);
GRANT ALL ON timings TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.timeit(p_step text, p_sql text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t0 timestamptz; n bigint;
BEGIN
    t0 := clock_timestamp();
    IF p_sql ~* '^\s*(UPDATE|INSERT|DELETE)' THEN
        EXECUTE 'WITH q AS (' || p_sql || ') SELECT count(*) FROM q' INTO n;
    ELSE
        EXECUTE 'SELECT count(*) FROM (' || p_sql || ') q' INTO n;
    END IF;
    INSERT INTO timings VALUES (p_step, round(extract(epoch FROM clock_timestamp() - t0) * 1000, 1), n);
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.timeit(text, text) TO PUBLIC;

-- the folders and library the timed reads look at, found as the superuser (the read role sees base tables only through views)
SELECT (SELECT f.id FROM folders f JOIN libraries l ON l.id = f.library_id WHERE l.kind = 'group' AND f.depth = 1 ORDER BY f.id LIMIT 1) AS gfold,
       (SELECT f.id FROM folders f JOIN libraries l ON l.id = f.library_id WHERE l.kind = 'shared' AND f.depth = 1 ORDER BY f.id LIMIT 1) AS sfold,
       (SELECT min(id) FROM libraries WHERE kind = 'shared') AS slib,
       (SELECT id FROM folders WHERE depth = 1 ORDER BY id LIMIT 1) AS mfold \gset
SELECT pg_temp.timeit('(diagnostic) index rows: pdf + contracts in the first shared library, no wall', 'SELECT 1 FROM search_index si WHERE si.library_id = ' || :slib || ' AND si.mime_kind = ''pdf'' AND si.tsv @@ websearch_to_tsquery(''simple'', ''contracts'')');
-- as member 37: a Member in a department, in a few groups, with some rows naming them
SET ROLE doccloud_records_ro;
SELECT set_config('app.member_id', '37', true);
SELECT pg_temp.timeit('warm-up (principals)', 'SELECT * FROM dc_my_principals()');
SELECT pg_temp.timeit('my libraries', 'SELECT * FROM dc_my_libraries()');
SELECT pg_temp.timeit('a folder listing page (a group library folder, 100 rows)', 'SELECT * FROM dc_folder_listing(' || :gfold || ', ''name'', ''asc'', 100, 0)');
SELECT pg_temp.timeit('a folder listing page (a shared library folder, 100 rows)', 'SELECT * FROM dc_folder_listing(' || :sfold || ', ''updated'', ''desc'', 100, 0)');
SELECT pg_temp.timeit('keyword search page: "termination Chicago" (25 of thousands)', 'SELECT * FROM dc_search(''termination Chicago'', ''{}'', 25, 0)');
SELECT pg_temp.timeit('keyword search page with filters: kind pdf, a library', 'SELECT * FROM dc_search(''contracts'', ''{"kind":"pdf","library":' || :slib || '}''::jsonb, 25, 0)');
SELECT pg_temp.timeit('name search by trigram', 'SELECT * FROM dc_search_names(''doc-1234'', 10)');
SELECT pg_temp.timeit('graph filter over 150 candidates', 'SELECT * FROM dc_graph_filter((SELECT jsonb_agg(jsonb_build_object(''maludb_document_id'', ''m'' || (i * 3), ''similarity'', 0.5 + (i % 50) / 100.0)) FROM generate_series(1, 150) i), 50)');
SELECT pg_temp.timeit('shared with me', 'SELECT * FROM dc_shared_with_me(100, 0)');
SELECT pg_temp.timeit('mcp_documents, one folder (the view, 100 rows)', 'SELECT * FROM mcp_documents WHERE folder_id = ' || :sfold || ' LIMIT 100');
SELECT pg_temp.timeit('recent documents', 'SELECT * FROM dc_recent_documents(20)');
RESET ROLE;
SET ROLE doccloud_rw;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.timeit('the admin: storage by library', 'SELECT * FROM dc_storage_by_library()');
SELECT pg_temp.timeit('the admin: duplicates (top 100)', 'SELECT * FROM dc_duplicates(100)');
SELECT pg_temp.timeit('a move of a folder with its subtree (paths and access roots)', 'UPDATE folders SET parent_id = (SELECT root_folder_id FROM libraries WHERE id = folders.library_id) WHERE id = ' || :mfold || ' RETURNING id');
RESET ROLE;

\o
\pset tuples_only on
SELECT '  ' || rpad(step, 66) || lpad(ms::text, 9) || ' ms  ' || rows_ || ' rows' FROM timings;
SELECT '  documents: ' || (SELECT count(*) FROM documents) || ', folders: ' || (SELECT count(*) FROM folders) || ', access rows: ' || (SELECT count(*) FROM folder_access) || ', index rows: ' || (SELECT count(*) FROM search_index);
SELECT CASE WHEN max(ms) > 2000 THEN '  TIMING FAILED: a step took ' || max(ms) || ' ms' ELSE '  timing ok: slowest step ' || max(ms) || ' ms' END FROM timings;
SELECT max(ms) > 2000 AS anyfail FROM timings \gset
ROLLBACK;
\if :anyfail
    \set ON_ERROR_STOP on
    SELECT 1 / 0;
\endif
