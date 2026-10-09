<?php
declare(strict_types=1);
/**
 * Settings (screen `admin-settings`, GET /admin/settings): slice 6 puts the WINDOWS on it — every version kept for N days then the last N, the trash's days, a lock's hours — each with its
 * bounds, posting `retention_set` (#retention-form). Slice 9 adds the other forms (ceilings, link defaults, rates, the graph's defaults) to this page. retention.manage.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('retention.manage');
$pdo = db();
$windows = retention_settings($pdo);
log_activity($pdo, 'screen.view', 'settings', null, ['screen' => 'admin-settings']);
if (wants_json()) {
    respond_screen(['windows' => array_map(static fn ($v) => is_bool($v) ? $v : (int) $v, $windows), 'bounds' => RETENTION_BOUNDS]);
}
$notices = ['retention' => ['success', 'The windows were saved; the next pass follows them.']];
render_screen('Settings', view('admin/retention.php', ['windows' => $windows, 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-settings', 'entity' => 'settings']);
