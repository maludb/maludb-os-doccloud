<?php
declare(strict_types=1);
/** Action `tag_propose` (document, tag — a slug or label, note): view on the document (the Clerk's and the expert's proposal to apply a tag; a person may too); log tag.propose. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.apply');
$pdo = db();
$me = (int) current_member_id();
$doc = document_from_request($pdo, 'view');
$word = trim((string) (req_val('tag') ?? ''));
if ($word === '' || mb_strlen($word) > 50) {
    dc_refuse_fields(['tag' => 'Say which tag (up to 50 characters).']);
}
$note = req_val('note');
if ($note !== null && mb_strlen($note) > 500) {
    dc_refuse_fields(['note' => 'The note is up to 500 characters.']);
}
$pid = dc_guard($pdo, static function () use ($pdo, $doc, $word, $note, $me): int {
    $pdo->beginTransaction();
    $id = tag_propose($pdo, 'apply', ['tag' => $word, 'document_id' => (int) $doc['id'], 'note' => $note === '' ? null : $note], $me);
    $p = find_proposal($pdo, $id);
    files_log($pdo, 'tag.propose', 'tag_proposal', $id, ['document_id' => (int) $doc['id'], 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['tag' => $p['tag_slug'] ?? $word, 'kind' => 'apply']]);
    $pdo->commit();
    return $id;
});
dc_done('Proposed the tag ' . $word . ' for ' . $doc['name'], $pid, dc_land(return_path('/documents/' . (int) $doc['id']), 'proposed', 'document-tags'), 'tagsChanged');
