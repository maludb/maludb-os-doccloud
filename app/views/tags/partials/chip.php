<?php /** One tag chip: a link to the tag with its count. Data: t (tag row with tag_id, label, slug, state|kind, colour, usage_count), count? (bool), remove? (document id → an × posting tag_remove) */
$state = $t['state'] ?? (($t['archived_at'] ?? null) !== null ? 'archived' : ($t['kind'] ?? 'free'));
$b = $state === 'archived' ? 'dark' : (TAG_KIND_BADGE[$state] ?? 'secondary');
$style = $state !== 'archived' && !empty($t['colour']) ? ' style="background-color:' . e($t['colour']) . '1a;color:' . e($t['colour']) . '"' : '';
$count = $count ?? false;
$remove = $remove ?? null;
?>
<span class="badge bg-soft-<?= e($b) ?> text-<?= e($b) ?> tag-chip me-1 mb-1" id="tag-chip-<?= (int) $t['tag_id'] ?>" data-slug="<?= e($t['slug']) ?>" data-state="<?= e($state) ?>"<?= $style ?>>
    <?= hx_link('/tags/' . (int) $t['tag_id'], e($t['label']), 'text-reset text-decoration-none') ?><?= $count ? ' <span class="opacity-75">' . (int) $t['usage_count'] . '</span>' : '' ?>
    <?php if ($remove !== null): ?><form method="post" action="/tags/remove.php" hx-post="/tags/remove.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= (int) $remove ?>"><input type="hidden" name="tags[]" value="<?= e($t['slug']) ?>"><button type="submit" class="btn btn-link btn-sm p-0 ms-1 text-reset tag-chip-remove" id="tag-remove-<?= (int) $t['tag_id'] ?>" aria-label="Remove <?= e($t['label']) ?>">&times;</button></form><?php endif; ?>
</span>
