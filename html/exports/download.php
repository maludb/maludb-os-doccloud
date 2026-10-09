<?php
declare(strict_types=1);
/** GET /exports/{id} — the zip (the maker, or the admin): streamed as an attachment; downloaded_at set; logged export.download. A link holder's zip is slice 3's door. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$e = find_export($pdo, request_integer('id') ?? 0);
if ($e === null) {
    refuse(404, 'Not found.');
}
if (empty($e['available']) || $e['status'] !== 'done') {
    refuse(404, $e['status'] === 'done' ? 'This zip expired.' : 'The zip is not ready yet.');
}
$rel = (string) one_value($pdo, 'SELECT storage_path FROM exports WHERE id = :id', ['id' => (int) $e['export_id']]);
$pdo->prepare('UPDATE exports SET downloaded_at = now() WHERE id = :id')->execute(['id' => (int) $e['export_id']]);
log_activity($pdo, 'export.download', 'export', (int) $e['export_id'], ['folder_id' => $e['folder_id'] === null ? null : (int) $e['folder_id'], 'after' => ['bytes' => (int) $e['byte_size'], 'items' => (int) $e['item_count']]]);
$name = ($e['kind'] === 'folder' && !empty($e['folder_name']) ? $e['folder_name'] : 'documents') . '-' . (int) $e['export_id'] . '.zip';
store_stream($rel, ['filename' => $name, 'mime' => 'application/zip', 'inline' => false, 'sha256' => 'export-' . (int) $e['export_id'] . '-' . (int) $e['byte_size'], 'byte_size' => (int) $e['byte_size']]);
