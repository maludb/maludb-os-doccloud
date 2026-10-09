<?php
declare(strict_types=1);
/**
 * Action `clerk_proposal_decide` (proposal, decision accept | dismiss, tags[] — which of a `tags` proposal's suggestions, default all —, note; log clerk.decide): the person a proposal is for decides it, or a
 * holder of groups.manage. A proposal the caller cannot see is "Not found." (404); one they see but may not decide is a 403. Accepting `tags` applies the chosen tags to the document through
 * dc_tag_apply as the decider (needs edit on it and tags.apply), source agent; every other kind's accept records the decision and lands on the thing to act on — nothing is deleted or shared by it.
 */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('proposal') ?? request_integer('id');
if ($id === null) {
    refuse(422, 'Say which proposal.');
}
$p = find_clerk_proposal($pdo, $id) ?? dc_not_found('Not found.');
$decision = strtolower((string) (req_val('decision') ?? ''));
if (!in_array($decision, ['accept', 'dismiss'], true)) {
    dc_refuse_fields(['decision' => 'Say accept or dismiss.']);
}
if ((int) ($p['for_member_id'] ?? 0) !== $me && !has_right('groups.manage')) {
    refuse(403, 'The Clerk proposes; the person it is for, or a curator, decides.');
}
if ($p['status'] !== 'proposed') {
    refuse(422, 'That proposal was already decided.');
}
$choice = request_list('tags') ?? [];
if ($decision === 'accept' && $p['kind'] === 'tags' && $p['document_id'] !== null) {
    require_right('tags.apply');
    document_at_level($pdo, (int) $p['document_id'], 'edit');
}
$r = dc_guard($pdo, static function () use ($pdo, $id, $decision, $me, $choice): array {
    $pdo->beginTransaction();
    $r = clerk_proposal_decide($pdo, $id, $decision, $me, $choice);
    $pdo->commit();
    return $r;
});
$note = req_val('note');
log_activity($pdo, 'clerk.decide', 'clerk_proposal', $id, ['document_id' => $r['document_id'], 'folder_id' => $p['folder_id'], 'library_id' => $p['library_id'],
    'after' => ['decision' => $decision, 'kind' => $p['kind'], 'applied' => $r['applied'], 'for_member_id' => $p['for_member_id'], 'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 200)]]);
$land = $decision === 'accept' && $p['kind'] !== 'tags' ? proposal_land($p) : return_path('/curate/proposals');
dc_done($decision === 'accept' ? ($p['kind'] === 'tags' ? 'Applied ' . $r['applied'] . ' tag' . ($r['applied'] === 1 ? '' : 's') : 'Accepted') : 'Dismissed', $id,
    dc_land($land, $decision === 'accept' ? 'accepted' : 'dismissed'), 'proposalChanged', ['decision' => $decision, 'kind' => $p['kind'], 'applied' => $r['applied']]);
