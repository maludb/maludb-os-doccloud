<?php /** Saved searches (screen `saved-searches`). Data: saved, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'saved-searches', 'title' => 'Saved searches', 'crumbs' => [['Home', '/'], ['Search', '/search'], ['Saved', null]], 'back' => ['/search', 'search']]) ?>
<div class="main-content" id="saved-searches-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="saved-searches-table"><thead><tr><th>Name</th><th>Query</th><th class="d-none d-md-table-cell">Filters</th><th></th></tr></thead><tbody>
        <?php if ($saved === []): ?><tr id="saved-searches-empty"><td colspan="4" class="text-muted">No saved search yet. Search, then name it to save.</td></tr><?php endif; ?>
        <?php foreach ($saved as $v): $p = present_saved_search($v); ?>
        <tr id="saved-search-<?= (int) $v['saved_search_id'] ?>" data-mine="<?= $v['mine'] ? '1' : '0' ?>">
            <td><?= hx_link($p['url'], e($v['name']), 'fw-semibold', 'id="saved-search-run-' . (int) $v['saved_search_id'] . '"') ?><?= $v['is_shared'] ? ' <span class="badge bg-soft-info text-info">shared</span>' : '' ?><div class="fs-11 text-muted"><?= $v['mine'] ? 'mine' : 'by ' . e($v['owner_name'] ?? '') ?> · <?= e($v['kind']) ?></div></td>
            <td><code><?= e($v['query']) ?></code></td>
            <td class="d-none d-md-table-cell"><?php foreach ($v['filters'] as $k => $fv): ?><span class="badge bg-soft-primary text-primary me-1"><?= e($k) ?><?= is_bool($fv) ? '' : ' ' . e((string) $fv) ?></span><?php endforeach; ?></td>
            <td class="text-end"><?php if ($v['mine']): ?><form method="post" action="/search/delete.php" hx-post="/search/delete.php" hx-target="#flash" hx-confirm="Delete the saved search <?= e($v['name']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="saved_search" value="<?= (int) $v['saved_search_id'] ?>"><button type="submit" class="btn btn-light btn-sm text-danger" id="saved-search-delete-<?= (int) $v['saved_search_id'] ?>" aria-label="Delete"><i class="feather-x"></i></button></form><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
</div>
