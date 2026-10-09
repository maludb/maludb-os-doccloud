<?php /** A group (group-view). Data: g, members, may (run, members, archive, delete), tz, timeline, notice, osDirectory */
$id = (int) $g['group_id'];
$archived = $g['archived_at'] !== null;
?>
<?= view('shared/header.php', ['id' => 'group-view', 'title' => $g['name'], 'crumbs' => [['Home', '/'], ['Groups', '/groups'], [$g['name'], null]], 'back' => back_link() ?? ['/groups', 'Groups'],
    'action' => $may['run'] && !$archived ? hx_link('/groups/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="group-edit-btn"') : '']) ?>
<div class="main-content" id="group-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="group-facts"><div class="card-body">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="badge bg-soft-<?= $g['kind'] === 'department' ? 'secondary text-secondary' : 'info text-info' ?>" id="group-kind"><?= $g['kind'] === 'department' ? 'follows a department' : 'custom' ?></span>
                    <span class="badge bg-soft-<?= e(LEVEL_BADGE[$g['member_level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$g['member_level']] ?? 'secondary') ?>" id="group-level">members <?= e($g['member_level']) ?></span>
                    <?php if ($g['i_am_owner']): ?><span class="badge bg-soft-primary text-primary">you own</span><?php endif; ?>
                    <?php if ($archived): ?><span class="badge bg-soft-dark text-dark" id="group-archived">archived</span><?php endif; ?>
                </div>
                <?php if (($g['description'] ?? '') !== ''): ?><p class="fs-12 mb-2" id="group-description"><?= nl2br(e($g['description'])) ?></p><?php endif; ?>
                <dl class="row fs-12 mb-0">
                    <?php if ($g['kind'] === 'department'): ?><dt class="col-5 col-md-4">Department</dt><dd class="col-7 col-md-8" id="group-department"><?= e($g['department_name'] ?? ('#' . (int) $g['department_id'])) ?> <a href="<?= e($osDirectory) ?>" class="fs-11">in the directory</a><div class="text-muted">Its members follow the department.</div></dd><?php endif; ?>
                    <dt class="col-5 col-md-4">Key</dt><dd class="col-7 col-md-8"><code id="group-key"><?= e($g['key']) ?></code></dd>
                    <?php if (!empty($g['library_id'])): ?><dt class="col-5 col-md-4">Library</dt><dd class="col-7 col-md-8"><?= hx_link('/libraries/' . (int) $g['library_id'], e($g['name']), '', 'id="group-library-link"') ?> · <?= e(format_bytes((int) ($g['used_bytes'] ?? 0))) ?> · <?= hx_link(with_back('/f/' . (int) $g['root_folder_id'], '/groups/' . $id), 'open', '', 'id="group-root-link"') ?></dd><?php endif; ?>
                </dl>
            </div></div>
            <?php if ($may['archive'] || $may['delete']): ?>
            <div class="card mb-3" id="group-danger"><div class="card-body d-flex flex-wrap gap-2">
                <?php if ($may['archive']): ?>
                <form method="post" action="/groups/<?= $archived ? 'restore' : 'archive' ?>.php" hx-post="/groups/<?= $archived ? 'restore' : 'archive' ?>.php" hx-target="#flash" hx-confirm="<?= $archived ? 'Restore' : 'Archive' ?> <?= e($g['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="group" value="<?= $id ?>">
                    <button type="submit" class="btn btn-light btn-touch" id="group-<?= $archived ? 'restore' : 'archive' ?>-btn"><i class="feather-<?= $archived ? 'rotate-ccw' : 'archive' ?> me-1"></i><?= $archived ? 'Restore' : 'Archive' ?></button></form>
                <?php endif; ?>
                <?php if ($may['delete']): ?>
                <form method="post" action="/groups/delete.php" hx-post="/groups/delete.php" hx-target="#flash" hx-confirm="Delete the group <?= e($g['name']) ?> and its empty library? This cannot be undone." class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="group" value="<?= $id ?>">
                    <input type="text" name="confirm_name" class="form-control btn-touch" placeholder="Type its name" aria-label="Type the group's name" id="group-delete-confirm"><button type="submit" class="btn btn-light btn-touch text-danger" id="group-delete-btn"><i class="feather-trash-2 me-1"></i>Delete</button></form>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="group-members-card"><div class="card-header d-flex align-items-center justify-content-between"><h5 class="card-title mb-0">Members</h5>
                <?php if ($may['members'] && !$archived): ?><?= hx_link('/groups/' . $id . '/members', '<i class="feather-user-plus me-1"></i>Add', 'btn btn-light btn-sm', 'id="group-add-members-btn"') ?><?php endif; ?></div>
                <div class="card-body p-0"><table class="table table-hover mb-0 fs-12" id="group-members-table"><thead class="thead-light"><tr><th>Who</th><th>Role</th><th class="d-none d-md-table-cell">Joined</th><th></th></tr></thead><tbody>
                    <?php if ($members === []): ?><tr><td colspan="4" class="text-center text-muted py-3" id="group-members-empty">Nobody yet.</td></tr><?php endif; ?>
                    <?php foreach ($members as $m) { echo view('groups/partials/member-row.php', ['m' => $m, 'g' => $g, 'may' => $archived ? ['run' => false, 'members' => false] : $may, 'tz' => $tz]); } ?>
                </tbody></table></div>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'group']) ?>
        </div>
    </div>
</div>
