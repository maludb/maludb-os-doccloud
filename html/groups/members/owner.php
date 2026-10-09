<?php
declare(strict_types=1);
/** Action `group_owner_set` (group, member, owner yes|no; log group.owner_set): groups.manage or an owner. On a department group the manager owns it by the directory; a curator may still name an owner. */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$g = group_from_request($pdo, 'group');
require_group_owner($g);
$mid = request_integer('member');
if ($mid === null) {
    dc_refuse_fields(['member' => 'Say whom.']);
}
$owner = dc_yes('owner', true);
$id = (int) $g['group_id'];
$row = $pdo->prepare('SELECT display_name, role FROM mcp_group_members WHERE group_id = :g AND member_id = :m AND left_at IS NULL');
$row->execute(['g' => $id, 'm' => $mid]);
$m = $row->fetch();
if ($m === false) {
    refuse(404, 'Not in the group.');
}
if (!$owner && (int) one_value($pdo, "SELECT count(*) FROM group_members WHERE group_id = :g AND role = 'owner' AND left_at IS NULL", ['g' => $id]) <= 1 && $m['role'] === 'owner') {
    refuse(422, 'A group keeps at least one owner: name another first.');
}
dc_guard($pdo, static function () use ($pdo, $g, $id, $mid, $m, $owner): void {
    $pdo->beginTransaction();
    group_owner_set($pdo, $id, $mid, $owner);
    files_log($pdo, 'group.owner_set', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'before' => ['member_id' => $mid, 'role' => $m['role']], 'after' => ['member_id' => $mid, 'name' => $m['display_name'], 'role' => $owner ? 'owner' : 'member']]);
    $pdo->commit();
});
dc_done($m['display_name'] . ($owner ? ' now owns ' : ' no longer owns ') . $g['name'], $id, dc_land(return_path('/groups/' . $id), 'owner_set'), 'groupChanged');
