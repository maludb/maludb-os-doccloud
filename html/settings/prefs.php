<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `prefs.save`): a person's own notification choices — email_enabled and text_enabled (yes/no), kinds[] (the events
 * to be told about), text_kinds[] (the events to be texted about).
 * A field left out stays as it was. Both channels off is allowed: that person is told nothing but in the app. Always about oneself.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$has = static fn (string $k): bool => array_key_exists($k, $_POST);
$yes = static function (string $k): bool {
    $v = strtolower(trim((string) $_POST[$k]));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (in_array($v, ['0', 'false', 'off', 'no', ''], true)) {
        return false;
    }
    refuse(422, 'Say yes or no for ' . str_replace('_', ' ', $k) . '.');
};
$list = static function (string $k): ?array {
    if (!array_key_exists($k, $_POST)) {
        return null;
    }
    $v = $_POST[$k];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    $v = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
    $bad = array_diff($v, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        refuse(422, 'Unknown event: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.');
    }
    return $v;
};
$f = [];
foreach (['email_enabled', 'text_enabled'] as $k) {
    if ($has($k)) {
        $f[$k] = $yes($k);
    }
}
if (($kinds = $list('kinds')) !== null) {
    $f['kinds'] = $kinds;
}
if (($textKinds = $list('text_kinds')) !== null) {
    $f['text_kinds'] = $textKinds;
}
$pdo->beginTransaction();
$r = save_prefs($pdo, $me, $f);
log_activity($pdo, 'prefs.save', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
$pdo->commit();
emit_action_status(true, ['did' => 'Saved how you are told', 'record_id' => $me, 'refresh' => 'prefsChanged', 'email_enabled' => $r['prefs']['email_enabled'], 'text_enabled' => $r['prefs']['text_enabled']]);
$return = safe_local_path($_POST['return_to'] ?? null) ?? '/settings/';
saved_go($return . (str_contains($return, '?') ? '&' : '?') . 'notice=prefs_saved', 'prefsChanged');
