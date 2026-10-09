<?php /** The folder screen. Data: folder, fv (mcp_folders row), library, crumbs, listing, accessLine, size, may, stars, tz, notice, uploadWanted, adminView */
$id = (int) $folder['id'];
$isRoot = $folder['parent_id'] === null;
$back = '/f/' . $id;
$base = '/f/' . $id . ($listing['kind'] !== null ? '?kind=' . rawurlencode($listing['kind']) : '');
$starred = isset($stars['folder'][$id]);
$kinds = ['' => 'Everything', 'folder' => 'Folders', 'document' => 'Documents', 'image' => 'Images', 'pdf' => 'PDFs', 'office' => 'Office', 'text' => 'Text', 'audio' => 'Audio', 'video' => 'Video', 'archive' => 'Archives'];
$actions = '';
if ($may['add']) {
    $actions .= hx_link('/folders/new?parent=' . $id, '<i class="feather-folder-plus me-1"></i>New folder', 'btn btn-light btn-touch', 'id="folder-new-btn"');
    $actions .= ' ' . hx_link('/f/' . $id . '/upload', '<i class="feather-upload me-1"></i>Upload', 'btn btn-primary btn-touch', 'id="folder-upload-btn"');
    if ($may['export'] ?? false) {
        $actions .= ' <form method="post" action="/folders/export.php" hx-post="/folders/export.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="folder" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="folder-export-btn" title="Download this folder as a zip"><i class="feather-package me-1"></i>Zip</button></form>';
    }
}
?>
<div class="page-header" id="folder-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate d-flex align-items-center gap-2"><?php if ($folder['colour']): ?><span class="wd-10 ht-10 rounded-circle d-inline-block flex-shrink-0" style="background:<?= e($folder['colour']) ?>" id="folder-colour-dot"></span><?php endif; ?><span id="folder-title"><?= e($folder['name']) ?></span></h5></div>
        <?= view('files/partials/crumbs.php', ['crumbs' => $crumbs, 'here' => $id]) ?>
    </div>
    <?php if ($actions !== ''): ?><div class="page-header-right ms-auto d-flex gap-1 flex-wrap justify-content-end"><?= $actions ?></div><?php endif; ?>
</div>
<div class="main-content" id="folder-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['add']): ?><?= view('files/partials/upload-zone.php', ['folderId' => $id, 'documentId' => null, 'settings' => $uploadSettings, 'open' => $uploadWanted, 'doneUrl' => '/f/' . $id]) ?><?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= view('files/partials/access-line.php', ['line' => $accessLine, 'folderId' => $id, 'may' => $may, 'adminView' => $adminView]) ?>
        <span class="fs-12 text-muted" id="folder-size"><?= e(format_bytes($size['bytes'])) ?> · <?= (int) $size['documents'] ?> document<?= $size['documents'] === 1 ? '' : 's' ?></span>
        <div class="ms-auto d-flex gap-1 flex-wrap" id="folder-actions">
            <?php if ($may['star']): ?>
                <form method="post" action="/favourites/<?= $starred ? 'remove' : 'add' ?>.php" hx-post="/favourites/<?= $starred ? 'remove' : 'add' ?>.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                    <button type="submit" class="btn btn-light btn-touch btn-icon" id="folder-star-btn" title="<?= $starred ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $starred ? 'Unstar' : 'Star' ?>"><i class="feather-star<?= $starred ? ' text-warning' : '' ?>"></i></button></form>
            <?php endif; ?>
            <?php if ($may['edit_self']): ?>
                <?= hx_link('/folders/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Rename', 'btn btn-light btn-touch', 'id="folder-edit-btn"') ?>
                <?= hx_link('/folders/' . $id . '/move', '<i class="feather-corner-up-right me-1"></i>Move', 'btn btn-light btn-touch', 'id="folder-move-btn"') ?>
            <?php endif; ?>
            <?php if ($may['share']): ?><?= hx_link('/folders/' . $id . '/share', '<i class="feather-share-2 me-1"></i>Share', 'btn btn-light btn-touch', 'id="folder-share-btn"') ?><?= hx_link('/folders/' . $id . '/links', '<i class="feather-link me-1"></i>Links', 'btn btn-light btn-touch', 'id="folder-links-btn"') ?><?php endif; ?>
            <?php if ($may['manage'] && !$isRoot): ?>
                <form method="post" action="/folders/trash.php" hx-post="/folders/trash.php" hx-target="#flash" hx-confirm="Send <?= e($folder['name']) ?> and everything in it to the trash? It can be restored for 30 days." class="d-inline"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= $id ?>">
                    <button type="submit" class="btn btn-light btn-touch text-danger" id="folder-trash-btn"><i class="feather-trash-2 me-1"></i>Trash</button></form>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-2" id="folder-kind-filters">
        <?php foreach ($kinds as $k => $label): ?><?= hx_link('/f/' . $id . ($k === '' ? '' : '?kind=' . $k), e($label), 'btn btn-sm btn-touch ' . (($listing['kind'] ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="folder-kind-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($may['edit'] || $may['manage']): ?><?= view('files/partials/select-bar.php', ['folderId' => $id, 'may' => $may]) ?><?php endif; ?>
    <?= view('files/partials/listing.php', ['rows' => $listing['rows'], 'tz' => $tz, 'select' => $may['edit'] || $may['manage'], 'stars' => $stars, 'back' => $back, 'sortBase' => $base, 'sort' => $listing['sort'], 'dir' => $listing['dir'],
        'empty' => $listing['kind'] === null ? 'Nothing here yet.' : 'Nothing of that kind here.', 'id' => 'listing-table']) ?>
    <?php if ($listing['pages'] > 1): ?>
    <nav class="d-flex align-items-center justify-content-between mt-2 fs-12" id="listing-pager">
        <span class="text-muted"><?= (int) $listing['total'] ?> items · page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?></span>
        <div class="d-flex gap-1">
            <?php $pq = static fn (int $p): string => '/f/' . $id . '?' . http_build_query(array_filter(['sort' => $listing['sort'], 'dir' => $listing['dir'], 'kind' => $listing['kind'], 'page' => $p])); ?>
            <?php if ($listing['page'] > 1): ?><?= hx_link($pq($listing['page'] - 1), 'Previous', 'btn btn-light btn-touch', 'id="listing-prev"') ?><?php endif; ?>
            <?php if ($listing['page'] < $listing['pages']): ?><?= hx_link($pq($listing['page'] + 1), 'Next', 'btn btn-light btn-touch', 'id="listing-next"') ?><?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
</div>
<?php if ($may['add']): ?><script src="/assets/js/dc-upload.js"></script>
<script>
(function () {
    var w = document.getElementById('upload-zone-wrap'); if (!w || !window.DcUpload) return;
    DcUpload.mount(w);
    var content = document.getElementById('folder-content');
    ['dragenter', 'dragover'].forEach(function (ev) { content.addEventListener(ev, function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { e.preventDefault(); w.classList.remove('d-none'); } }); });
    w.addEventListener('dc-upload:all-done', function () { var done = document.getElementById('upload-done-btn'); if (done) { done.textContent = 'Done — refresh the listing'; } });
})();
</script><?php endif; ?>
<script>
(function () {
    var bar = document.getElementById('select-bar'); if (!bar) return;
    var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.listing-check')); };
    var update = function () { var n = checks().filter(function (c) { return c.checked; }).length; bar.classList.toggle('d-none', n === 0); bar.classList.toggle('d-flex', n > 0); document.getElementById('select-bar-count').textContent = n + ' selected'; };
    checks().forEach(function (c) { c.addEventListener('change', update); });
    var all = document.getElementById('listing-select-all'); if (all) { all.addEventListener('change', function () { checks().forEach(function (c) { c.checked = all.checked; }); update(); }); }
    var clear = document.getElementById('select-bar-clear-btn'); if (clear) { clear.addEventListener('click', function () { checks().forEach(function (c) { c.checked = false; }); if (all) all.checked = false; update(); }); }
})();
</script>
