<?php
declare(strict_types=1);

/** Slice 6's admin prelude: the files prelude (library_from_request …), the quotas' and the worker's reads and writes. */
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/present.php';

const WORKER_QUEUE_LABELS = ['outbox_queued' => 'Mail and texts waiting', 'outbox_failed' => 'Mail and texts that failed', 'graph_queued' => 'Graph jobs queued', 'uploads_open' => 'Uploads open',
    'exports_queued' => 'Zips being made', 'blobs_to_free' => 'Files to free', 'trash_due' => 'Trash past its days', 'locks_stale' => 'Locks past their time'];

/** The bounds of the four windows the retention form edits: key => [label, min, max, unit]. */
const RETENTION_BOUNDS = ['versions_keep_days' => ['Keep every version for', 1, 3650, 'days'], 'versions_keep_count' => ['Then keep the last', 1, 1000, 'versions (the current one always)'],
    'trash_days' => ['The trash keeps things for', 1, 3650, 'days'], 'lock_hours' => ['A lock lasts', 1, 720, 'hours']];
