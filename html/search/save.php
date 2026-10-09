<?php
declare(strict_types=1);
/** Action `saved_search_save` (name, query, filters — a JSON object, kind keyword|graph, is_shared, saved_search to change mine): search.use; log search.save. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('search.use');
$pdo = db();
$me = (int) current_member_id();
$name = (string) (req_val('name') ?? '');
$errors = [];
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Name the search (up to 80 characters).';
}
$filters = [];
if (req_has('filters') && req_val('filters') !== '') {
    $filters = json_decode((string) req_val('filters'), true);
    if (!is_array($filters)) {
        $errors['filters'] = 'The filters are a JSON object.';
        $filters = [];
    }
}
$kind = req_val('kind') ?? 'keyword';
if (!in_array($kind, ['keyword', 'graph'], true)) {
    $errors['kind'] = 'A search is keyword or graph.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$f = ['name' => $name, 'query' => (string) (req_val('query') ?? req_val('q') ?? ''), 'filters' => $filters, 'kind' => $kind, 'is_shared' => dc_yes('is_shared'), 'saved_search' => request_integer('saved_search')];
$id = dc_guard($pdo, static function () use ($pdo, $f, $me): int {
    $pdo->beginTransaction();
    $id = saved_search_save($pdo, $f, $me);
    files_log($pdo, 'search.save', 'saved_search', $id, ['after' => ['name' => $f['name'], 'query' => mb_substr($f['query'], 0, 120), 'filters' => $f['filters'], 'kind' => $f['kind'], 'is_shared' => $f['is_shared']]]);
    $pdo->commit();
    return $id;
});
dc_done('Saved the search ' . $name, $id, dc_land(return_path('/search/saved'), 'saved'), 'searchesChanged');
