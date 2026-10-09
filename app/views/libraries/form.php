<?php /** A shared library's form (library-add / library-edit). Data: lib (null on add), values, errors */
$editing = $lib !== null;
$id = $editing ? (int) $lib['id'] : null;
?>
<?= view('shared/header.php', ['id' => 'library-form', 'title' => $editing ? 'Edit ' . $lib['name'] : 'New shared library', 'crumbs' => [['Home', '/'], ['Libraries', '/libraries'], [$editing ? 'Edit' : 'New', null]], 'back' => [$editing ? '/libraries/' . $id : '/libraries', $editing ? $lib['name'] : 'Libraries']]) ?>
<div class="main-content" id="library-form-content">
    <form method="post" action="/libraries/save.php" hx-post="/libraries/save.php" hx-target="#flash" id="library-form" class="card">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="library" value="<?= $id ?>"><input type="hidden" name="return_to" value="/libraries/<?= $id ?>"><?php endif; ?>
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="library-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="library-form-field-name" name="name" value="<?= e($values['name']) ?>" maxlength="120" required autofocus>
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?></div>
            <div class="mb-3"><label class="form-label" for="library-form-field-description">Description</label>
                <textarea class="form-control" id="library-form-field-description" name="description" rows="3" maxlength="2000"><?= e($values['description']) ?></textarea></div>
            <?php if (!$editing): ?>
            <div class="mb-3"><label class="form-label" for="library-form-field-everyone">Who gets what by default</label>
                <select class="form-select btn-touch" name="everyone_level" id="library-form-field-everyone"><?php foreach (EVERYONE_LEVELS as $k => $label): ?><option value="<?= e($k) ?>" <?= $values['everyone_level'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <div class="form-text">Every member of the business. Change it later on the library's page.</div></div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label" for="library-form-field-quota">Quota</label>
                <input type="text" class="form-control btn-touch" id="library-form-field-quota" disabled value="<?= $editing && $lib['quota_bytes'] !== null ? e(format_bytes((int) $lib['quota_bytes'])) : 'none' ?>"><div class="form-text">The admin sets it (slice 6).</div></div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="library-form-save-btn"><?= $editing ? 'Save' : 'Make the library' ?></button>
            <?= hx_link($editing ? '/libraries/' . $id : '/libraries', 'Cancel', 'btn btn-light btn-touch', 'id="library-form-cancel-btn"') ?>
        </div>
    </form>
</div>
