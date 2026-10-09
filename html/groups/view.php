<?php
declare(strict_types=1);
/** A group (screen `group-view`, /groups/{id}): kind, the mirrored department, member level, the library, the members table with Make owner · Remove (owners and curators; not on a department group), Add members, Edit · Archive/Restore · Delete. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_login();
require_right('library.personal');
$pdo = db();
$g = group_from_request($pdo, 'id');
$id = (int) $g['group_id'];
$members = group_members($pdo, $id);
$run = may_run_group($g);
$may = ['run' => $run, 'members' => $run && $g['kind'] === 'custom', 'archive' => has_right('groups.manage'), 'delete' => has_right('groups.manage') && $g['archived_at'] !== null && $g['library_id'] !== null && library_document_count($pdo, (int) $g['library_id']) === 0
        && (int) one_value($pdo, 'SELECT count(*) FROM documents WHERE library_id = :l', ['l' => (int) $g['library_id']]) === 0];
log_screen_view($pdo, 'group-view');
$timeline = find_record_activity($pdo, 'group', $id, 20);
if (wants_json()) {
    respond_screen(['group' => present_group($g), 'members' => array_map('present_group_member', $members), 'may' => $may, 'timeline' => array_map('present_activity_row', $timeline)]);
}
$notices = ['updated' => ['success', 'Saved.'], 'member_added' => ['success', 'Added to the group.'], 'member_removed' => ['success', 'Removed from the group.'], 'owner_set' => ['success', 'Saved.'],
            'archived' => ['success', 'Archived. The library stays; its members no longer reach it.'], 'restored' => ['success', 'Restored.'], 'created' => ['success', 'Group made.']];
render_screen($g['name'], view('groups/view.php', ['g' => $g, 'members' => $members, 'may' => $may, 'tz' => member_timezone(), 'timeline' => $timeline, 'notice' => dc_notice($_GET['notice'] ?? null, $notices),
    'osDirectory' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/departments/' . (int) ($g['department_id'] ?? 0)]), ['activeNav' => 'groups', 'screen' => 'group-view', 'entity' => 'group', 'recordId' => $id]);
