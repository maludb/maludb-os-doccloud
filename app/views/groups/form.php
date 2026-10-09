<?php /** A group's form (group-add / group-edit). Data: g (null on add), values, errors, departments (without a live group) */
$editing = $g !== null;
$id = $editing ? (int) $g['group_id'] : null;
?>
<?= view('shared/header.php', ['id' => 'group-form', 'title' => $editing ? 'Edit ' . $g['name'] : 'New group', 'crumbs' => [['Home', '/'], ['Groups', '/groups'], [$editing ? 'Edit' : 'New', null]], 'back' => [$editing ? '/groups/' . $id : '/groups', $editing ? $g['name'] : 'Groups']]) ?>
<div class="main-content" id="group-form-content">
    <form method="post" action="/groups/save.php" hx-post="/groups/save.php" hx-target="#flash" id="group-form" class="card">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="group" value="<?= $id ?>"><input type="hidden" name="return_to" value="/groups/<?= $id ?>"><?php endif; ?>
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="group-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="group-form-field-name" name="name" value="<?= e($values['name']) ?>" maxlength="120" required autofocus>
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?></div>
            <?php if (!$editing): ?>
            <div class="mb-3"><label class="form-label" for="group-form-field-key">Key</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['key']) ? ' is-invalid' : '' ?>" id="group-form-field-key" name="key" value="<?= e($values['key']) ?>" placeholder="from the name" pattern="[a-z0-9][a-z0-9_-]{0,59}">
                <div class="form-text">Lower-case letters, digits and dashes; blank = from the name.</div><?php if (isset($errors['key'])): ?><div class="invalid-feedback d-block"><?= e($errors['key']) ?></div><?php endif; ?></div>
            <div class="mb-3"><label class="form-label d-block">Kind</label>
                <div class="d-flex flex-column gap-2" id="group-form-kinds">
                    <label class="btn btn-light btn-touch text-start" for="group-form-kind-custom"><input type="radio" class="form-check-input me-2" name="kind" value="custom" id="group-form-kind-custom" <?= $values['kind'] === 'custom' ? 'checked' : '' ?>>Custom — you choose its members and own it</label>
                    <label class="btn btn-light btn-touch text-start" for="group-form-kind-department"><input type="radio" class="form-check-input me-2" name="kind" value="department" id="group-form-kind-department" <?= $values['kind'] === 'department' ? 'checked' : '' ?>>From a department — its members follow the directory; the manager owns it</label>
                </div>
                <?php if (isset($errors['kind'])): ?><div class="invalid-feedback d-block"><?= e($errors['kind']) ?></div><?php endif; ?></div>
            <div class="mb-3" id="group-form-department-row"><label class="form-label" for="group-form-field-department">Department</label>
                <select class="form-select btn-touch<?= isset($errors['department']) ? ' is-invalid' : '' ?>" name="department" id="group-form-field-department"><option value="">— a department with no live group —</option>
                    <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (int) ($values['department'] ?? 0) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
                <?php if (isset($errors['department'])): ?><div class="invalid-feedback d-block"><?= e($errors['department']) ?></div><?php endif; ?></div>
            <?php else: ?>
            <div class="mb-3 fs-12 text-muted" id="group-form-kind-fixed">Kind: <?= $g['kind'] === 'department' ? 'follows a department' : 'custom' ?> (set when it was made).</div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label" for="group-form-field-description">Description</label>
                <textarea class="form-control" id="group-form-field-description" name="description" rows="3" maxlength="2000"><?= e($values['description']) ?></textarea></div>
            <div class="mb-3"><label class="form-label d-block">Members get</label>
                <div class="d-flex gap-2" id="group-form-levels">
                    <?php foreach (MEMBER_LEVELS as $k => $label): ?><label class="btn btn-light btn-touch flex-grow-1" for="group-form-level-<?= e($k) ?>"><input type="radio" class="form-check-input me-2" name="member_level" value="<?= e($k) ?>" id="group-form-level-<?= e($k) ?>" <?= $values['member_level'] === $k ? 'checked' : '' ?>><?= e($label) ?></label><?php endforeach; ?>
                </div>
                <?php if (isset($errors['member_level'])): ?><div class="invalid-feedback d-block"><?= e($errors['member_level']) ?></div><?php endif; ?></div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="group-form-save-btn"><?= $editing ? 'Save' : 'Make the group' ?></button>
            <?= hx_link($editing ? '/groups/' . $id : '/groups', 'Cancel', 'btn btn-light btn-touch', 'id="group-form-cancel-btn"') ?>
        </div>
    </form>
</div>
