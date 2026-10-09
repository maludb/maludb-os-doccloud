<?php
declare(strict_types=1);
/** Who is in a group (screen `group-members`, /groups/{id}/members): the members table and the picker to add people and agents (owners and curators; a department group's members follow the department). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$g = group_from_request($pdo, 'id');
$id = (int) $g['group_id'];
$members = group_members($pdo, $id);
$run = may_run_group($g);
log_screen_view($pdo, 'group-members');
if (wants_json()) {
    respond_screen(['group' => present_group($g), 'members' => array_map('present_group_member', $members), 'may' => ['members' => $run && $g['kind'] === 'custom', 'run' => $run]]);
}
$notices = ['member_added' => ['success', 'Added to the group.'], 'member_removed' => ['success', 'Removed from the group.'], 'owner_set' => ['success', 'Saved.']];
render_screen($g['name'] . ' · members', view('groups/members.php', ['g' => $g, 'members' => $members, 'may' => ['members' => $run && $g['kind'] === 'custom', 'run' => $run], 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'groups', 'screen' => 'group-members', 'entity' => 'group', 'recordId' => $id]);
