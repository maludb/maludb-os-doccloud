<?php
declare(strict_types=1);

/**
 * The DocCloud worker — one pass a minute from doccloud-worker.timer (design §14): upload-session expiry, text extraction and thumbnails,
 * the keyword index catch-up, the graph jobs under the budget, the versions and trash windows, blob freeing, link and lock expiry, exports,
 * the outbox (email through MaluMail, texts through the kernel's K6), dispatches to agents, quotas, proposals, key-usage pruning. Idempotent;
 * advisory-locked so two runs never overlap; each pass its own try (one failing never stops the next); the report is printed as one JSON
 * line, logged once as `worker.pass` (counts and sentences, never a document's text, a token or a path) and kept as a worker_passes row
 * when the table exists (db/013).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. DC_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in (design §10):
 * `upload_sessions_expire`, `extract_text`, `thumbnails` slice 2's; `search_catch_up` slice 4's; `graph_jobs` slice 5's; `versions_expire`,
 * `trash_purge`, `blobs_free`, `locks_expire`, `quotas` slice 6's; `links_expire`, `requests_close` slice 3's; `exports` slice 2's (zip);
 * `outbox`, `dispatches`, `proposals` slice 8's; `key_usage_prune` slice 7's.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/files/handler.php';          // the store, the gate, the file modules (slice 2)
require_once dirname(__DIR__) . '/app/features/files/worker_steps.php';
require_once dirname(__DIR__) . '/app/features/links/worker_steps.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['upload_sessions_expire', 'extract_text', 'thumbnails', 'search_catch_up', 'graph_jobs', 'versions_expire', 'trash_purge', 'blobs_free',
    'links_expire', 'requests_close', 'locks_expire', 'exports', 'outbox', 'dispatches', 'quotas', 'proposals', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
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
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('doccloud_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 2 — upload sessions older than upload_session_hours: their parts removed, the row expired (app/features/files/worker_steps.php). */
function worker_pass_upload_sessions_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_uploads_expire($pdo); }
/** SLICE 2 — text extraction for pending versions (app/doccloud/extract/), document_text written; the triggers index the document and queue its graph job. */
function worker_pass_extract_text(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_extract($pdo, min($limit, 50)); }
/** SLICE 2 — thumbnails for images (GD) and PDFs' first page (pdftoppm). */
function worker_pass_thumbnails(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_thumbs($pdo, min($limit, 50)); }
/** SLICE 4 — dc_search_catch_up(): every document whose index row is older than its change. */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['indexed' => 0, 'stub' => true]; }
/** SLICE 5 — the graph jobs, one document at a time under the monthly budget (app/doccloud/graph.php): index, full, replace, forget. */
function worker_pass_graph_jobs(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['done' => 0, 'failed' => 0, 'deferred' => 0, 'stub' => true]; }
/** SLICE 6 — versions past the window (versions_keep_days, then the last versions_keep_count) purged; the current always kept. */
function worker_pass_versions_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => true]; }
/** SLICE 6 — documents in the trash longer than trash_days purged (versions, text, the index row, the graph's document forgotten). */
function worker_pass_trash_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => true]; }
/** SLICE 6 — blobs whose ref_count reached zero: the file unlinked, the row marked deleted. */
function worker_pass_blobs_free(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['freed' => 0, 'stub' => true]; }
/** SLICE 3 — links past expires_at logged link.expire once (the database already refuses them; app/features/links/worker_steps.php). */
function worker_pass_links_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_links_expire($pdo, $limit); }
/** SLICE 3 — file requests past their expiry revoked, the maker told once. */
function worker_pass_requests_close(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_requests_close($pdo, $limit); }
/** SLICE 2 (the lock UI is there) — locks past lock_expires_at released. */
function worker_pass_locks_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_locks_expire($pdo); }
/** SLICE 2 — queued exports built (a zip of what the maker may see); expired exports' files removed. */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array { return worker_exports($pdo, 5) + worker_exports_expire($pdo); }
/** SLICE 8 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel (kernel_send_text), retries with backoff. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'stub' => true]; }
/** SLICE 8 — agent dispatches: a proposal needing a hand → one chat turn (ask_assistant), at most three attempts. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['dispatched' => 0, 'retried' => 0, 'stub' => true]; }
/** SLICE 6 — libraries near their quota noticed once (quota_near). */
function worker_pass_quotas(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['noticed' => 0, 'stub' => true]; }
/** SLICE 8 — the Clerk's standing proposals the worker can make without a model (a free tag used by three people, a link opened from many addresses). */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['proposed' => 0, 'stub' => true]; }
/** SLICE 7 — dc_prune_key_usage(): minute buckets older than a day, day buckets older than 35 days. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['pruned' => 0, 'stub' => true]; }
