<?php
declare(strict_types=1);
/**
 * The disk (screen `admin-storage`, GET /admin/storage): the truth beside the database — the blobs and their bytes (live, waiting to be freed, freed), the libraries' used bytes against them (what
 * de-duplication saved), the storage directory's state (dc_storage_state) and the disk's free space. settings.manage. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/curate/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
$d = storage_disk_truth($pdo);
log_activity($pdo, 'screen.view', 'storage', null, ['screen' => 'admin-storage']);
if (wants_json()) {
    respond_screen(['state' => $d['state'], 'free_bytes' => $d['free_bytes'], 'total_bytes' => $d['total_bytes'], 'blobs' => ['live' => $d['blobs_live'], 'live_bytes' => $d['blobs_live_bytes'], 'waiting' => $d['blobs_waiting'], 'waiting_bytes' => $d['blobs_waiting_bytes'],
        'freed' => $d['blobs_freed'], 'freed_bytes' => $d['blobs_freed_bytes']], 'libraries_used_bytes' => $d['libraries_used_bytes'], 'deduplicated_bytes' => $d['deduplicated_bytes']]);
}
render_screen('The disk', view('admin/storage.php', ['d' => $d]), ['activeNav' => 'admin', 'screen' => 'admin-storage']);
