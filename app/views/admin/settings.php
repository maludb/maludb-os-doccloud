<?php /** Settings (screen `admin-settings`). Data: windows, current (mcp_settings), may {settings, limits, retention}, notice */
$field = static function (string $k) use ($current): string {
    [$group, $type, $label, $min, $max, $unit] = SETTINGS_FIELDS[$k];
    $v = $current[$k] ?? null;
    $id = 'settings-' . $k;
    $hint = '';
    $input = '';
    switch ($type) {
        case 'text':
            $input = '<input type="text" class="form-control btn-touch" name="' . e($k) . '" id="' . e($id) . '" maxlength="' . (int) $max . '" value="' . e((string) $v) . '">';
            $hint = 'Up to ' . (int) $max . ' characters.';
            break;
        case 'tz':
            $input = '<input type="text" class="form-control btn-touch" name="' . e($k) . '" id="' . e($id) . '" list="settings-tz-list" value="' . e((string) $v) . '" autocomplete="off">';
            $hint = 'A name like America/Chicago, or UTC.';
            break;
        case 'int':
            $input = '<div class="input-group"><input type="number" class="form-control btn-touch" name="' . e($k) . '" id="' . e($id) . '" min="' . (int) $min . '" max="' . (int) $max . '" value="' . (int) $v . '"><span class="input-group-text fs-12">' . e($unit) . '</span></div>';
            $hint = (int) $min . ' to ' . number_format((int) $max) . '.';
            break;
        case 'bool':
            $input = '<input type="hidden" name="' . e($k) . '" value="0"><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="' . e($k) . '" id="' . e($id) . '" value="1"' . ($v ? ' checked' : '') . '><label class="form-check-label fs-12" for="' . e($id) . '">Allowed</label></div>';
            break;
        case 'enum':
            $input = '<select class="form-select btn-touch" name="' . e($k) . '" id="' . e($id) . '">';
            foreach ($max as $o) {
                $input .= '<option value="' . e($o) . '"' . ($v === $o ? ' selected' : '') . '>' . e($o) . '</option>';
            }
            $input .= '</select>';
            break;
        case 'bytes':
            $mb = rtrim(rtrim(number_format((int) $v / 1048576, 4, '.', ''), '0'), '.');
            $name = preg_replace('/_bytes$/', '_mb', $k);
            $input = '<div class="input-group"><input type="number" step="any" class="form-control btn-touch" name="' . e($name) . '" id="' . e($id) . '" min="' . e((string) ($min / 1048576)) . '" max="' . e((string) ($max / 1048576)) . '" value="' . e($mb) . '"><span class="input-group-text fs-12">MB</span></div>';
            $hint = rtrim(rtrim(number_format($min / 1048576, 4, '.', ''), '0'), '.') . ' to ' . number_format($max / 1048576) . ' MB.';
            break;
    }
    return '<div class="col-12 col-md-6"><label class="form-label fs-12" for="' . e($id) . '">' . e($label) . '</label>' . $input . ($hint !== '' ? '<div class="form-text">' . e($hint) . '</div>' : '') . '</div>';
};
?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Settings', null]]]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($may['settings']): ?>
    <div class="card mb-3" id="settings-card"><div class="card-header"><h6 class="m-0">The business</h6></div><div class="card-body">
        <form method="post" action="/admin/settings/save.php" hx-post="/admin/settings/save.php" hx-target="#flash" id="settings-form" class="row g-3"><?= csrf_field() ?><input type="hidden" name="return_to" value="/admin/settings">
            <?php foreach (array_keys(array_filter(SETTINGS_FIELDS, static fn (array $d): bool => $d[0] === 'settings')) as $k): ?><?= $field($k) ?><?php endforeach; ?>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch" id="settings-save">Save the settings</button></div>
        </form>
        <datalist id="settings-tz-list"><?php foreach (['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Berlin', 'Asia/Kolkata', 'Asia/Tokyo', 'Australia/Sydney'] as $tz): ?><option value="<?= e($tz) ?>"></option><?php endforeach; ?></datalist>
    </div></div>
    <?php endif; ?>
    <?php if ($may['limits']): ?>
    <div class="card mb-3" id="limits-card"><div class="card-header"><h6 class="m-0">Limits</h6></div><div class="card-body">
        <form method="post" action="/admin/settings/save.php" hx-post="/admin/settings/save.php" hx-target="#flash" id="limits-form" class="row g-3"><?= csrf_field() ?><input type="hidden" name="return_to" value="/admin/settings">
            <?php foreach (array_keys(array_filter(SETTINGS_FIELDS, static fn (array $d): bool => $d[0] === 'limits')) as $k): ?><?= $field($k) ?><?php endforeach; ?>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch" id="limits-save">Save the limits</button></div>
        </form>
        <div class="form-text mt-2">An environment setting may lower the largest file further; it can never raise it.</div>
    </div></div>
    <?php endif; ?>
    <?php if ($may['retention']): ?><?= view('admin/retention.php', ['windows' => $windows]) ?><?php endif; ?>
    <div class="fs-12 text-muted">Quotas are set per library on <?= has_right('quotas.manage') ? hx_link('/admin/quotas', 'Quotas', 'fw-semibold', 'id="settings-quotas-link"') : 'Quotas' ?>; the graph's policy and budget are on <?= has_right('graph.manage') ? hx_link('/graph/admin', 'the graph screen', 'fw-semibold', 'id="settings-graph-link"') : 'the graph screen' ?>.</div>
</div>
