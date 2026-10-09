<?php
declare(strict_types=1);
/**
 * Action `graph_policy_set` (library or folder — one of them; graph_policy: none, index, full, or inherit for a folder): the row changed, the subtree requeued
 * or forgotten (dc_graph_requeue_*); the admin, or a manager of the library for none/index (no model spend); pauses for an agent (other). Over the month's
 * budget a `full` needs confirm=full. Logged graph.policy_set with the counts.
 */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$kind = request_integer('folder') !== null ? 'folder' : (request_integer('library') !== null ? 'library' : null);
if ($kind === null) {
    dc_refuse_fields(['library' => 'Say which library or folder.']);
}
$policy = strtolower((string) (req_val('graph_policy') ?? ''));
$allowed = $kind === 'folder' ? ['none', 'index', 'full', 'inherit'] : ['none', 'index', 'full'];
if (!in_array($policy, $allowed, true)) {
    dc_refuse_fields(['graph_policy' => 'The policy is none, index or full' . ($kind === 'folder' ? ' (or inherit for a folder)' : '') . '.']);
}
if ($kind === 'folder') {
    $f = folder_at_level($pdo, request_integer('folder'), 'manage');
    $libraryId = (int) $f['library_id'];
    $id = (int) $f['id'];
    $name = $f['name'];
    $before = $f['graph_policy'] ?? 'inherit';
    $estimateFolder = $id;
} else {
    $l = library_from_request($pdo, 'library');
    $libraryId = (int) $l['id'];
    $id = $libraryId;
    $name = $l['name'];
    $before = $l['graph_policy'];
    $estimateFolder = (int) $l['root_folder_id'];
}
if (!may_set_policy($pdo, $libraryId)) {
    refuse(403, 'Only the library\'s managers and the graph admin set a policy.');
}
if ($policy === 'full' && !has_right('graph.manage')) {
    refuse(403, 'Only the graph admin may choose full: it spends the model budget.');
}
$estimate = $policy === 'full' ? graph_estimate($pdo, $estimateFolder) : null;
if ($estimate !== null && $estimate['over_budget'] && strtolower((string) (req_val('confirm') ?? '')) !== 'full') {
    dc_refuse_fields(['confirm' => 'Over this month\'s budget: ' . estimate_sentence($estimate) . ' Type "full" to confirm.']);
}
$queued = dc_guard($pdo, static function () use ($pdo, $kind, $id, $policy, $before, $name, $libraryId, $me, $estimate): int {
    $pdo->beginTransaction();
    $n = graph_policy_set($pdo, $kind, $id, $policy === 'inherit' ? null : $policy);
    log_activity($pdo, 'graph.policy_set', $kind, $id, ['library_id' => $libraryId, 'folder_id' => $kind === 'folder' ? $id : null, 'before' => ['graph_policy' => $before],
        'after' => ['graph_policy' => $policy, 'queued' => $n, 'name' => $name, 'estimate' => $estimate === null ? null : ['documents' => $estimate['documents'], 'chars' => $estimate['chars'], 'over_budget' => $estimate['over_budget']]]]);
    $pdo->commit();
    return $n;
});
dc_done('Set the graph policy of ' . $name . ' to ' . $policy . ($queued > 0 ? ' (' . $queued . ' document' . ($queued === 1 ? '' : 's') . ' queued)' : ''), $id,
    dc_land(return_path($kind === 'folder' ? '/f/' . $id : '/libraries/' . $id), 'policy'), 'graphChanged', ['queued' => $queued, 'graph_policy' => $policy]);
