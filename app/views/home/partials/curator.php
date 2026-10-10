<?php /** A curator's blocks (home-section-untagged, -duplicates, -stale). Data: s (curator), tz, fmtBytes */
$c = $s['curator'];
if ($c === null) { return; }
?>
<h5 class="mt-4 mb-2" id="home-curator-title">For curators</h5>
<div class="row g-3">
    <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="home-section-untagged"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Added this week, untagged</h5>
        <span class="badge bg-soft-secondary text-dark" id="home-untagged-count"><?= count($c['untagged_week']) ?></span></div>
        <?php if ($c['untagged_week'] === []): ?><div class="card-body fs-12 text-muted" id="home-untagged-empty">Everything added this week carries a tag.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach (array_slice($c['untagged_week'], 0, 5) as $r): ?>
            <div class="list-group-item fs-12" id="home-untagged-<?= (int) $r['document_id'] ?>"><i class="feather-file me-1 text-muted"></i><?= hx_link(with_back('/documents/' . (int) $r['document_id'], '/'), e($r['name']), 'text-dark') ?></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-duplicates"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">The same file, many times</h5>
        <?= hx_link('/curate/storage#duplicates', 'Report', 'fs-12 fw-semibold', 'id="home-duplicates-all"') ?></div>
        <?php if ($c['duplicates'] === []): ?><div class="card-body fs-12 text-muted" id="home-duplicates-empty">No file is kept twice.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($c['duplicates'] as $i => $r): $names = pg_text_array((string) $r['names']); $ids = pg_int_array((string) $r['document_ids']); ?>
            <div class="list-group-item fs-12" id="home-duplicate-<?= $i ?>"><?= hx_link(with_back('/documents/' . ($ids[0] ?? 0), '/'), e($names[0] ?? ''), 'text-dark fw-semibold') ?>
                <div class="text-muted"><?= (int) $r['copies'] ?> copies · <?= e($fmtBytes((int) $r['byte_size'])) ?> each</div></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-stale"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Guest shares gone stale</h5>
        <?= hx_link('/curate/proposals', 'Proposals', 'fs-12 fw-semibold', 'id="home-stale-all"') ?></div>
        <?php if ($c['stale_shares'] === []): ?><div class="card-body fs-12 text-muted" id="home-stale-empty">No guest has held access for long.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach (array_slice($c['stale_shares'], 0, 5) as $r): $k = $r['kind'] === 'folder' ? '/folders/' : '/documents/'; ?>
            <div class="list-group-item fs-12" id="home-stale-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>"><?= hx_link(with_back($k . (int) $r['id'] . '/share', '/'), e($r['name']), 'text-dark fw-semibold') ?>
                <div class="text-muted"><?= e($r['guest_name'] ?? 'A guest') ?> since <?= e(format_ts($r['created_at'], $tz, 'M j, Y')) ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
</div>
