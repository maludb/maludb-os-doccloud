<?php
declare(strict_types=1);
/**
 * Action `ownership_transfer` (owner — whose documents —, new_owner — an admitted person —, library — only this library's; log document.transfer with the count): a leaver's (or anyone's) documents
 * go to someone. ownership.transfer (curators); pauses for an agent (deletion). The new owner is told `ownership_received`. Folders are not moved: the SQL function moves documents.
 */
require_once dirname(__DIR__, 3) . '/app/features/curate/handler.php';
dc_handler_begin();
require_right('ownership.transfer');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$from = dc_ref($pdo, 'owner', null, 'SELECT 1 FROM members WHERE id = :id', 'person whose documents move', $errors, false);
$to = dc_ref($pdo, 'new_owner', null, 'SELECT 1 FROM members WHERE id = :id', 'new owner', $errors, false);
$libraryId = dc_ref($pdo, 'library', null, 'SELECT 1 FROM libraries WHERE id = :id', 'library', $errors, true);
if (!req_has('owner')) {
    $errors['owner'] = 'Say whose documents move.';
}
if (!req_has('new_owner')) {
    $errors['new_owner'] = 'Say who receives them.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
if ($from === $to) {
    dc_refuse_fields(['new_owner' => 'Choose someone other than the person whose documents move.']);
}
$holdings = owner_holdings($pdo, $from, $libraryId);
if ($holdings['documents'] === 0) {
    dc_refuse_fields(['owner' => 'That person owns no documents' . ($libraryId !== null ? ' in that library' : '') . '.']);
}
$moved = dc_guard($pdo, static function () use ($pdo, $from, $to, $libraryId, $me, $holdings): int {
    $pdo->beginTransaction();
    $n = ownership_transfer($pdo, $from, $to, $libraryId, $me);
    log_activity($pdo, 'document.transfer', 'member', $to, ['library_id' => $libraryId, 'before' => ['owner_member_id' => $from], 'after' => ['owner_member_id' => $to, 'documents' => $n, 'bytes' => $holdings['bytes'], 'library_id' => $libraryId]]);
    $was = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $from]);
    notify($pdo, $to, 'ownership_received', $n . ' document' . ($n === 1 ? '' : 's') . ' of ' . $was . ' are yours now', 'They were handed to you' . ($libraryId !== null ? ' from one library' : '') . '. Find them under My files and Recent.',
        ['record_type' => 'member', 'record_id' => $to, 'actor' => $me]);
    $pdo->commit();
    return $n;
});
dc_done('Handed ' . $moved . ' document' . ($moved === 1 ? '' : 's') . ' to the new owner', $to, dc_land(return_path('/curate/ownership'), 'transferred', 'ownership-form'), 'ownershipChanged', ['documents' => $moved, 'from' => $from, 'to' => $to]);
