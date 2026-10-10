<?php /** The disk (screen `admin-storage`). Data: d (storage_disk_truth) */
$used = $d['total_bytes'] !== null && $d['free_bytes'] !== null ? $d['total_bytes'] - $d['free_bytes'] : null;
$pct = $used !== null && $d['total_bytes'] > 0 ? min(100, round(100 * $used / $d['total_bytes'], 1)) : null;
?>
<?= view('shared/header.php', ['id' => 'admin-storage', 'title' => 'The disk', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['The disk', null]]]) ?>
<div class="main-content" id="admin-storage-content">
    <div class="card mb-3" id="storage-state"><div class="card-body fs-12">
        <div>The store is <span class="badge bg-soft-<?= $d['state'] === 'ok' ? 'success text-success' : 'danger text-danger' ?>" id="storage-state-badge"><?= e($d['state']) ?></span> <span class="text-muted" id="storage-directory"><?= e($d['directory']) ?></span></div>
        <?php if ($pct !== null): ?>
        <div class="progress ht-8 mt-2 mb-1"><div class="progress-bar bg-<?= $pct >= 95 ? 'danger' : ($pct >= 85 ? 'warning' : 'primary') ?>" role="progressbar" style="width: <?= (float) $pct ?>%"></div></div>
        <div id="storage-disk">The disk: <?= e(format_bytes((int) $d['free_bytes'])) ?> free of <?= e(format_bytes((int) $d['total_bytes'])) ?> (<?= e((string) $pct) ?>% used)</div>
        <?php else: ?><div class="text-muted" id="storage-disk">The disk's free space is not known here.</div><?php endif; ?>
    </div></div>
    <div class="row g-2 mb-3" id="storage-blobs">
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Blobs in use</div><div class="fs-5 fw-semibold" id="blobs-live"><?= (int) $d['blobs_live'] ?></div><div class="fs-12 text-muted" id="blobs-live-bytes"><?= e(format_bytes((int) $d['blobs_live_bytes'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Waiting to be freed</div><div class="fs-5 fw-semibold" id="blobs-waiting"><?= (int) $d['blobs_waiting'] ?></div><div class="fs-12 text-muted"><?= e(format_bytes((int) $d['blobs_waiting_bytes'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Freed</div><div class="fs-5 fw-semibold" id="blobs-freed"><?= (int) $d['blobs_freed'] ?></div><div class="fs-12 text-muted"><?= e(format_bytes((int) $d['blobs_freed_bytes'])) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Saved by de-duplication</div><div class="fs-5 fw-semibold" id="blobs-dedup"><?= e(format_bytes((int) $d['deduplicated_bytes'])) ?></div><div class="fs-12 text-muted">libraries count <?= e(format_bytes((int) $d['libraries_used_bytes'])) ?></div></div></div></div>
    </div>
    <div class="fs-12 text-muted">The libraries add up every version they hold; a file kept twice is one blob on the disk. <?= hx_link('/curate/storage', 'The storage report', 'fw-semibold', 'id="admin-storage-report"') ?> shows where the bytes are.</div>
</div>
