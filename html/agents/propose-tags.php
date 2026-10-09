<?php
declare(strict_types=1);
/**
 * Action `tags_propose_request` (folder; log agent.dispatch): "Ask the expert to propose tags for this folder's untagged documents" — queues ONE `untagged_batch` dispatch the worker sends to the kernel's
 * chat endpoint as the expert, acting for the person asking. edit on the folder; a person's (the expert is not asked by an agent). Once an hour per folder.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
dc_handler_begin();
require_human();
require_right('tags.apply');
$pdo = db();
$me = (int) current_member_id();
$folder = folder_from_request($pdo, 'edit');
$fid = (int) $folder['id'];
if (agent_settings($pdo)['expert_member_id'] === null) {
    refuse(422, 'No expert has been named yet: the admin names one on the Agents screen.');
}
$untagged = (int) one_value($pdo, "SELECT count(*) FROM documents d WHERE d.folder_id = :f AND d.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = d.id)", ['f' => $fid]);
if ($untagged === 0) {
    refuse(422, 'Every document in this folder already has tags.');
}
$id = dc_guard($pdo, static function () use ($pdo, $fid, $me, $untagged, $folder): ?int {
    $pdo->beginTransaction();
    $id = dispatch_make($pdo, 'untagged_batch', ['folder_id' => $fid, 'acting_member_id' => $me, 'detail' => $untagged . ' untagged, asked for', 'once_within' => '1 hour']);
    if ($id !== null) {
        files_log($pdo, 'agent.dispatch', 'agent_dispatch', $id, ['folder_id' => $fid, 'library_id' => (int) $folder['library_id'], 'after' => ['kind' => 'untagged_batch', 'untagged' => $untagged, 'asked_by' => $me]]);
    }
    $pdo->commit();
    return $id;
});
if ($id === null) {
    refuse(422, 'The expert is already on it (or was asked within the hour).');
}
dc_done('Asked the expert to propose tags for ' . $untagged . ' document' . ($untagged === 1 ? '' : 's') . ' in ' . $folder['name'] . ' (it answers within a minute or two)', $id, dc_land(return_path('/folders/' . $fid), 'asked'), 'agentChanged', ['untagged' => $untagged]);
