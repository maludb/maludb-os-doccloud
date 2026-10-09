<?php
declare(strict_types=1);

/**
 * The writes of the document graph (slice 5): the policy on a library or a folder (requeued through dc_graph_requeue_*), the budget, the
 * model (dc_graph_config_set — the credential stored by MaluDB only), a test extraction (one model call, nothing kept), a job retried or
 * skipped, a library or folder rebuilt. Each is SQL (or one engine call) and nothing else: the controllers parse, judge and log.
 */

/** Set the policy of a library (none|index|full) or a folder (none|index|full|null = inherit); returns how many documents were (re)queued or forgotten. */
function graph_policy_set(PDO $pdo, string $kind, int $id, ?string $policy): int
{
    if ($kind === 'library') {
        $pdo->prepare('UPDATE libraries SET graph_policy = :p WHERE id = :id')->execute(['p' => $policy ?? 'index', 'id' => $id]);
        return (int) one_value($pdo, 'SELECT dc_graph_requeue_library(:id)', ['id' => $id]);
    }
    $pdo->prepare('UPDATE folders SET graph_policy = :p WHERE id = :id')->execute(['p' => $policy, 'id' => $id]);
    return (int) one_value($pdo, 'SELECT dc_graph_requeue_folder(:id)', ['id' => $id]);
}

function graph_budget_set(PDO $pdo, int $mb): void
{
    $pdo->prepare('UPDATE dc_settings SET graph_monthly_text_mb = :mb WHERE id = 1')->execute(['mb' => $mb]);
}

/** The model through the engine; the settings row mirrors the alias and the embedding model. Returns the engine's answer. */
function graph_model_set(PDO $pdo, array $cfg): array
{
    $r = dc_graph_config_set($cfg);
    if ($r['status'] === 'ok') {
        $pdo->prepare('UPDATE dc_settings SET model_alias = :a, embedding_model = :e WHERE id = 1')->execute(['a' => $cfg['model_alias'], 'e' => $cfg['embedding_model']]);
    }
    return $r;
}

/** One model call on a document's first 2,000 characters under the real namespace, forgotten at once. {status, edges_count, chunk_count, error} */
function graph_test_extraction(PDO $pdo, int $documentId): array
{
    $t = document_text_of($pdo, $documentId);
    $text = mb_substr((string) ($t['text'] ?? ''), 0, 2000);
    if (trim($text) === '') {
        return ['status' => 'no_text', 'edges_count' => 0, 'error' => 'The document has no text to extract from.'];
    }
    $name = (string) one_value($pdo, 'SELECT name FROM documents WHERE id = :id', ['id' => $documentId]);
    $r = dc_graph_full_document(['id' => 'test-' . $documentId, 'name' => 'test: ' . $name], $text, ['doc:test'], ['document_id' => 0, 'test' => true]);
    $out = ['status' => $r['status'], 'edges_count' => (int) ($r['edges_count'] ?? 0), 'chunk_count' => (int) ($r['chunk_count'] ?? 0), 'error' => $r['error'] ?? null];
    if (!empty($r['maludb_document_id'])) {
        dc_graph_forget((string) $r['maludb_document_id']);
    }
    return $out;
}

function graph_job_retry(PDO $pdo, int $jobId): bool
{
    $st = $pdo->prepare("UPDATE graph_jobs SET status = 'queued', attempts = 0, error = NULL, started_at = NULL, finished_at = NULL WHERE id = :id AND status = 'failed'");
    $st->execute(['id' => $jobId]);
    return $st->rowCount() === 1;
}

function graph_job_skip(PDO $pdo, int $jobId, string $reason): bool
{
    $status = one_value($pdo, 'SELECT status FROM graph_jobs WHERE id = :id', ['id' => $jobId]);
    if (!in_array($status, ['queued', 'failed'], true)) {
        return false;
    }
    $pdo->prepare('SELECT dc_graph_job_skip(:id, :r)')->execute(['id' => $jobId, 'r' => $reason]);
    return true;
}

/** Everything under a library's root or a folder re-sent: the engine's documents forgotten (forget jobs), the rows cleared, the documents requeued. */
function graph_rebuild_all(PDO $pdo, string $kind, int $id): int
{
    $folder = $kind === 'library' ? (int) one_value($pdo, 'SELECT root_folder_id FROM libraries WHERE id = :id', ['id' => $id]) : $id;
    $pdo->prepare("INSERT INTO graph_jobs (document_id, kind, maludb_document_id)
                   SELECT NULL, 'forget', g.maludb_document_id FROM graph_documents g JOIN documents d ON d.id = g.document_id
                    WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(:f)) AND g.maludb_document_id NOT LIKE 'none:%'")->execute(['f' => $folder]);
    $pdo->prepare("UPDATE graph_jobs j SET status = 'skipped', finished_at = now(), error = 'rebuilt' FROM documents d
                    WHERE j.document_id = d.id AND j.status = 'queued' AND d.folder_id IN (SELECT dc_folder_subtree_ids(:f))")->execute(['f' => $folder]);
    $pdo->prepare('DELETE FROM graph_documents g USING documents d WHERE g.document_id = d.id AND d.folder_id IN (SELECT dc_folder_subtree_ids(:f))')->execute(['f' => $folder]);
    return (int) one_value($pdo, 'SELECT dc_graph_requeue_folder(:f)', ['f' => $folder]);
}
