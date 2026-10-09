<?php
declare(strict_types=1);
/** Action `tag_restore` (tag): tags.manage, people only; log tag.restore. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.manage');
$pdo = db();
$tag = tag_from_request($pdo, 'tag');
$id = (int) $tag['tag_id'];
dc_guard($pdo, static function () use ($pdo, $id, $tag): void {
    $pdo->beginTransaction();
    tag_restore($pdo, $id);
    files_log($pdo, 'tag.restore', 'tag', $id, ['after' => ['slug' => $tag['slug']]]);
    $pdo->commit();
});
dc_done('Restored the tag ' . $tag['label'], $id, dc_land(return_path('/tags/' . $id), 'restored'), 'tagsChanged');
