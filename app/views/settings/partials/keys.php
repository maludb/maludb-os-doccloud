<?php
/**
 * My API keys (the keys part of screen `settings-tokens`, also /settings/keys/): the key shown ONCE after a mint or a rotation, the mint form, the table of my keys with
 * their state, today's calls and limits, Rotate and Revoke per row. Data: keys, once (or null), defaults [per minute, per day], tz, more (link to the full page?)
 */
$once = $once ?? null;
?>
<div id="keys-part">
    <?php if ($once): ?>
    <div class="alert alert-success" id="key-once" role="status">
        <div class="fw-bold mb-1">Your <?= $once['how'] === 'rotated' ? 'replacement' : 'new' ?> key "<?= e($once['label']) ?>" — copy it now; it is shown once.</div>
        <code class="user-select-all text-break" id="key-once-value"><?= e($once['raw']) ?></code>
        <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-light btn-touch" id="key-once-copy" onclick="navigator.clipboard &amp;&amp; navigator.clipboard.writeText(document.getElementById('key-once-value').textContent)"><i class="feather-copy me-1"></i>Copy</button>
            <span class="fs-12">It acts as you: it sees exactly what you see and does what you may do. Send it as <code>Authorization: Bearer …</code>.</span>
        </div>
    </div>
    <?php endif; ?>
    <div class="card mb-3" id="keys-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><h5 class="card-title mb-0">Your API keys</h5>
            <?= hx_link('/settings/keys/new', '<i class="feather-plus me-1"></i>Mint a key', 'btn btn-primary btn-sm btn-touch', 'id="key-mint-btn"') ?></div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush" id="keys-table">
            <?php if ($keys === []): ?><div class="list-group-item text-center text-muted py-4 fs-12" id="keys-empty">No keys yet. A key lets a scanner, a script or a workflow tool act as you.</div><?php endif; ?>
            <?php foreach ($keys as $k): $id = (int) $k['token_id']; $state = $k['state']; $live = in_array($state, ['live', 'rotating'], true); ?>
                <div class="list-group-item fs-12 <?= $live ? '' : 'text-muted' ?>" id="key-row-<?= $id ?>" data-state="<?= e($state) ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <span class="fw-semibold fs-6"><?= e($k['label']) ?></span>
                            <span class="badge bg-soft-<?= e(KEY_STATE_BADGE[$state]) ?> text-<?= e(KEY_STATE_BADGE[$state]) ?> ms-1" id="key-row-<?= $id ?>-state"><?= e($state) ?></span>
                            <?php if ($k['rotated_from'] !== null): ?><span class="badge bg-soft-secondary text-secondary ms-1" id="key-row-<?= $id ?>-from">replaces #<?= (int) $k['rotated_from'] ?></span><?php endif; ?>
                        </div>
                        <?php if ($live): ?>
                        <div class="d-flex gap-1">
                            <?php if ($state === 'live'): ?>
                            <form method="post" action="/settings/tokens/key-rotate.php" hx-post="/settings/tokens/key-rotate.php" hx-confirm="Rotate the key <?= e($k['label']) ?>? A new key is made; this one keeps working for the overlap, then stops." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="key-row-<?= $id ?>-rotate-btn"><i class="feather-refresh-cw me-1"></i>Rotate</button></form>
                            <?php endif; ?>
                            <form method="post" action="/settings/tokens/key-revoke.php" hx-post="/settings/tokens/key-revoke.php" hx-confirm="Revoke the key <?= e($k['label']) ?>? Anything using it stops working now." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="api_key" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="key-row-<?= $id ?>-revoke-btn" aria-label="Revoke <?= e($k['label']) ?>"><i class="feather-x me-1"></i>Revoke</button></form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6 col-md-3"><div class="text-muted">Today</div><div id="key-row-<?= $id ?>-calls"><?= (int) $k['calls_today'] ?> of <?= number_format((int) $k['rate_per_day']) ?></div></div>
                        <div class="col-6 col-md-3"><div class="text-muted">A minute</div><div><?= number_format((int) $k['rate_per_minute']) ?></div></div>
                        <div class="col-6 col-md-3"><div class="text-muted">Made</div><div><?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></div></div>
                        <div class="col-6 col-md-3"><div class="text-muted">Last used</div><div><?= e(format_ts($k['last_used_at'], $tz) ?: 'never') ?></div></div>
                        <?php if ($k['expires_at'] !== null): ?><div class="col-12"><span class="text-muted"><?= $state === 'expired' ? 'Expired' : 'Stops working' ?></span> <span id="key-row-<?= $id ?>-expires"><?= e(format_ts($k['expires_at'], $tz)) ?></span><?= $state === 'rotating' ? ' — use its replacement #' . (int) $k['successor'] : '' ?></div><?php endif; ?>
                        <?php if ($k['revoked_at'] !== null): ?><div class="col-12 text-muted">Revoked <?= e(format_ts($k['revoked_at'], $tz)) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="card-footer fs-12 text-muted">A key acts as you and sees only what you see. <?= number_format((int) $defaults[0]) ?> calls a minute and <?= number_format((int) $defaults[1]) ?> a day; the API is described in <code>docs/api.md</code>.</div>
    </div>
</div>
