<?php
declare(strict_types=1);
/** Action `folder_auto_tags_set` (folder, auto_tags[] — slugs applied to every document added under it; empty to clear; log folder.auto_tags): manage on the folder. Slice 4 makes the vocabulary real; the slugs are stored now. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
$slugs = [];
foreach (request_list('auto_tags') ?? [] as $t) {
    $slug = (string) one_value($pdo, 'SELECT dc_slugify(:t)', ['t' => $t]);
    if ($slug === '') {
        dc_refuse_fields(['auto_tags' => 'A tag is letters, digits and dashes.']);
    }
    $slugs[] = $slug;
}
$slugs = array_values(array_unique($slugs));
$before = pg_text_array((string) $folder['auto_tags']);
dc_guard($pdo, static function () use ($pdo, $folder, $slugs, $before): void {
    $pdo->beginTransaction();
    folder_auto_tags_set($pdo, (int) $folder['id'], $slugs);
    files_log($pdo, 'folder.auto_tags', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'before' => ['auto_tags' => $before], 'after' => ['auto_tags' => $slugs]]);
    $pdo->commit();
});
dc_done($slugs === [] ? 'Cleared the folder\'s auto-tags' : 'Set the folder\'s auto-tags: ' . implode(', ', $slugs), (int) $folder['id'], dc_land(return_path('/f/' . (int) $folder['id']), 'updated'), 'folderChanged', ['auto_tags' => $slugs]);
