<?php
declare(strict_types=1);
/** Action `export_folder` (folder): a zip of its subtree the worker builds, ready within the minute, kept 7 days; export.own and view on the folder. Log folder.export; lands on Exports. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('export.own');
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'view');
$exportId = dc_guard($pdo, static function () use ($pdo, $folder, $me): int {
    $id = export_make($pdo, 'folder', (int) $folder['id'], [], $me);
    files_log($pdo, 'folder.export', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['export_id' => $id, 'name' => $folder['name']]]);
    return $id;
});
dc_done('The zip of ' . $folder['name'] . ' is being made', $exportId, dc_land('/exports', 'queued'), 'exportsChanged', ['export_id' => $exportId]);
