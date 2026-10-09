<?php
/**
 * THE LISTING TABLE — one row shape for a folder, Recent, Favourites, slice 3's Shared with me, slice 4's search results, slice 6's trash.
 * Data: rows (dc_folder_listing's columns: kind, id, name, mime_kind, byte_size, owner_member_id, owner_name, updated_at, tags, level, locked_by,
 * version_count, has_thumbnail), tz, select (bool: checkboxes for the select bar), stars (my_favourite_ids), back (the ?back= of every link),
 * sort/dir/sortBase (the headers link when sortBase is set), empty (the empty state's sentence), id (the table's id, listing-table), extra (a
 * column renderer: fn(row): string for a last cell, e.g. the folder crumb on Recent).
 * At 375 px the table is a list: the name and the kind icon, size · modified beneath, the owner's initials (CSS: .listing-table in app-overrides).
 */
$select = $select ?? false;
$stars = $stars ?? ['folder' => [], 'document' => []];
$back = $back ?? '';
$sortBase = $sortBase ?? null;
$sort = $sort ?? 'name';
$dir = $dir ?? 'asc';
$tableId = $id ?? 'listing-table';
$extra = $extra ?? null;
$extraHead = $extraHead ?? '';
$empty = $empty ?? 'Nothing here yet.';
$head = static function (string $key, string $label, string $class = '') use ($sortBase, $sort, $dir): string {
    if ($sortBase === null) {
        return '<th class="' . $class . '">' . e($label) . '</th>';
    }
    $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';
    $icon = $sort === $key ? ' <i class="feather-chevron-' . ($dir === 'asc' ? 'up' : 'down') . ' fs-11"></i>' : '';
    return '<th class="' . $class . '">' . hx_link($sortBase . (str_contains($sortBase, '?') ? '&' : '?') . http_build_query(['sort' => $key, 'dir' => $next]), e($label) . $icon, 'text-dark', 'id="listing-sort-' . $key . '"') . '</th>';
};
$initials = static fn (?string $n): string => $n === null || $n === '' ? '' : strtoupper(mb_substr($n, 0, 1));
?>
<?php if ($rows === []): ?>
<div class="card" id="<?= e($tableId) ?>-card"><div class="card-body"><div class="empty-state text-center py-4" id="listing-empty">
    <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-folder"></i></span>
    <div class="fw-semibold"><?= e($empty) ?></div>
</div></div></div>
<?php else: ?>
<div class="card" id="<?= e($tableId) ?>-card"><div class="card-body p-0"><div class="table-responsive-none">
<table class="table table-hover mb-0 fs-12 listing-table" id="<?= e($tableId) ?>">
    <thead class="thead-light"><tr>
        <?php if ($select): ?><th class="listing-select"><input type="checkbox" class="form-check-input" id="listing-select-all" aria-label="Select all"></th><?php endif; ?>
        <?= $head('name', 'Name') ?><th class="listing-kind">Kind</th><?= $head('size', 'Size', 'text-end') ?><?= $head('updated', 'Modified') ?><th>Owner</th><th>Tags</th><th class="text-end"><?= $extraHead ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rid = (int) $r['id']; $isFolder = $r['kind'] === 'folder'; $url = ($isFolder ? '/f/' : '/documents/') . $rid; $tags = is_array($r['tags']) ? $r['tags'] : pg_text_array((string) ($r['tags'] ?? '{}')); $starred = isset($stars[$isFolder ? 'folder' : 'document'][$rid]); ?>
        <tr id="listing-row-<?= e($r['kind']) ?>-<?= $rid ?>" class="listing-row" data-kind="<?= e($r['kind']) ?>" data-id="<?= $rid ?>">
            <?php if ($select): ?><td class="listing-select"><input type="checkbox" class="form-check-input listing-check" name="<?= $isFolder ? 'folder[]' : 'documents[]' ?>" value="<?= $rid ?>" form="select-bar" aria-label="Select <?= e($r['name']) ?>"></td><?php endif; ?>
            <td class="listing-name">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <i class="<?= e(kind_icon($isFolder ? 'folder' : $r['mime_kind'])) ?> <?= $isFolder ? 'text-primary' : 'text-muted' ?> flex-shrink-0"></i>
                    <div class="min-w-0">
                        <?= hx_link($back !== '' ? with_back($url, $back) : $url, e($r['name']), 'text-dark fw-semibold text-truncate d-block', 'id="listing-link-' . e($r['kind']) . '-' . $rid . '"') ?>
                        <div class="listing-sub d-md-none fs-11 text-muted"><?= $isFolder ? 'folder' : e($r['mime_kind'] ?? '') ?><?= $r['byte_size'] !== null ? ' · ' . e(format_bytes((int) $r['byte_size'])) : '' ?> · <?= e(format_ts($r['updated_at'], $tz, 'M j')) ?></div>
                    </div>
                    <?php if ($starred): ?><i class="feather-star text-warning fs-11 flex-shrink-0" title="Favourite"></i><?php endif; ?>
                    <?php if (($r['locked_by'] ?? null) !== null): ?><i class="feather-lock text-muted fs-11 flex-shrink-0" title="Locked"></i><?php endif; ?>
                </div>
            </td>
            <td class="d-none d-md-table-cell listing-kind"><?= $isFolder ? 'folder' : e($r['mime_kind'] ?? '') ?><?= !$isFolder && ($r['version_count'] ?? 1) > 1 ? ' <span class="badge bg-soft-secondary text-secondary">v' . (int) $r['version_count'] . '</span>' : '' ?></td>
            <td class="d-none d-md-table-cell text-end text-nowrap"><?= $r['byte_size'] !== null ? e(format_bytes((int) $r['byte_size'])) : '—' ?></td>
            <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($r['updated_at'], $tz, 'M j, Y')) ?></td>
            <td class="listing-owner"><span class="avatar-text avatar-sm" title="<?= e($r['owner_name'] ?? '') ?>"><?= e($initials($r['owner_name'] ?? null)) ?></span><span class="d-none d-lg-inline ms-1"><?= e($r['owner_name'] ?? '') ?></span></td>
            <td class="d-none d-md-table-cell"><?php foreach (array_slice($tags, 0, 4) as $t): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($t) ?></span><?php endforeach; ?></td>
            <td class="text-end text-nowrap"><?= $extra !== null ? $extra($r) : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div></div>
<?php endif; ?>
