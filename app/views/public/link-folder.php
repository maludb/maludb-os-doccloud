<?php /** A folder behind a link: a read-only listing of its subtree, crumbs within the link's root, Download all. Data: link, listing, base, zip (an export row when one is in the making) */
$allow = (bool) $link['allow_download'];
$root = (int) $listing['root'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="public-folder-header">
    <div class="min-w-0 flex-grow-1">
        <h5 class="mb-0 text-truncate d-flex align-items-center gap-2"><i class="feather-folder text-muted"></i><span id="public-folder-name"><?= e($listing['crumbs'][count($listing['crumbs']) - 1]['name'] ?? $link['target_name']) ?></span></h5>
        <div class="fs-12 text-muted" id="public-folder-facts"><?= (int) $listing['documents_total'] ?> document<?= $listing['documents_total'] === 1 ? '' : 's' ?> · <?= e(format_bytes((int) $listing['bytes'])) ?> · shared by <?= e($link['made_by_name']) ?></div>
    </div>
    <?php if ($allow): ?>
        <form method="post" action="<?= e($base) ?>/zip" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-primary btn-touch" id="public-zip-btn"><i class="feather-package me-1"></i>Download all</button></form>
    <?php endif; ?>
</div>
<?php if (!empty($link['note'])): ?><div class="alert alert-light fs-12" id="public-note"><?= nl2br(e($link['note'])) ?></div><?php endif; ?>
<?php if ($zip !== null): ?>
    <div class="alert alert-info fs-12 d-flex align-items-center gap-2" id="public-zip-state" data-status="<?= e($zip['status']) ?>">
        <?php if ($zip['status'] === 'done' && $zip['storage_path'] !== null): ?>Your zip is ready. <a href="<?= e($base) ?>/zip/<?= (int) $zip['id'] ?>" class="fw-semibold" id="public-zip-download">Download it</a>
        <?php elseif ($zip['status'] === 'failed'): ?>The zip could not be made. Try again.
        <?php else: ?>The zip is being made; this page refreshes in a few seconds. <meta http-equiv="refresh" content="5"><?php endif; ?>
    </div>
<?php endif; ?>
<ul class="breadcrumb fs-12" id="public-crumbs"><?php foreach ($listing['crumbs'] as $i => $c): ?><li class="breadcrumb-item"><?php if ($i === count($listing['crumbs']) - 1): ?><?= e($c['name']) ?><?php else: ?><a href="<?= e($base) ?><?= (int) $c['folder_id'] === $root ? '' : '/f/' . (int) $c['folder_id'] ?>"><?= e($c['name']) ?></a><?php endif; ?></li><?php endforeach; ?></ul>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="public-listing">
    <thead><tr><th>Name</th><th class="d-none d-md-table-cell">Kind</th><th class="text-end">Size</th></tr></thead>
    <tbody>
    <?php if ($listing['folders'] === [] && $listing['documents'] === []): ?><tr><td colspan="3" class="text-muted text-center py-4" id="public-listing-empty">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($listing['folders'] as $f): ?>
        <tr id="public-row-folder-<?= (int) $f['id'] ?>"><td><a href="<?= e($base) ?>/f/<?= (int) $f['id'] ?>" class="fw-semibold"><i class="feather-folder me-1 text-muted"></i><?= e($f['name']) ?></a></td><td class="d-none d-md-table-cell text-muted">folder</td><td class="text-end text-muted">—</td></tr>
    <?php endforeach; ?>
    <?php foreach ($listing['documents'] as $d): ?>
        <tr id="public-row-document-<?= (int) $d['id'] ?>"><td><a href="<?= e($base) ?>/d/<?= (int) $d['id'] ?>" class="fw-semibold"><i class="<?= e(kind_icon($d['mime_kind'])) ?> me-1 text-muted"></i><?= e($d['name']) ?></a></td><td class="d-none d-md-table-cell text-muted"><?= e($d['mime_kind']) ?></td><td class="text-end text-muted"><?= e(format_bytes((int) $d['byte_size'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
