<?php /** Mint a key (screen `settings-tokens`, /settings/keys/new). Data: defaults [per minute, per day], admin (may set any limit the database allows), errors */ $errors = $errors ?? []; ?>
<?= view('shared/header.php', ['id' => 'key-form', 'title' => 'Mint a key', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['API keys', '/settings/keys/'], ['Mint a key', null]]]) ?>
<div class="main-content" id="key-form-content">
    <div class="card"><div class="card-body">
        <form method="post" action="/settings/tokens/key-mint.php" hx-post="/settings/tokens/key-mint.php" hx-target="#page-content" hx-swap="innerHTML" id="key-form-form">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label" for="key-form-field-label">Label</label>
                <input type="text" class="form-control btn-touch <?= isset($errors['label']) ? 'is-invalid' : '' ?>" name="label" id="key-form-field-label" required maxlength="80" placeholder="The scanner in the mail room" autocomplete="off">
                <div class="form-text">What will use this key. The log and this screen call it that.</div></div>
            <div class="row g-3">
                <div class="col-6"><label class="form-label" for="key-form-field-rate_per_minute">Calls a minute</label>
                    <input type="number" class="form-control btn-touch" name="rate_per_minute" id="key-form-field-rate_per_minute" min="1" max="<?= $admin ? 100000 : (int) $defaults[0] ?>" placeholder="<?= (int) $defaults[0] ?>"></div>
                <div class="col-6"><label class="form-label" for="key-form-field-rate_per_day">Calls a day</label>
                    <input type="number" class="form-control btn-touch" name="rate_per_day" id="key-form-field-rate_per_day" min="1" max="<?= $admin ? 10000000 : (int) $defaults[1] ?>" placeholder="<?= (int) $defaults[1] ?>"></div>
                <div class="col-12"><div class="form-text">Blank keeps the settings' limits (<?= number_format((int) $defaults[0]) ?> a minute, <?= number_format((int) $defaults[1]) ?> a day); you may lower them<?= $admin ? ', and as an admin raise them' : '' ?>.</div></div>
                <div class="col-12"><label class="form-label" for="key-form-field-expires_in_days">Expires in (days)</label>
                    <input type="number" class="form-control btn-touch" name="expires_in_days" id="key-form-field-expires_in_days" min="1" max="3650" placeholder="never"></div>
            </div>
            <div class="alert alert-info fs-12 mt-3 mb-3">The key <strong>acts as you</strong>: it sees exactly what you see and may do what you may do. It is shown once, right after you mint it.</div>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="key-form-save-btn"><i class="feather-key me-1"></i>Mint the key</button>
                <?= hx_link('/settings/keys/', 'Cancel', 'btn btn-light btn-touch', 'id="key-form-cancel"') ?></div>
        </form>
    </div></div>
</div>
