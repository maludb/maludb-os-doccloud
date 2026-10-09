<?php
declare(strict_types=1);
/** Action `saved_search_delete` (saved_search — mine): log search.delete. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('search.use');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('saved_search') ?? request_integer('id');
if ($id === null) {
    refuse(422, 'Say which saved search.');
}
$s = find_saved_search($pdo, $id);
if ($s === null || !$s['mine']) {
    dc_not_found('Not found.');
}
dc_guard($pdo, static function () use ($pdo, $id, $me, $s): void {
    $pdo->beginTransaction();
    saved_search_delete($pdo, $id, $me);
    files_log($pdo, 'search.delete', 'saved_search', $id, ['before' => ['name' => $s['name'], 'query' => mb_substr((string) $s['query'], 0, 120)]]);
    $pdo->commit();
});
dc_done('Deleted the saved search ' . $s['name'], $id, dc_land(return_path('/search/saved'), 'deleted'), 'searchesChanged');
