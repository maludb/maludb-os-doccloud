<?php /** One access row. Data: r (access_rows_of row), kind (folder|document), inherited (bool), manage (bool), levels (the select's options), tz, fromName? */
$kindBadge = PRINCIPAL_BADGE[$r['principal_kind']] ?? 'secondary';
$kindWord = PRINCIPAL_KINDS[$r['principal_kind']] ?? $r['principal_kind'];
$aid = (int) $r['access_id'];
$inherited = $inherited ?? false;
?>
<tr id="<?= $inherited ? 'share-inherited-' : 'share-row-' ?><?= $aid ?>" class="<?= $inherited ? 'text-muted' : '' ?>" data-principal="<?= e($r['principal_kind']) ?>:<?= e((string) ($r['principal_id'] ?? $r['principal_ref'] ?? '')) ?>" data-level="<?= e($r['level']) ?>">
    <td class="text-truncate" style="max-width:260px">
        <span class="badge bg-soft-<?= e($kindBadge) ?> text-<?= e(explode(' ', $kindBadge)[0]) ?> me-1"><?= e($kindWord) ?></span>
        <span class="fw-semibold"><?= e($r['principal_name']) ?></span>
        <?php if (!empty($r['is_guest']) && $r['principal_kind'] !== 'guest'): ?><span class="badge bg-soft-warning text-warning ms-1">guest</span><?php endif; ?>
        <?php if ($inherited): ?><span class="fs-11 ms-1">from <?= e($fromName ?? '') ?></span><?php endif; ?>
    </td>
    <td>
        <?php if ($manage && !$inherited): ?>
            <form method="post" action="/access/level.php" hx-post="/access/level.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="access" value="<?= $aid ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>">
                <select name="level" class="form-select form-select-sm d-inline-block w-auto btn-touch" id="share-level-<?= $aid ?>" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()" aria-label="Level">
                    <?php foreach ($levels as $l): ?><option value="<?= e($l) ?>" <?= $l === $r['level'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    <option value="none" <?= $r['level'] === 'none' ? 'selected' : '' ?>>none</option>
                </select></form>
        <?php else: ?><span class="badge bg-soft-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?>"><?= e($r['level']) ?></span><?php endif; ?>
    </td>
    <td class="d-none d-md-table-cell fs-11"><?= e($r['granted_by_name'] ?? '') ?> · <?= e(format_ts($r['created_at'], $tz, 'M j, Y')) ?></td>
    <td class="text-end">
        <?php if ($manage && !$inherited): ?>
            <form method="post" action="/access/remove.php" hx-post="/access/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($r['principal_name']) ?> from this <?= e($kind) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="access" value="<?= $aid ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>">
                <button type="submit" class="btn btn-light btn-sm" id="share-remove-<?= $aid ?>" aria-label="Remove"><i class="feather-x"></i></button></form>
        <?php endif; ?>
    </td>
</tr>
