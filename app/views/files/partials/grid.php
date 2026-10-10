<?php
/**
 * THE GRID (docs/build-specs/grid-and-media.md) — the folder listing as tiles: 2 a row at 375 px, then 3 / 4 / 5 / 6. The same data as the
 * listing table (rows, tz, select, stars, back, sortBase, sort, dir, empty, id) and the same select bar (each tile's checkbox; Select all).
 * The table's header sorts; the grid has the same three sorts as links.
 */
$select = $select ?? false;
$stars = $stars ?? ['folder' => [], 'document' => []];
$back = $back ?? '';
$sortBase = $sortBase ?? null;
$sort = $sort ?? 'name';
$dir = $dir ?? 'asc';
$gridId = $id ?? 'listing-grid';
$empty = $empty ?? 'Nothing here yet.';
$mayEditRight = has_right('documents.edit');
$sortLink = static function (string $key, string $label) use ($sortBase, $sort, $dir): string {
    if ($sortBase === null) {
        return '';
    }
    $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';
    $icon = $sort === $key ? ' <i class="feather-chevron-' . ($dir === 'asc' ? 'up' : 'down') . ' fs-11"></i>' : '';
    return hx_link($sortBase . (str_contains($sortBase, '?') ? '&' : '?') . http_build_query(['sort' => $key, 'dir' => $next]), e($label) . $icon,
        'btn btn-sm btn-touch ' . ($sort === $key ? 'btn-light fw-semibold' : 'btn-link text-muted'), 'id="grid-sort-' . $key . '"');
};
?>
<?php if ($rows === []): ?>
<div class="card" id="<?= e($gridId) ?>-card"><div class="card-body"><div class="empty-state text-center py-4" id="listing-empty">
    <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-folder"></i></span>
    <div class="fw-semibold"><?= e($empty) ?></div>
</div></div></div>
<?php else: ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2 fs-12" id="grid-toolbar">
    <?php if ($select): ?><div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="listing-select-all"><label class="form-check-label" for="listing-select-all">Select all</label></div><?php endif; ?>
    <?php if ($sortBase !== null): ?><div class="ms-auto d-flex align-items-center gap-1" id="grid-sorts"><span class="text-muted">Sort</span><?= $sortLink('name', 'Name') ?><?= $sortLink('updated', 'Modified') ?><?= $sortLink('size', 'Size') ?></div><?php endif; ?>
</div>
<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xxl-6 g-2 listing-grid" id="<?= e($gridId) ?>">
    <?php foreach ($rows as $r): ?><div class="col"><?= view('files/partials/tile.php', ['r' => $r, 'tz' => $tz, 'select' => $select, 'stars' => $stars, 'back' => $back, 'mayEditRight' => $mayEditRight]) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
