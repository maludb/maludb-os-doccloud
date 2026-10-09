<?php
declare(strict_types=1);
/** Action `tag_proposal_decide` (tag_proposal, decision accept|dismiss): tags.manage (an apply: edit on the document); log tag.accept / tag.dismiss (the manifest's tag.decide). */
require_once dirname(__DIR__, 3) . '/app/features/tags/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('tag_proposal') ?? request_integer('id');
if ($id === null) {
    refuse(422, 'Say which proposal.');
}
$p = find_proposal($pdo, $id) ?? dc_not_found('Not found.');
$decision = strtolower((string) (req_val('decision') ?? ''));
if (!in_array($decision, ['accept', 'dismiss'], true)) {
    dc_refuse_fields(['decision' => 'Say accept or dismiss.']);
}
if ($p['kind'] === 'apply') {
    document_at_level($pdo, (int) $p['document_id'], 'edit');
    if (!has_right('tags.manage') && !has_right('tags.apply')) { refuse(403, 'You may not decide this.'); }
} else {
    require_right('tags.manage');
}
if ($p['status'] !== 'proposed') {
    refuse(422, 'That proposal was already decided.');
}
$r = dc_guard($pdo, static function () use ($pdo, $id, $decision, $me, $p): array {
    $pdo->beginTransaction();
    $r = $decision === 'accept' ? proposal_accept($pdo, $id, $me) : proposal_dismiss($pdo, $id, $me);
    files_log($pdo, 'tag.decide', 'tag_proposal', $id, ['document_id' => $r['document_id'], 'after' => ['decision' => $decision, 'kind' => $p['kind'], 'tag' => $p['tag_slug'], 'did' => $r['did']]]);
    $pdo->commit();
    return $r;
});
$word = ['promoted' => 'Promoted ' . $p['tag_label'] . ' to the vocabulary', 'applied' => 'Applied ' . $p['tag_label'], 'merged' => 'Merged ' . $p['tag_label'] . ' into ' . ($p['into_label'] ?? ''), 'dismissed' => 'Dismissed the proposal for ' . $p['tag_label']][$r['did']] ?? 'Decided';
dc_done($word, $id, dc_land(return_path('/tags/proposals'), $decision === 'accept' ? 'accepted' : 'dismissed'), 'tagsChanged', ['outcome' => $r['did']]);
