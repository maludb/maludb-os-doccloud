<?php /** The admin's blocks (home-section-storage, -graph, -failures). Data: s (admin), tz, fmtBytes */
$a = $s['admin'];
if ($a === null) { return; }
$b = $a['graph']['budget'];
$pct = $b === null ? null : min(100, (float) $b['pct']);
?>
<h5 class="mt-4 mb-2" id="home-admin-title">For the admin</h5>
<div class="row g-3">
    <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="home-section-storage"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Storage by library</h5>
        <?= hx_link('/curate/storage', 'Report', 'fs-12 fw-semibold', 'id="home-storage-all"') ?></div>
        <?php if ($a['storage'] === []): ?><div class="card-body fs-12 text-muted" id="home-storage-empty">Nothing is stored yet.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($a['storage'] as $r): ?>
            <div class="list-group-item fs-12 d-flex justify-content-between gap-2" id="home-storage-<?= (int) $r['library_id'] ?>"><span class="min-w-0 text-truncate"><?= hx_link(with_back('/libraries/' . (int) $r['library_id'], '/'), e($r['name']), 'text-dark') ?></span>
                <span class="text-muted text-nowrap"><?= e($fmtBytes((int) $r['used_bytes'])) ?><?= $r['pct'] !== null ? ' · ' . e((string) $r['pct']) . '%' : '' ?></span></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-graph"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">The graph queue and spend</h5>
        <?= hx_link('/graph/admin', 'Graph admin', 'fs-12 fw-semibold', 'id="home-graph-all"') ?></div>
        <div class="card-body fs-12">
            <div id="home-graph-queue"><?= (int) $a['graph']['jobs']['queued'] ?> queued · <?= (int) $a['graph']['jobs']['running'] ?> running · <span class="<?= (int) $a['graph']['jobs']['failed'] > 0 ? 'text-danger' : '' ?>"><?= (int) $a['graph']['jobs']['failed'] ?> failed</span></div>
            <?php if ($b !== null): ?>
            <div class="mt-2" id="home-graph-spend"><?= e($fmtBytes((int) $b['full_chars'])) ?> of <?= e($fmtBytes((int) $b['budget_chars'])) ?> of text this month</div>
            <div class="progress mt-1" style="height:6px"><div class="progress-bar bg-<?= $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'primary') ?>" style="width:<?= (float) $pct ?>%" role="progressbar"></div></div>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-failures"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Text that could not be read</h5>
        <span class="badge bg-soft-<?= $a['failures_total'] > 0 ? 'warning text-warning' : 'secondary text-dark' ?>" id="home-failures-count"><?= (int) $a['failures_total'] ?></span></div>
        <?php if ($a['failures'] === []): ?><div class="card-body fs-12 text-muted" id="home-failures-empty">Every file's text was read.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($a['failures'] as $r): ?>
            <div class="list-group-item fs-12" id="home-failure-<?= (int) $r['document_id'] ?>"><?= hx_link(with_back('/documents/' . (int) $r['document_id'], '/'), e($r['name']), 'text-dark') ?> <span class="text-muted"><?= e(str_replace('_', ' ', (string) $r['status'])) ?></span></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
</div>
