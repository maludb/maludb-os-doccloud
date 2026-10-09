<?php /** The worker (screen `admin-worker`). Data: passes, queues, windows, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-worker', 'title' => 'The worker', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['The worker', null]]]) ?>
<div class="main-content" id="admin-worker-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="worker-run"><div class="card-body">
        <form method="post" action="/admin/worker/run.php" hx-post="/admin/worker/run.php" hx-target="#flash" hx-indicator="#worker-run-spinner" class="d-flex flex-wrap gap-2 align-items-end" id="worker-run-form"><?= csrf_field() ?>
            <div><label class="form-label fs-12" for="worker-step">Run</label>
                <select name="step" id="worker-step" class="form-select btn-touch"><option value="">every pass, in order</option><?php foreach (WORKER_PASSES as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-primary btn-touch" id="worker-run-btn"><i class="feather-play me-1"></i>Run a pass now</button>
            <span class="spinner-border spinner-border-sm htmx-indicator" id="worker-run-spinner" role="status"></span>
        </form>
        <div class="form-text">The timer runs a pass every minute; this runs one now, for up to 50 seconds. A pass already running makes this one wait.</div>
    </div></div>
    <div class="card mb-3" id="worker-queues"><div class="card-header"><h6 class="m-0">Waiting for the worker</h6></div>
        <div class="card-body"><div class="row g-2 fs-12">
            <?php foreach (WORKER_QUEUE_LABELS as $k => $label): ?><div class="col-6 col-md-3"><div class="text-muted"><?= e($label) ?></div><div class="fs-5 fw-semibold" id="worker-queue-<?= e($k) ?>"><?= (int) ($queues[$k] ?? 0) ?></div></div><?php endforeach; ?>
        </div></div></div>
    <div class="card mb-3" id="worker-windows"><div class="card-body fs-12">
        <span class="text-muted">The windows in force:</span> every version for <?= (int) $windows['versions_keep_days'] ?> days then the last <?= (int) $windows['versions_keep_count'] ?> · the trash <?= (int) $windows['trash_days'] ?> days · a lock <?= (int) $windows['lock_hours'] ?> hours · an upload <?= (int) $windows['upload_session_hours'] ?> hours.
    </div></div>
    <div class="card mb-3" id="worker-passes-card"><div class="card-header"><h6 class="m-0">The last passes</h6></div>
        <div class="list-group list-group-flush" id="worker-passes">
        <?php if ($passes === []): ?><div class="list-group-item text-muted fs-12" id="worker-passes-empty">No pass has run yet.</div><?php endif; ?>
        <?php foreach ($passes as $p): $pid = (int) $p['id']; ?>
            <div class="list-group-item fs-12" id="worker-pass-<?= $pid ?>" data-status="<?= $p['error'] === null ? 'success' : 'danger' ?>">
                <div class="d-flex flex-wrap gap-2 align-items-center"><span class="fw-semibold"><?= e(format_ts($p['started_at'], $tz, 'M j, g:i:s A')) ?></span><span class="text-muted"><?= $p['ms'] === null ? '' : (int) $p['ms'] . ' ms' ?></span>
                    <span class="badge bg-soft-<?= $p['error'] === null ? 'success' : 'danger' ?> text-<?= $p['error'] === null ? 'success' : 'danger' ?>"><?= $p['error'] === null ? 'ok' : 'failed' ?></span></div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                    <?php foreach ($p['steps'] as $name => $s): $bad = isset($s['error']); ?>
                    <span class="badge bg-soft-<?= $bad ? 'danger' : 'success' ?> text-<?= $bad ? 'danger' : 'success' ?>" title="<?= e($bad ? (string) $s['error'] : $name) ?>"><?= e($name) ?> <?= (int) ($s['count'] ?? 0) ?> · <?= (int) ($s['ms'] ?? 0) ?> ms</span>
                    <?php endforeach; ?>
                </div>
                <?php if ($p['error'] !== null): ?><div class="text-danger mt-1"><?= e($p['error']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div></div>
</div>
