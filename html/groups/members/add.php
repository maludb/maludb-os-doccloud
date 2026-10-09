<?php
declare(strict_types=1);
/** Action `group_member_add` (group, member — one id or `member[]` several, people and agents, never a guest; role member|owner; log group.member_add): groups.manage or an owner; a custom group only ("members follow the department"). */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$g = group_from_request($pdo, 'group');
require_group_owner($g);
if ($g['kind'] === 'department') {
    refuse(422, 'This group\'s members follow the department: change them in the directory.');
}
if ($g['archived_at'] !== null) {
    refuse(422, 'The group is archived.');
}
$ids = array_values(array_unique(array_map('intval', array_filter(request_list('member') ?? [], 'ctype_digit'))));
if ($ids === []) {
    dc_refuse_fields(['member' => 'Choose whom to add.']);
}
$role = (string) (req_val('role') ?? 'member');
if ($role === '') { $role = 'member'; }
if (!in_array($role, ['member', 'owner'], true)) {
    dc_refuse_fields(['role' => 'A member is a member or an owner.']);
}
$st = $pdo->prepare('SELECT member_id, display_name, is_guest FROM mcp_members WHERE member_id = ANY (CAST(:ids AS bigint[]))');
$st->execute(['ids' => pg_array_literal($ids)]);
$found = [];
foreach ($st->fetchAll() as $m) {
    if ($m['is_guest']) {
        dc_refuse_fields(['member' => 'A guest is never in a group: ' . $m['display_name'] . '.']);
    }
    $found[(int) $m['member_id']] = $m['display_name'];
}
foreach ($ids as $mid) {
    if (!isset($found[$mid])) {
        dc_refuse_fields(['member' => 'That person is not here.']);
    }
}
$id = (int) $g['group_id'];
$n = dc_guard($pdo, static function () use ($pdo, $g, $id, $ids, $found, $role, $me): int {
    $pdo->beginTransaction();
    $n = group_member_add($pdo, $id, $ids, $me, $role);
    foreach ($ids as $mid) {
        files_log($pdo, 'group.member_add', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'after' => ['member_id' => $mid, 'name' => $found[$mid], 'role' => $role]]);
    }
    $pdo->commit();
    return $n;
});
$names = implode(', ', array_map(static fn (int $m): string => $found[$m], $ids));
dc_done(($n === 0 ? 'Already in the group: ' : 'Added to ' . $g['name'] . ': ') . $names, $id, dc_land(return_path('/groups/' . $id), 'member_added'), 'groupChanged', ['added' => $n]);
