<?php
declare(strict_types=1);
/**
 * Action `key_mint` (label, rate_per_minute?, rate_per_day?, expires_in_days?; log key.mint — the label and limits, NEVER the key): a key for the token API that acts as the
 * caller (design §4, D13). People only; a guest may not. The limits default to the settings' (60 a minute, 5,000 a day) and may be lowered, never raised past them — an admin
 * may set any value the database allows. The raw key is shown ONCE (the keys screen in a browser, the answer's `token` in JSON); only its hash is stored.
 */
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
dc_handler_begin();
require_key_maker();
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$label = trim((string) (req_val('label') ?? ''));
if ($label === '' || mb_strlen($label) > 80) {
    $errors['label'] = 'Give the key a label (up to 80 characters): what will use it. The log and your screen call it that.';
}
[$defMinute, $defDay] = key_defaults($pdo);
$admin = is_dc_admin();
$limit = static function (string $name, int $default, int $max, string $what) use (&$errors, $admin): ?int {
    $v = req_val($name);
    if ($v === null || $v === '') {
        return null;
    }
    if (!ctype_digit($v) || (int) $v < 1 || (int) $v > ($admin ? $max : $default)) {
        $errors[$name] = 'Calls ' . $what . ' are 1 to ' . number_format($admin ? $max : $default) . '.';
        return null;
    }
    return (int) $v;
};
$perMinute = $limit('rate_per_minute', $defMinute, 100000, 'a minute');
$perDay = $limit('rate_per_day', $defDay, 10000000, 'a day');
$days = null;
$d = req_val('expires_in_days');
if ($d !== null && $d !== '') {
    if (!ctype_digit($d) || (int) $d < 1 || (int) $d > 3650) {
        $errors['expires_in_days'] = 'Expires in a number of days (1 to 3650), or leave it blank for never.';
    } else {
        $days = (int) $d;
    }
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$k = dc_guard($pdo, static function () use ($pdo, $me, $label, $perMinute, $perDay, $days): array {
    $pdo->beginTransaction();
    $k = key_mint($pdo, $me, $label, $perMinute, $perDay, $days);
    $pdo->commit();
    return $k;
});
$row = find_key($pdo, $k['token_id']);
files_log($pdo, 'key.mint', 'mcp_access_token', $k['token_id'], ['after' => ['label' => $label, 'rate_per_minute' => (int) $row['rate_per_minute'], 'rate_per_day' => (int) $row['rate_per_day'], 'expires_in_days' => $days]]);
emit_action_status(true, ['did' => 'Minted the key "' . $label . '" — shown once', 'record_id' => $k['token_id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the key "' . $label . '"', 'record_id' => $k['token_id'], 'token' => $k['raw'], 'rate_per_minute' => (int) $row['rate_per_minute'], 'rate_per_day' => (int) $row['rate_per_day'],
                   'location' => '/settings/keys/#key-row-' . $k['token_id'], 'refresh' => 'tokenChanged']);   // the value, once
}
key_show_once($k['token_id'], $k['raw'], $label, 'minted');
saved_go('/settings/keys/', 'tokenChanged');
