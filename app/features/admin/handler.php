<?php
declare(strict_types=1);

/** Slice 6's admin prelude: the files prelude (library_from_request …), the quotas' and the worker's reads and writes. */
require_once dirname(__DIR__) . '/files/handler.php';
require_once dirname(__DIR__) . '/links/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/present.php';

const WORKER_QUEUE_LABELS = ['outbox_queued' => 'Mail and texts waiting', 'outbox_failed' => 'Mail and texts that failed', 'graph_queued' => 'Graph jobs queued', 'uploads_open' => 'Uploads open',
    'exports_queued' => 'Zips being made', 'blobs_to_free' => 'Files to free', 'trash_due' => 'Trash past its days', 'locks_stale' => 'Locks past their time', 'key_buckets_old' => 'Key counters to prune'];

/** The bounds of the four windows the retention form edits: key => [label, min, max, unit]. */
const RETENTION_BOUNDS = ['versions_keep_days' => ['Keep every version for', 1, 3650, 'days'], 'versions_keep_count' => ['Then keep the last', 1, 1000, 'versions (the current one always)'],
    'trash_days' => ['The trash keeps things for', 1, 3650, 'days'], 'lock_hours' => ['A lock lasts', 1, 720, 'hours']];

/**
 * The settings form's fields (slice 9; db/005's columns and their CHECK ranges): key => [group, type, label, min, max, unit]. `settings` needs settings.manage, `limits` quotas.manage. A `bytes` field is
 * sent as <name> (bytes) or as the same name with _mb for megabytes (a number, decimals allowed) — the form uses megabytes. A `ceiling` is a `bytes` field that may be emptied (blank or 0 = none; db/024).
 * `bool` is yes/no, `enum` one of the list in `max`, `tz` an identifier from PHP's list.
 */
const SETTINGS_FIELDS = [
    'business_name' => ['settings', 'text', 'The business name', 0, 120, 'characters'],
    'timezone' => ['settings', 'tz', 'The time zone', 0, 0, ''],
    'link_default_days' => ['settings', 'int', 'A link\'s default days', 1, 3650, 'days'],
    'link_no_expiry_allowed' => ['settings', 'bool', 'Links that never expire', 0, 0, ''],
    'free_tag_proposal_threshold' => ['settings', 'int', 'Free tag proposal threshold', 1, 100, 'documents'],
    'stale_guest_share_days' => ['settings', 'int', 'Days before a guest share is stale', 1, 3650, 'days'],
    'graph_default_policy' => ['settings', 'enum', 'The graph\'s default policy', 0, ['none', 'index', 'full'], ''],
    'graph_text_chars' => ['settings', 'int', 'Characters the index policy remembers', 1000, 200000, 'characters'],
    'max_document_bytes' => ['limits', 'ceiling', 'The largest file', 1048576, null, 'MB'],
    'max_text_bytes' => ['limits', 'bytes', 'The most text read from a file', 65536, 1073741824, 'MB'],
    'upload_part_bytes' => ['limits', 'bytes', 'The size of an upload part', 1048576, 67108864, 'MB'],
    'upload_session_hours' => ['limits', 'int', 'An abandoned upload is dropped after', 1, 168, 'hours'],
    'key_rate_per_minute' => ['limits', 'int', 'A key\'s calls a minute', 1, 100000, 'calls'],
    'key_rate_per_day' => ['limits', 'int', 'A key\'s calls a day', 1, 10000000, 'calls'],
    'key_rotation_overlap_hours' => ['limits', 'int', 'The overlap when a key is rotated', 0, 168, 'hours'],
    'link_opens_per_minute' => ['limits', 'int', 'Link opens a minute from one address', 1, 100000, 'opens'],
    'request_uploads_per_hour' => ['limits', 'int', 'File request uploads an hour from one address', 1, 100000, 'uploads'],
];
