<?php
declare(strict_types=1);
/** Action `document_unlock` (document): one's own lock dropped (dc_document_unlock); another's is broken by a manager through lock-break.php. Log document.unlock. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'view');
$id = (int) $doc['id'];
if ($doc['locked_by'] === null) {
    refuse(422, 'It is not locked.');
}
if ((int) $doc['locked_by'] !== $me) {
    refuse(409, 'Locked by someone else; a manager of the folder can break it.');
}
dc_guard($pdo, static function () use ($pdo, $doc, $id, $me): void {
    document_unlock($pdo, $id, $me, false);
    files_log($pdo, 'document.unlock', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name']]]);
});
dc_done('Unlocked ' . $doc['name'], $id, dc_land(return_path('/documents/' . $id), 'unlocked'), 'documentChanged');
