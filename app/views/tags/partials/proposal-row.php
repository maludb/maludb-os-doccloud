<?php /** One proposal row. Data: p (open_proposals row), tz, decided (bool) */
$pid = (int) $p['tag_proposal_id'];
$sentence = match ($p['kind']) {
    'promote' => $p['tag_label'] . ' — ' . (int) ($p['people'] ?? 0) . ' people use it: promote it to the vocabulary?',
    'merge' => 'merge ' . $p['tag_label'] . ' into ' . ($p['into_label'] ?? '?'),
    default => 'apply ' . $p['tag_label'] . ' to ' . ($p['document_name'] ?? 'a document'),
};
$b = PROPOSAL_BADGE[$p['status']] ?? 'secondary';
?>
<tr id="proposal-<?= $pid ?>" data-kind="<?= e($p['kind']) ?>" data-status="<?= e($p['status']) ?>">
    <td><span class="badge bg-soft-<?= e($b) ?> text-<?= e($b) ?> me-1"><?= e($p['kind']) ?></span><?= e($sentence) ?><?php if (!empty($p['note'])): ?><div class="fs-11 text-muted"><?= e($p['note']) ?></div><?php endif; ?>
        <div class="fs-11 text-muted"><?= $p['proposed_by_name'] !== null ? 'proposed by ' . e($p['proposed_by_name']) : 'proposed by the rule' ?> · <?= e(format_ts($p['created_at'], $tz, 'M j, Y')) ?><?= $p['document_id'] !== null ? ' · ' . hx_link('/documents/' . (int) $p['document_id'], 'the document') : '' ?></div></td>
    <td class="text-end text-nowrap">
        <?php if ($p['status'] === 'proposed'): ?>
        <form method="post" action="/tags/proposals/decide.php" hx-post="/tags/proposals/decide.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag_proposal" value="<?= $pid ?>"><input type="hidden" name="decision" value="accept"><input type="hidden" name="return_to" value="/tags/proposals"><button type="submit" class="btn btn-primary btn-sm" id="proposal-accept-<?= $pid ?>"><?= $p['kind'] === 'promote' ? 'Promote' : 'Accept' ?></button></form>
        <form method="post" action="/tags/proposals/decide.php" hx-post="/tags/proposals/decide.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag_proposal" value="<?= $pid ?>"><input type="hidden" name="decision" value="dismiss"><input type="hidden" name="return_to" value="/tags/proposals"><button type="submit" class="btn btn-light btn-sm" id="proposal-dismiss-<?= $pid ?>">Dismiss</button></form>
        <?php else: ?><span class="fs-11 text-muted"><?= e($p['status']) ?> by <?= e($p['decided_by_name'] ?? '') ?></span><?php endif; ?>
    </td>
</tr>
