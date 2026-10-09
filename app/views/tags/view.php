<?php /** One tag (screen `tag-view`). Data: tag, docs (rows, total, page, pages), manage, tz, notice */
$id = (int) $tag['tag_id'];
?>
<?= view('shared/header.php', ['id' => 'tag', 'title' => $tag['label'], 'crumbs' => [['Home', '/'], ['Tags', '/tags'], [$tag['label'], null]], 'back' => ['/tags', 'tags'],
    'action' => $manage ? hx_link('/tags/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="tag-edit-btn"') : '']) ?>
<div class="main-content" id="tag-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="tag-facts"><div class="card-body fs-12">
        <?= view('tags/partials/chip.php', ['t' => $tag, 'count' => true]) ?>
        <span class="text-muted ms-2">slug <code><?= e($tag['slug']) ?></code> · <?= e($tag['state']) ?><?= $tag['aliases'] !== [] ? ' · also known as ' . e(implode(', ', $tag['aliases'])) : '' ?></span>
        <?php if ($manage): ?>
        <div class="d-flex flex-wrap gap-1 mt-2" id="tag-actions">
            <?php if ($tag['archived_at'] === null): ?><form method="post" action="/tags/archive.php" hx-post="/tags/archive.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm" id="tag-archive-btn">Archive</button></form>
            <?php else: ?><form method="post" action="/tags/restore.php" hx-post="/tags/restore.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm" id="tag-restore-btn">Restore</button></form><?php endif; ?>
            <?php if ((int) $tag['usage_count'] === 0): ?><form method="post" action="/tags/delete.php" hx-post="/tags/delete.php" hx-target="#flash" hx-confirm="Delete the tag <?= e($tag['label']) ?>? No document carries it." class="d-inline"><?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm text-danger" id="tag-delete-btn">Delete</button></form><?php endif; ?>
        </div>
        <?php endif; ?>
    </div></div>
    <div class="card" id="tag-documents"><div class="card-header"><h5 class="card-title mb-0"><?= (int) $docs['total'] ?> document<?= (int) $docs['total'] === 1 ? '' : 's' ?> you may see</h5></div>
        <div class="list-group list-group-flush" id="tag-documents-list">
            <?php if ($docs['rows'] === []): ?><div class="list-group-item text-muted fs-12" id="tag-documents-empty">None that you may see.</div><?php endif; ?>
            <?php foreach ($docs['rows'] as $r): ?><?= view('search/partials/result.php', ['r' => $r, 'tz' => $tz, 'q' => '']) ?><?php endforeach; ?>
        </div>
        <?php if ($docs['pages'] > 1): ?><div class="card-footer fs-12 d-flex gap-2"><?php for ($p = 1; $p <= $docs['pages']; $p++): ?><?= hx_link('/tags/' . $id . '?page=' . $p, (string) $p, $p === $docs['page'] ? 'fw-bold' : '') ?><?php endfor; ?></div><?php endif; ?>
    </div>
</div>
