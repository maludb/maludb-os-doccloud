<?php
/**
 * ONE GRID TILE (docs/build-specs/grid-and-media.md) — a listing row (dc_folder_listing's columns) as a card: the square preview (the thumbnail of
 * an image, a video or a PDF page; the type's icon with its extension otherwise; "Preview hidden" — and no image requested at all — while the
 * document's preview is hidden), a video's play mark and length, the name, size · modified, the star and lock marks, the select checkbox, and
 * below them the "Hide preview" switch on an image or video for a caller who may edit it (posts on change; the tile is swapped in place).
 * Data: r, tz, select (bool), stars, back, mayEditRight (has_right('documents.edit'), asked once by the grid).
 */
$rid = (int) $r['id'];
$isFolder = $r['kind'] === 'folder';
$url = ($isFolder ? '/f/' : '/documents/') . $rid;
$href = ($back ?? '') !== '' ? with_back($url, $back) : $url;
$fam = file_family($isFolder ? 'folder' : $r['mime_kind'], $r['mime_type'] ?? null, $r['name']);
$hideable = !$isFolder && in_array($r['mime_kind'], PREVIEW_HIDEABLE, true);
$hidden = $hideable && !empty($r['preview_hidden']) && $r['preview_hidden'] !== 'f';
$hasThumb = !empty($r['has_thumbnail']) && $r['has_thumbnail'] !== 'f';
$showThumb = !$isFolder && !$hidden && $hasThumb;
$mayHide = $hideable && !empty($mayEditRight) && dc_level_at_least((string) ($r['level'] ?? 'none'), 'edit');
$starred = isset(($stars ?? [])[$isFolder ? 'folder' : 'document'][$rid]);
$tileId = 'tile-' . $r['kind'] . '-' . $rid;
$isVideo = !$isFolder && $r['mime_kind'] === 'video';
$duration = $isVideo && ($r['duration_ms'] ?? null) !== null ? format_duration((int) $r['duration_ms']) : '';
?>
<div class="card h-100 mb-0 listing-tile<?= $hidden ? ' is-hidden' : '' ?>" id="<?= e($tileId) ?>" data-kind="<?= e($r['kind']) ?>" data-id="<?= $rid ?>" data-family="<?= e($fam['family']) ?>">
    <div class="tile-media">
        <a href="<?= e($href) ?>" hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>" class="tile-media-link" id="<?= e($tileId) ?>-open" aria-label="Open <?= e($r['name']) ?>">
            <?php if ($showThumb): ?>
                <img src="/d/<?= $rid ?>/thumb?v=<?= e((string) strtotime((string) $r['updated_at'])) ?>" alt="" loading="lazy" decoding="async" class="tile-img<?= $fam['family'] === 'pdf' ? ' is-page' : '' ?>" id="<?= e($tileId) ?>-img">
            <?php elseif ($hidden): ?>
                <span class="tile-placeholder" id="<?= e($tileId) ?>-placeholder"><i class="feather-eye-off"></i><span>Preview hidden</span></span>
            <?php else: ?>
                <span class="tile-icon <?= e($fam['colour']) ?>" id="<?= e($tileId) ?>-icon"><i class="<?= e($fam['icon']) ?>"></i><?php if ($fam['label'] !== ''): ?><span class="tile-ext"><?= e($fam['label']) ?></span><?php endif; ?></span>
            <?php endif; ?>
            <?php if ($isVideo && $showThumb): ?><span class="tile-play" aria-hidden="true"><i class="feather-play"></i></span><?php endif; ?>
            <?php if ($duration !== '' && !$hidden): ?><span class="tile-badge tile-duration" id="<?= e($tileId) ?>-duration"><?= e($duration) ?></span><?php endif; ?>
        </a>
        <?php if (!empty($select)): ?><input type="checkbox" class="form-check-input listing-check tile-check" name="<?= $isFolder ? 'folder[]' : 'documents[]' ?>" value="<?= $rid ?>" form="select-bar" aria-label="Select <?= e($r['name']) ?>"><?php endif; ?>
        <span class="tile-marks">
            <?php if ($starred): ?><i class="feather-star text-warning" title="Favourite"></i><?php endif; ?>
            <?php if (($r['locked_by'] ?? null) !== null): ?><i class="feather-lock" title="Locked"></i><?php endif; ?>
        </span>
    </div>
    <div class="card-body p-2 d-flex flex-column">
        <a href="<?= e($href) ?>" hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>" class="tile-name text-dark fw-semibold" id="<?= e($tileId) ?>-name" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></a>
        <div class="fs-11 text-muted text-truncate"><?= $isFolder ? 'folder' : e(format_bytes((int) $r['byte_size'])) ?> · <?= e(format_ts($r['updated_at'], $tz, 'M j, Y')) ?></div>
        <?php if ($mayHide): ?>
        <form method="post" action="/documents/preview-<?= $hidden ? 'show' : 'hide' ?>.php" hx-post="/documents/preview-<?= $hidden ? 'show' : 'hide' ?>.php" hx-target="#<?= e($tileId) ?>" hx-swap="outerHTML" hx-trigger="change"
              hx-on::response-error="this.reset()" class="tile-switch mt-auto pt-1" id="<?= e($tileId) ?>-switch-form"><?= csrf_field() ?>
            <input type="hidden" name="document" value="<?= $rid ?>"><input type="hidden" name="swap" value="tile"><input type="hidden" name="select" value="<?= !empty($select) ? '1' : '0' ?>"><input type="hidden" name="return_to" value="<?= e($back ?? '') ?>">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="<?= e($tileId) ?>-switch"<?= $hidden ? ' checked' : '' ?> aria-label="Hide the preview of <?= e($r['name']) ?>">
                <label class="form-check-label fs-11" for="<?= e($tileId) ?>-switch">Hide preview</label>
            </div>
            <noscript><button type="submit" class="btn btn-sm btn-light mt-1"><?= $hidden ? 'Show the preview' : 'Hide the preview' ?></button></noscript>
        </form>
        <?php endif; ?>
    </div>
</div>
