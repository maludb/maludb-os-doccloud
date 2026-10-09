<?php
declare(strict_types=1);
/** Action `group_member_remove` (group, member; log group.member_remove): dates the membership out, never deletes it. groups.manage or an owner; a custom group only. */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$g = group_from_request($pdo, 'group');
require_group_owner($g);
if ($g['kind'] === 'department') {
    refuse(422, 'This group\'s members follow the department: change them in the directory.');
}
$mid = request_integer('member');
if ($mid === null) {
    dc_refuse_fields(['member' => 'Say whom to remove.']);
}
$id = (int) $g['group_id'];
$name = one_value($pdo, 'SELECT display_name FROM mcp_group_members WHERE group_id = :g AND member_id = :m AND left_at IS NULL', ['g' => $id, 'm' => $mid]);
if ($name === null) {
    refuse(404, 'Not in the group.');
}
dc_guard($pdo, static function () use ($pdo, $g, $id, $mid, $name): void {
    $pdo->beginTransaction();
    group_member_remove($pdo, $id, $mid);
    files_log($pdo, 'group.member_remove', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'before' => ['member_id' => $mid, 'name' => $name]]);
    $pdo->commit();
});
dc_done('Removed ' . $name . ' from ' . $g['name'], $id, dc_land(return_path('/groups/' . $id), 'member_removed'), 'groupChanged');
