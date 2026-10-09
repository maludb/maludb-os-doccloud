<?php /** The password page, once per session. Data: link, base, wrong */ ?>
<div class="card" id="public-password"><div class="card-body">
    <h5 class="mb-1"><?= e($link['label']) ?></h5>
    <div class="fs-12 text-muted mb-3">This link needs its password.</div>
    <?php if ($wrong): ?><div class="alert alert-danger fs-12" id="public-password-wrong">That is not the password.</div><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/password" id="public-password-form"><?= csrf_field() ?>
        <label class="form-label" for="public-password-input">Password</label>
        <input type="password" name="password" id="public-password-input" class="form-control btn-touch" autocomplete="off" required autofocus>
        <button type="submit" class="btn btn-primary btn-touch mt-3 w-100" id="public-password-btn">Open</button>
    </form>
</div></div>
