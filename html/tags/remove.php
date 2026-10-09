<?php
declare(strict_types=1);
/** Action `tag_remove` (document, tags[] — slugs, labels or ids): tags.apply and edit on the document; log tag.remove. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.apply');
$pdo = db();
$doc = document_from_request($pdo, 'edit');
$words = request_list('tags') ?? [];
if (req_has('tag') && req_val('tag') !== '') {
    $words[] = (string) req_val('tag');
}
if ($words === []) {
    dc_refuse_fields(['tags' => 'Say which tag.']);
}
$removed = dc_guard($pdo, static function () use ($pdo, $doc, $words): array {
    $pdo->beginTransaction();
    $out = [];
    foreach ($words as $w) {
        $t = ctype_digit($w) ? find_tag($pdo, (int) $w) : tag_by_word($pdo, $w);
        if ($t === null) { continue; }
        if (tag_remove($pdo, (int) $doc['id'], (int) $t['tag_id'])) {
            $out[] = $t['slug'];
            files_log($pdo, 'tag.remove', 'document', (int) $doc['id'], ['document_id' => (int) $doc['id'], 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'before' => ['tag' => $t['slug'], 'tag_id' => (int) $t['tag_id']]]);
        }
    }
    $pdo->commit();
    return $out;
});
dc_done($removed === [] ? 'That tag was not on the document' : 'Removed ' . implode(', ', $removed) . ' from ' . $doc['name'], (int) $doc['id'], dc_land(return_path('/documents/' . (int) $doc['id']), 'untagged', 'document-tags'), 'tagsChanged', ['removed' => $removed]);
