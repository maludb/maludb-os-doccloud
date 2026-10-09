<?php /** Who is in a group (group-members). Data: g, members, may (members, run), tz, notice */ $id = (int) $g['group_id']; ?>
<?= view('shared/header.php', ['id' => 'group-members', 'title' => $g['name'] . ' · members', 'crumbs' => [['Home', '/'], ['Groups', '/groups'], [$g['name'], '/groups/' . $id], ['Members', null]], 'back' => ['/groups/' . $id, $g['name']]]) ?>
<div class="main-content" id="group-members-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['members']): ?>
    <div class="card mb-3" id="group-add-card"><div class="card-header"><h5 class="card-title mb-0">Add people or agents</h5></div><div class="card-body">
        <form method="post" action="/groups/members/add.php" hx-post="/groups/members/add.php" hx-target="#flash" id="group-add-form" class="d-flex flex-wrap gap-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="group" value="<?= $id ?>"><input type="hidden" name="return_to" value="/groups/<?= $id ?>/members">
            <div class="flex-grow-1"><label class="form-label fs-12" id="group-add-member-label">Who</label><?= picker_field(['id' => 'group-add-field-member', 'name' => 'member', 'source' => 'member', 'params' => ['group' => $id], 'required' => true, 'title' => 'Add to ' . $g['name'], 'placeholder' => 'Choose a person or an agent', 'labelledby' => 'group-add-member-label']) ?></div>
            <div><label class="form-label fs-12" for="group-add-field-role">As</label><select class="form-select btn-touch" name="role" id="group-add-field-role"><option value="member">member</option><option value="owner">owner</option></select></div>
            <button type="submit" class="btn btn-primary btn-touch" id="group-add-save-btn">Add</button>
        </form>
        <div class="form-text">People and agents of the business; a guest is never in a group — share a folder with them instead.</div>
    </div></div>
    <?php elseif ($g['kind'] === 'department'): ?><div class="alert alert-secondary fs-12" id="group-members-follow">Members follow the department: whoever the directory puts in it is in the group.</div><?php endif; ?>
    <div class="card" id="group-members-card"><div class="card-body p-0"><table class="table table-hover mb-0 fs-12" id="group-members-table"><thead class="thead-light"><tr><th>Who</th><th>Role</th><th class="d-none d-md-table-cell">Joined</th><th></th></tr></thead><tbody>
        <?php if ($members === []): ?><tr><td colspan="4" class="text-center text-muted py-3" id="group-members-empty">Nobody yet.</td></tr><?php endif; ?>
        <?php foreach ($members as $m) { echo view('groups/partials/member-row.php', ['m' => $m, 'g' => $g, 'may' => $may, 'tz' => $tz]); } ?>
    </tbody></table></div></div>
</div>
