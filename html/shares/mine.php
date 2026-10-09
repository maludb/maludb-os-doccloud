<?php
declare(strict_types=1);
/** My shares (`my-shares`, /shares/mine): what I granted, to whom, at what level; guest rows flagged, stale past the setting; Remove. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
require_right('documents.share');
$pdo = db();
$rows = my_shares($pdo);
$days = (int) one_value($pdo, "SELECT dc_setting_int('stale_guest_share_days')");
log_screen_view($pdo, 'my-shares');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_my_share', $rows), 'stale_days' => $days]);
}
$notices = ['removed' => ['success', 'The share was removed.']];
render_screen('My shares', view('files/shares.php', ['rows' => $rows, 'days' => $days, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'shared', 'screen' => 'my-shares']);
