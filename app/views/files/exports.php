<?php /** The exports screen. Data: rows (my_exports), tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'exports', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Exports', null]]]) ?>
<div class="main-content" id="exports-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state text-center py-4" id="exports-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-package"></i></span><div class="fw-semibold">No zips yet.</div><div class="fs-12 text-muted">A folder's "Zip" button makes one; it appears here within the minute and is kept 7 days.</div></div></div></div>
    <?php else: ?>
    <div class="card"><div class="card-body p-0"><div class="table-responsive-none"><table class="table table-hover mb-0 fs-12 listing-table" id="exports-table"><thead class="thead-light"><tr><th>What</th><th>State</th><th class="text-end d-none d-md-table-cell">Size</th><th class="d-none d-md-table-cell">Made</th><th class="d-none d-md-table-cell">Expires</th><th class="text-end"></th></tr></thead><tbody>
        <?php foreach ($rows as $e): $eid = (int) $e['export_id']; $chip = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'][$e['status']] ?? 'secondary'; ?>
        <tr id="export-row-<?= $eid ?>" data-status="<?= e($e['status']) ?>">
            <td><?= $e['kind'] === 'folder' ? '<i class="feather-folder me-1 text-primary"></i>' . e($e['folder_name'] ?? 'a folder') : '<i class="feather-file me-1 text-muted"></i>' . count(pg_int_array((string) $e['document_ids'])) . ' documents' ?><?= $e['item_count'] ? ' <span class="text-muted">· ' . (int) $e['item_count'] . ' files</span>' : '' ?><?= $e['log'] ? '<div class="fs-11 text-muted">' . e($e['log']) . '</div>' : '' ?></td>
            <td><span class="badge bg-soft-<?= $chip ?> text-<?= $chip ?>"><?= e($e['status']) ?></span></td>
            <td class="text-end d-none d-md-table-cell"><?= $e['byte_size'] !== null ? e(format_bytes((int) $e['byte_size'])) : '—' ?></td>
            <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($e['created_at'], $tz)) ?></td>
            <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($e['expires_at'], $tz, 'M j')) ?></td>
            <td class="text-end"><?php if ($e['status'] === 'done' && !empty($e['available'])): ?><a href="/exports/<?= $eid ?>" class="btn btn-sm btn-primary btn-touch" id="export-download-<?= $eid ?>"><i class="feather-download me-1"></i>Download</a><?php elseif ($e['status'] === 'done'): ?><span class="text-muted">expired</span><?php endif; ?>
                <?php if ($e['kind'] === 'folder' && $e['folder_id'] !== null): ?><form method="post" action="/folders/export.php" hx-post="/folders/export.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $e['folder_id'] ?>"><button type="submit" class="btn btn-sm btn-light btn-touch" id="export-again-<?= $eid ?>" title="Build again"><i class="feather-refresh-cw"></i></button></form><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php endif; ?>
</div>
