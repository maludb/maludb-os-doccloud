<?php /** The document's edit form (document-edit). Data: d, values, errors, crumbs */
$id = (int) $d['document_id'];
?>
<?= view('shared/header.php', ['id' => 'document-edit', 'title' => 'Edit ' . $d['name'], 'crumbs' => array_merge([['Home', '/']], array_map(static fn (array $c): array => [$c['name'], '/f/' . (int) $c['folder_id']], $crumbs), [[$d['name'], '/documents/' . $id], ['Edit', null]]), 'back' => ['/documents/' . $id, $d['name']]]) ?>
<div class="main-content" id="document-edit-content">
    <form method="post" action="/documents/save.php" hx-post="/documents/save.php" hx-target="#flash" id="document-form" class="card"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="return_to" value="/documents/<?= $id ?>">
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="document-form-field-name">Name</label><input type="text" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="document-form-field-name" name="name" value="<?= e($values['name']) ?>" maxlength="255" required autofocus><?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?></div>
            <div class="mb-3"><label class="form-label" for="document-form-field-description">Description</label><textarea class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="document-form-field-description" name="description" rows="4" maxlength="4000"><?= e($values['description']) ?></textarea><?php if (isset($errors['description'])): ?><div class="invalid-feedback d-block"><?= e($errors['description']) ?></div><?php endif; ?></div>
            <div class="fs-12 text-muted">Elsewhere: <?= hx_link('/documents/' . $id . '/move', 'Move', 'fw-semibold', 'id="document-form-move-link"') ?> · <?= hx_link('/documents/' . $id . '/copy', 'Copy to', 'fw-semibold', 'id="document-form-copy-link"') ?></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="document-form-save-btn">Save</button><?= hx_link('/documents/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="document-form-cancel-btn"') ?></div>
    </form>
</div>
