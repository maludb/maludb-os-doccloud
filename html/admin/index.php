<?php
declare(strict_types=1);
/**
 * Admin (screen `admin`, GET /admin/): the admin's index — Settings · Limits · Retention · Quotas · Graph · Links · Libraries · Keys · Agents · Connections · Worker · Storage, each with what needs a look, and the
 * health line (the store, the graph, the worker's last pass, the directory sync). settings.manage (the admin). Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
$ix = admin_index($pdo);
log_screen_view($pdo, 'admin');
if (wants_json()) {
    $h = $ix['health'];
    respond_screen(['counts' => $ix['counts'], 'health' => ['storage' => $h['storage'], 'graph' => $h['graph'], 'worker' => ['state' => $h['worker']['state'], 'last_at' => json_ts($h['worker']['last_at']), 'error' => $h['worker']['error']],
        'directory' => ['last_run_at' => json_ts($h['directory']['last_run_at']), 'error' => $h['directory']['error']]]]);
}
render_screen('Admin', view('admin/index.php', ['ix' => $ix, 'tz' => member_timezone()]), ['activeNav' => 'admin', 'screen' => 'admin']);
