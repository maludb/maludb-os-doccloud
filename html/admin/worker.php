<?php
declare(strict_types=1);
/**
 * The worker (screen `admin-worker`, GET /admin/worker?limit=): the last 50 passes (when, how long, each step as a chip with its count and milliseconds, an error `danger`), the queues at a
 * glance, the windows in force, and "Run a pass now" (worker_run). settings.manage (the admin). Slice 6.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
$limit = max(1, min(200, request_integer('limit') ?? 50));
$passes = worker_passes($pdo, $limit);
$queues = worker_queues($pdo);
$windows = retention_settings($pdo);
log_activity($pdo, 'screen.view', 'worker', null, ['screen' => 'admin-worker']);
if (wants_json()) {
    respond_screen(['passes' => array_map('present_worker_pass', $passes), 'queues' => $queues, 'windows' => $windows, 'steps' => WORKER_PASSES]);
}
$notices = ['ran' => ['success', 'The worker ran.'], 'ran_busy' => ['warning', 'Another pass is running; try again in a minute.'], 'ran_errors' => ['danger', 'The worker ran, and a step failed: see the list.']];
render_screen('The worker', view('admin/worker.php', ['passes' => $passes, 'queues' => $queues, 'windows' => $windows, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-worker', 'entity' => 'worker']);
