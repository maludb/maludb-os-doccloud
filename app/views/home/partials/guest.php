<?php /** A guest's home: the things most lately shared with them (home-section-shared). Data: s, tz */ ?>
<?php if ($s['shared_recent'] !== []): ?>
<div class="card mt-3" id="home-section-shared"><div class="card-header"><h5 class="card-title mb-0">Shared with you</h5></div>
    <div class="list-group list-group-flush">
    <?php foreach ($s['shared_recent'] as $r): $k = $r['kind'] === 'folder' ? '/f/' : '/documents/'; ?>
        <div class="list-group-item fs-12 d-flex justify-content-between gap-2" id="home-shared-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>">
            <span class="min-w-0 text-truncate"><i class="<?= $r['kind'] === 'folder' ? 'feather-folder' : 'feather-file' ?> me-1 text-muted"></i><?= hx_link(with_back($k . (int) $r['id'], '/'), e($r['name']), 'text-dark') ?></span>
            <span class="text-muted text-nowrap"><?= e(format_ts($r['shared_at'], $tz, 'M j')) ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
