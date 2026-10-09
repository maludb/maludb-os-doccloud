<?php
declare(strict_types=1);

/**
 * The worker's graph pass (slice 5; docs/build-specs/document-graph.md §4): one job at a time from dc_graph_next_job() (forgets first, then
 * the oldest hour's smallest text; a `full` job waits when the month's budget is spent) under a time budget — `forget` → dc_graph_forget;
 * `index` → the text cut at graph_text_chars + dc_graph_subjects() → dc_graph_index_document (no model call); `full` → the whole text →
 * dc_graph_full_document (the namespace's prompt, one model call per chunk; no_edges → skipped, nothing kept). A replace: the document's
 * previous engine id is forgotten by a forget job queued after the new one is done. graph_unavailable: nothing is taken, the queue waits;
 * the engine going away mid-job gives the attempt back; a timeout or a model failure counts (three → failed). The month's budget notice to the admins at 80% and at 100%, once a month each.
 */
require_once dirname(__DIR__, 2) . '/doccloud/graph.php';
require_once dirname(__DIR__, 2) . '/doccloud/graph_prompt.php';

function worker_graph(PDO $pdo, int $maxJobs = 10, int $seconds = 50): array
{
    $out = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'forgotten' => 0, 'caught_up' => 0, 'budget_pct' => 0.0];
    $queued = (int) one_value($pdo, "SELECT count(*) FROM graph_jobs WHERE status = 'queued'");
    if (!dc_graph_available()) {
        $out['deferred'] = $queued;
        $out['reason'] = 'graph_unavailable';
        graph_pass_note($pdo, $queued);
        return $out;
    }
    $started = microtime(true);
    $textChars = max(1000, (int) one_value($pdo, "SELECT dc_setting_int('graph_text_chars')"));
    for ($i = 0; $i < $maxJobs; $i++) {
        if (microtime(true) - $started > $seconds) {
            break;
        }
        $job = $pdo->query('SELECT * FROM dc_graph_next_job()')->fetch();
        if ($job === false) {
            break;
        }
        try {
            $result = worker_graph_job($pdo, $job, $textChars);
            $out[$result]++;
        } catch (Throwable $e) {
            error_log('graph job ' . $job['id'] . ': ' . $e->getMessage());
            $pdo->prepare('SELECT dc_graph_job_fail(:id, :e)')->execute(['id' => (int) $job['id'], 'e' => mb_substr($e->getMessage(), 0, 400)]);
            $out['failed']++;
        }
    }
    $out['caught_up'] = (int) one_value($pdo, 'SELECT dc_pass_graph_catch_up(200)');
    $b = $pdo->query('SELECT * FROM dc_graph_budget_state()')->fetch() ?: [];
    $out['budget_pct'] = (float) ($b['pct'] ?? 0);
    worker_graph_budget_notice($pdo, $out['budget_pct'], (string) ($b['month'] ?? date('Y-m-01')));
    $out['deferred'] = (int) one_value($pdo, "SELECT count(*) FROM graph_jobs WHERE status = 'queued'");
    return $out;
}

/** One job: returns done | failed | skipped | forgotten. */
function worker_graph_job(PDO $pdo, array $job, int $textChars): string
{
    $jobId = (int) $job['id'];
    $kind = (string) $job['kind'];
    $docId = $job['document_id'] === null ? null : (int) $job['document_id'];
    if ($kind === 'forget') {
        $r = dc_graph_forget((string) $job['maludb_document_id']);
        if (in_array($r['status'], ['forgotten', 'missing', 'not_found'], true)) {
            $pdo->prepare("SELECT dc_graph_job_done(:id, NULL, '{}', NULL)")->execute(['id' => $jobId]);
            graph_job_log($pdo, 'graph.forget', $docId, ['job_id' => $jobId, 'maludb_document_id' => $job['maludb_document_id'], 'result' => $r['status']]);
            return 'forgotten';
        }
        return worker_graph_fail($pdo, $jobId, $docId, $r);
    }
    $st = $pdo->prepare('SELECT d.*, t.text, t.char_count FROM documents d LEFT JOIN document_text t ON t.version_id = :v WHERE d.id = :id');
    $st->execute(['v' => (int) $job['version_id'], 'id' => $docId]);
    $doc = $st->fetch();
    if ($doc === false || $doc['trashed_at'] !== null || (int) $doc['current_version_id'] !== (int) $job['version_id']) {
        $pdo->prepare('SELECT dc_graph_job_skip(:id, :r)')->execute(['id' => $jobId, 'r' => $doc === false ? 'gone' : 'superseded']);
        return 'skipped';
    }
    $text = (string) ($doc['text'] ?? '');
    if (trim($text) === '') {
        $pdo->prepare('SELECT dc_graph_job_skip(:id, :r)')->execute(['id' => $jobId, 'r' => 'no_text']);
        return 'skipped';
    }
    $previous = one_value($pdo, "SELECT maludb_document_id FROM graph_documents WHERE document_id = :id AND maludb_document_id NOT LIKE 'none:%'", ['id' => $docId]);
    $subjects = pg_text_array((string) one_value($pdo, 'SELECT dc_graph_subjects(:id)', ['id' => $docId]));
    $meta = ['document_id' => $docId, 'library_id' => (int) $doc['library_id'], 'version_id' => (int) $job['version_id'], 'folder_id' => (int) $doc['folder_id']];
    $docInfo = ['id' => $docId, 'name' => (string) $doc['name']];
    if ($kind === 'index') {
        $r = dc_graph_index_document($docInfo, mb_substr($text, 0, $textChars), $subjects, $meta);
        if ($r['status'] !== 'indexed') {
            return worker_graph_fail($pdo, $jobId, $docId, $r);
        }
        $kept = $r['subjects'] ?? $subjects;
        $pdo->prepare('SELECT dc_graph_job_done(:id, :m, CAST(:s AS text[]), CAST(:u AS jsonb))')
            ->execute(['id' => $jobId, 'm' => (string) $r['maludb_document_id'], 's' => '{' . implode(',', array_map(static fn (string $x): string => '"' . str_replace('"', '', $x) . '"', $kept)) . '}',
                       'u' => json_encode(['chunk_count' => $r['chunk_count'] ?? null, 'statements' => $r['statements'] ?? null])]);
        graph_job_log($pdo, 'graph.index', $docId, ['job_id' => $jobId, 'chars' => min(mb_strlen($text), $textChars), 'maludb_document_id' => $r['maludb_document_id'], 'subjects' => count($kept), 'dropped' => count($r['subjects_dropped'] ?? [])], $doc);
    } else {
        $r = dc_graph_full_document($docInfo, $text, $subjects, $meta);
        if ($r['status'] === 'no_edges') {
            $pdo->prepare('SELECT dc_graph_job_skip(:id, :r)')->execute(['id' => $jobId, 'r' => 'no_edges']);
            graph_job_log($pdo, 'graph.skip', $docId, ['job_id' => $jobId, 'reason' => 'no_edges'], $doc);
            worker_graph_forget_previous($pdo, $previous, $docId);
            return 'skipped';
        }
        if ($r['status'] !== 'extracted') {
            return worker_graph_fail($pdo, $jobId, $docId, $r);
        }
        $pdo->prepare('SELECT dc_graph_job_done(:id, :m, CAST(:s AS text[]), CAST(:u AS jsonb))')
            ->execute(['id' => $jobId, 'm' => (string) $r['maludb_document_id'], 's' => '{' . implode(',', array_map(static fn (string $x): string => '"' . str_replace('"', '', $x) . '"', $subjects)) . '}',
                       'u' => json_encode(['chunk_count' => $r['chunk_count'] ?? null, 'edges_count' => $r['edges_count'] ?? null, 'usage' => $r['usage'] ?? null])]);
        graph_job_log($pdo, 'graph.full', $docId, ['job_id' => $jobId, 'chars' => mb_strlen($text), 'maludb_document_id' => $r['maludb_document_id'], 'edges' => $r['edges_count'] ?? null], $doc);
    }
    worker_graph_forget_previous($pdo, $previous, $docId, (string) $r['maludb_document_id']);
    return 'done';
}

/** A replace: the engine document the document held before is forgotten by its own job. */
function worker_graph_forget_previous(PDO $pdo, mixed $previous, ?int $docId, string $new = ''): void
{
    if ($previous === null || $previous === false || (string) $previous === $new || (string) $previous === '') {
        return;
    }
    $pdo->prepare("INSERT INTO graph_jobs (document_id, kind, maludb_document_id) VALUES (NULL, 'forget', :m)")->execute(['m' => (string) $previous]);
}

/** An engine failure: graph_unavailable gives the attempt back (the engine is down: the queue waits); anything else — a timeout too (spec §4:
 *  a text the engine cannot take in one synchronous call fails thrice and shows in the queue; M11 owed upstream) — counts (three → failed). */
function worker_graph_fail(PDO $pdo, int $jobId, ?int $docId, array $r): string
{
    $status = (string) ($r['status'] ?? 'graph_error');
    if ($status === 'graph_unavailable') {
        $pdo->prepare("UPDATE graph_jobs SET status = 'queued', attempts = GREATEST(0, attempts - 1), started_at = NULL, error = :e WHERE id = :id")->execute(['id' => $jobId, 'e' => $status]);
        return 'deferred';
    }
    $pdo->prepare('SELECT dc_graph_job_fail(:id, :e)')->execute(['id' => $jobId, 'e' => mb_substr($status . ': ' . (string) ($r['error'] ?? ''), 0, 400)]);
    graph_job_log($pdo, 'graph.fail', $docId, ['job_id' => $jobId, 'error_code' => $status]);
    return 'failed';
}

function graph_job_log(PDO $pdo, string $event, ?int $docId, array $after, ?array $doc = null): void
{
    log_activity($pdo, $event, $docId === null ? 'graph_job' : 'document', $docId ?? (int) ($after['job_id'] ?? 0), ['source' => 'cron', 'actor_member_id' => null, 'document_id' => $docId,
        'folder_id' => isset($doc['folder_id']) ? (int) $doc['folder_id'] : null, 'library_id' => isset($doc['library_id']) ? (int) $doc['library_id'] : null, 'after' => $after]);
}

/** Once per pass when the engine is away and jobs wait: one note in the log (never one per job). */
function graph_pass_note(PDO $pdo, int $queued): void
{
    if ($queued > 0) {
        log_activity($pdo, 'graph.deferred', 'graph_job', 0, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['queued' => $queued, 'reason' => 'graph_unavailable']]);
    }
}

/** The admins told at 80% and at 100% of the month's budget, once a month each (dc_notify dedupes by key). */
function worker_graph_budget_notice(PDO $pdo, float $pct, string $month): void
{
    foreach ([80 => 'graph_budget:' . $month . ':80', 100 => 'graph_budget:' . $month . ':100'] as $at => $key) {
        if ($pct < $at) {
            continue;
        }
        $admins = $pdo->query("SELECT id FROM members WHERE status = 'active' AND capability IS NOT NULL AND member_kind = 'human' AND ('admin' = ANY (roles) OR business_role = 'super_admin')")->fetchAll();
        // dc_notify dedupes through the outbox, and graph_budget is not among the kinds a person mails by default (no outbox row, no dedupe):
        // the threshold is the notification's record_id and the month's bell row is the once-a-month rule.
        $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'graph_budget' AND record_type = 'graph' AND record_id = :at
                                  AND created_at >= CAST(:month AS date)");
        foreach ($admins as $a) {
            $told->execute(['m' => (int) $a['id'], 'at' => $at, 'month' => $month]);
            if ($told->fetchColumn()) {
                continue;
            }
            $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, NULL, NULL, NULL, :dd)')
                ->execute(['m' => (int) $a['id'], 'k' => 'graph_budget', 't' => 'The document graph has used ' . (int) $pct . '% of this month\'s text budget',
                           'b' => $at >= 100 ? 'Full extraction jobs wait until next month, or until the budget is raised.' : 'Full extraction jobs over the remainder will wait until next month.',
                           'rt' => 'graph', 'rid' => $at, 'dd' => $key . ':' . (int) $a['id']]);
        }
    }
}
