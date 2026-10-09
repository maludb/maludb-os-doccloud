-- 012: THE DOCUMENT GRAPH's bookkeeping — the policy, the queue, what is in the engine, the budget, the wall over the engine's
-- answers (design §6.3; D3, D8). The engine itself is app/doccloud/graph.php (the ONE file that reads the MaluDB token).
--
-- POLICY: a folder's graph_policy overrides its library's for its subtree (the nearest ancestor-or-self that names one). `none`
-- keeps a document out; `index` (the default) sends it through `remember` (no model call) under its known subjects; `full` sends it
-- through `documents` (model extraction) — queued, one at a time, under graph_monthly_text_mb. A new version REPLACES (forget,
-- then ingest); a purge FORGETS; a policy lowered forgets, raised queues. The worker takes one job at a time (dc_graph_next_job),
-- smallest text first within the oldest, retried thrice.
-- THE WALL: dc_graph_filter(candidates) maps the engine's answers (document ids from metadata, or maludb document ids through
-- graph_documents) to documents and keeps only what dc_can_see_document() allows — one statement, before any ranking or excerpt.
--
-- THE ESTATE'S DATA MODEL (design §6.4): new (Knowledge's builds are per base, all at once; a job here is per document, continuous).
BEGIN;

CREATE TABLE graph_documents (
    document_id         bigint PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
    version_id          bigint REFERENCES document_versions(id) ON DELETE SET NULL,
    maludb_document_id  text NOT NULL,
    policy              text NOT NULL CHECK (policy IN ('index', 'full')),
    subjects            text[] NOT NULL DEFAULT '{}',
    chars               integer NOT NULL DEFAULT 0,
    indexed_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX graph_documents_maludb_idx ON graph_documents (maludb_document_id);

CREATE TABLE graph_jobs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id   bigint,                                                    -- NULL after a purge (forget jobs outlive the row)
    version_id    bigint,
    kind          text NOT NULL CHECK (kind IN ('index', 'full', 'forget')),
    maludb_document_id text,                                                 -- forget: what to forget
    chars         integer NOT NULL DEFAULT 0,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'skipped')),
    attempts      integer NOT NULL DEFAULT 0,
    error         text,
    usage         jsonb,
    created_at    timestamptz NOT NULL DEFAULT now(),
    started_at    timestamptz,
    finished_at   timestamptz
);
CREATE UNIQUE INDEX graph_jobs_one_open ON graph_jobs (document_id, kind) WHERE status IN ('queued', 'running') AND document_id IS NOT NULL;
CREATE INDEX graph_jobs_queue_idx ON graph_jobs (status, created_at, chars) WHERE status = 'queued';
CREATE INDEX graph_jobs_document_idx ON graph_jobs (document_id, created_at DESC);

CREATE TABLE graph_usage (
    month        date NOT NULL,                                              -- the first of the month
    kind         text NOT NULL CHECK (kind IN ('index', 'full', 'forget', 'find')),
    calls        integer NOT NULL DEFAULT 0,
    chars        bigint NOT NULL DEFAULT 0,
    documents    integer NOT NULL DEFAULT 0,
    PRIMARY KEY (month, kind)
);

-- The policy a document is under: the nearest folder upward naming one, else the library's.
CREATE OR REPLACE FUNCTION dc_effective_graph_policy(p_folder_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(
        (SELECT a.graph_policy FROM folders f JOIN folders a ON f.path LIKE a.path || '%' WHERE f.id = p_folder_id AND a.graph_policy IS NOT NULL ORDER BY a.depth DESC LIMIT 1),
        (SELECT l.graph_policy FROM folders f JOIN libraries l ON l.id = f.library_id WHERE f.id = p_folder_id),
        'none');
$$;

-- Queue what a document needs now: its policy against what the engine holds. Idempotent.
CREATE OR REPLACE FUNCTION dc_graph_queue_document(p_document_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d documents%ROWTYPE; pol text; g graph_documents%ROWTYPE; n integer; chars integer;
BEGIN
    SELECT * INTO d FROM documents WHERE id = p_document_id;
    IF NOT FOUND THEN RETURN 'missing'; END IF;
    SELECT * INTO g FROM graph_documents WHERE document_id = d.id;
    pol := CASE WHEN d.trashed_at IS NOT NULL THEN 'none' ELSE dc_effective_graph_policy(d.folder_id) END;
    IF pol = 'none' THEN
        IF FOUND THEN
            INSERT INTO graph_jobs (document_id, kind, maludb_document_id) VALUES (d.id, 'forget', g.maludb_document_id) ON CONFLICT DO NOTHING;
            RETURN 'forget';
        END IF;
        RETURN 'none';
    END IF;
    IF d.current_version_id IS NULL THEN RETURN 'no_version'; END IF;
    IF NOT EXISTS (SELECT 1 FROM document_text t WHERE t.version_id = d.current_version_id AND t.char_count > 0) THEN RETURN 'no_text'; END IF;
    IF g.document_id IS NOT NULL AND g.version_id = d.current_version_id AND g.policy = pol THEN RETURN 'current'; END IF;
    SELECT t.char_count INTO chars FROM document_text t WHERE t.version_id = d.current_version_id;
    UPDATE graph_jobs SET status = 'skipped', finished_at = now(), error = 'superseded by a ' || pol || ' job' WHERE document_id = d.id AND status = 'queued' AND kind <> pol;
    INSERT INTO graph_jobs (document_id, version_id, kind, chars) VALUES (d.id, d.current_version_id, pol, chars) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN CASE WHEN n = 1 THEN pol ELSE 'queued_already' END;
END$$;

-- A policy set on a library or a folder queues (or forgets) every document under it.
CREATE OR REPLACE FUNCTION dc_graph_requeue_folder(p_folder_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN SELECT d.id FROM documents d WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(p_folder_id)) LOOP
        IF dc_graph_queue_document(r.id) IN ('index', 'full', 'forget') THEN n := n + 1; END IF;
    END LOOP;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION dc_graph_requeue_library(p_library_id bigint) RETURNS integer
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    SELECT dc_graph_requeue_folder(root_folder_id) FROM libraries WHERE id = p_library_id;
$$;

-- Triggers: text extracted → queue; a move → queue (the policy may differ); a trash → forget; a purge → forget by the engine id.
CREATE OR REPLACE FUNCTION dc_graph_text_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    PERFORM dc_graph_queue_document(NEW.document_id);
    RETURN NULL;
END$$;
CREATE TRIGGER document_text_graph AFTER INSERT OR UPDATE OF text ON document_text FOR EACH ROW EXECUTE FUNCTION dc_graph_text_trg();

CREATE OR REPLACE FUNCTION dc_graph_document_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g graph_documents%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        SELECT * INTO g FROM graph_documents WHERE document_id = OLD.id;
        IF FOUND THEN INSERT INTO graph_jobs (document_id, kind, maludb_document_id) VALUES (NULL, 'forget', g.maludb_document_id); END IF;
        RETURN NULL;
    END IF;
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    PERFORM dc_graph_queue_document(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER documents_graph AFTER UPDATE OF folder_id, trashed_at, current_version_id OR DELETE ON documents FOR EACH ROW EXECUTE FUNCTION dc_graph_document_trg();

-- Tags change the subjects of an indexed document: re-queue (cheap: an index job is an embedding, not a model).
CREATE OR REPLACE FUNCTION dc_graph_tags_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did bigint := COALESCE(NEW.document_id, OLD.document_id);
BEGIN
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    IF EXISTS (SELECT 1 FROM graph_documents g WHERE g.document_id = did) THEN
        UPDATE graph_documents SET version_id = NULL WHERE document_id = did;   -- "not current": the next queue pass re-sends it
        PERFORM dc_graph_queue_document(did);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER document_tags_graph AFTER INSERT OR DELETE ON document_tags FOR EACH ROW EXECUTE FUNCTION dc_graph_tags_trg();

-- The month's spend against the budget (characters of `full` text; `index` is free of the model).
CREATE OR REPLACE FUNCTION dc_graph_budget_state(p_month date DEFAULT date_trunc('month', now())::date)
    RETURNS TABLE (month date, full_chars bigint, budget_chars bigint, pct numeric, full_documents integer, index_documents integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p_month,
           COALESCE((SELECT chars FROM graph_usage WHERE graph_usage.month = p_month AND kind = 'full'), 0),
           dc_setting_int('graph_monthly_text_mb') * 1048576,
           round(100.0 * COALESCE((SELECT chars FROM graph_usage WHERE graph_usage.month = p_month AND kind = 'full'), 0) / NULLIF(dc_setting_int('graph_monthly_text_mb') * 1048576, 0), 1),
           COALESCE((SELECT documents FROM graph_usage WHERE graph_usage.month = p_month AND kind = 'full'), 0),
           COALESCE((SELECT documents FROM graph_usage WHERE graph_usage.month = p_month AND kind = 'index'), 0);
$$;

-- What a `full` policy would cost on a folder now: documents, characters, model calls, against what is left this month.
CREATE OR REPLACE FUNCTION dc_graph_estimate(p_folder_id bigint)
    RETURNS TABLE (documents bigint, chars bigint, calls bigint, budget_left_chars bigint, over_budget boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH t AS (
        SELECT count(*) AS n, COALESCE(sum(x.char_count), 0) AS c
          FROM documents d JOIN document_text x ON x.version_id = d.current_version_id
         WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(p_folder_id)) AND d.trashed_at IS NULL AND x.char_count > 0
           AND NOT EXISTS (SELECT 1 FROM graph_documents g WHERE g.document_id = d.id AND g.policy = 'full' AND g.version_id = d.current_version_id)),
         b AS (SELECT (budget_chars - full_chars) AS left_chars FROM dc_graph_budget_state())
    SELECT t.n, t.c, ceil(t.c / 1800.0)::bigint, b.left_chars, t.c > b.left_chars FROM t, b;
$$;

-- The worker: the next job — forgets first, then the oldest queued, smallest text first; a `full` job waits when the month's
-- budget is spent (status stays queued; the admin sees it). Marks it running.
CREATE OR REPLACE FUNCTION dc_graph_next_job() RETURNS SETOF graph_jobs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE j graph_jobs%ROWTYPE; left_chars bigint;
BEGIN
    SELECT (budget_chars - full_chars) INTO left_chars FROM dc_graph_budget_state();
    SELECT * INTO j FROM graph_jobs
     WHERE status = 'queued' AND attempts < 3 AND (kind <> 'full' OR chars <= left_chars)
     ORDER BY CASE kind WHEN 'forget' THEN 0 ELSE 1 END, date_trunc('hour', created_at), chars, id
     LIMIT 1 FOR UPDATE SKIP LOCKED;
    IF NOT FOUND THEN RETURN; END IF;
    UPDATE graph_jobs SET status = 'running', attempts = attempts + 1, started_at = now() WHERE id = j.id RETURNING * INTO j;
    RETURN NEXT j;
END$$;

CREATE OR REPLACE FUNCTION dc_graph_job_done(p_job_id bigint, p_maludb_document_id text, p_subjects text[], p_usage jsonb DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE j graph_jobs%ROWTYPE;
BEGIN
    UPDATE graph_jobs SET status = 'done', finished_at = now(), maludb_document_id = COALESCE(p_maludb_document_id, maludb_document_id), usage = p_usage
     WHERE id = p_job_id RETURNING * INTO j;
    IF j.kind = 'forget' THEN
        IF j.document_id IS NOT NULL THEN DELETE FROM graph_documents WHERE document_id = j.document_id; END IF;
    ELSE
        INSERT INTO graph_documents (document_id, version_id, maludb_document_id, policy, subjects, chars)
        VALUES (j.document_id, j.version_id, p_maludb_document_id, j.kind, COALESCE(p_subjects, '{}'), j.chars)
        ON CONFLICT (document_id) DO UPDATE SET version_id = EXCLUDED.version_id, maludb_document_id = EXCLUDED.maludb_document_id, policy = EXCLUDED.policy,
            subjects = EXCLUDED.subjects, chars = EXCLUDED.chars, indexed_at = now();
    END IF;
    INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, j.kind, 1, j.chars, 1)
    ON CONFLICT (month, kind) DO UPDATE SET calls = graph_usage.calls + 1, chars = graph_usage.chars + EXCLUDED.chars, documents = graph_usage.documents + 1;
END$$;

CREATE OR REPLACE FUNCTION dc_graph_job_fail(p_job_id bigint, p_error text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE graph_jobs SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END, error = left(p_error, 500), finished_at = CASE WHEN attempts >= 3 THEN now() END
     WHERE id = p_job_id;
END$$;

-- A document's known subjects for the `index` policy: its tags (managed first), its folder, its library, its owner, its kind, its
-- year, the request label it came from — at most eight (M12).
CREATE OR REPLACE FUNCTION dc_graph_subjects(p_document_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH d AS (SELECT * FROM documents WHERE id = p_document_id),
         s AS (
            SELECT 'tag:' || t.slug AS subj, 1 + CASE WHEN t.kind = 'managed' THEN 0 ELSE 1 END AS prio, -t.usage_count AS ord FROM document_tags dt JOIN tags t ON t.id = dt.tag_id, d WHERE dt.document_id = d.id
            UNION ALL SELECT 'folder:' || f.name, 3, 0 FROM folders f, d WHERE f.id = d.folder_id AND f.parent_id IS NOT NULL
            UNION ALL SELECT 'library:' || l.name, 4, 0 FROM libraries l, d WHERE l.id = d.library_id
            UNION ALL SELECT 'owner:' || m.display_name, 5, 0 FROM members m, d WHERE m.id = d.owner_member_id
            UNION ALL SELECT 'kind:' || d.mime_kind, 6, 0 FROM d
            UNION ALL SELECT 'year:' || to_char(d.created_at, 'YYYY'), 7, 0 FROM d)
    SELECT COALESCE((SELECT array_agg(subj ORDER BY prio, ord, subj) FROM (SELECT subj, prio, ord FROM s ORDER BY prio, ord, subj LIMIT 8) x), '{}');
$$;

-- THE WALL over the engine's answers. Input: [{"maludb_document_id": "...", "document_id": 12, "similarity": 0.8, "subject": "tag:acme", "excerpt": "..."}];
-- output: the documents the caller may see, best first, with the engine's reasons kept.
CREATE OR REPLACE FUNCTION dc_graph_filter(p_candidates jsonb, p_limit integer DEFAULT 50)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, library_id bigint, owner_member_id bigint, mime_kind text, score numeric, subjects text[], hits integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
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
     WHERE d.trashed_at IS NULL AND dc_can_see_document(d.id)
     ORDER BY a.score DESC, d.updated_at DESC
     LIMIT greatest(1, least(p_limit, 200));
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON graph_documents, graph_jobs, graph_usage TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_effective_graph_policy(bigint), dc_graph_budget_state(date), dc_graph_estimate(bigint), dc_graph_subjects(bigint), dc_graph_filter(jsonb, integer)
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_graph_queue_document(bigint), dc_graph_requeue_folder(bigint), dc_graph_requeue_library(bigint), dc_graph_next_job(),
    dc_graph_job_done(bigint, text, text[], jsonb), dc_graph_job_fail(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_graph_queue_document(bigint), dc_graph_requeue_folder(bigint), dc_graph_requeue_library(bigint), dc_graph_next_job(),
    dc_graph_job_done(bigint, text, text[], jsonb), dc_graph_job_fail(bigint, text) TO doccloud_rw;

COMMIT;
