<?php /** The proposals (screen `tag-proposals`). Data: proposals, status, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'tag-proposals', 'title' => 'Tag proposals', 'crumbs' => [['Home', '/'], ['Tags', '/tags'], ['Proposals', null]], 'back' => ['/tags', 'tags']]) ?>
<div class="main-content" id="tag-proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-3" id="proposals-tabs"><?php foreach (['proposed' => 'Open', 'accepted' => 'Accepted', 'dismissed' => 'Dismissed'] as $s => $w): ?><?= hx_link('/tags/proposals?status=' . $s, $w, 'btn btn-touch ' . ($status === $s ? 'btn-primary' : 'btn-light'), 'id="proposals-tab-' . $s . '"') ?><?php endforeach; ?></div>
    <div class="card"><div class="table-responsive"><table class="table mb-0 fs-12" id="proposals-list"><tbody>
        <?php if ($proposals === []): ?><tr id="proposals-empty"><td class="text-muted">Nothing <?= e($status) ?>.</td></tr><?php endif; ?>
        <?php foreach ($proposals as $p): ?><?= view('tags/partials/proposal-row.php', ['p' => $p, 'tz' => $tz, 'decided' => $status !== 'proposed']) ?><?php endforeach; ?>
    </tbody></table></div></div>
</div>
