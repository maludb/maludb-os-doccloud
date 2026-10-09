<?php
declare(strict_types=1);
/** Action `favourite_remove` (document or folder; log favourite.remove): one's own star. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$docId = request_integer('document');
$folderId = request_integer('folder');
if (($docId === null) === ($folderId === null)) {
    refuse(422, 'Say a document or a folder, one of them.');
}
$removed = favourite_remove($pdo, $me, $docId, $folderId);
if (!$removed) {
    refuse(404, 'Not a favourite of yours.');
}
files_log($pdo, 'favourite.remove', $docId !== null ? 'document' : 'folder', (int) ($docId ?? $folderId), ['document_id' => $docId, 'folder_id' => $folderId]);
dc_done('Removed from your favourites', (int) ($docId ?? $folderId), dc_land(return_path('/favourites'), 'unstarred'), 'favouriteChanged');
