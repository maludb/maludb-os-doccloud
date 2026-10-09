<?php /** Move a folder (folder-move). Data: folder, pathText (never `path`: view() keeps that name), errors, ids */
$fid = (int) $folder['id'];
?>
<?= view('shared/header.php', ['id' => 'folder-move', 'title' => 'Move ' . $folder['name'], 'crumbs' => [['Home', '/'], [$folder['name'], '/f/' . $fid], ['Move', null]], 'back' => ['/f/' . $fid, $folder['name']]]) ?>
<div class="main-content" id="folder-move-content">
    <form method="post" action="/folders/move.php" hx-post="/folders/move.php" hx-target="#flash" id="folder-move-form" class="card">
        <?= csrf_field() ?><input type="hidden" name="folder" value="<?= $fid ?>">
        <div class="card-body">
            <div class="fs-12 text-muted mb-3">Now in <span class="fw-semibold" id="folder-move-path"><?= e($pathText) ?></span></div>
            <div class="mb-3">
                <label class="form-label" id="folder-move-target-label">Move to</label>
                <?= picker_field(['id' => 'folder-move-field-target', 'name' => 'target_folder', 'source' => 'folder', 'required' => true, 'title' => 'Move to', 'placeholder' => 'Choose the folder to move it into', 'labelledby' => 'folder-move-target-label', 'invalid' => isset($errors['target_folder'])]) ?>
                <div class="form-text">Only folders you may add to are offered. A folder stays in its library and never goes under itself.</div>
                <?php if (isset($errors['target_folder'])): ?><div class="invalid-feedback d-block"><?= e($errors['target_folder']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="folder-move-save-btn">Move</button>
            <?= hx_link('/f/' . $fid, 'Cancel', 'btn btn-light btn-touch', 'id="folder-move-cancel-btn"') ?>
        </div>
    </form>
</div>
