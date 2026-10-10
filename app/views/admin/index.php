<?php /** Admin (screen `admin`). Data: ix (admin_index: counts, health), tz */
$c = $ix['counts'];
$h = $ix['health'];
$flag = static fn (int $n, string $word): string => $n > 0 ? '<span class="badge bg-soft-warning text-warning ms-1">' . $n . ' ' . $word . '</span>' : '';
$cards = [
    ['settings', '/admin/settings', 'feather-sliders', 'Settings', 'settings.manage', 'The business name, time zone, link defaults, tags and the graph\'s defaults.', ''],
    ['limits', '/admin/settings#limits-card', 'feather-activity', 'Limits', 'quotas.manage', 'The largest file, upload parts and the rates of keys and links.', ''],
    ['retention', '/admin/settings#retention-card', 'feather-clock', 'Retention', 'retention.manage', 'How long versions, the trash and locks are kept.', ''],
    ['quotas', '/admin/quotas', 'feather-pie-chart', 'Quotas', 'quotas.manage', 'What each library may hold.', $flag($c['quotas_near'], 'nearly full')],
    ['graph', '/graph/admin', 'feather-share-2', 'The graph', 'graph.manage', 'Policy, budget, model and the queue.', $flag($c['graph_failed'], 'failed')],
    ['links', '/admin/links', 'feather-link', 'Links', 'links.all', 'Every link and file request; revoke any.', $flag($c['links_suspicious'], 'suspicious')],
    ['libraries', '/admin/libraries', 'feather-hard-drive', 'Libraries', 'library.admin_view', 'Every library, personal ones included.', ''],
    ['keys', '/admin/keys', 'feather-key', 'API keys', 'links.all', 'Every key, its limits and use.', ''],
    ['agents', '/admin/agents', 'feather-message-circle', 'Agents', 'agents.settings', 'The expert, the Filing Clerk and what they were sent.', $flag($c['dispatches_open'], 'to look at')],
    ['connections', '/admin/connections', 'feather-share', 'Connections', 'settings.manage', 'Which applications read which folders.', ''],
    ['worker', '/admin/worker', 'feather-cpu', 'The worker', 'settings.manage', 'What waits, and what each pass did.', $flag($c['outbox_failed'], 'unsent')],
    ['storage', '/admin/storage', 'feather-database', 'The disk', 'settings.manage', 'Blobs, de-duplication and free space.', ''],
];
$counts = ['libraries' => $c['libraries'] . ' libraries', 'links' => $c['links_live'] . ' live', 'keys' => $c['keys_live'] . ' live'];
$ok = static fn (string $s): string => in_array($s, ['ok'], true) ? 'success' : (in_array($s, ['unconfigured', 'never'], true) ? 'secondary' : 'danger');
?>
<?= view('shared/header.php', ['id' => 'admin', 'title' => 'Admin', 'crumbs' => [['Home', '/'], ['Admin', null]]]) ?>
<div class="main-content" id="admin-content">
    <div class="card mb-3" id="admin-health"><div class="card-body d-flex flex-wrap gap-3 fs-12">
        <span id="health-storage">The store <span class="badge bg-soft-<?= e($ok($h['storage'])) ?> text-<?= e($ok($h['storage'])) ?>"><?= e($h['storage']) ?></span></span>
        <span id="health-graph">The graph <span class="badge bg-soft-<?= e($ok($h['graph'])) ?> text-<?= e($ok($h['graph'])) ?>"><?= e($h['graph']) ?></span></span>
        <span id="health-worker">The worker <span class="badge bg-soft-<?= e($h['worker']['state'] === 'stale' ? 'warning' : $ok($h['worker']['state'])) ?> text-<?= e($h['worker']['state'] === 'stale' ? 'warning' : $ok($h['worker']['state'])) ?>"><?= e($h['worker']['state']) ?></span>
            <?php if ($h['worker']['last_at'] !== null): ?><span class="text-muted">last pass <?= e(format_ts($h['worker']['last_at'], $tz, 'M j, g:i A')) ?></span><?php endif; ?></span>
        <span id="health-directory">The directory <?php if ($h['directory']['last_run_at'] === null): ?><span class="badge bg-soft-secondary text-secondary">never synced</span><?php else: ?><span class="badge bg-soft-<?= $h['directory']['error'] === null ? 'success text-success' : 'danger text-danger' ?>"><?= $h['directory']['error'] === null ? 'ok' : 'error' ?></span> <span class="text-muted"><?= e(format_ts($h['directory']['last_run_at'], $tz, 'M j, g:i A')) ?></span><?php endif; ?></span>
    </div></div>
    <div class="row g-3" id="admin-index">
    <?php foreach ($cards as [$key, $url, $icon, $title, $right, $about, $extra]): if (!has_right($right)) { continue; } ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="admin-card-<?= e($key) ?>"><div class="card-body d-flex gap-3 align-items-start">
            <span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="<?= e($icon) ?>"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= hx_link($url, e($title), 'text-dark', 'id="admin-link-' . e($key) . '"') ?><?= $extra ?></div>
                <div class="fs-12 mt-1"><?= e($about) ?></div>
                <?php if (isset($counts[$key])): ?><div class="fs-12 text-muted" id="admin-count-<?= e($key) ?>"><?= e($counts[$key]) ?></div><?php endif; ?></div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
