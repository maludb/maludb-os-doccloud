<?php
declare(strict_types=1);
/**
 * The agents (screen `admin-agents`, GET /admin/agents?status=&kind=&page=): the two shipped agents as cards (the expert, the Filing Clerk — from maludb-os.json and the directory mirror: named or not,
 * active, the last dispatch, the Clerk's last report and open proposals), THE THRESHOLDS (agents_settings_save: who the expert and the Clerk are, the untagged batch, the copies, the retry wait,
 * the Monday report on or off), THE DISPATCHES (what the expert was woken for: status, the reply's excerpt, the attempts; Retry · Cancel). Hires and grants are the kernel's (a link).
 * agents.settings (the admin). Slice 8.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
require_login();
require_right('agents.settings');
$pdo = db();
$cards = agents_summary($pdo);
$settings = agent_settings($pdo);
$list = find_dispatches($pdo, ['status' => request_string('status'), 'kind' => request_string('kind')], request_integer('page') ?? 1);
$feed = function_exists('os_my_applications') ? os_my_applications() : null;
$osUrl = is_array($feed) ? ($feed['os_url'] ?? null) : null;
log_activity($pdo, 'screen.view', 'agent', null, ['screen' => 'admin-agents']);
if (wants_json()) {
    respond_screen(['agents' => array_map('present_agent_card', $cards), 'settings' => $settings, 'dispatches' => array_map('present_dispatch', $list['rows']), 'total' => $list['total'], 'page' => $list['page'], 'pages' => $list['pages'],
                    'kernel_url' => $osUrl]);
}
$notices = ['saved' => ['success', 'The settings were saved.'], 'retried' => ['success', 'Back in the queue: the next pass sends it.'], 'cancelled' => ['success', 'Cancelled: nothing is sent again.']];
render_screen('Agents', view('admin/agents.php', ['cards' => $cards, 'settings' => $settings, 'list' => $list, 'status' => request_string('status'), 'kind' => request_string('kind'), 'osUrl' => $osUrl,
    'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'admin', 'screen' => 'admin-agents', 'entity' => 'agent']);
