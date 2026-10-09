<?php /** A library (library-view). Data: lib (mcp_libraries), raw, members, quota, group, owner, docs, may, tz, timeline, notice, adminView */
$id = (int) $lib['library_id'];
$root = (int) $lib['root_folder_id'];
$archived = $lib['archived_at'] !== null;
?>
<?= view('shared/header.php', ['id' => 'library-view', 'title' => $lib['name'], 'crumbs' => [['Home', '/'], ['Libraries', '/libraries'], [$lib['name'], null]], 'back' => back_link() ?? ['/libraries', 'Libraries'],
    'action' => $may['edit'] ? hx_link('/libraries/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="library-edit-btn"') : '']) ?>
<div class="main-content" id="library-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($adminView): ?><div class="alert alert-danger fs-12" id="library-admin-view">You see this library as the admin: it is not yours, and this look is logged.</div><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3" id="library-facts"><div class="card-body">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="badge bg-soft-<?= e(LIBRARY_KIND_BADGE[$lib['kind']] ?? 'secondary text-secondary') ?>" id="library-kind"><?= e($lib['kind']) ?></span>
                    <span class="badge bg-soft-<?= e(LEVEL_BADGE[$lib['my_level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$lib['my_level']] ?? 'secondary') ?>" id="library-my-level"><?= e($lib['my_level']) ?></span>
                    <?= hx_link('/graph/admin?library=' . (int) $lib['library_id'], 'graph: ' . e($lib['graph_policy']), 'badge bg-soft-secondary text-secondary text-decoration-none', 'id="library-policy" title="the graph policy"') ?>
                    <?php if ($archived): ?><span class="badge bg-soft-dark text-dark" id="library-archived">archived</span><?php endif; ?>
                </div>
                <?php if (($lib['description'] ?? '') !== ''): ?><p class="fs-12 mb-2" id="library-description"><?= nl2br(e($lib['description'])) ?></p><?php endif; ?>
                <dl class="row fs-12 mb-0">
                    <?php if ($group !== null): ?><dt class="col-5 col-md-4">Group</dt><dd class="col-7 col-md-8"><?= hx_link('/groups/' . (int) $group['group_id'], e($group['name']), '', 'id="library-group-link"') ?> <span class="text-muted">(named after the group)</span></dd><?php endif; ?>
                    <?php if ($lib['kind'] === 'personal' && $owner !== null): ?><dt class="col-5 col-md-4">Owner</dt><dd class="col-7 col-md-8" id="library-owner"><?= e($owner) ?></dd><?php endif; ?>
                    <?php if ($lib['kind'] === 'shared'): ?><dt class="col-5 col-md-4">Everyone gets</dt><dd class="col-7 col-md-8" id="library-everyone"><?= e(EVERYONE_LEVELS[$lib['everyone_level']] ?? $lib['everyone_level']) ?></dd><?php endif; ?>
                    <dt class="col-5 col-md-4">Documents</dt><dd class="col-7 col-md-8" id="library-documents"><?= (int) $docs ?></dd>
                    <dt class="col-5 col-md-4">Storage</dt><dd class="col-7 col-md-8" id="library-quota"><?= view('libraries/partials/quota-bar.php', ['q' => $quota, 'idPrefix' => 'quota']) ?><?php if (has_right('quotas.manage')): ?><?= hx_link('/admin/quotas?library=' . $id, 'Set the quota', 'fs-11 fw-semibold', 'id="library-quota-link"') ?><?php endif; ?></dd>
                </dl>
                <div class="mt-3 d-flex flex-wrap gap-1">
                    <?= hx_link(with_back('/f/' . $root, '/libraries/' . $id), '<i class="feather-folder me-1"></i>Open the root folder', 'btn btn-primary btn-touch', 'id="library-open-btn"') ?>
                </div>
            </div></div>
            <?php if ($may['everyone']): ?>
            <div class="card mb-3" id="library-everyone-card"><div class="card-header"><h5 class="card-title mb-0">Who gets what by default</h5></div><div class="card-body">
                <form method="post" action="/libraries/everyone.php" hx-post="/libraries/everyone.php" hx-target="#flash" id="library-everyone-form" class="d-flex flex-wrap gap-2 align-items-end">
                    <?= csrf_field() ?><input type="hidden" name="library" value="<?= $id ?>"><input type="hidden" name="return_to" value="/libraries/<?= $id ?>">
                    <div class="flex-grow-1"><label class="form-label fs-12" for="library-everyone-field">Every member of the business</label>
                        <select class="form-select btn-touch" name="everyone_level" id="library-everyone-field"><?php foreach (EVERYONE_LEVELS as $k => $label): ?><option value="<?= e($k) ?>" <?= $lib['everyone_level'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <button type="submit" class="btn btn-light btn-touch" id="library-everyone-save-btn">Save</button>
                </form>
                <div class="form-text">Raising it reaches everyone at once; an agent's change waits for a person's approval.</div>
            </div></div>
            <?php endif; ?>
            <?php if ($may['archive'] || $may['delete']): ?>
            <div class="card mb-3" id="library-danger"><div class="card-body d-flex flex-wrap gap-2">
                <?php if ($may['archive']): ?>
                <form method="post" action="/libraries/save.php" hx-post="/libraries/save.php" hx-target="#flash" hx-confirm="<?= $archived ? 'Restore' : 'Archive' ?> <?= e($lib['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="library" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $archived ? 'no' : 'yes' ?>">
                    <button type="submit" class="btn btn-light btn-touch" id="library-<?= $archived ? 'restore' : 'archive' ?>-btn"><i class="feather-<?= $archived ? 'rotate-ccw' : 'archive' ?> me-1"></i><?= $archived ? 'Restore' : 'Archive' ?></button></form>
                <?php endif; ?>
                <?php if ($may['delete']): ?>
                <form method="post" action="/libraries/delete.php" hx-post="/libraries/delete.php" hx-target="#flash" hx-confirm="Delete the empty library <?= e($lib['name']) ?>? This cannot be undone." class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="library" value="<?= $id ?>">
                    <input type="text" name="confirm_name" class="form-control btn-touch" placeholder="Type its name" aria-label="Type the library's name" id="library-delete-confirm"><button type="submit" class="btn btn-light btn-touch text-danger" id="library-delete-btn"><i class="feather-trash-2 me-1"></i>Delete</button></form>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <?php if ($lib['kind'] === 'group'): ?>
            <div class="card mb-3" id="library-members"><div class="card-header"><h5 class="card-title mb-0">Members</h5></div>
                <div class="list-group list-group-flush" id="library-members-list">
                    <?php foreach ($members as $m): ?><div class="list-group-item fs-12 d-flex justify-content-between" id="library-member-<?= (int) $m['member_id'] ?>"><span><?= e($m['display_name']) ?></span><?php if ($m['role'] === 'owner'): ?><span class="badge bg-soft-primary text-primary">owner</span><?php endif; ?></div><?php endforeach; ?>
                    <?php if ($members === []): ?><div class="list-group-item fs-12 text-muted">Nobody yet.</div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'library']) ?>
        </div>
    </div>
</div>
