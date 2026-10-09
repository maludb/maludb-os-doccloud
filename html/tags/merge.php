<?php
declare(strict_types=1);
/** Action `tag_merge` (tag — the one that goes, into — the one that stays; its slug becomes an alias): tags.manage; a confirm; log tag.merge on the survivor. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.manage');
$pdo = db();
$me = (int) current_member_id();
$from = tag_from_request($pdo, 'tag');
$intoWord = req_val('into') ?? '';
$into = $intoWord === '' ? null : (ctype_digit($intoWord) ? find_tag($pdo, (int) $intoWord) : tag_by_word($pdo, $intoWord));
if ($into === null) {
    dc_refuse_fields(['into' => 'Say which tag it merges into.']);
}
if ((int) $into['tag_id'] === (int) $from['tag_id']) {
    dc_refuse_fields(['into' => 'A tag is not merged into itself.']);
}
$moved = dc_guard($pdo, static function () use ($pdo, $from, $into, $me): int {
    $pdo->beginTransaction();
    $n = tag_merge($pdo, (int) $from['tag_id'], (int) $into['tag_id'], $me);
    files_log($pdo, 'tag.merge', 'tag', (int) $into['tag_id'], ['before' => ['from' => $from['slug'], 'from_id' => (int) $from['tag_id']], 'after' => ['into' => $into['slug'], 'into_id' => (int) $into['tag_id'], 'documents_moved' => $n, 'alias' => $from['slug']]]);
    $pdo->commit();
    return $n;
});
dc_done('Merged ' . $from['label'] . ' into ' . $into['label'] . ' (' . $moved . ' document' . ($moved === 1 ? '' : 's') . ' moved; ' . $from['slug'] . ' kept as an alias)', (int) $into['tag_id'], dc_land(return_path('/tags/' . (int) $into['tag_id']), 'merged'), 'tagsChanged', ['moved' => $moved, 'alias' => $from['slug']]);
