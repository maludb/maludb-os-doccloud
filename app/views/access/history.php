<?php /** Access history. Data: kind, id, name, at, rows (dc_access_history), changes (mcp_access_changes), tz */
$back = ($kind === 'folder' ? '/f/' : '/documents/') . $id;
$url = ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/history';
?>
<?= view('shared/header.php', ['id' => 'access-history', 'title' => 'Access history of ' . $name, 'crumbs' => [['Home', '/'], [$name, $back], ['Who can see this', ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/share'], ['History', null]], 'back' => [$back, $name]]) ?>
<div class="main-content" id="access-history-content">
    <form method="get" action="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-push-url="true" class="d-flex gap-2 align-items-end mb-3" id="access-history-form">
        <div><label class="form-label" for="access-history-at">On the day</label><input type="date" name="at" id="access-history-at" class="form-control btn-touch" value="<?= e($at) ?>"></div>
        <button type="submit" class="btn btn-primary btn-touch" id="access-history-btn">Show</button>
    </form>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who could see it at the end of <?= e(format_date($at)) ?> (the explicit rows)</h5></div>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="access-history-table"><thead><tr><th>Who</th><th>Level</th><th>Since</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr id="access-history-empty"><td colspan="3" class="text-muted">No explicit row then; the library's own rule applied.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr><td><span class="badge bg-soft-secondary text-secondary me-1"><?= e(PRINCIPAL_KINDS[$r['principal_kind']] ?? $r['principal_kind']) ?></span><?= e($r['principal_name']) ?></td><td><?= e($r['level']) ?></td><td><?= e(format_ts($r['since'], $tz)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Every change</h5></div>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="access-changes-table"><thead><tr><th>When</th><th>Who</th><th>From</th><th>To</th><th class="d-none d-md-table-cell">By</th></tr></thead><tbody>
        <?php if ($changes === []): ?><tr><td colspan="5" class="text-muted">No change yet.</td></tr><?php endif; ?>
        <?php foreach ($changes as $c): ?><tr><td><?= e(format_ts($c['changed_at'], $tz)) ?></td><td><?= e($c['principal_name']) ?></td><td><?= e($c['old_level'] ?? '—') ?></td><td><?= e($c['new_level'] ?? 'removed') ?></td><td class="d-none d-md-table-cell"><?= e($c['changed_by_name'] ?? '') ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
</div>
