<?php
declare(strict_types=1);
/**
 * Connections (screen `admin-connections`, GET /admin/connections; read-only): which sibling applications hold an `application` access row on which folders and documents, and the share reads logged
 * (folders_index, documents_index, document_text, document_link) per application. Approving a connection is a super-admin's in the operating system (a link when the kernel says where). settings.manage. Slice 7.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/connections.php';
require_login();
require_right('settings.manage');
$pdo = db();
$days = max(1, min(365, request_integer('days') ?? 30));
$connections = shares_connections($pdo, $days);
$recent = shares_recent_reads($pdo, 30);
$approved = kernel_connections();                       // the kernel's own list when it answers (GET /api/v1/apps/connections.php); null → the manifest's shares and the sentence
$declared = declared_shares();
$osUrl = null;
try {
    $feed = function_exists('os_my_applications') ? os_my_applications() : null;
    $osUrl = is_array($feed) && !empty($feed['os_url']) ? rtrim((string) $feed['os_url'], '/') . '/applications' : null;
} catch (Throwable) {
    $osUrl = null;
}
log_activity($pdo, 'screen.view', 'share', null, ['screen' => 'admin-connections']);
if (wants_json()) {
    respond_screen(['connections' => array_map('present_connection', $connections), 'days' => $days,
        'recent' => array_map(static fn (array $r): array => ['occurred_at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'tool' => $r['tool'], 'consumer' => $r['consumer'], 'rows' => $r['rows'] === null ? null : (int) $r['rows'], 'outcome' => $r['outcome'], 'reason' => $r['reason']], $recent),
        'approved' => $approved, 'approved_in_kernel' => $approved !== null, 'declared_shares' => array_map(static fn (array $s): array => ['tool' => $s['tool']], $declared), 'kernel_url' => $osUrl]);
}
render_screen('Connections', view('admin/connections.php', ['connections' => $connections, 'recent' => $recent, 'days' => $days, 'tz' => member_timezone(), 'osUrl' => $osUrl, 'approved' => $approved, 'declared' => $declared]),
    ['activeNav' => 'admin', 'screen' => 'admin-connections', 'entity' => 'share']);
