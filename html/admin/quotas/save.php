<?php
declare(strict_types=1);
/**
 * Action `quota_set` (library, quota_mb — or quota_gb — blank for none; log quota.set): a library's quota. quotas.manage (the admin); pauses for an agent (other).
 * An upload over it is refused in words at its start; a quota below what is used stops new uploads until space is freed.
 */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
dc_handler_begin();
require_right('quotas.manage');
$pdo = db();
$me = (int) current_member_id();
$lib = library_from_request($pdo, 'library');
$id = (int) $lib['id'];
$bytes = null;
if (req_has('quota_mb') && req_has('quota_gb') && req_val('quota_mb') !== '' && req_val('quota_gb') !== '') {
    dc_refuse_fields(['quota_mb' => 'Give the quota in MB or in GB, not both.']);
}
$field = req_has('quota_mb') ? 'quota_mb' : (req_has('quota_gb') ? 'quota_gb' : null);
if ($field === null) {
    dc_refuse_fields(['quota_mb' => 'Say the quota (blank for none).']);
}
$raw = str_replace(',', '.', (string) req_val($field));
if ($raw !== '') {
    if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > ($field === 'quota_gb' ? 1048576 : 1073741824)) {
        dc_refuse_fields([$field => 'The quota is a number above zero, in ' . ($field === 'quota_gb' ? 'GB' : 'MB') . ' (blank for none).']);
    }
    $bytes = (int) round((float) $raw * ($field === 'quota_gb' ? 1073741824 : 1048576));
}
$was = dc_guard($pdo, static function () use ($pdo, $id, $bytes, $me, $lib): ?int {
    $pdo->beginTransaction();
    $was = quota_set($pdo, $id, $bytes, $me);
    log_activity($pdo, 'quota.set', 'library', $id, ['library_id' => $id, 'before' => ['quota_bytes' => $was], 'after' => ['quota_bytes' => $bytes, 'name' => $lib['name'], 'used_bytes' => (int) $lib['used_bytes']]]);
    $pdo->commit();
    return $was;
});
dc_done($bytes === null ? 'Removed the quota of ' . $lib['name'] : 'Set the quota of ' . $lib['name'] . ' to ' . format_bytes($bytes), $id, dc_land(return_path('/admin/quotas?library=' . $id), $bytes === null ? 'quota_cleared' : 'quota'), 'libraryChanged',
    ['quota_bytes' => $bytes, 'was_bytes' => $was]);
