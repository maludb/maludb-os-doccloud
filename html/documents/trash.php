<?php
declare(strict_types=1);
/** Action `document_trash` (document; log document.trash): to the trash — 30 days to restore; its lock goes with it. trash.own and manage on the document (the owner always manages). */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
dc_handler_begin();
require_right('trash.own');
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'manage');
$id = (int) $doc['id'];
if ($doc['trashed_at'] !== null) {
    refuse(422, 'It is already in the trash.');
}
dc_guard($pdo, static function () use ($pdo, $doc, $id, $me): void {
    $pdo->beginTransaction();
    document_trash($pdo, $id, $me);
    files_log($pdo, 'document.trash', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'from_folder_id' => (int) $doc['folder_id']]]);
    $pdo->commit();
});
dc_done('Sent ' . $doc['name'] . ' to the trash', $id, dc_land(return_path('/f/' . (int) $doc['folder_id']), 'trashed'), 'documentChanged', ['document_id' => $id]);
