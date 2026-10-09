<?php
declare(strict_types=1);
/** Action `worker_run` (step — one pass by name, or blank for all; log worker.run): runs bin/worker.php in the request (a 50 s budget). settings.manage; people only (no agent). */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
dc_handler_begin();
require_right('settings.manage');
if ((current_member()['member_kind'] ?? '') === 'agent') {
    refuse(403, 'Running the worker is a person\'s to do.');
}
$pdo = db();
$step = req_val('step') ?? '';
if ($step !== '' && !in_array($step, WORKER_PASSES, true)) {
    dc_refuse_fields(['step' => 'The passes are ' . implode(', ', WORKER_PASSES) . '.']);
}
$report = worker_run($pdo, $step === '' ? null : $step);
$busy = isset($report['skipped']);
$errors = $report['errors'] ?? [];
log_activity($pdo, 'worker.run', 'worker', null, ['after' => ['step' => $step === '' ? 'all' : $step, 'busy' => $busy, 'errors' => count($errors), 'seconds' => $report['seconds'] ?? null, 'timed_out' => (bool) ($report['timed_out'] ?? false)]]);
$did = $busy ? 'Another pass is running; nothing was started' : 'Ran ' . ($step === '' ? 'every pass' : $step) . ($errors === [] ? '' : ' (' . count($errors) . ' failed)');
dc_done($did, null, dc_land('/admin/worker', $busy ? 'ran_busy' : ($errors === [] ? 'ran' : 'ran_errors'), 'worker-passes'), 'workerChanged',
    ['step' => $step === '' ? 'all' : $step, 'busy' => $busy, 'errors' => $errors, 'ran' => array_map(static fn ($r): mixed => $r, $report['ran'] ?? []), 'seconds' => $report['seconds'] ?? null]);
