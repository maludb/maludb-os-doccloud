<?php
declare(strict_types=1);
/** Action `notifications_read_all` (all — yes; log notification.read_all): every unread notification of mine is marked read. Own rows only; the count is the answer. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$me = (int) current_member_id();
$pdo->beginTransaction();
$n = mark_notifications_read($pdo, $me, null);
log_activity($pdo, 'notification.read_all', null, null, ['after' => ['count' => $n]]);
$pdo->commit();
emit_action_status(true, ['did' => 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read', 'record_id' => $n, 'count' => $n, 'refresh' => 'notificationChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read', 'record_id' => $n, 'count' => $n, 'location' => '/notifications', 'refresh' => 'notificationChanged']);
}
if (is_htmx_request()) {
    hx_trigger('notificationChanged');
}
saved_go('/notifications', 'notificationChanged');
