<?php
declare(strict_types=1);
/**
 * Every API key (screen `admin-keys`, GET /admin/keys?member=&status=): every person's keys with their state, limits, last use and today's calls; Revoke (key_revoke — links.all). The key itself is never shown
 * here or anywhere again. Needs links.all (the admin). Slice 7.
 */
require_once dirname(__DIR__, 2) . '/app/features/keys/handler.php';
require_login();
require_right('links.all');
$pdo = db();
$filters = ['member' => request_integer('member'), 'status' => request_string('status')];
$rows = all_keys($pdo, $filters);
log_activity($pdo, 'screen.view', 'mcp_access_token', null, ['screen' => 'admin-keys']);
if (wants_json()) {
    respond_screen(['keys' => array_map('present_key', $rows), 'filters' => ['member' => $filters['member'], 'status' => $filters['status'] ?: null]]);
}
$notices = ['revoked' => ['success', 'The key was revoked.']];
render_screen('API keys', view('admin/keys.php', ['rows' => $rows, 'filters' => $filters, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-keys', 'entity' => 'mcp_access_token']);
