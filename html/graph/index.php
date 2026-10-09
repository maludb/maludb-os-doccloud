<?php
declare(strict_types=1);
/**
 * The document graph's FIND (screen `graph`, GET /graph/?q=&library=&folder=&tag=&kind=&owner=&mine=): the box, the same chips as the search page, the results
 * from dc_graph_find() — name, folder crumb, the matched subjects as chips, the best passage from DocCloud's own text — "N found of M candidates", the policy
 * and the queue state in a footer; graph_unavailable → one sentence and the keyword search link. A find is a GET, never an action (D4: the expert runs it
 * only when asked; the search page's "Try the graph" is the person asking). Logged search.graph without the words.
 */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
require_login();
require_right('graph.use');
$pdo = db();
$typed = trim(request_string('q'));
$parsed = search_parse($pdo, $typed);
$filters = search_filters_from_query($pdo, $_GET, $parsed['filters']);
$filters = array_intersect_key($filters, array_flip(['library', 'folder', 'tag', 'kind', 'owner', 'mine']));
$q = $parsed['q'];
$asked = $typed !== '';
$find = $asked ? graph_find($pdo, $q, $filters, GRAPH_FIND_PAGE) : ['status' => 'no_query', 'query' => '', 'filters' => $filters, 'subjects' => [], 'rows' => [], 'total' => 0, 'candidates' => 0, 'took_ms' => 0];
if (!$asked) {
    log_activity($pdo, 'screen.view', 'graph', null, ['screen' => 'graph']);
}
$state = graph_state($pdo);
$chips = search_chips($pdo, $q, $filters);
// search_chips() builds the keyword search's URLs; on this page a chip's × re-runs the graph find without it
foreach ($chips as &$c) {
    if (str_starts_with((string) $c['without'], '/search')) {
        $c['without'] = '/graph/' . substr((string) $c['without'], strlen('/search'));
    }
}
unset($c);
if (wants_json()) {
    respond_screen(['find' => present_graph_find($find), 'chips' => $chips, 'asked' => $asked, 'available' => $state['available'], 'state' => ['by_policy' => $state['by_policy'], 'queued' => $state['queued'], 'running' => $state['running'], 'failed' => $state['failed'], 'budget' => present_budget($state['budget'])],
        'search_url' => '/search?' . http_build_query(['q' => $q])]);
}
render_screen($asked ? 'Graph: ' . $typed : 'Graph', view('graph/index.php', ['f' => $find, 'typed' => $typed, 'q' => $q, 'chips' => $chips, 'asked' => $asked, 'state' => $state, 'tz' => member_timezone(), 'may' => ['manage' => has_right('graph.manage')]]),
    ['activeNav' => 'graph', 'screen' => 'graph']);
