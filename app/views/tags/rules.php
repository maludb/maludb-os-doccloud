<?php /** The auto-tag rules (screen `tag-rules`). Data: rules, folder (when ?folder= names one), nearest, datalist, notice */ ?>
<?= view('shared/header.php', ['id' => 'tag-rules', 'title' => 'Auto-tag rules', 'crumbs' => [['Home', '/'], ['Tags', '/tags'], ['Rules', null]], 'back' => ['/tags', 'tags']]) ?>
<div class="main-content" id="tag-rules-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($folder !== null): ?>
    <div class="card mb-3" id="tag-rule-folder"><div class="card-header"><h5 class="card-title mb-0">The folder <?= e($folder['name']) ?></h5></div>
        <div class="card-body fs-12">
            <?php if ($nearest !== null && (int) $nearest['folder_id'] !== (int) $folder['id']): ?><div class="text-muted mb-2" id="tag-rule-nearest">A new upload here follows the rule of <?= hx_link('/f/' . (int) $nearest['folder_id'], e($nearest['name'])) ?>: <?= e(implode(', ', $nearest['auto_tags'])) ?></div><?php endif; ?>
            <?php if (dc_level_at_least($folder['my_level'], 'manage')): ?>
            <form method="post" action="/folders/auto-tags.php" hx-post="/folders/auto-tags.php" hx-target="#flash" id="tag-rule-form"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $folder['id'] ?>"><input type="hidden" name="return_to" value="/tags/rules?folder=<?= (int) $folder['id'] ?>">
                <label class="form-label" for="tag-rule-input">Tags applied to every document added under it <span class="text-muted">(comma-separated; a new word makes a free tag)</span></label>
                <input type="text" name="auto_tags" id="tag-rule-input" class="form-control btn-touch" list="tag-vocabulary" value="<?= e(implode(', ', pg_text_array((string) $folder['auto_tags']))) ?>" placeholder="contract, 2026">
                <datalist id="tag-vocabulary"><?php foreach ($datalist as $d): ?><option value="<?= e($d['label']) ?>"></option><?php endforeach; ?></datalist>
                <button type="submit" class="btn btn-primary btn-touch mt-2" id="tag-rule-save">Save the rule</button>
            </form>
            <?php else: ?><div class="text-muted">Its rule: <?= e(implode(', ', pg_text_array((string) $folder['auto_tags'])) ?: 'none') ?> (a manager of the folder changes it).</div><?php endif; ?>
        </div></div>
    <?php endif; ?>
    <div class="card" id="tag-rules-card"><div class="card-header"><h5 class="card-title mb-0">Folders that tag on upload</h5></div>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="tag-rules-table"><thead><tr><th>Folder</th><th>Tags</th><th></th></tr></thead><tbody>
        <?php if ($rules === []): ?><tr id="tag-rules-empty"><td colspan="3" class="text-muted">No folder tags on upload yet. Open a folder you manage and set its rule.</td></tr><?php endif; ?>
        <?php foreach ($rules as $r): ?><tr id="tag-rule-<?= (int) $r['folder_id'] ?>"><td><?= hx_link('/f/' . (int) $r['folder_id'], e($r['path_text'] ?: $r['name'])) ?><div class="fs-11 text-muted"><?= e($r['library_name'] ?? '') ?></div></td><td><?php foreach ($r['auto_tags'] as $s): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($s) ?></span><?php endforeach; ?></td><td class="text-end"><?= hx_link('/tags/rules?folder=' . (int) $r['folder_id'], 'change', 'fs-12') ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
</div>
