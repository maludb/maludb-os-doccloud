<?php /** My file requests. Data: rows, status, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'requests', 'title' => 'File requests', 'crumbs' => [['Home', '/'], ['File requests', null]]]) ?>
<div class="main-content" id="requests-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-2" id="requests-filters">
        <?php foreach (['' => 'All', 'open' => 'Open', 'closed' => 'Closed'] as $k => $w): ?><?= hx_link('/requests' . ($k === '' ? '' : '?status=' . $k), e($w), 'btn btn-sm btn-touch ' . (($status ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="requests-filter-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="requests-table"><thead><tr><th>Label</th><th>Into</th><th>State</th><th>Uploads</th><th></th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr id="requests-empty"><td colspan="5" class="text-muted">No file request<?= $status ? ' ' . e($status) : '' ?>. Make one from a folder's links screen.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $b = LINK_STATE_BADGE[$r['state']] ?? 'secondary'; $open = in_array($r['state'], ['live', 'expiring'], true); ?>
        <tr id="request-row-<?= (int) $r['link_id'] ?>" data-state="<?= e($r['state']) ?>"><td class="fw-semibold"><?= e($r['label']) ?></td><td><?= hx_link('/f/' . (int) $r['folder_id'], e($r['folder_name'])) ?></td>
            <td><span class="badge bg-soft-<?= e($b) ?> text-<?= e($b) ?>"><?= e($r['state']) ?></span><div class="fs-11 text-muted"><?= $r['expires_at'] === null ? 'never expires' : 'expires ' . e(format_ts($r['expires_at'], $tz, 'M j, Y')) ?></div></td>
            <td><?= (int) $r['uploads'] ?><?= $r['last_used_at'] !== null ? '<div class="fs-11 text-muted">last ' . e(format_ts($r['last_used_at'], $tz)) . '</div>' : '' ?></td>
            <td class="text-end"><?php if ($open): ?><form method="post" action="/requests/close.php" hx-post="/requests/close.php" hx-target="#flash" hx-confirm="Close the request <?= e($r['label']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="request" value="<?= (int) $r['link_id'] ?>"><input type="hidden" name="return_to" value="/requests"><button type="submit" class="btn btn-light btn-sm text-danger" id="request-close-<?= (int) $r['link_id'] ?>">Close</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>
