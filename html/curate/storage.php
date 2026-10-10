<?php
declare(strict_types=1);
/**
 * Storage report (screen `curate-storage`, GET /curate/storage?library=): storage by library with bars, by owner, the duplicates and the bytes they waste, the disk beside the sum of the blobs, and a CSV
 * export (storage_export). reports.storage (curators). Logged report.storage. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/curate/handler.php';
require_login();
require_right('reports.storage');
$pdo = db();
$r = storage_report($pdo);
log_activity($pdo, 'report.storage', 'report', null, ['screen' => 'curate-storage', 'after' => ['libraries' => count($r['libraries']), 'duplicates' => count($r['duplicates'])]]);
if (wants_json()) {
    respond_screen(['libraries' => array_map(static fn (array $l): array => ['library_id' => (int) $l['library_id'], 'kind' => $l['kind'], 'name' => $l['name'], 'used_bytes' => (int) $l['used_bytes'],
            'quota_bytes' => $l['quota_bytes'] === null ? null : (int) $l['quota_bytes'], 'pct' => $l['pct'] === null ? null : (float) $l['pct'], 'documents' => (int) $l['documents'], 'versions' => (int) $l['versions']], $r['libraries']),
        'owners' => array_map(static fn (array $o): array => ['member_id' => (int) $o['member_id'], 'display_name' => $o['display_name'], 'documents' => (int) $o['documents'], 'live_bytes' => (int) $o['live_bytes']], $r['owners']),
        'duplicates' => array_map(static fn (array $d): array => ['copies' => (int) $d['copies'], 'byte_size' => (int) $d['byte_size'], 'wasted_bytes' => (int) $d['wasted'], 'names' => pg_text_array((string) $d['names']), 'document_ids' => pg_int_array((string) $d['document_ids'])], $r['duplicates']),
        'wasted_bytes' => $r['wasted_bytes'], 'totals' => $r['totals'], 'disk' => array_diff_key($r['disk'], ['directory' => 1])]);
}
render_screen('Storage report', view('curate/storage.php', ['r' => $r, 'focus' => request_integer('library')]), ['activeNav' => 'curate', 'screen' => 'curate-storage']);
