<?php
declare(strict_types=1);
/** Action `tag_archive` (tag — kept on its documents, hidden from the pickers): tags.manage, people only; log tag.archive. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.manage');
$pdo = db();
$tag = tag_from_request($pdo, 'tag');
$id = (int) $tag['tag_id'];
dc_guard($pdo, static function () use ($pdo, $id, $tag): void {
    $pdo->beginTransaction();
    tag_archive($pdo, $id);
    files_log($pdo, 'tag.archive', 'tag', $id, ['after' => ['slug' => $tag['slug'], 'usage_count' => (int) $tag['usage_count']]]);
    $pdo->commit();
});
dc_done('Archived the tag ' . $tag['label'], $id, dc_land(return_path('/tags/' . $id), 'archived'), 'tagsChanged');
