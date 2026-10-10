<?php /** Storage report (screen `curate-storage`). Data: r (storage_report), focus (library id or null) */
$d = $r['disk'];
?>
<?= view('shared/header.php', ['id' => 'curate-storage', 'title' => 'Storage report', 'crumbs' => [['Home', '/'], ['Curate', '/curate/'], ['Storage report', null]]]) ?>
<div class="main-content" id="curate-storage-content">
    <div class="d-flex flex-wrap gap-2 mb-3">
        <form method="post" action="/curate/storage/export.php" id="storage-export-form"><?= csrf_field() ?><input type="hidden" name="report" value="storage"><input type="hidden" name="format" value="csv">
            <button type="submit" class="btn btn-light btn-touch" id="storage-export-btn"><i class="feather-download me-1"></i>Export CSV</button></form>
        <?php if (has_right('settings.manage')): ?><?= hx_link('/admin/storage', '<i class="feather-hard-drive me-1"></i>The disk', 'btn btn-light btn-touch', 'id="storage-disk-link"') ?><?php endif; ?>
    </div>
    <div class="row g-2 mb-3" id="storage-totals">
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Used</div><div class="fs-5 fw-semibold" id="storage-total-used"><?= e(format_bytes((int) $r['totals']['used_bytes'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Documents</div><div class="fs-5 fw-semibold" id="storage-total-documents"><?= (int) $r['totals']['documents'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Versions kept</div><div class="fs-5 fw-semibold" id="storage-total-versions"><?= (int) $r['totals']['versions'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Wasted by copies</div><div class="fs-5 fw-semibold" id="storage-total-wasted"><?= e(format_bytes((int) $r['wasted_bytes'])) ?></div></div></div></div>
    </div>
    <div class="card mb-3" id="storage-table"><div class="card-header"><h6 class="m-0">By library</h6></div>
        <div class="list-group list-group-flush">
        <?php if ($r['libraries'] === []): ?><div class="list-group-item text-muted fs-12" id="storage-empty">No library yet.</div><?php endif; ?>
        <?php foreach ($r['libraries'] as $l): $lid = (int) $l['library_id']; $q = ['used_bytes' => $l['used_bytes'], 'quota_bytes' => $l['quota_bytes'], 'pct' => $l['pct']]; ?>
            <div class="list-group-item fs-12<?= $focus === $lid ? ' bg-soft-primary' : '' ?>" id="storage-row-<?= $lid ?>">
                <div class="d-flex flex-wrap justify-content-between gap-1"><span><?= hx_link(with_back('/libraries/' . $lid, '/curate/storage'), e($l['name']), 'fw-semibold', 'id="storage-link-' . $lid . '"') ?> <span class="text-muted"><?= e($l['kind']) ?></span></span>
                    <span class="text-muted"><?= (int) $l['documents'] ?> document<?= (int) $l['documents'] === 1 ? '' : 's' ?> · <?= (int) $l['versions'] ?> version<?= (int) $l['versions'] === 1 ? '' : 's' ?></span></div>
                <div class="my-1"><?= view('libraries/partials/quota-bar.php', ['q' => $q, 'idPrefix' => 'storage-row-' . $lid]) ?></div>
            </div>
        <?php endforeach; ?>
        </div></div>
    <div class="card mb-3" id="storage-owners"><div class="card-header"><h6 class="m-0">By owner</h6></div>
        <?php if ($r['owners'] === []): ?><div class="card-body fs-12 text-muted">Nobody owns a document yet.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Owner</th><th class="text-end">Documents</th><th class="text-end">Live bytes</th></tr></thead><tbody>
        <?php foreach ($r['owners'] as $o): ?><tr id="storage-owner-<?= (int) $o['member_id'] ?>"><td><?= e($o['display_name']) ?></td><td class="text-end"><?= (int) $o['documents'] ?></td><td class="text-end"><?= e(format_bytes((int) $o['live_bytes'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <div class="card mb-3" id="duplicates"><div class="card-header"><h6 class="m-0">The same file, kept more than once</h6></div>
        <?php if ($r['duplicates'] === []): ?><div class="card-body fs-12 text-muted" id="duplicates-empty">No file is kept twice.</div><?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($r['duplicates'] as $i => $dup): $names = pg_text_array((string) $dup['names']); $ids = pg_int_array((string) $dup['document_ids']); ?>
            <div class="list-group-item fs-12" id="duplicate-<?= $i ?>"><div class="fw-semibold"><?= (int) $dup['copies'] ?> copies of <?= e($names[0] ?? '') ?> <span class="text-muted fw-normal">· <?= e(format_bytes((int) $dup['byte_size'])) ?> each · <?= e(format_bytes((int) $dup['wasted'])) ?> wasted</span></div>
                <div class="d-flex flex-wrap gap-2 mt-1"><?php foreach ($ids as $k => $did): ?><?= hx_link(with_back('/documents/' . $did, '/curate/storage'), e($names[$k] ?? ('#' . $did)), 'badge bg-soft-secondary text-dark') ?><?php endforeach; ?></div></div>
        <?php endforeach; ?></div><?php endif; ?></div>
</div>
