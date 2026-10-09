<?php /** The proposals (screen `proposals`). Data: proposals, status, kind, mine, counts, me, curator, tz, notice */
$tabs = PROPOSAL_STATUS_WORDS;
$q = static fn (array $over): string => '/curate/proposals' . (($p = http_build_query(array_filter(array_merge(['status' => $status === 'proposed' ? null : $status, 'kind' => $kind ?: null, 'mine' => $mine ? 1 : null], $over), static fn ($v) => $v !== null && $v !== ''))) === '' ? '' : '?' . $p);
?>
<?= view('shared/header.php', ['id' => 'proposals', 'title' => 'Proposals', 'crumbs' => [['Home', '/'], ['Proposals', null]]]) ?>
<div class="main-content" id="proposals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <p class="fs-12 text-muted mb-2" id="proposals-intro">The Filing Clerk reads the store every Monday and leaves what needs a hand. It proposes; you decide. Nothing is deleted, shared or changed until you say so.</p>
    <div class="d-flex flex-wrap gap-1 mb-2" id="proposals-tabs">
        <?php foreach ($tabs as $s => $w): ?><?= hx_link($q(['status' => $s === 'proposed' ? null : $s]), e($w), 'btn btn-touch ' . ($status === $s ? 'btn-primary' : 'btn-light'), 'id="proposals-tab-' . e($s) . '"') ?><?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3 align-items-center" id="proposals-filters">
        <?= hx_link($q(['kind' => null]), 'Every kind', 'btn btn-sm ' . ($kind === '' ? 'btn-secondary' : 'btn-light'), 'id="proposals-kind-all"') ?>
        <?php foreach (PROPOSAL_KIND_WORDS as $k => $w): ?><?= hx_link($q(['kind' => $k]), e($w), 'btn btn-sm ' . ($kind === $k ? 'btn-secondary' : 'btn-light'), 'id="proposals-kind-' . e($k) . '"') ?><?php endforeach; ?>
        <?php if ($curator): ?><?= hx_link($q(['mine' => $mine ? null : 1]), $mine ? 'Showing mine · show all' : 'Only mine', 'btn btn-sm ms-auto ' . ($mine ? 'btn-secondary' : 'btn-light'), 'id="proposals-mine"') ?><?php endif; ?>
    </div>
    <div id="proposals-list">
        <?php if ($proposals === []): ?>
            <div class="card"><div class="card-body"><div class="empty-state" id="proposals-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-check-circle"></i></span>
                <div><div class="fw-semibold">Nothing <?= e(strtolower($tabs[$status])) ?>.</div><div class="fs-12 text-muted">When the Clerk finds something on Monday, it appears here.</div></div></div></div></div>
        <?php endif; ?>
        <?php foreach ($proposals as $p): ?><?= view('proposals/partials/card.php', ['p' => $p, 'me' => $me, 'curator' => $curator, 'tz' => $tz]) ?><?php endforeach; ?>
    </div>
</div>
