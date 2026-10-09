<?php
declare(strict_types=1);
/** Action `graph_job_skip` (graph_job — a queued or failed one): skipped, the document marked current so the catch-up stops offering it; graph.manage, people only. Logged graph.skip. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_human();
require_right('graph.manage');
$pdo = db();
$j = job_from_request($pdo);
if (!in_array($j['status'], ['queued', 'failed'], true)) {
    refuse(422, 'Only a queued or failed job is skipped (this one is ' . $j['status'] . ').');
}
dc_guard($pdo, static function () use ($pdo, $j): void {
    $pdo->beginTransaction();
    graph_job_skip($pdo, (int) $j['id'], 'skipped by ' . (int) current_member_id());
    log_activity($pdo, 'graph.skip', 'graph_job', (int) $j['id'], ['document_id' => $j['document_id'] === null ? null : (int) $j['document_id'], 'after' => ['kind' => $j['kind'], 'reason' => 'by hand']]);
    $pdo->commit();
});
dc_done('Skipped the job', (int) $j['id'], dc_land('/graph/admin', 'skipped', 'graph-queue'), 'graphChanged');
