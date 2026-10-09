<?php
declare(strict_types=1);
/** Action `dispatch_retry` (dispatch — a failed or refused one; log agent.retry): back into the queue, the attempts start again; the next worker pass sends it. agents.settings (the admin). */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
dc_handler_begin();
require_right('agents.settings');
$pdo = db();
$id = request_integer('dispatch') ?? request_integer('id');
$d = $id === null ? null : find_dispatch($pdo, $id);
if ($d === null) {
    $id === null ? refuse(422, 'Say which dispatch.') : dc_not_found('Not found.');
}
if (!in_array($d['status'], ['failed', 'refused'], true)) {
    refuse(422, 'Only a failed or refused dispatch can be tried again; this one is ' . str_replace('_', ' ', $d['status']) . '.');
}
dc_guard($pdo, static function () use ($pdo, $id, $d): void {
    $pdo->beginTransaction();
    dispatch_retry($pdo, $id);
    log_activity($pdo, 'agent.retry', 'agent_dispatch', $id, ['folder_id' => $d['folder_id'] === null ? null : (int) $d['folder_id'], 'document_id' => $d['document_id'] === null ? null : (int) $d['document_id'], 'after' => ['kind' => $d['kind'], 'was' => $d['status']]]);
    $pdo->commit();
});
dc_done('Queued again: the next pass sends it', $id, dc_land(return_path('/admin/agents'), 'retried', 'dispatches-table'), 'agentChanged');
