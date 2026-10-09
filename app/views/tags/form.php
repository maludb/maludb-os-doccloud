<?php /** The tag form (`tag-add` / `tag-edit`). Data: tag (null for a new one), manage, refusal */
$edit = $tag !== null;
$id = $edit ? (int) $tag['tag_id'] : 0;
$back = $edit ? '/tags/' . $id : '/tags';
?>
<?= view('shared/header.php', ['id' => 'tag-form', 'title' => $edit ? 'Edit the tag ' . $tag['label'] : 'New tag', 'crumbs' => [['Home', '/'], ['Tags', '/tags'], [$edit ? $tag['label'] : 'New', null]], 'back' => [$back, $edit ? $tag['label'] : 'tags']]) ?>
<div class="main-content" id="tag-form-content">
    <form method="post" action="/tags/save.php" hx-post="/tags/save.php" hx-target="#flash" class="card" id="tag-form"><?= csrf_field() ?>
        <?php if ($edit): ?><input type="hidden" name="tag" value="<?= $id ?>"><?php endif; ?><input type="hidden" name="return_to" value="<?= e($back) ?>">
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="tag-label">Label</label><input type="text" name="label" id="tag-label" class="form-control btn-touch" maxlength="50" required value="<?= e($tag['label'] ?? '') ?>" placeholder="Contract">
                <div class="fs-11 text-muted mt-1" id="tag-slug-preview"><?= $edit ? 'slug ' . e($tag['slug']) . ' (a slug never changes: every document carries it)' : 'The slug is made from the label: letters, digits and dashes.' ?></div></div>
            <?php if ($manage): ?>
            <div class="mb-3"><label class="form-label" for="tag-kind">Kind</label><select name="kind" id="tag-kind" class="form-select btn-touch"><option value="managed" <?= ($tag['kind'] ?? 'managed') === 'managed' ? 'selected' : '' ?>>managed — the curator's vocabulary</option><option value="free" <?= ($tag['kind'] ?? '') === 'free' ? 'selected' : '' ?>>free — anyone's</option></select></div>
            <?php else: ?><input type="hidden" name="kind" value="free"><?php endif; ?>
            <div class="mb-3"><label class="form-label" for="tag-colour">Colour <span class="text-muted">(optional)</span></label>
                <div class="d-flex gap-1 flex-wrap" id="tag-colours">
                    <label class="btn btn-light btn-sm"><input type="radio" name="colour" value="" <?= empty($tag['colour']) ? 'checked' : '' ?>> none</label>
                    <?php foreach (FOLDER_COLOURS as $hex => $name): ?><label class="btn btn-light btn-sm"><input type="radio" name="colour" value="<?= e($hex) ?>" <?= ($tag['colour'] ?? '') === $hex ? 'checked' : '' ?>> <span style="display:inline-block;width:.9em;height:.9em;border-radius:50%;background:<?= e($hex) ?>"></span> <?= e($name) ?></label><?php endforeach; ?>
                </div></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="tag-form-submit"><?= $edit ? 'Save' : 'Make the tag' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch') ?></div>
    </form>
    <?php if ($edit && $manage): ?>
    <form method="post" action="/tags/merge.php" hx-post="/tags/merge.php" hx-target="#flash" hx-confirm="Merge <?= e($tag['label']) ?> into the chosen tag? Its documents move; its slug stays as an alias." class="card mt-3" id="tag-merge-form"><?= csrf_field() ?>
        <input type="hidden" name="tag" value="<?= $id ?>"><div class="card-header"><h5 class="card-title mb-0">Merge into another tag</h5></div>
        <div class="card-body"><?= picker_field(['id' => 'tag-merge-into', 'name' => 'into', 'source' => 'tag', 'placeholder' => 'Choose the tag that stays', 'title' => 'Merge into', 'required' => true]) ?></div>
        <div class="card-footer"><button type="submit" class="btn btn-light btn-touch" id="tag-merge-btn">Merge</button></div>
    </form>
    <?php endif; ?>
</div>
