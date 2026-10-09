<?php
/** A quota bar (`#quota-bar` on the library view). Data: q (used_bytes, quota_bytes, pct — dc_quota_state), idPrefix?, compact? — under 90% primary, 90% and over warning, full danger. */
$idPrefix = $idPrefix ?? 'quota';
$compact = $compact ?? false;
$bar = $idPrefix === 'quota' ? 'quota-bar' : $idPrefix . '-bar';
$class = quota_bar_class($q);
$pct = $q !== null && $q['quota_bytes'] !== null ? min(100, (float) $q['pct']) : 0;
?>
<?php if ($q === null || $q['quota_bytes'] === null): ?>
<div class="fs-12" id="<?= e($bar) ?>" data-status="none"><?= $q === null ? '' : e(format_bytes((int) $q['used_bytes'])) . ' used · ' ?><span class="text-muted">no quota</span></div>
<?php else: ?>
<div id="<?= e($bar) ?>" data-status="<?= e($class) ?>" data-pct="<?= e((string) $q['pct']) ?>">
    <div class="progress ht-8 mb-1"><div class="progress-bar bg-<?= e($class) ?>" role="progressbar" style="width: <?= e((string) $pct) ?>%" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
    <div class="fs-12"><?= e(format_bytes((int) $q['used_bytes'])) ?> of <?= e(format_bytes((int) $q['quota_bytes'])) ?> (<?= e((string) $q['pct']) ?>%)<?php if ((float) $q['pct'] >= 100): ?> <span class="badge bg-soft-danger text-danger" id="<?= e($bar) ?>-state">full</span><?php elseif ((float) $q['pct'] >= 90): ?> <span class="badge bg-soft-warning text-warning" id="<?= e($bar) ?>-state">nearly full</span><?php endif; ?></div>
</div>
<?php endif; ?>
