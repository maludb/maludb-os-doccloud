<?php
declare(strict_types=1);
/**
 * EXPLORE (screen `graph-explore`, GET /graph/explore?library=&subject=): the subjects by degree — each shown only with the count of its documents the caller
 * may see — a subject search (the picker's source, inline), surprises (empty until MaluDB computes communities, M6), the policy and the queue state.
 */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
require_login();
require_right('graph.use');
$pdo = db();
$libraryId = request_integer('library');
$library = $libraryId === null ? null : find_library($pdo, $libraryId);
$nodes = god_nodes($pdo, $library === null ? null : (int) $library['library_id'], 25);
$surprises = graph_engine_up() ? dc_graph_surprises(10) : ['status' => 'graph_unavailable'];
$state = graph_state($pdo);
log_activity($pdo, 'screen.view', 'graph', null, ['screen' => 'graph-explore', 'library_id' => $libraryId]);
$libs = my_libraries($pdo);
if (wants_json()) {
    respond_screen(['available' => $state['available'], 'library' => $library === null ? null : ['library_id' => (int) $library['library_id'], 'name' => $library['name']], 'subjects' => $nodes['subjects'], 'status' => $nodes['status'],
        'surprises' => array_values($surprises['surprises'] ?? []), 'sentence' => graph_status_sentence($nodes['status']), 'libraries' => array_map(static fn (array $l): array => ['library_id' => (int) $l['library_id'], 'name' => $l['name']], $libs),
        'state' => ['by_policy' => $state['by_policy'], 'queued' => $state['queued'], 'budget' => present_budget($state['budget'])]]);
}
render_screen('Explore the graph', view('graph/explore.php', ['nodes' => $nodes, 'surprises' => $surprises, 'library' => $library, 'libraries' => $libs, 'state' => $state]), ['activeNav' => 'graph', 'screen' => 'graph-explore']);
