<?php /** The tag editor on a document: chips with × and an input with the vocabulary's datalist; Enter adds (a new label makes a free tag). Data: documentId, tags (document_tags rows), may (bool), datalist (label, slug, kind) */ ?>
<div id="tag-editor" class="tag-editor" data-document="<?= (int) $documentId ?>">
    <div id="document-tag-chips">
        <?php if ($tags === []): ?><span class="text-muted fs-12" id="document-tags-empty">none yet</span><?php endif; ?>
        <?php foreach ($tags as $t): ?><?= view('tags/partials/chip.php', ['t' => $t, 'remove' => $may ? $documentId : null]) ?><?php endforeach; ?>
    </div>
    <?php if ($may): ?>
    <form method="post" action="/tags/apply.php" hx-post="/tags/apply.php" hx-target="#flash" class="d-flex gap-1 mt-1" id="tag-editor-form"><?= csrf_field() ?><input type="hidden" name="document" value="<?= (int) $documentId ?>"><input type="hidden" name="return_to" value="/documents/<?= (int) $documentId ?>">
        <input type="text" name="tags[]" id="tag-input" class="form-control form-control-sm" list="tag-vocabulary" placeholder="Add a tag and press Enter" maxlength="50" autocomplete="off" aria-label="Add a tag">
        <button type="submit" class="btn btn-light btn-sm" id="tag-add-btn">Add</button>
    </form>
    <datalist id="tag-vocabulary"><?php foreach ($datalist as $d): ?><option value="<?= e($d['label']) ?>"><?= e($d['slug']) ?><?= $d['kind'] === 'managed' ? ' · managed' : '' ?></option><?php endforeach; ?></datalist>
    <?php endif; ?>
</div>
