<?php /** Connections (screen `admin-connections`). Data: connections, recent, days, tz, osUrl, approved (the kernel's rows or null), declared (the manifest's shares) */ ?>
<?= view('shared/header.php', ['id' => 'admin-connections', 'title' => 'Connections', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Connections', null]]]) ?>
<div class="main-content" id="admin-connections-content">
    <div class="alert alert-info fs-12" id="connections-note">A folder is shared with a sibling application on its <strong>Share</strong> screen (the application is a principal, like a group). The sibling then reads it through the kernel's K7 — only over a connection a super-admin approved.
        <?php if ($osUrl): ?><a href="<?= e($osUrl) ?>" class="fw-semibold" id="connections-kernel-link">Approve connections in the operating system</a><?php else: ?>Approving connections is done in the operating system.<?php endif; ?></div>
    <div class="card mb-3" id="connections-kernel"><div class="card-header"><h6 class="m-0">Approved in the kernel</h6></div><div class="card-body fs-12">
        <?php if ($approved === null): ?>
            <div id="connections-kernel-fallback">The kernel did not list its connections: they are approved in the kernel (<code>bin/app_connection.php</code>) by a super-admin. DocCloud offers <?= count($declared) ?> reads:
                <?= implode(', ', array_map(static fn (array $s): string => '<code>' . e($s['tool']) . '</code>', $declared)) ?>.</div>
        <?php elseif ($approved === []): ?><div class="text-muted" id="connections-kernel-none">No application is approved to read from DocCloud yet.</div>
        <?php else: ?><ul class="list-unstyled mb-0" id="connections-kernel-list">
            <?php foreach ($approved as $i => $a): ?><li id="connections-kernel-<?= $i ?>"><code><?= e($a['consumer']) ?></code> reads <code><?= e($a['tool']) ?></code> <span class="badge bg-soft-<?= $a['status'] === 'approved' ? 'success text-success' : 'secondary text-secondary' ?>"><?= e($a['status']) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div></div>
    <?php if ($connections === []): ?><div class="card"><div class="card-body text-muted fs-12" id="connections-empty">No sibling application holds a share here yet, and none has read anything.</div></div><?php endif; ?>
    <?php foreach ($connections as $c): $app = $c['application']; ?>
    <div class="card mb-3" id="connection-<?= e($app) ?>">
        <div class="card-header"><h6 class="m-0"><?= e($app) ?></h6></div>
        <div class="card-body fs-12">
            <div class="fw-semibold mb-1">Folders shared</div>
            <?php if ($c['folders'] === []): ?><div class="text-muted mb-2" id="connection-<?= e($app) ?>-no-folders">None.</div><?php endif; ?>
            <ul class="list-unstyled mb-2" id="connection-<?= e($app) ?>-folders">
            <?php foreach ($c['folders'] as $f): ?><li><?= hx_link('/f/' . (int) $f['folder_id'], e($f['path']), 'fw-semibold') ?> <span class="badge bg-soft-secondary text-secondary"><?= e($f['level']) ?></span> <span class="text-muted"><?= (int) $f['document_count'] ?> document<?= (int) $f['document_count'] === 1 ? '' : 's' ?></span></li><?php endforeach; ?>
            </ul>
            <?php if ($c['documents'] !== []): ?>
            <div class="fw-semibold mb-1">Single documents shared</div>
            <ul class="list-unstyled mb-2" id="connection-<?= e($app) ?>-documents">
            <?php foreach ($c['documents'] as $d): ?><li><?= hx_link('/documents/' . (int) $d['document_id'], e($d['name']), 'fw-semibold') ?> <span class="badge bg-soft-secondary text-secondary"><?= e($d['level']) ?></span></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <div class="fw-semibold mb-1">Reads in the last <?= (int) $days ?> days</div>
            <?php if ($c['reads'] === []): ?><div class="text-muted" id="connection-<?= e($app) ?>-no-reads">None.</div><?php endif; ?>
            <ul class="list-unstyled mb-0" id="connection-<?= e($app) ?>-reads">
            <?php foreach ($c['reads'] as $r): ?><li><code><?= e($r['tool']) ?></code> — <?= (int) $r['calls'] ?> call<?= (int) $r['calls'] === 1 ? '' : 's' ?><?= (int) $r['refused'] > 0 ? ', <span class="text-warning">' . (int) $r['refused'] . ' refused</span>' : '' ?>, last <?= e(format_ts($r['last_at'], $tz)) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($recent !== []): ?>
    <div class="card" id="connections-recent"><div class="card-header"><h6 class="m-0">The latest reads</h6></div><div class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?><div class="list-group-item fs-12"><span class="text-muted"><?= e(format_ts($r['occurred_at'], $tz)) ?></span> <code><?= e($r['consumer'] ?? 'unknown') ?></code> <?= e($r['tool'] ?? '') ?>
            <?= $r['action'] === 'share.refused' ? '<span class="badge bg-soft-warning text-warning">refused: ' . e((string) $r['reason']) . '</span>' : '<span class="text-muted">' . (int) $r['rows'] . ' row' . ((int) $r['rows'] === 1 ? '' : 's') . '</span>' ?></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
