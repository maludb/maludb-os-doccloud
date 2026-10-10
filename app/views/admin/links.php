<?php /** Every link (screen `admin-links`). Data: rows, status, kind, tz, notice */
$filters = [['', 'Every link'], ['live', 'Live'], ['expiring', 'Expiring'], ['expired', 'Expired'], ['revoked', 'Revoked'], ['suspicious', 'Suspicious']];
$url = static fn (string $s, string $k): string => '/admin/links' . ($s !== '' || $k !== '' ? '?' . http_build_query(array_filter(['status' => $s, 'kind' => $k])) : '');
?>
<?= view('shared/header.php', ['id' => 'admin-links', 'title' => 'Links', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Links', null]]]) ?>
<div class="main-content" id="admin-links-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="admin-links-filters">
        <?php foreach ($filters as [$s, $label]): ?><?= hx_link($url($s, $kind), $label, 'btn btn-sm btn-touch ' . ($status === $s ? 'btn-primary' : 'btn-light'), 'id="admin-links-filter-' . ($s === '' ? 'all' : $s) . '"') ?><?php endforeach; ?>
        <?= hx_link($url($status, $kind === 'request' ? '' : 'request'), 'File requests', 'btn btn-sm btn-touch ms-auto ' . ($kind === 'request' ? 'btn-secondary' : 'btn-light'), 'id="admin-links-filter-requests"') ?>
    </div>
    <div class="card" id="admin-links-card"><div class="list-group list-group-flush" id="admin-links-table">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="admin-links-empty">No link<?= $status !== '' ? ' in that state' : ' yet' ?>.</div><?php endif; ?>
        <?php foreach ($rows as $l): $id = (int) $l['link_id']; $live = in_array($l['state'], ['live', 'expiring'], true);
            $thingUrl = $l['document_id'] !== null ? '/documents/' . (int) $l['document_id'] : '/f/' . (int) $l['folder_id']; ?>
        <div class="list-group-item fs-12 <?= $live ? '' : 'text-muted' ?>" id="admin-link-row-<?= $id ?>" data-state="<?= e($l['state']) ?>" data-suspicious="<?= $l['suspicious'] ? '1' : '0' ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div><span class="fw-semibold fs-6"><?= e($l['label']) ?></span>
                    <span class="badge bg-soft-<?= e(LINK_STATE_BADGE[$l['state']] ?? 'secondary') ?> text-<?= e(LINK_STATE_BADGE[$l['state']] ?? 'secondary') ?> ms-1" id="admin-link-row-<?= $id ?>-state"><?= e($l['state']) ?></span>
                    <span class="badge bg-soft-secondary text-secondary"><?= $l['kind'] === 'request' ? 'file request' : 'link' ?></span>
                    <?php if ($l['suspicious']): ?><span class="badge bg-soft-danger text-danger" id="admin-link-row-<?= $id ?>-suspicious" title="Opened from many addresses this week">suspicious · <?= (int) $l['suspicious_addresses'] ?> addresses</span><?php endif; ?>
                    <div class="text-muted">on <?= hx_link(with_back($thingUrl, '/admin/links'), e($l['document_name'] ?? $l['folder_name'] ?? 'something removed'), '', 'id="admin-link-row-' . $id . '-thing"') ?> · made by <?= e($l['made_by_name'] ?? 'someone who left') ?></div></div>
                <?php if ($live): ?>
                <form method="post" action="/links/revoke.php" hx-post="/links/revoke.php" hx-confirm="Revoke the link <?= e($l['label']) ?>? It stops opening now." hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="link" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($url($status, $kind)) ?>">
                    <button type="submit" class="btn btn-light btn-touch" id="admin-link-revoke-<?= $id ?>"><i class="feather-slash me-1"></i>Revoke</button></form>
                <?php endif; ?>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-6 col-md-3"><div class="text-muted">Opened</div><div><?= (int) $l['view_count'] ?><?= $l['kind'] === 'request' ? ' · ' . (int) $l['upload_count'] . ' uploads' : '' ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Ends</div><div><?= $l['expires_at'] === null ? 'never' : e(format_ts($l['expires_at'], $tz, 'M j, Y')) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Made</div><div><?= e(format_ts($l['created_at'], $tz, 'M j, Y')) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Last used</div><div><?= e($l['last_used_at'] === null ? 'never' : format_ts($l['last_used_at'], $tz)) ?></div></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div></div>
    <div class="form-text mt-2">A link's address is stored hashed: it is shown once, to the person who made it, and not here.</div>
</div>
