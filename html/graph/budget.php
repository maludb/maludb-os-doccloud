<?php
declare(strict_types=1);
/** Action `graph_budget_set` (graph_monthly_text_mb 1–100000): dc_settings; graph.manage; pauses for an agent (other). Logged graph.budget_set. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_right('graph.manage');
$pdo = db();
$mb = request_integer('graph_monthly_text_mb');
if ($mb === null || $mb < 1 || $mb > 100000) {
    dc_refuse_fields(['graph_monthly_text_mb' => 'The monthly budget is 1 to 100,000 MB of text.']);
}
$before = (int) one_value($pdo, "SELECT dc_setting_int('graph_monthly_text_mb')");
dc_guard($pdo, static function () use ($pdo, $mb, $before): void {
    $pdo->beginTransaction();
    graph_budget_set($pdo, $mb);
    log_activity($pdo, 'graph.budget_set', 'settings', 1, ['before' => ['graph_monthly_text_mb' => $before], 'after' => ['graph_monthly_text_mb' => $mb]]);
    $pdo->commit();
});
dc_done('Set the graph budget to ' . number_format($mb) . ' MB of text a month', 1, dc_land('/graph/admin', 'budget', 'graph-budget'), 'graphChanged', ['graph_monthly_text_mb' => $mb]);
