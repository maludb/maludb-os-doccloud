<?php
declare(strict_types=1);
/** Action `note_delete` (note; log note.delete): a comment goes. Its author may; so may a manager of the thing; anyone else who can see the comment is refused (403), anyone who cannot, "Not found." (404). */
require_once dirname(__DIR__, 2) . '/app/features/notes/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$noteId = request_integer('note') ?? request_integer('id');
if ($noteId === null || $noteId <= 0) {
    dc_refuse_fields(['note' => 'Say which comment.']);
}
$n = find_note($pdo, $noteId);                                     // mcp_notes holds the wall: a comment on a thing the caller cannot see does not exist
if ($n === null) {
    dc_not_found('Not found.');
}
$kind = (string) $n['record_type'];
$thing = $kind === 'document' ? document_at_level($pdo, (int) $n['record_id'], 'view') : folder_at_level($pdo, (int) $n['record_id'], 'view');
$own = $n['member_id'] !== null && (int) $n['member_id'] === $me;
if (!$own && !dc_level_at_least((string) $thing['my_level'], 'manage')) {
    refuse(403, 'Only the author of a comment, or a manager of the ' . $kind . ', deletes it.');
}
dc_guard($pdo, static function () use ($pdo, $noteId, $kind, $thing, $n, $me): void {
    $pdo->beginTransaction();
    if (!note_delete($pdo, $noteId)) {
        throw new DomainException('Not found.');
    }
    log_activity($pdo, 'note.delete', $kind, (int) $n['record_id'], ['library_id' => (int) $thing['library_id'], 'after' => ['note_id' => $noteId, 'author_member_id' => $n['member_id'] === null ? null : (int) $n['member_id'], 'by_author' => $n['member_id'] !== null && (int) $n['member_id'] === $me]]);
    $pdo->commit();
});
dc_done('Deleted the comment', $noteId, dc_land(return_path(($kind === 'document' ? '/documents/' : '/f/') . (int) $n['record_id']), 'comment_deleted', 'document-comments'), 'notesChanged');
