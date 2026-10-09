<?php
declare(strict_types=1);
/** A PATH between two subjects (screen `graph-path`, GET /graph/path?from=&to=): the chain of chips, the documents on the way judged by the wall (a hidden one shown as such). */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
require_login();
require_right('graph.use');
$pdo = db();
$a = request_integer('from') ?? request_integer('a');
$b = request_integer('to') ?? request_integer('b');
$asked = $a !== null && $b !== null && $a > 0 && $b > 0;
$path = $asked ? subject_path($pdo, $a, $b, 6) : ['status' => graph_engine_up() ? 'no_query' : 'graph_unavailable', 'steps' => [], 'found' => false];
$labels = ['from' => $a !== null && $a > 0 ? (subject_label($a) ?? ('#' . $a)) : null, 'to' => $b !== null && $b > 0 ? (subject_label($b) ?? ('#' . $b)) : null];
log_activity($pdo, 'screen.view', 'graph', null, ['screen' => 'graph-path', 'after' => ['asked' => $asked, 'found' => $path['found']]]);
if (wants_json()) {
    respond_screen(['from' => $a, 'to' => $b, 'labels' => $labels, 'status' => $path['status'], 'found' => $path['found'], 'steps' => $path['steps'], 'sentence' => graph_status_sentence($path['status'] === 'no_query' ? 'ok' : $path['status'])]);
}
render_screen('A path in the graph', view('graph/path.php', ['route' => $path, 'a' => $a, 'b' => $b, 'labels' => $labels, 'asked' => $asked]), ['activeNav' => 'graph', 'screen' => 'graph-path']);
