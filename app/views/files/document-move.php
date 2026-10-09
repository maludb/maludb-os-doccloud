<?php /** Move (or copy, $copy) a document: the folder picker. Data: d, pathText, errors, ids, copy */
$id = (int) $d['document_id'];
$action = $copy ? '/documents/copy.php' : '/documents/move.php';
$title = ($copy ? 'Copy ' : 'Move ') . $d['name'];
?>
<?= view('shared/header.php', ['id' => 'document-move', 'title' => $title, 'crumbs' => [['Home', '/'], [$d['name'], '/documents/' . $id], [$copy ? 'Copy' : 'Move', null]], 'back' => ['/documents/' . $id, $d['name']]]) ?>
<div class="main-content" id="document-move-content">
    <form method="post" action="<?= e($action) ?>" hx-post="<?= e($action) ?>" hx-target="#flash" id="document-move-form" class="card"><?= csrf_field() ?>
        <?php foreach ($ids as $i): ?><input type="hidden" name="document" value="<?= (int) $i ?>"><?php endforeach; ?>
        <div class="card-body">
            <div class="fs-12 text-muted mb-3">Now in <span class="fw-semibold" id="document-move-path"><?= e($pathText) ?></span></div>
            <div class="mb-3">
                <label class="form-label" id="document-move-target-label"><?= $copy ? 'Copy into' : 'Move to' ?></label>
                <?= picker_field(['id' => 'document-move-field-target', 'name' => 'target_folder', 'source' => 'folder', 'required' => true, 'title' => $copy ? 'Copy into' : 'Move to', 'placeholder' => 'Choose a folder', 'labelledby' => 'document-move-target-label', 'invalid' => isset($errors['target_folder'])]) ?>
                <div class="form-text">Only folders you may add to are offered.</div>
                <?php if (isset($errors['target_folder'])): ?><div class="invalid-feedback d-block"><?= e($errors['target_folder']) ?></div><?php endif; ?>
            </div>
            <?php if ($copy): ?><div class="mb-3"><label class="form-label" for="document-copy-name">The copy's name</label><input type="text" class="form-control btn-touch" id="document-copy-name" name="name" value="<?= e($d['name']) ?>" maxlength="255"></div><?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="document-move-save-btn"><?= $copy ? 'Copy' : 'Move' ?></button><?= hx_link('/documents/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="document-move-cancel-btn"') ?></div>
    </form>
</div>
