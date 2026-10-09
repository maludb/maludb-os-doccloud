<?php
declare(strict_types=1);
/** Action `graph_job_retry` (graph_job — a failed one): queued again with attempts 0; graph.manage. Logged graph.retry. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_right('graph.manage');
$pdo = db();
$j = job_from_request($pdo);
if ($j['status'] !== 'failed') {
    refuse(422, 'Only a failed job is retried (this one is ' . $j['status'] . ').');
}
dc_guard($pdo, static function () use ($pdo, $j): void {
    $pdo->beginTransaction();
    graph_job_retry($pdo, (int) $j['id']);
    log_activity($pdo, 'graph.retry', 'graph_job', (int) $j['id'], ['document_id' => $j['document_id'] === null ? null : (int) $j['document_id'], 'after' => ['kind' => $j['kind'], 'was' => $j['error']]]);
    $pdo->commit();
});
dc_done('Queued the job again', (int) $j['id'], dc_land('/graph/admin', 'retried', 'graph-queue'), 'graphChanged');
