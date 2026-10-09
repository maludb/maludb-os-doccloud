<?php /** A group card. Data: g, back */ $gid = (int) $g['group_id']; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="group-card-<?= $gid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0"><i class="feather-users"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?= hx_link(with_back('/groups/' . $gid, $back), e($g['name']), 'text-dark', 'id="group-card-' . $gid . '-link"') ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <span class="badge bg-soft-<?= $g['kind'] === 'department' ? 'secondary text-secondary' : 'info text-info' ?>"><?= $g['kind'] === 'department' ? 'follows a department' : 'custom' ?></span>
                <?php if ($g['i_am_owner']): ?><span class="badge bg-soft-primary text-primary">you own</span><?php elseif ($g['i_am_member']): ?><span class="badge bg-soft-success text-success">member</span><?php endif; ?>
                <?php if ($g['archived_at'] !== null): ?><span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="fs-12 text-muted mt-2"><?= (int) $g['member_count'] ?> member<?= (int) $g['member_count'] === 1 ? '' : 's' ?> · level <?= e($g['member_level']) ?></div>
    <?php if (($g['description'] ?? '') !== ''): ?><div class="fs-12 mt-1 text-truncate"><?= e($g['description']) ?></div><?php endif; ?>
    <?php if (!empty($g['root_folder_id'])): ?><div class="mt-auto pt-2"><?= hx_link(with_back('/f/' . (int) $g['root_folder_id'], $back), '<i class="feather-folder me-1"></i>Its library', 'btn btn-light btn-touch w-100', 'id="group-card-' . $gid . '-open"') ?></div><?php endif; ?>
</div></div></div>
