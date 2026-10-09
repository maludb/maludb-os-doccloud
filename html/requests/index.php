<?php
declare(strict_types=1);
/** `requests` (/requests?status=open|closed): my file requests with the uploads each received; Close. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
require_any_right('requests.make|links.all');
$pdo = db();
$status = request_string('status') ?: null;
if ($status !== null && !in_array($status, ['open', 'closed'], true)) {
    refuse(422, 'The status is open or closed.');
}
$rows = my_requests($pdo, $status);
log_screen_view($pdo, 'requests');
if (wants_json()) {
    respond_screen(['status' => $status, 'requests' => array_map(static fn (array $r): array => present_link($r) + ['folder_name' => $r['folder_name'], 'uploads' => (int) $r['uploads']], $rows)]);
}
$notices = ['closed' => ['success', 'The request is closed.']];
render_screen('File requests', view('requests/index.php', ['rows' => $rows, 'status' => $status, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'files', 'screen' => 'requests']);
