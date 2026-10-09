<?php
declare(strict_types=1);
/** Action `folder_delete` (folder, confirm_name; log folder.delete): a TRASHED folder with its subtree, purged now (every document under it released, the graph forgetting them). trash.purge (the admin); pauses for an agent (deletion). */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
dc_handler_begin();
require_right('trash.purge');
$pdo = db();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
$id = (int) $folder['id'];
if ($folder['trashed_at'] === null) {
    refuse(422, 'Only a folder in the trash is deleted for good; send it to the trash first.');
}
if (trim((string) (req_val('confirm_name') ?? '')) !== $folder['name']) {
    dc_refuse_fields(['confirm_name' => 'Type the folder\'s name (' . $folder['name'] . ') to delete it for good.']);
}
[$docs, $folders] = dc_guard($pdo, static function () use ($pdo, $folder, $id): array {
    $pdo->beginTransaction();
    $r = folder_purge($pdo, $id);
    files_log($pdo, 'folder.delete', 'folder', $id, ['folder_id' => $id, 'library_id' => (int) $folder['library_id'], 'after' => ['name' => $folder['name'], 'documents' => $r[0], 'folders' => $r[1]]]);
    $pdo->commit();
    return $r;
});
dc_done('Deleted ' . $folder['name'] . ' for good', $id, dc_land(return_path('/trash/'), 'purged'), 'folderChanged', ['documents' => $docs, 'folders' => $folders]);
