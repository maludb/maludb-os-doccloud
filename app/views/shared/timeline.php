<?php /** The timeline of a record from the activity log, newest first (shared). Data: rows (find_record_activity), tz, prefix */ $p = $prefix ?? 'record'; ?>
<div class="card mb-3" id="<?= e($p) ?>-timeline"><div class="card-header"><h5 class="card-title mb-0">Timeline</h5></div>
    <div class="list-group list-group-flush" id="<?= e($p) ?>-timeline-list">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="<?= e($p) ?>-timeline-empty">Nothing yet.</div><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <div class="list-group-item fs-12" id="timeline-row-<?= (int) $r['activity_id'] ?>"><span class="text-muted"><?= e(format_ts($r['occurred_at'], $tz)) ?></span> — <?= e(activity_sentence($r)) ?><?= ($r['source'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></div>
        <?php endforeach; ?>
    </div>
</div>
