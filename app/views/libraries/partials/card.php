<?php /** A library card. Data: l (library_id, kind, name, root_folder_id, level, used_bytes, quota_bytes, graph_policy, documents, admin_view, group_id), back */
$lid = (int) $l['library_id'];
$pct = $l['quota_bytes'] !== null ? min(100, (int) round(100 * (int) $l['used_bytes'] / max(1, (int) $l['quota_bytes']))) : null;
$level = $l['level'] ?? $l['my_level'] ?? 'none';
?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0<?= !empty($l['admin_view']) ? ' border-danger' : '' ?>" id="library-card-<?= $lid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="<?= e(['personal' => 'feather-user', 'group' => 'feather-users', 'shared' => 'feather-hard-drive'][$l['kind']] ?? 'feather-folder') ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?= hx_link(with_back('/libraries/' . $lid, $back), e($l['name']), 'text-dark', 'id="library-card-' . $lid . '-link"') ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <span class="badge bg-soft-<?= e(LIBRARY_KIND_BADGE[$l['kind']] ?? 'secondary text-secondary') ?>"><?= e($l['kind']) ?></span>
                <span class="badge bg-soft-<?= e(LEVEL_BADGE[$level] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$level] ?? 'secondary') ?>"><?= e($level) ?></span>
                <?php if (!empty($l['admin_view'])): ?><span class="badge bg-soft-danger text-danger">admin view</span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="fs-12 text-muted mt-2"><?= (int) ($l['documents'] ?? 0) ?> document<?= (int) ($l['documents'] ?? 0) === 1 ? '' : 's' ?> · <?= e(format_bytes((int) $l['used_bytes'])) ?><?= $pct !== null ? ' of ' . e(format_bytes((int) $l['quota_bytes'])) : '' ?></div>
    <?php if ($pct !== null): ?><div class="progress mt-1" style="height: 6px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $pct >= 90 ? 'danger' : 'primary' ?>" style="width: <?= $pct ?>%"></div></div><?php endif; ?>
    <div class="mt-auto pt-2"><?= hx_link(with_back('/f/' . (int) $l['root_folder_id'], $back), '<i class="feather-folder me-1"></i>Open', 'btn btn-light btn-touch w-100', 'id="library-card-' . $lid . '-open"') ?></div>
</div></div></div>
