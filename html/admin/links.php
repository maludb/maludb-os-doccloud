<?php
declare(strict_types=1);
/**
 * Every link (screen `admin-links`, GET /admin/links?status=&kind=): every link and file request of the business (dc_find_links with links.all) — live, expiring, expired, revoked — the suspicious ones flagged
 * (dc_links_suspicious: many addresses in a week), and Revoke on each live one (link_revoke, with links.all). The token itself is never shown: it is stored hashed. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('links.all');
$pdo = db();
$status = request_string('status');
$status = in_array($status, ['live', 'expiring', 'expired', 'revoked', 'suspicious'], true) ? $status : '';
if (request_bool('live') && $status === '') {
    $status = 'live';
}
$kind = request_string('kind');
$kind = in_array($kind, ['view', 'request'], true) ? $kind : '';
$rows = all_links($pdo, ['status' => $status, 'kind' => $kind]);
log_activity($pdo, 'screen.view', 'link', null, ['screen' => 'admin-links']);
if (wants_json()) {
    respond_screen(['links' => array_map('present_admin_link', $rows), 'status' => $status ?: null, 'kind' => $kind ?: null]);
}
$notices = ['revoked' => ['success', 'The link was revoked.']];
render_screen('Links', view('admin/links.php', ['rows' => $rows, 'status' => $status, 'kind' => $kind, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-links', 'entity' => 'link']);
