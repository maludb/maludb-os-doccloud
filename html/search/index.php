<?php
declare(strict_types=1);
/**
 * Search (screen `search`, GET /search?q=…&library=&folder=&owner=&tag=&kind=&after=&before=&mine=&shared_with_me=&page=): the typed modifiers parsed, the
 * explicit filters merged, dc_search (the index first, then the batch wall), the results with marked excerpts, "Did you mean" from the names, "Try the graph"
 * under three results, save this search; every search logged search.keyword with the query cut at 120. A search is a GET, never an action.
 */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
require_right('search.use');
$pdo = db();
$typed = trim(request_string('q'));
$page = request_integer('page') ?? 1;
$explicit = array_intersect_key($_GET, array_flip(['library', 'folder', 'owner', 'tag', 'kind', 'before', 'after', 'mine', 'shared_with_me', 'min_bytes', 'max_bytes']));
$asked = $typed !== '' || $explicit !== [];
$result = $asked ? search($pdo, $typed, $page, $explicit) : ['q' => '', 'typed' => '', 'filters' => [], 'rows' => [], 'total' => 0, 'capped' => false, 'page' => 1, 'pages' => 1, 'took_ms' => 0, 'names' => []];
if ($asked) {
    search_log_write($pdo, 'keyword', $typed !== '' ? $typed : json_encode($result['filters']), $result['filters'], $result['total'], $result['took_ms']);
    log_activity($pdo, 'search.keyword', 'search', null, ['screen' => 'search', 'after' => ['query' => mb_substr($typed, 0, 120), 'filters' => $result['filters'], 'results' => $result['total'], 'took_ms' => $result['took_ms'], 'page' => $result['page']]]);
} else {
    log_activity($pdo, 'screen.view', 'search', null, ['screen' => 'search']);
}
$chips = search_chips($pdo, $result['q'], $result['filters']);
$saved = my_saved_searches($pdo);
if (wants_json()) {
    respond_screen(['search' => present_search($result), 'chips' => $chips, 'asked' => $asked, 'saved' => array_map('present_saved_search', $saved), 'graph_url' => '/graph/?' . http_build_query(['q' => $result['q']])]);
}
$notices = ['saved' => ['success', 'Saved: find it under saved searches.']];
render_screen($asked ? 'Search: ' . ($typed !== '' ? $typed : 'filters') : 'Search', view('search/index.php', ['s' => $result, 'chips' => $chips, 'asked' => $asked, 'saved' => $saved, 'tz' => member_timezone(),
    'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'stars' => my_favourite_ids($pdo)]), ['activeNav' => 'search', 'screen' => 'search']);
