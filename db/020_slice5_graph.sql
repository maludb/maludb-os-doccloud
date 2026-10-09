-- 020 (slice 5, the document graph): a policy of `none` cancels what waits — dc_graph_queue_document() skipped a queued index or full job
-- only when a job of the other kind superseded it; under `none` (or a trashed document) the queued jobs stayed queued and the worker
-- would have sent a document its folder had just excluded. Now the `none` branch skips every queued index/full job of the document
-- first, then queues the forget when the engine holds it. Found by tests/phase3/slice5/policy.php.
BEGIN;

CREATE OR REPLACE FUNCTION dc_graph_queue_document(p_document_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d documents%ROWTYPE; pol text; g graph_documents%ROWTYPE; n integer; chars integer;
BEGIN
    SELECT * INTO d FROM documents WHERE id = p_document_id;
    IF NOT FOUND THEN RETURN 'missing'; END IF;
    SELECT * INTO g FROM graph_documents WHERE document_id = d.id;
    pol := CASE WHEN d.trashed_at IS NOT NULL THEN 'none' ELSE dc_effective_graph_policy(d.folder_id) END;
    IF pol = 'none' THEN
        UPDATE graph_jobs SET status = 'skipped', finished_at = now(), error = 'the policy is none'
         WHERE document_id = d.id AND status = 'queued' AND kind IN ('index', 'full');
        IF g.document_id IS NOT NULL AND g.maludb_document_id NOT LIKE 'none:%' THEN
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

COMMIT;
