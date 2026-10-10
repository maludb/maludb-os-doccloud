<?php
declare(strict_types=1);
/**
 * Action `settings_save` (any of the fields of SETTINGS_FIELDS — see app/features/admin/handler.php; log settings.save and limits.save): the application's settings and its limits in one action, each field
 * with its bounds, refused in words on the field. The settings group needs settings.manage, the limits group quotas.manage (a request may carry both and then needs both). Only the fields sent change.
 * Pauses for an agent (other); restore prior. The retention windows are `retention_set`, the quotas `quota_set`, the graph's policy and budget the graph screen's.
 */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$rightFor = ['settings' => 'settings.manage', 'limits' => 'quotas.manage'];
$sent = [];
foreach (SETTINGS_FIELDS as $k => [$group, $type]) {
    $names = in_array($type, ['bytes', 'ceiling'], true) ? [$k, preg_replace('/_bytes$/', '_mb', $k)] : [$k];
    foreach ($names as $n) {
        if (req_has($n)) {
            $sent[$k] = $n;
        }
    }
}
if ($sent === []) {
    dc_refuse_fields(['business_name' => 'Say what to change: ' . implode(', ', array_keys(SETTINGS_FIELDS)) . '.']);
}
foreach ($sent as $k => $n) {
    require_right($rightFor[SETTINGS_FIELDS[$k][0]]);                     // 403 in words before any 422
}
$f = [];
$errors = [];
foreach ($sent as $k => $n) {
    [$group, $type, $label, $min, $max, $unit] = SETTINGS_FIELDS[$k];
    $raw = (string) req_val($n);
    switch ($type) {
        case 'text':
            if (mb_strlen($raw) > $max) {
                $errors[$n] = $label . ' is at most ' . $max . ' characters.';
            } else {
                $f[$k] = $raw === '' ? null : $raw;
            }
            break;
        case 'tz':
            if (!in_array($raw, DateTimeZone::listIdentifiers(), true)) {
                $errors[$n] = $label . ' is a name like America/Chicago or UTC.';
            } else {
                $f[$k] = $raw;
            }
            break;
        case 'int':
            if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < $min || (int) $raw > $max) {
                $errors[$n] = $label . ' is a whole number from ' . $min . ' to ' . number_format($max) . ' ' . $unit . '.';
            } else {
                $f[$k] = (int) $raw;
            }
            break;
        case 'bool':
            $f[$k] = dc_yes($n);
            break;
        case 'enum':
            if (!in_array($raw, $max, true)) {
                $errors[$n] = $label . ' is one of ' . implode(', ', $max) . '.';
            } else {
                $f[$k] = $raw;
            }
            break;
        case 'ceiling':
            if (in_array(trim($raw), ['', '0'], true)) {
                $f[$k] = null;                                                   // no ceiling: the store's free space limits
                break;
            }
            // a number: as bytes, with no top
        case 'bytes':
            $isMb = str_ends_with($n, '_mb');
            $num = str_replace(',', '.', $raw);
            if (!is_numeric($num) || (float) $num <= 0) {
                $errors[$n] = $label . ' is a number above zero' . ($isMb ? ' in megabytes' : ' of bytes') . ($type === 'ceiling' ? ', or empty for no limit.' : '.');
                break;
            }
            $bytes = (int) round((float) $num * ($isMb ? 1048576 : 1));
            if ($bytes < $min || ($max !== null && $bytes > $max)) {
                $errors[$n] = $max === null ? $label . ' is at least ' . format_bytes($min) . ', or empty for no limit.' : $label . ' is from ' . format_bytes($min) . ' to ' . format_bytes($max) . '.';
            } else {
                $f[$k] = $bytes;
            }
            break;
    }
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$diff = dc_guard($pdo, static function () use ($pdo, $f, $me): array {
    $pdo->beginTransaction();
    $d = settings_save($pdo, $f, $me);
    foreach (['settings' => 'settings.save', 'limits' => 'limits.save'] as $group => $event) {
        $keys = array_filter(array_keys($f), static fn (string $k): bool => SETTINGS_FIELDS[$k][0] === $group);
        if ($keys !== []) {
            log_activity($pdo, $event, 'settings', null, ['before' => array_intersect_key($d['before'], array_flip($keys)), 'after' => array_intersect_key($d['after'], array_flip($keys))]);
        }
    }
    $pdo->commit();
    return $d;
});
$groups = array_unique(array_map(static fn (string $k): string => SETTINGS_FIELDS[$k][0], array_keys($f)));
$notice = $groups === ['limits'] ? 'limits' : 'settings';
dc_done($diff['after'] === [] ? 'The settings were already so' : 'Saved the ' . ($notice === 'limits' ? 'limits' : 'settings') . ' (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($diff['after']))) . ')', null,
    dc_land(return_path('/admin/settings'), $notice, $notice === 'limits' ? 'limits-form' : 'settings-form'), 'settingsChanged', ['changed' => $diff['after'], 'was' => $diff['before']]);
