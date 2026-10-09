<?php
declare(strict_types=1);
/** The queue's state as a partial the admin screen polls every 5 s while visible (graph.manage): counts, the running job, the budget bar. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
require_login();
require_right('graph.manage');
$pdo = db();
$state = graph_state($pdo);
$running = $pdo->query("SELECT j.*, d.name AS document_name FROM graph_jobs j LEFT JOIN documents d ON d.id = j.document_id WHERE j.status = 'running' ORDER BY j.started_at LIMIT 3")->fetchAll();
if (wants_json()) {
    respond_screen(['state' => ['queued' => $state['queued'], 'running' => $state['running'], 'failed' => $state['failed'], 'skipped' => $state['skipped'], 'done' => $state['done'], 'available' => $state['available']], 'budget' => present_budget($state['budget']), 'running_jobs' => array_map('present_job', $running)]);
}
header('Cache-Control: no-store');
echo view('graph/partials/queue-state.php', ['state' => $state, 'running' => $running]);
