<?php
declare(strict_types=1);
/** Action `tag_delete` (tag — only one no document carries; an in-use tag is archived instead): tags.manage; a confirm; pauses for an agent (deletion); log tag.delete. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.manage');
$pdo = db();
$tag = tag_from_request($pdo, 'tag');
$id = (int) $tag['tag_id'];
dc_guard($pdo, static function () use ($pdo, $id, $tag): void {
    $pdo->beginTransaction();
    tag_delete($pdo, $id);
    files_log($pdo, 'tag.delete', 'tag', $id, ['before' => ['slug' => $tag['slug'], 'label' => $tag['label'], 'kind' => $tag['kind']]]);
    $pdo->commit();
});
dc_done('Deleted the tag ' . $tag['label'], $id, dc_land(return_path('/tags/'), 'deleted'), 'tagsChanged');
