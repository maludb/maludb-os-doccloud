<?php /** Every API key (screen `admin-keys`). Data: rows, filters, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-keys', 'title' => 'API keys', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['API keys', null]]]) ?>
<div class="main-content" id="admin-keys-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="admin-keys-filters">
        <?php foreach ([['', 'Every key'], ['live', 'Live'], ['rotating', 'Rotating'], ['expired', 'Expired'], ['revoked', 'Revoked']] as [$st, $label]): ?>
            <?= hx_link('/admin/keys' . ($st === '' ? '' : '?status=' . $st), $label, 'btn btn-sm btn-touch ' . (($filters['status'] ?? '') === $st ? 'btn-primary' : 'btn-light'), 'id="admin-keys-filter-' . ($st === '' ? 'all' : $st) . '"') ?>
        <?php endforeach; ?>
        <?= hx_link('/admin/connections', '<i class="feather-link me-1"></i>Connections', 'btn btn-sm btn-touch btn-light ms-auto', 'id="admin-keys-connections"') ?>
    </div>
    <div class="card" id="admin-keys-card"><div class="list-group list-group-flush" id="admin-keys-table">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="admin-keys-empty">No keys<?= ($filters['status'] ?? '') !== '' ? ' in that state' : ' yet' ?>.</div><?php endif; ?>
        <?php foreach ($rows as $k): $id = (int) $k['token_id']; $state = $k['state']; $live = in_array($state, ['live', 'rotating'], true); ?>
        <div class="list-group-item fs-12 <?= $live ? '' : 'text-muted' ?>" id="admin-key-row-<?= $id ?>" data-state="<?= e($state) ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div><span class="fw-semibold fs-6"><?= e($k['label']) ?></span>
                    <span class="badge bg-soft-<?= e(KEY_STATE_BADGE[$state]) ?> text-<?= e(KEY_STATE_BADGE[$state]) ?> ms-1" id="admin-key-row-<?= $id ?>-state"><?= e($state) ?></span>
                    <div class="text-muted">acts as <?= hx_link('/admin/keys?member=' . (int) $k['member_id'], e($k['member_name'] ?? 'someone who left'), '', 'id="admin-key-row-' . $id . '-member"') ?></div></div>
                <?php if ($live): ?>
                <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($k['label']) ?> of <?= e($k['member_name'] ?? 'someone') ?>? Anything using it stops working now." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><input type="hidden" name="return_to" value="/admin/keys"><button type="submit" class="btn btn-light btn-sm btn-touch" id="admin-key-row-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($k['label']) ?>"><i class="feather-x me-1"></i>Revoke</button></form>
                <?php endif; ?>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-6 col-md-3"><div class="text-muted">Today</div><div><?= (int) $k['calls_today'] ?> of <?= number_format((int) $k['rate_per_day']) ?><?= (int) $k['refused_today'] > 0 ? ' <span class="badge bg-soft-warning text-warning">' . (int) $k['refused_today'] . ' refused</span>' : '' ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">A minute</div><div><?= number_format((int) $k['rate_per_minute']) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Made</div><div><?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Last used</div><div><?= e(format_ts($k['last_used_at'], $tz) ?: 'never') ?></div></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div></div>
    <div class="form-text mt-2">A key is shown once, to its owner, when it is made. Here you see its label, limits and use — and you may close it.</div>
</div>
