<?php
declare(strict_types=1);
/**
 * Trash (screen `trash`, GET /trash/?folder=): MY trash — what I own, trashed or manage — as the listing table: name, kind, from folder, trashed when and by, "purges on" with its chip
 * (more than 7 days secondary, 7 or fewer warning, due danger); Restore for everyone who may, Purge and "Empty what is due" for the admin (trash.purge). A folder's trash
 * (/f/{id}/trash, ?folder=) is for its managers. Slice 6.
 */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
require_login();
require_right('trash.own');
$pdo = db();
$folder = null;
$folderId = request_integer('folder');
if ($folderId !== null) {
    $folder = folder_at_level($pdo, $folderId, 'manage');
}
$rows = my_trash($pdo, $folderId);
$mayPurge = has_right('trash.purge');
$window = trash_window_days($pdo);
$dueCount = count(array_filter($rows, static fn (array $r): bool => trash_status($r['purge_at'])[0] === 'danger'));
log_activity($pdo, 'screen.view', 'trash', null, ['screen' => 'trash', 'folder_id' => $folder === null ? null : (int) $folder['id'], 'library_id' => $folder === null ? null : (int) $folder['library_id']]);
if (wants_json()) {
    respond_screen(['rows' => array_map('present_trash_row', $rows), 'folder' => $folder === null ? null : ['folder_id' => (int) $folder['id'], 'name' => $folder['name']], 'window_days' => $window, 'due' => $dueCount,
        'may' => ['restore' => true, 'purge' => $mayPurge, 'empty' => $mayPurge]]);
}
$notices = ['purged' => ['success', 'Deleted for good.'], 'emptied' => ['success', 'The trash was emptied.'], 'restored' => ['success', 'Restored.'], 'untrashed' => ['success', 'Restored.'], 'restored_root' => ['success', 'Restored to the library\'s top folder: its folder is gone.']];
render_screen($folder === null ? 'Trash' : 'Trash: ' . $folder['name'], view('trash/index.php', ['rows' => $rows, 'folder' => $folder, 'mayPurge' => $mayPurge, 'window' => $window, 'due' => $dueCount, 'tz' => member_timezone(),
    'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'trash', 'screen' => 'trash', 'entity' => 'trash']);
