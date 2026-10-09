<?php /** The trash (screen `trash`). Data: rows, folder, mayPurge, window, due, tz, notice */
$crumbs = $folder === null ? [['Home', '/'], ['Trash', null]] : [['Home', '/'], [$folder['name'], '/f/' . (int) $folder['id']], ['Trash', null]];
?>
<?= view('shared/header.php', ['id' => 'trash', 'title' => $folder === null ? 'Trash' : 'Trash: ' . $folder['name'], 'crumbs' => $crumbs, 'back' => $folder === null ? null : ['/f/' . (int) $folder['id'], $folder['name']]]) ?>
<div class="main-content" id="trash-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="trash-intro">
        <span class="fs-12 text-muted" id="trash-window">Things stay here <?= (int) $window ?> day<?= $window === 1 ? '' : 's' ?>, then they are deleted for good.<?= $folder === null ? ' This is what you own, what you trashed and what you manage.' : '' ?></span>
        <?php if ($mayPurge): ?>
        <div class="ms-auto d-flex gap-1 flex-wrap">
            <form method="post" action="/trash/purge-all.php" hx-post="/trash/purge-all.php" hx-target="#flash" hx-confirm="Delete for good everything that is past its <?= (int) $window ?> days? It cannot be undone." class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="due_only" value="yes"><input type="hidden" name="confirm" value="yes"><input type="hidden" name="return_to" value="/trash/">
                <button type="submit" class="btn btn-light btn-touch text-danger" id="trash-empty-btn"<?= $due === 0 ? ' disabled' : '' ?>><i class="feather-trash-2 me-1"></i>Empty what is due<?= $due > 0 ? ' (' . (int) $due . ')' : '' ?></button></form>
            <form method="post" action="/trash/purge-all.php" hx-post="/trash/purge-all.php" hx-target="#flash" hx-confirm="Delete for good EVERYTHING in the trash, due or not? It cannot be undone." class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="due_only" value="no"><input type="hidden" name="confirm" value="yes"><input type="hidden" name="return_to" value="/trash/">
                <button type="submit" class="btn btn-light btn-touch text-danger" id="trash-empty-all-btn"<?= $rows === [] ? ' disabled' : '' ?>>Empty the whole trash</button></form>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
    <div class="card" id="trash-card"><div class="card-body"><div class="empty-state text-center py-4" id="trash-empty">
        <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-trash-2"></i></span>
        <div class="fw-semibold">The trash is empty.</div>
        <div class="fs-12 text-muted">Whatever you delete waits here for <?= (int) $window ?> days.</div>
    </div></div></div>
    <?php else: ?>
    <div class="card" id="trash-card"><div class="card-body p-0"><div class="table-responsive-none">
    <table class="table table-hover mb-0 fs-12 listing-table" id="trash-table">
        <thead class="thead-light"><tr><th>Name</th><th class="d-none d-md-table-cell">Kind</th><th class="d-none d-md-table-cell">From</th><th class="d-none d-md-table-cell">Trashed</th><th>Purges</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $rid = (int) $r['id']; $isFolder = $r['kind'] === 'folder'; [$badge, $words] = trash_status($r['purge_at']); ?>
        <tr id="trash-row-<?= e($r['kind']) ?>-<?= $rid ?>" data-kind="<?= e($r['kind']) ?>" data-id="<?= $rid ?>" data-status="<?= e($badge) ?>">
            <td class="listing-name"><div class="d-flex align-items-center gap-2 min-w-0"><i class="<?= e(kind_icon($isFolder ? 'folder' : ($r['mime_kind'] ?? 'other'))) ?> text-muted flex-shrink-0"></i>
                <div class="min-w-0"><span class="fw-semibold text-truncate d-block"><?= e($r['name']) ?></span>
                    <span class="fs-11 text-muted d-md-none"><?= e(format_ts($r['trashed_at'], $tz, 'M j')) ?><?= $r['trashed_by_name'] ? ' · ' . e($r['trashed_by_name']) : '' ?></span></div></div></td>
            <td class="d-none d-md-table-cell"><?= $isFolder ? 'folder' : e((string) ($r['mime_kind'] ?? 'document')) ?></td>
            <td class="d-none d-md-table-cell"><?= $r['from_folder_id'] !== null && $r['from_folder_name'] ? e($r['from_folder_name']) : '<span class="text-muted">—</span>' ?></td>
            <td class="d-none d-md-table-cell"><?= e(format_ts($r['trashed_at'], $tz)) ?><?= $r['trashed_by_name'] ? '<div class="text-muted">' . e($r['trashed_by_name']) . '</div>' : '' ?></td>
            <td><span class="badge bg-soft-<?= e($badge) ?> text-<?= e($badge) ?>" id="trash-status-<?= e($r['kind']) ?>-<?= $rid ?>"><?= e($words) ?></span><div class="text-muted fs-11"><?= e(format_ts($r['purge_at'], $tz, 'M j, Y')) ?></div></td>
            <td class="text-end text-nowrap">
                <form method="post" action="<?= $isFolder ? '/folders/restore.php' : '/trash/restore.php' ?>" hx-post="<?= $isFolder ? '/folders/restore.php' : '/trash/restore.php' ?>" hx-target="#flash" class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="<?= $isFolder ? 'folder' : 'document' ?>" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= $isFolder ? '/f/' . $rid : '/trash/' ?>">
                    <button type="submit" class="btn btn-light btn-touch px-2" id="trash-restore-<?= e($r['kind']) ?>-<?= $rid ?>" title="Restore" aria-label="Restore <?= e($r['name']) ?>"><i class="feather-rotate-ccw"></i><span class="d-none d-md-inline ms-1">Restore</span></button></form>
                <?php if ($mayPurge): ?>
                <form method="post" action="<?= $isFolder ? '/trash/delete.php' : '/trash/purge.php' ?>" hx-post="<?= $isFolder ? '/trash/delete.php' : '/trash/purge.php' ?>" hx-target="#flash" hx-confirm="Delete <?= e($r['name']) ?> for good? It cannot be undone." class="d-inline"><?= csrf_field() ?>
                    <input type="hidden" name="<?= $isFolder ? 'folder' : 'document' ?>" value="<?= $rid ?>"><?= $isFolder ? '<input type="hidden" name="confirm_name" value="' . e($r['name']) . '">' : '<input type="hidden" name="confirm" value="yes">' ?><input type="hidden" name="return_to" value="/trash/">
                    <button type="submit" class="btn btn-light btn-touch text-danger" id="trash-purge-<?= e($r['kind']) ?>-<?= $rid ?>" title="Delete for good" aria-label="Purge <?= e($r['name']) ?>"><i class="feather-x-circle"></i></button></form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
    <?php endif; ?>
</div>
