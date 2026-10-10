<?php
declare(strict_types=1);
/**
 * Action `note_add` (document or folder — one of them —, body up to 4000 characters; log note.add): a comment on the thing. View on it is enough (design §3: "see, preview, download, comment" — a guest
 * comments on the document shared with them). The owner is told `comment` unless they wrote it. The body is never in the log, only its length. Undo: note_delete.
 */
require_once dirname(__DIR__, 2) . '/app/features/notes/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$documentId = request_integer('document');
$folderId = request_integer('folder');
if (($documentId === null) === ($folderId === null)) {
    dc_refuse_fields(['document' => 'Say which document (or folder) the comment is on.']);
}
$body = trim((string) req_val('body'));
if ($body === '') {
    dc_refuse_fields(['body' => 'Write the comment.']);
}
if (mb_strlen($body) > NOTE_MAX_CHARS) {
    dc_refuse_fields(['body' => 'A comment is at most ' . NOTE_MAX_CHARS . ' characters.']);
}
if ($documentId !== null) {
    $thing = document_at_level($pdo, $documentId, 'view');
    if ($thing['trashed_at'] !== null) {
        refuse(422, 'A document in the trash cannot be commented on; restore it first.');
    }
    $kind = 'document';
    $id = $documentId;
    $land = '/documents/' . $id;
} else {
    $thing = folder_at_level($pdo, $folderId, 'view');
    $kind = 'folder';
    $id = $folderId;
    $land = '/f/' . $id;
}
$noteId = dc_guard($pdo, static function () use ($pdo, $kind, $id, $body, $me, $thing): int {
    $pdo->beginTransaction();
    $n = note_add($pdo, $kind, $id, $body, $me);
    log_activity($pdo, 'note.add', $kind, $id, ['library_id' => (int) $thing['library_id'], 'after' => ['note_id' => $n, 'chars' => mb_strlen($body), 'name' => $thing['name']]]);
    $owner = $thing['owner_member_id'] ?? null;
    if ($owner !== null && (int) $owner !== $me) {
        $who = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $me]);
        notify($pdo, (int) $owner, 'comment', $who . ' commented on ' . $thing['name'], mb_substr($body, 0, 300),
            ['record_type' => $kind, 'record_id' => $id, 'document_id' => $kind === 'document' ? $id : null, 'folder_id' => $kind === 'folder' ? $id : null, 'actor' => $me]);
    }
    $pdo->commit();
    return $n;
});
dc_done('Added the comment', $noteId, dc_land(return_path($land), 'commented', 'document-comments'), 'notesChanged', ['note_id' => $noteId, 'undo' => 'note_delete']);
