<?php
declare(strict_types=1);
/** Action `favourite_add` (document or folder, one of them; log favourite.add): star a thing the caller may see. Any admitted member (a guest too, on what is shared with them). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$docId = request_integer('document');
$folderId = request_integer('folder');
if (($docId === null) === ($folderId === null)) {
    refuse(422, 'Say a document or a folder, one of them.');
}
$thing = $docId !== null ? document_at_level($pdo, $docId, 'view') : folder_at_level($pdo, $folderId, 'view');
$added = favourite_add($pdo, $me, $docId, $folderId);
if ($added) {
    files_log($pdo, 'favourite.add', $docId !== null ? 'document' : 'folder', (int) $thing['id'], ['library_id' => (int) $thing['library_id'], 'document_id' => $docId, 'folder_id' => $folderId ?? ($docId !== null ? (int) $thing['folder_id'] : null), 'after' => ['name' => $thing['name']]]);
}
dc_done(($added ? 'Added ' : 'Already a favourite: ') . $thing['name'], (int) $thing['id'], dc_land(return_path($docId !== null ? '/documents/' . $docId : '/f/' . $folderId), 'starred'), 'favouriteChanged');
