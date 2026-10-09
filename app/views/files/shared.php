<?php /** Shared with me. Data: rows (dc_shared_with_me + shared_by_name), page, more, kind, tz, guest */ ?>
<?= view('shared/header.php', ['id' => 'shared', 'title' => 'Shared with me', 'crumbs' => [['Home', '/'], ['Shared with me', null]]]) ?>
<div class="main-content" id="shared-content">
    <div class="d-flex gap-1 mb-2" id="shared-filters">
        <?php foreach (['' => 'Everything', 'folder' => 'Folders', 'document' => 'Documents'] as $k => $w): ?><?= hx_link('/shared/' . ($k === '' ? '' : '?kind=' . $k), e($w), 'btn btn-sm btn-touch ' . (($kind ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="shared-filter-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="shared-table"><thead><tr><th>Name</th><th>Level</th><th class="d-none d-md-table-cell">Shared by</th><th class="d-none d-md-table-cell">When</th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr id="shared-empty"><td colspan="4" class="text-muted py-4 text-center"><?= $guest ? 'Nothing has been shared with you yet.' : 'Nothing shared with you by name yet — the libraries you belong to are under Libraries.' ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $url = $r['kind'] === 'folder' ? '/f/' . (int) $r['id'] : '/documents/' . (int) $r['id']; ?>
        <tr id="shared-row-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>"><td><?= hx_link($url, '<i class="' . e($r['kind'] === 'folder' ? 'feather-folder' : 'feather-file') . ' me-1 text-muted"></i>' . e($r['name']), 'fw-semibold', 'id="shared-link-' . e($r['kind']) . '-' . (int) $r['id'] . '"') ?><span class="fs-11 text-muted ms-1">via <?= e($r['via']) ?></span></td>
            <td><span class="badge bg-soft-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?>"><?= e($r['level']) ?></span></td>
            <td class="d-none d-md-table-cell"><?= e($r['shared_by_name'] ?? '') ?></td><td class="d-none d-md-table-cell"><?= e(format_ts($r['shared_at'], $tz)) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <?php if ($more || $page > 1): ?><nav class="d-flex justify-content-between mt-2 fs-12" id="shared-pager"><?php if ($page > 1): ?><?= hx_link('/shared/?page=' . ($page - 1) . ($kind ? '&kind=' . e($kind) : ''), 'Previous', 'btn btn-light btn-touch') ?><?php else: ?><span></span><?php endif; ?><?php if ($more): ?><?= hx_link('/shared/?page=' . ($page + 1) . ($kind ? '&kind=' . e($kind) : ''), 'Next', 'btn btn-light btn-touch') ?><?php endif; ?></nav><?php endif; ?>
</div>
