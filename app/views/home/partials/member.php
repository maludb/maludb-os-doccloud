<?php /** A Member's blocks (home-section-shared, -requests, -links): shared with me this week, my open file requests, my links expiring this week. Data: s (member), tz */
$m = $s['member'];
if ($m === null) { return; }
$thing = static fn (array $r): string => $r['document_id'] !== null ? '/documents/' . (int) $r['document_id'] : '/f/' . (int) $r['folder_id'];
?>
<div class="row g-3 mt-0">
    <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="home-section-shared"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Shared with me this week</h5>
        <?= hx_link('/shared/', 'All', 'fs-12 fw-semibold', 'id="home-shared-all"') ?></div>
        <?php if ($m['shared_week'] === []): ?><div class="card-body fs-12 text-muted" id="home-shared-empty">Nothing new has been shared with you this week.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach (array_slice($m['shared_week'], 0, 5) as $r): ?>
            <div class="list-group-item fs-12" id="home-shared-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>"><i class="<?= $r['kind'] === 'folder' ? 'feather-folder' : 'feather-file' ?> me-1 text-muted"></i><?= hx_link(with_back(($r['kind'] === 'folder' ? '/f/' : '/documents/') . (int) $r['id'], '/'), e($r['name']), 'text-dark') ?>
                <div class="text-muted"><?= e($r['shared_by_name'] ?? 'Someone') ?> · <?= e($r['level']) ?> · <?= e(format_ts($r['shared_at'], $tz, 'M j')) ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-requests"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">My open file requests</h5>
        <?= hx_link('/requests', 'All', 'fs-12 fw-semibold', 'id="home-requests-all"') ?></div>
        <?php if ($m['requests_open'] === []): ?><div class="card-body fs-12 text-muted" id="home-requests-empty">No file request is open.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach (array_slice($m['requests_open'], 0, 5) as $r): ?>
            <div class="list-group-item fs-12" id="home-request-<?= (int) $r['link_id'] ?>"><?= hx_link(with_back('/f/' . (int) $r['folder_id'], '/'), e($r['label']), 'text-dark fw-semibold') ?>
                <div class="text-muted">into <?= e($r['folder_name']) ?> · <?= (int) $r['uploads'] ?> received<?= $r['expires_at'] !== null ? ' · until ' . e(format_ts($r['expires_at'], $tz, 'M j')) : '' ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-section-links"><div class="card-header"><h5 class="card-title mb-0">My links expiring this week</h5></div>
        <?php if ($m['links_expiring'] === []): ?><div class="card-body fs-12 text-muted" id="home-links-empty">None of your links expires in the next seven days.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach (array_slice($m['links_expiring'], 0, 5) as $r): ?>
            <div class="list-group-item fs-12" id="home-link-<?= (int) $r['link_id'] ?>"><?= hx_link(with_back($thing($r) . '/links', '/'), e($r['label']), 'text-dark fw-semibold') ?>
                <div class="text-muted">ends <?= e(format_ts($r['expires_at'], $tz, 'M j, g:i A')) ?> · opened <?= (int) $r['view_count'] ?> time<?= (int) $r['view_count'] === 1 ? '' : 's' ?></div></div>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
</div>
