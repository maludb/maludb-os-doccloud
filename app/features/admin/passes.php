<?php
declare(strict_types=1);

/**
 * The worker's passes, in the order they run (bin/worker.php) and as the admin's "Run a pass now" names them. A new pass is a name here and a function
 * worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array in bin/worker.php. Data-owner rule: uploads_expire, extract_text, thumbnails, exports, locks_expire slice 2;
 * links_expire, requests_close slice 3; search_catch_up slice 4; graph_jobs slice 5; versions_expire, trash_purge, blobs_free, quota_watch, outbox, housekeeping slice 6;
 * dispatches, proposals slice 8; key_usage_prune slice 7.
 */
const WORKER_PASSES = ['upload_sessions_expire', 'extract_text', 'thumbnails', 'search_catch_up', 'graph_jobs', 'exports', 'versions_expire', 'trash_purge', 'blobs_free',
    'links_expire', 'requests_close', 'locks_expire', 'quota_watch', 'outbox', 'housekeeping', 'dispatches', 'proposals', 'key_usage_prune'];
