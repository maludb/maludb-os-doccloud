<?php /** The windows on Settings (screen `admin-settings`). Data: windows, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Settings', null]]]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="retention-card"><div class="card-header"><h6 class="m-0">How long things are kept</h6></div><div class="card-body">
        <form method="post" action="/admin/settings/retention.php" hx-post="/admin/settings/retention.php" hx-target="#flash" id="retention-form" class="row g-3"><?= csrf_field() ?><input type="hidden" name="return_to" value="/admin/settings">
            <?php foreach (RETENTION_BOUNDS as $k => [$label, $min, $max, $unit]): ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12" for="retention-<?= e($k) ?>"><?= e($label) ?></label>
                <div class="input-group"><input type="number" min="<?= (int) $min ?>" max="<?= (int) $max ?>" name="<?= e($k) ?>" id="retention-<?= e($k) ?>" class="form-control btn-touch" value="<?= (int) $windows[$k] ?>"><span class="input-group-text fs-12"><?= e($unit) ?></span></div>
                <div class="form-text"><?= (int) $min ?> to <?= (int) $max ?>.</div></div>
            <?php endforeach; ?>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch" id="retention-save">Save the windows</button></div>
        </form>
        <div class="form-text mt-2">The worker applies them every minute: shorten a window and the older versions or trash go at the next pass.</div>
    </div></div>
</div>
