<?php /** A folder's form (folder-add / folder-edit). Data: folder (null on add), parent, values, errors, crumbs */
$editing = $folder !== null;
$fid = $editing ? (int) $folder['id'] : null;
$pid = (int) $parent['id'];
$action = '/folders/save.php';
?>
<?= view('shared/header.php', ['id' => 'folder-form', 'title' => $editing ? 'Edit folder' : 'New folder', 'crumbs' => array_merge([['Home', '/']], array_map(static fn (array $c): array => [$c['name'], '/f/' . (int) $c['folder_id']], $crumbs), [[$editing ? 'Edit' : 'New folder', null]]),
    'back' => ['/f/' . ($editing ? $fid : $pid), $editing ? $folder['name'] : $parent['name']]]) ?>
<div class="main-content" id="folder-form-content">
    <form method="post" action="<?= e($action) ?>" hx-post="<?= e($action) ?>" hx-target="#flash" id="folder-form" class="card">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="folder" value="<?= $fid ?>"><input type="hidden" name="return_to" value="/f/<?= $fid ?>"><?php else: ?><input type="hidden" name="parent" value="<?= $pid ?>"><?php endif; ?>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="folder-form-field-name">Name</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="folder-form-field-name" name="name" value="<?= e($values['name']) ?>" maxlength="255" required autofocus>
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label d-block">Colour</label>
                <div class="d-flex flex-wrap gap-2" id="folder-form-colours">
                    <label class="btn btn-light btn-touch<?= ($values['colour'] ?? null) === null ? ' active' : '' ?>" for="folder-form-colour-none"><input type="radio" class="form-check-input me-1" name="colour" value="" id="folder-form-colour-none" <?= ($values['colour'] ?? null) === null ? 'checked' : '' ?>>None</label>
                    <?php foreach (FOLDER_COLOURS as $hex => $word): ?>
                        <label class="btn btn-light btn-touch d-flex align-items-center gap-1<?= ($values['colour'] ?? '') === $hex ? ' active' : '' ?>" for="folder-form-colour-<?= e($word) ?>" title="<?= e($word) ?>"><input type="radio" class="form-check-input m-0" name="colour" value="<?= e($hex) ?>" id="folder-form-colour-<?= e($word) ?>" <?= ($values['colour'] ?? '') === $hex ? 'checked' : '' ?>><span class="wd-15 ht-15 rounded-circle d-inline-block" style="background:<?= e($hex) ?>"></span></label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($errors['colour'])): ?><div class="invalid-feedback d-block"><?= e($errors['colour']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="folder-form-field-auto-tags">Auto-tags</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['auto_tags']) ? ' is-invalid' : '' ?>" id="folder-form-field-auto-tags" name="auto_tags" value="<?= e($values['auto_tags']) ?>" placeholder="contract, 2026">
                <div class="form-text">Comma-separated. Every document added under this folder gets them.</div>
                <?php if (isset($errors['auto_tags'])): ?><div class="invalid-feedback d-block"><?= e($errors['auto_tags']) ?></div><?php endif; ?>
            </div>
            <?php if ($editing): ?><div class="fs-12 text-muted mb-3">To move it elsewhere: <?= hx_link('/folders/' . $fid . '/move', 'Move', 'fw-semibold', 'id="folder-form-move-link"') ?></div><?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="folder-form-save-btn"><?= $editing ? 'Save' : 'Make the folder' ?></button>
            <?= hx_link('/f/' . ($editing ? $fid : $pid), 'Cancel', 'btn btn-light btn-touch', 'id="folder-form-cancel-btn"') ?>
        </div>
    </form>
</div>
