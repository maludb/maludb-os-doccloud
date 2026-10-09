<?php
declare(strict_types=1);
/** Action `folder_restore` (folder; log folder.restore): back from the trash with what went with it; a vanished parent lands it at the root. manage on the folder, or trash.own by the one who trashed it. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('folder') ?? request_integer('id');
if ($id === null) {
    refuse(422, 'Say which folder.');
}
$st = $pdo->prepare('SELECT * FROM folders WHERE id = :id');
$st->execute(['id' => $id]);
$folder = $st->fetch();
if ($folder === false || $folder['trashed_at'] === null) {
    dc_not_found('Not found.');
}
$level = dc_folder_level($pdo, $id);
if (!dc_level_at_least($level, 'manage') && !((int) $folder['trashed_by'] === $me && has_right('trash.own'))) {
    dc_not_found('Not found.');
}
$n = dc_guard($pdo, static function () use ($pdo, $folder, $id): int {
    $pdo->beginTransaction();
    $n = folder_restore($pdo, $id);
    files_log($pdo, 'folder.restore', 'folder', $id, ['folder_id' => $id, 'library_id' => (int) $folder['library_id'], 'after' => ['name' => $folder['name'], 'folders' => $n]]);
    $pdo->commit();
    return $n;
});
dc_done('Restored ' . $folder['name'], $id, dc_land('/f/' . $id, 'restored'), 'folderChanged', ['folders' => $n]);
