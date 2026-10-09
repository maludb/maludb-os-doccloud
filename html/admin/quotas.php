<?php
declare(strict_types=1);
/**
 * Quotas (screen `admin-quotas`, GET /admin/quotas?near=1&library=): every library's use against its quota, the fullest first (`near` = only the ones at 90% or more), the form to
 * set one (in GB, blank = none) and, for the library named, its bar and the owners holding the most. quotas.manage (the admin). Slice 6.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('quotas.manage');
$pdo = db();
$near = request_bool('near');
$rows = quotas_overview($pdo, $near);
$focus = null;
$owners = [];
if (request_integer('library') !== null) {
    $focus = library_from_request($pdo, 'library');
    $owners = library_top_owners($pdo, (int) $focus['id']);
}
log_activity($pdo, 'screen.view', 'library', $focus === null ? null : (int) $focus['id'], ['screen' => 'admin-quotas', 'library_id' => $focus === null ? null : (int) $focus['id']]);
if (wants_json()) {
    respond_screen(['libraries' => array_map('present_quota_row', $rows), 'near' => $near, 'focus' => $focus === null ? null : (int) $focus['id'],
        'top_owners' => array_map(static fn (array $o): array => ['member_id' => $o['member_id'] === null ? null : (int) $o['member_id'], 'display_name' => $o['display_name'], 'documents' => (int) $o['documents'], 'live_bytes' => (int) $o['live_bytes']], $owners)]);
}
$notices = ['quota' => ['success', 'The quota was saved.'], 'quota_cleared' => ['success', 'The quota was removed: this library has no limit now.']];
render_screen('Quotas', view('admin/quotas.php', ['rows' => $rows, 'near' => $near, 'focus' => $focus, 'owners' => $owners, 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-quotas', 'entity' => 'library', 'recordId' => $focus === null ? null : (int) $focus['id']]);
