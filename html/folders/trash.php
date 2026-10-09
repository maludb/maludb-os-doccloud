<?php
declare(strict_types=1);
/** Action `folder_trash` (folder; log folder.trash): the subtree goes with it — 30 days to restore (slice 6's trash screen lists it). manage on the folder. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
if ($folder['parent_id'] === null) {
    refuse(422, 'A library\'s root is never trashed.');
}
$n = dc_guard($pdo, static function () use ($pdo, $folder, $me): int {
    $pdo->beginTransaction();
    $n = folder_trash($pdo, (int) $folder['id'], $me);
    files_log($pdo, 'folder.trash', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['name' => $folder['name'], 'folders' => $n, 'parent_id' => (int) $folder['parent_id']]]);
    $pdo->commit();
    return $n;
});
dc_done('Sent ' . $folder['name'] . ' to the trash', (int) $folder['id'], dc_land('/f/' . (int) $folder['parent_id'], 'trashed'), 'folderChanged', ['folders' => $n]);
