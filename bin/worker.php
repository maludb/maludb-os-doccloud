<?php
declare(strict_types=1);

/**
 * The DocCloud worker — one pass a minute from doccloud-worker.timer (design §14): upload-session expiry, text extraction and thumbnails,
 * the keyword index catch-up, the graph jobs under the budget, the versions and trash windows, blob freeing, link and lock expiry, exports,
 * the outbox (email through MaluMail, texts through the kernel's K6), dispatches to agents, quotas, proposals, key-usage pruning. Idempotent;
 * advisory-locked so two runs never overlap; each pass its own try (one failing never stops the next); the report is printed as one JSON
 * line, logged once as `worker.pass` (counts and sentences, never a document's text, a token or a path) and kept as a worker_passes row
 * when the table exists (db/013).
 *   php bin/worker.php [--passes=a,b | --only=a] [--limit=200]
 * With --passes (or --only) the named passes run whether or not they are due; without it every pass runs, in the order of app/features/admin/passes.php. DC_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * Every step is timed: one `worker_passes` row per pass with `steps = {name: {count, ms[, error]}}`, `cron.run` logged with the totals, a step that throws caught, logged
 * `cron.step_failed` with its name, and the pass goes on. DC_WORKER_FAIL=<step> (honoured when APP_ENV is not prod) makes that step throw, for the proof.
 * Slice 8 made `dispatches` (the expert's chat turns) and `proposals` (the Clerk's Monday sweep) real.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/files/handler.php';          // the store, the gate, the file modules (slice 2)
require_once dirname(__DIR__) . '/app/features/files/worker_steps.php';
require_once dirname(__DIR__) . '/app/features/links/worker_steps.php';
require_once dirname(__DIR__) . '/app/features/graph/worker_steps.php';   // slice 5: the graph jobs

require_once dirname(__DIR__) . '/app/features/admin/queries.php';      // WORKER_PASSES (passes.php): the passes, in the order they run; worker_step_count() (slice 6)
require_once dirname(__DIR__) . '/app/features/outbox/sender.php';      // slice 6: the outbox
require_once dirname(__DIR__) . '/app/features/agents/handler.php';     // slice 8: the dispatch loop, the Clerk's Monday sweep, the notifications wrapper

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=') || str_starts_with($a, '--only=')) {
        $only = array_values(array_filter(explode(',', substr($a, strpos($a, '=') + 1))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('DC_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('doccloud_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
$steps = [];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    $t = microtime(true);
    try {
        if (app_is_prod() === false && getenv('DC_WORKER_FAIL') === $pass) {
            throw new RuntimeException('a step made to fail by DC_WORKER_FAIL');
        }
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
        $steps[$pass] = ['count' => worker_step_count($report['ran'][$pass]), 'ms' => (int) round((microtime(true) - $t) * 1000)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
        $steps[$pass] = ['count' => 0, 'ms' => (int) round((microtime(true) - $t) * 1000), 'error' => mb_substr($e->getMessage(), 0, 200)];
        log_activity($pdo, 'cron.step_failed', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['step' => $pass, 'error' => mb_substr($e->getMessage(), 0, 200)]]);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
$report['steps'] = $steps;
log_activity($pdo, 'cron.run', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['ran' => array_keys($steps), 'count' => array_sum(array_column($steps, 'count')), 'failed' => count($report['errors']), 'seconds' => $report['seconds']]]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($steps, JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('doccloud_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (each its slice's) ------------------------------------------------------------------------------------------

/** SLICE 2 — upload sessions older than upload_session_hours: their parts removed, the row expired (app/features/files/worker_steps.php). */
function worker_pass_upload_sessions_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_uploads_expire($pdo); }
/** SLICE 2 — text extraction for pending versions (app/doccloud/extract/), document_text written; the triggers index the document and queue its graph job. */
function worker_pass_extract_text(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_extract($pdo, min($limit, 50)); }
/** SLICE 2 (and docs/build-specs/grid-and-media.md) — thumbnails for images (GD), PDFs' first page (pdftoppm) and videos (ffmpeg), with a video's length. */
function worker_pass_thumbnails(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_thumbs($pdo, min($limit, 50)); }
/** SLICE 4 — dc_search_catch_up(): every document whose index row is older than its change. */
/** SLICE 4 — the keyword index's catch-up: documents whose row is missing or older than the document (dc_search_catch_up), 500 a pass. */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_search_catch_up($pdo, max($limit, 500)); }
/** SLICE 5 — the graph jobs, one document at a time under the monthly budget (app/doccloud/graph.php): index, full, replace, forget. */
function worker_pass_graph_jobs(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_graph($pdo, min($limit, 10), 50); }
/** SLICE 6 — versions past the window (versions_keep_days, then the last versions_keep_count) purged; the current always kept. */
function worker_pass_versions_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_versions_expire($pdo, max($limit, 1000)); }
/** SLICE 6 — documents in the trash longer than trash_days purged (versions, text, the index row, the graph's document forgotten). */
function worker_pass_trash_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_trash_purge($pdo, max($limit, 500)); }
/** SLICE 6 — blobs whose ref_count reached zero: the file unlinked, the row marked deleted. */
function worker_pass_blobs_free(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_blobs_free($pdo, max($limit, 500)); }
/** SLICE 3 — links past expires_at logged link.expire once (the database already refuses them; app/features/links/worker_steps.php). */
function worker_pass_links_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_links_expire($pdo, $limit); }
/** SLICE 3 — file requests past their expiry revoked, the maker told once. */
function worker_pass_requests_close(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_requests_close($pdo, $limit); }
/** SLICE 2 (the lock UI is there) — locks past lock_expires_at released. */
function worker_pass_locks_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_locks_expire($pdo); }
/** SLICE 2 — queued exports built (a zip of what the maker may see); expired exports' files removed. */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_exports($pdo, 5) + worker_exports_expire($pdo); }
/** SLICE 6 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel's K6, three attempts with backoff; at most 50 emails and 20 texts a pass. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_outbox($pdo); }
/** SLICE 6 — the housekeeping: old proposals, search log, link opens and sent mail pruned. */
function worker_pass_housekeeping(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_housekeeping($pdo); }
/** SLICE 8 — agent dispatches: the triggers (40 untagged in a folder, uploads on a request, a file kept five times) → one chat turn each as the expert (ask_assistant), three attempts an interval apart. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_dispatches($pdo, min($limit, 10)); }
/** SLICE 6 — libraries at 90% of their quota: their owners told once a month (quota_near). */
function worker_pass_quota_watch(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_quota_watch($pdo); }
/** SLICE 8 — the Clerk's Monday store report without a model: from Monday 07:00 (org time) dc_clerk_report() → proposals, the Monday notes; once an ISO week. */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_proposals($pdo, $now); }
/** SLICE 7 — dc_prune_key_usage(): minute buckets older than a day, day buckets older than 35 days. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['pruned' => (int) one_value($pdo, 'SELECT dc_prune_key_usage()')]; }
