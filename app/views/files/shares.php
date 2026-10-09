<?php /** My shares. Data: rows (my_shares), days, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'my-shares', 'title' => 'My shares', 'crumbs' => [['Home', '/'], ['My shares', null]]]) ?>
<div class="main-content" id="my-shares-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="my-shares-table"><thead><tr><th>What</th><th>With</th><th>Level</th><th class="d-none d-md-table-cell">When</th><th></th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr id="my-shares-empty"><td colspan="5" class="text-muted py-4 text-center">You have not shared anything by name yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $url = $r['kind'] === 'folder' ? '/f/' . (int) $r['id'] : '/documents/' . (int) $r['id']; $kb = PRINCIPAL_BADGE[$r['principal_kind']] ?? 'secondary'; ?>
        <tr id="my-share-row-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>-<?= e($r['principal_kind']) ?>-<?= e((string) ($r['principal_id'] ?? $r['principal_ref'] ?? '')) ?>" data-stale="<?= !empty($r['stale']) ? '1' : '0' ?>">
            <td><?= hx_link($url, '<i class="' . e($r['kind'] === 'folder' ? 'feather-folder' : 'feather-file') . ' me-1 text-muted"></i>' . e($r['name']), 'fw-semibold') ?></td>
            <td><span class="badge bg-soft-<?= e($kb) ?> text-<?= e(explode(' ', $kb)[0]) ?> me-1"><?= e(PRINCIPAL_KINDS[$r['principal_kind']] ?? $r['principal_kind']) ?></span><?= e($r['principal_name']) ?>
                <?php if (!empty($r['is_guest'])): ?><span class="badge bg-soft-warning text-warning ms-1">guest</span><?php endif; ?><?php if (!empty($r['stale'])): ?><span class="badge bg-warning text-dark ms-1" id="stale-<?= e($r['kind']) ?>-<?= (int) $r['id'] ?>">older than <?= (int) $days ?> days</span><?php endif; ?></td>
            <td><span class="badge bg-soft-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$r['level']] ?? 'secondary') ?>"><?= e($r['level']) ?></span></td>
            <td class="d-none d-md-table-cell"><?= e(format_ts($r['created_at'], $tz, 'M j, Y')) ?></td>
            <td class="text-end"><?= hx_link(($r['kind'] === 'folder' ? '/folders/' : '/documents/') . (int) $r['id'] . '/share', 'Manage', 'btn btn-light btn-sm') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>
