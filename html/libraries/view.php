<?php
declare(strict_types=1);
/** A library (screen `library-view`, /libraries/{id}): name, kind, description, the owner or the group, everyone level (shared), graph policy chip, quota, the root's listing link, a group's members, Edit · Archive/Restore · Delete by rule, the timeline. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/queries.php';
require_login();
$pdo = db();
$lib = library_from_request($pdo, 'id');
$id = (int) $lib['id'];
$view = find_library($pdo, $id) ?? dc_not_found('Not found.');
$members = $lib['kind'] === 'group' ? library_members($pdo, $id) : [];
$quota = library_quota_state($pdo, $id);
$group = $lib['group_id'] !== null ? find_group($pdo, (int) $lib['group_id']) : null;
$owner = $lib['owner_member_id'] !== null ? one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => (int) $lib['owner_member_id']]) : null;
$docs = library_document_count($pdo, $id);
$mayRun = $lib['kind'] === 'shared' && (has_right('libraries.shared') || is_dc_admin());
$may = ['edit' => $mayRun, 'archive' => $mayRun, 'delete' => $mayRun && $docs === 0, 'everyone' => $mayRun];
log_activity($pdo, 'screen.view', 'library', $id, ['screen' => 'library-view', 'library_id' => $id]);
$timeline = find_record_activity($pdo, 'library', $id, 20);
if (wants_json()) {
    respond_screen(['library' => present_library($view + ['documents' => $docs]), 'members' => array_map('present_group_member', $members), 'quota' => $quota, 'group' => $group === null ? null : present_group($group),
        'owner_name' => $owner, 'may' => $may, 'timeline' => array_map('present_activity_row', $timeline)]);
}
$notices = ['updated' => ['success', 'Saved.'], 'everyone' => ['success', 'Who gets what by default is saved.'], 'archived' => ['success', 'Archived. Its documents stay; members no longer reach it.'], 'restored' => ['success', 'Restored.']];
render_screen($lib['name'], view('libraries/view.php', ['lib' => $view, 'raw' => $lib, 'members' => $members, 'quota' => $quota, 'group' => $group, 'owner' => $owner, 'docs' => $docs, 'may' => $may, 'tz' => member_timezone(),
    'timeline' => $timeline, 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'adminView' => dc_is_admin_view($id)]),
    ['activeNav' => 'libraries', 'screen' => 'library-view', 'entity' => 'library', 'recordId' => $id]);
