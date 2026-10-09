<?php
declare(strict_types=1);
/** Action `document_lock` (document): lock for the caller for lock_hours (dc_document_lock); edit on the document; held by another → 409 in words. Log document.lock. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('documents.edit');
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'edit');
$id = (int) $doc['id'];
if (($holder = lock_held_by_another($pdo, $id, $me)) !== null) {
    $until = (string) one_value($pdo, 'SELECT lock_expires_at FROM documents WHERE id = :id', ['id' => $id]);
    refuse(409, 'Locked by ' . $holder . ' until ' . format_ts($until, member_timezone()) . '.');
}
dc_guard($pdo, static function () use ($pdo, $doc, $id, $me): void {
    if (!document_lock($pdo, $id, $me)) {
        throw new DomainException('The document could not be locked.');
    }
    files_log($pdo, 'document.lock', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name']]]);
});
$until = (string) one_value($pdo, 'SELECT lock_expires_at FROM documents WHERE id = :id', ['id' => $id]);
dc_done('Locked ' . $doc['name'] . ' for you until ' . format_ts($until, member_timezone()), $id, dc_land(return_path('/documents/' . $id), 'locked'), 'documentChanged', ['lock_expires_at' => json_ts($until)]);
