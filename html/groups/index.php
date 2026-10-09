<?php
declare(strict_types=1);
/** Groups (screen `group-list`, /groups?q=&mine=&kind=&archived=): cards — name, kind chip, members, "you own"; New group for groups.manage. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'mine' => request_bool('mine'), 'kind' => request_string('kind') ?: null, 'archived' => request_bool('archived')];
$rows = find_groups($pdo, $filters);
log_screen_view($pdo, 'group-list');
if (wants_json()) {
    respond_screen(['groups' => array_map('present_group', $rows), 'filters' => $filters, 'may' => ['create' => has_right('groups.manage')]]);
}
$notices = ['created' => ['success', 'Group made.'], 'deleted' => ['success', 'Group deleted.'], 'archived' => ['success', 'Group archived.'], 'restored' => ['success', 'Group restored.']];
render_screen('Groups', view('groups/index.php', ['rows' => $rows, 'filters' => $filters, 'may' => ['create' => has_right('groups.manage')], 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'groups', 'screen' => 'group-list']);
