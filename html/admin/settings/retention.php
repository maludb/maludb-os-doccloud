<?php
declare(strict_types=1);
/**
 * Action `retention_set` (versions_keep_days 1–3650, versions_keep_count 1–1000, trash_days 1–3650, lock_hours 1–720 — any of them; log retention.set): the windows the worker's passes
 * read. retention.manage (the admin); pauses for an agent (other). A shorter window is felt by the next pass: say so.
 */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
dc_handler_begin();
require_right('retention.manage');
$pdo = db();
$me = (int) current_member_id();
$now = retention_settings($pdo);
$errors = [];
$f = [
    'versions_keep_days' => dc_int('versions_keep_days', null, 1, 3650, 'Keep every version for days', $errors),
    'versions_keep_count' => dc_int('versions_keep_count', null, 1, 1000, 'Then keep the last versions', $errors),
    'trash_days' => dc_int('trash_days', null, 1, 3650, 'Trash days', $errors),
    'lock_hours' => dc_int('lock_hours', null, 1, 720, 'Lock hours', $errors),
];
if ($errors !== []) {
    dc_refuse_fields($errors);
}
if (array_filter($f, static fn ($v): bool => $v !== null) === []) {
    dc_refuse_fields(['versions_keep_days' => 'Say which window to change: versions_keep_days, versions_keep_count, trash_days or lock_hours.']);
}
$diff = dc_guard($pdo, static function () use ($pdo, $f, $me): array {
    $pdo->beginTransaction();
    $d = retention_set($pdo, $f, $me);
    log_activity($pdo, 'retention.set', 'settings', null, ['before' => $d['before'], 'after' => $d['after']]);
    $pdo->commit();
    return $d;
});
dc_done($diff['after'] === [] ? 'The windows were already so' : 'Set the windows (' . implode(', ', array_map(static fn ($k, $v): string => str_replace('_', ' ', $k) . ' ' . $v, array_keys($diff['after']), $diff['after'])) . ')', 1,
    dc_land(return_path('/admin/settings'), 'retention', 'retention-form'), 'settingsChanged', ['changed' => $diff['after'], 'was' => $diff['before']]);
