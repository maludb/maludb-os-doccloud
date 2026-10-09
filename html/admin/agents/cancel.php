<?php
declare(strict_types=1);
/** Action `dispatch_cancel` (dispatch — one still sent, waiting for an approval or failed; log agent.dispatch_cancel): closed as refused with your name, nothing is sent again. agents.settings (the admin); a person's, not an agent's. */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
dc_handler_begin();
require_human();
require_right('agents.settings');
$pdo = db();
$id = request_integer('dispatch') ?? request_integer('id');
$d = $id === null ? null : find_dispatch($pdo, $id);
if ($d === null) {
    $id === null ? refuse(422, 'Say which dispatch.') : dc_not_found('Not found.');
}
if (!in_array($d['status'], ['sent', 'awaiting_approval', 'failed'], true)) {
    refuse(422, 'Only a dispatch not yet answered can be cancelled; this one is ' . str_replace('_', ' ', $d['status']) . '.');
}
$name = (string) (current_member()['display_name'] ?? 'an admin');
dc_guard($pdo, static function () use ($pdo, $id, $d, $name): void {
    $pdo->beginTransaction();
    dispatch_cancel($pdo, $id, $name);
    log_activity($pdo, 'agent.dispatch_cancel', 'agent_dispatch', $id, ['folder_id' => $d['folder_id'] === null ? null : (int) $d['folder_id'], 'document_id' => $d['document_id'] === null ? null : (int) $d['document_id'], 'after' => ['kind' => $d['kind'], 'was' => $d['status']]]);
    $pdo->commit();
});
dc_done('Cancelled: nothing is sent again', $id, dc_land(return_path('/admin/agents'), 'cancelled', 'dispatches-table'), 'agentChanged');
