<?php /** Quotas (screen `admin-quotas`). Data: rows, near, focus (a library row or null), owners, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-quotas', 'title' => 'Quotas', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Quotas', null]]]) ?>
<div class="main-content" id="admin-quotas-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="quota-filters">
        <?= hx_link('/admin/quotas', 'Every library', 'btn btn-sm btn-touch ' . ($near ? 'btn-light' : 'btn-primary'), 'id="quota-filter-all"') ?>
        <?= hx_link('/admin/quotas?near=1', 'Nearly full', 'btn btn-sm btn-touch ' . ($near ? 'btn-primary' : 'btn-light'), 'id="quota-filter-near"') ?>
    </div>
    <?php if ($focus !== null): ?>
    <div class="card mb-3" id="quota-focus"><div class="card-header"><h6 class="m-0"><?= e($focus['name']) ?></h6></div><div class="card-body fs-12">
        <?php $fq = quota_state(db(), (int) $focus['id']); ?>
        <?= view('libraries/partials/quota-bar.php', ['q' => $fq, 'idPrefix' => 'quota-focus']) ?>
        <?php if ($owners !== []): ?>
        <div class="mt-3 fw-semibold">Who holds the most</div>
        <table class="table table-sm mb-0 fs-12" id="quota-owners"><tbody>
            <?php foreach ($owners as $o): ?><tr><td><?= e($o['display_name'] ?? 'Someone who left') ?></td><td class="text-end"><?= (int) $o['documents'] ?> file<?= (int) $o['documents'] === 1 ? '' : 's' ?></td><td class="text-end"><?= e(format_bytes((int) $o['live_bytes'])) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <div class="card" id="quota-card"><div class="list-group list-group-flush" id="quota-table">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="quota-empty"><?= $near ? 'No library is nearly full.' : 'No libraries yet.' ?></div><?php endif; ?>
        <?php foreach ($rows as $r): $lid = (int) $r['library_id']; $q = ['used_bytes' => $r['used_bytes'], 'quota_bytes' => $r['quota_bytes'], 'pct' => $r['pct']]; $gb = $r['quota_bytes'] === null ? '' : rtrim(rtrim(number_format((int) $r['quota_bytes'] / 1073741824, 3, '.', ''), '0'), '.'); ?>
        <div class="list-group-item fs-12" id="quota-row-<?= $lid ?>">
            <div class="d-flex flex-wrap justify-content-between gap-1"><span><?= hx_link('/admin/quotas?library=' . $lid, e($r['name']), 'fw-semibold', 'id="quota-link-' . $lid . '"') ?> <span class="text-muted"><?= e($r['kind']) ?></span></span><span class="text-muted"><?= (int) $r['documents'] ?> file<?= (int) $r['documents'] === 1 ? '' : 's' ?></span></div>
            <div class="my-2"><?= view('libraries/partials/quota-bar.php', ['q' => $q, 'idPrefix' => 'quota-row-' . $lid]) ?></div>
            <form method="post" action="/admin/quotas/save.php" hx-post="/admin/quotas/save.php" hx-target="#flash" class="d-flex gap-1" id="quota-form-<?= $lid ?>"><?= csrf_field() ?><input type="hidden" name="library" value="<?= $lid ?>"><input type="hidden" name="return_to" value="/admin/quotas<?= $near ? '?near=1' : '' ?>">
                <input type="number" step="0.001" min="0.001" name="quota_gb" value="<?= e($gb) ?>" placeholder="no quota (GB)" class="form-control btn-touch" style="max-width:160px" aria-label="Quota in GB for <?= e($r['name']) ?>" id="quota-input-<?= $lid ?>">
                <button type="submit" class="btn btn-light btn-touch" id="quota-save-<?= $lid ?>">Set</button></form>
        </div>
        <?php endforeach; ?>
    </div></div>
    <div class="form-text mt-2">Blank means no limit. An upload over the quota is refused at its start; the owners are told once a month at 90%.</div>
</div>
