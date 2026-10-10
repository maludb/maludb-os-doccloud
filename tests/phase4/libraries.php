<?php
/**
 * Proof — libraries, groups and folders (L1-L5) through the records server: find_libraries, get_library, folder_listing, get_folder, folder_path, find_folders, find_groups, get_group, group_members, my_groups,
 * storage_by_library, storage_by_owner, quota_state. Each as the people whose rows differ: Marco (curator, owns the Legal group), Priya (a member of it), Dana (nothing), Ann (a guest), the admin.
 */
require __DIR__ . '/lib.php';
$w = p4_world();

echo "1. find_libraries and get_library\n";
$m = tdata(26, 'find_libraries');
$names = array_column($m, 'name');
sort($names);
ok($names === ['SMOKE Board', 'SMOKE Policies', 'SMOKE Priya', 'SMOKE p4 Legal'], 'Priya reaches her personal library, the Board and Legal (groups she is in) and the shared Policies — nothing else: ' . implode(', ', $names));
$byName = array_column($m, null, 'name');
ok($byName['SMOKE Priya']['my_level'] === 'manage' && $byName['SMOKE Board']['my_level'] === 'edit' && $byName['SMOKE Policies']['my_level'] === 'view', 'with her level in each: manage, edit, view');
ok($byName['SMOKE Board']['quota_bytes'] === 1000000 && $byName['SMOKE Board']['used_bytes'] > 900000, 'and the Board\'s quota and use');
$d = array_column(tdata(30, 'find_libraries'), 'name');
ok(!in_array('SMOKE Board', $d, true) && !in_array('SMOKE p4 Legal', $d, true) && !in_array('SMOKE Priya', $d, true) && in_array('SMOKE Policies', $d, true), 'Dana reaches the shared Policies and her own, not the Board, not Legal, not Priya\'s');
$a = tdata(29, 'find_libraries');
ok(!in_array('SMOKE Policies', array_column($a, 'name'), true) && count(array_filter($a, fn ($l) => $l['my_level'] !== 'none')) === 0, 'Ann, a guest, holds no level in any library (she reaches only the things named to her)');
ok(count(tdata(1, 'find_libraries', ['limit' => 100])) >= 8, 'the admin reaches every library');
$one = tdata(26, 'find_libraries', ['q' => 'legal']);
ok(count($one) === 1 && array_keys($one[0]) === ['library_id', 'name', 'kind'], 'q alone is the resolver: a plain list of {library_id, name, kind}');
ok(array_column(tdata(26, 'find_libraries', ['kind' => 'shared']), 'name') === ['SMOKE Policies'], 'kind filters');
ok(count(tdata(26, 'find_libraries', ['limit' => 2])) === 2, 'limit caps');
$g = tdata(26, 'get_library', ['library' => 'SMOKE p4 Legal']);
ok($g['library']['kind'] === 'group' && $g['group']['key'] === 'p4-legal' && $g['quota']['near'] === false && $g['document_count'] === 3 && $g['folder_count'] >= 2 && $g['root_folder_id'] === $w['legal_root'], 'get_library: kind, the group, the quota, 3 documents, the root folder');
$hidden = tool(30, 'get_library', ['library' => 'SMOKE p4 Legal']);
$absent = tool(30, 'get_library', ['library' => 'No Such Library']);
ok($hidden['code'] === 'not_found' && $absent['code'] === 'not_found' && $hidden['data']['error']['message'] === $absent['data']['error']['message'], 'Dana asks for Legal: not_found — the same words as a library that does not exist');
$n0 = (int) one("SELECT count(*) FROM activity_log WHERE action = 'library.admin_view'");
$lib = (int) one("SELECT library_id FROM folders WHERE id = :f", ['f' => root_of(30)]);
$av = tdata(1, 'get_library', ['library' => $lib]);
ok($av['admin_view'] === true && (int) one("SELECT count(*) FROM activity_log WHERE action = 'library.admin_view'") === $n0 + 1, 'the admin opening Dana\'s personal library sees it, marked admin_view, and it is logged library.admin_view');
tdata(1, 'get_library', ['library' => $lib]);
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'library.admin_view'") === $n0 + 1, '...once an hour, however many times');
$ev = last_event('library.admin_view');
ok((int) $ev['actor_member_id'] === 1 && $ev['source'] === 'mcp' && (int) $ev['library_id'] === $lib, '...as the admin, source mcp, on that library');
ok(!isset(tdata(1, 'get_library', ['library' => 'SMOKE Policies'])['admin_view']), 'an admin opening a library they hold a base level in (the shared Policies) is not an admin_view');

echo "2. folder_listing, get_folder, folder_path, find_folders\n";
$l = tdata(27, 'folder_listing', ['folder' => $w['legal_contracts']]);
ok($l['total'] === 2 && ids($l['rows'], 'name') === ['p4-lease.pdf', 'p4-nda.pdf'] && $l['folder']['my_level'] === 'manage' && count($l['folder']['path']) === 2, 'Marco lists p4 Contracts: two documents, sorted by name, his level manage, the path from the root');
ok($l['rows'][1]['tags'] === ['p4-legal'] && $l['rows'][1]['owner_name'] === 'SMOKE Marco' && $l['rows'][1]['kind'] === 'document', 'each row: kind, tags, owner name');
$s = tdata(27, 'folder_listing', ['folder' => $w['legal_contracts'], 'sort' => 'name', 'dir' => 'desc', 'limit' => 1, 'offset' => 1]);
ok($s['total'] === 2 && count($s['rows']) === 1 && $s['rows'][0]['name'] === 'p4-lease.pdf', 'dir desc, limit 1, offset 1: the second of two, total still 2');
$root = tdata(27, 'folder_listing', ['folder' => $w['legal_root']]);
ok($root['rows'][0]['kind'] === 'folder' && $root['rows'][1]['kind'] === 'folder' && end($root['rows'])['kind'] === 'document', 'folders come first, then documents');
ok(count(tdata(27, 'folder_listing', ['folder' => $w['legal_contracts'], 'kind' => 'pdf'])['rows']) === 2 && count(tdata(27, 'folder_listing', ['folder' => $w['legal_contracts'], 'kind' => 'folder'])['rows']) === 0, 'kind: a kind of file, or folder');
ok(tdata(26, 'folder_listing', ['folder' => 'p4 Contracts'])['total'] === 2, 'a folder by NAME (Priya, a Legal member)');
foreach ([30 => 'Dana', 29 => 'Ann', 28 => 'Bea'] as $who => $nm) {
    $r = tool($who, 'folder_listing', ['folder' => $w['legal_contracts']]);
    ok($r['code'] === 'not_found', "$nm cannot list p4 Contracts: not_found");
}
$gf = tdata(27, 'get_folder', ['folder' => $w['legal_contracts']]);
ok($gf['effective_graph_policy'] === 'index' && $gf['document_count'] === 2 && $gf['subtree_bytes'] === 2400 && $gf['access_summary']['applications'] === 1 && $gf['access_summary']['groups'] >= 1, 'get_folder as a manager: the policy, counts, bytes, and the access summary by kind (one application)');
$gp = tdata(26, 'get_folder', ['folder' => $w['legal_contracts']]);
ok(array_keys($gp['access_summary']) === ['inherited_from'] && $gp['access_summary']['inherited_from'] === null, 'a non-manager sees only where the access is inherited from, no counts');
$sub = tdata(1, 'folder_path', ['folder' => $w['legal_contracts']]);
ok(ids($sub, 'name') === ['SMOKE p4 Legal', 'p4 Contracts'] && ids($sub, 'depth') === [0, 1], 'folder_path: root first');
$ff = tdata(26, 'find_folders', ['q' => 'Contracts']);
ok(count($ff) === 3 && isset($ff[0]['path']) && isset($ff[0]['path_text']), 'find_folders q alone: the resolver\'s plain list, labelled by path');
$ff = tdata(26, 'find_folders', ['q' => '/SMOKE p4 Legal/p4 Contracts', 'limit' => 5]);
ok(count($ff) === 1 && $ff[0]['folder_id'] === $w['legal_contracts'], 'a /path resolves to the one folder');
$ff = tdata(26, 'find_folders', ['q' => 'p4 Contracts', 'library' => 'SMOKE p4 Legal', 'under' => $w['legal_root'], 'include_trashed' => true, 'limit' => 5]);
ok(count($ff) === 1 && $ff[0]['my_level'] === 'edit' && $ff[0]['depth'] === 1 && $ff[0]['path_text'] === 'SMOKE p4 Legal › p4 Contracts', 'with library, under and include_trashed: the full row, my_level');
ok(count(tdata(30, 'find_folders', ['q' => 'p4 Contracts'])) === 0, 'Dana finds no such folder');
$amb = tool(26, 'folder_listing', ['folder' => 'Contracts']);
ok($amb['code'] === 'ambiguous' && count($amb['data']['error']['candidates']) === 2, 'a name that matches two folders is ambiguous, with the candidates');

echo "3. Groups\n";
$fg = tdata(26, 'find_groups');
ok(in_array('p4-legal', array_column($fg, 'key'), true) && find_row($fg, 'key', 'p4-legal')['i_am_member'] === true && find_row($fg, 'key', 'p4-legal')['i_am_owner'] === false, 'find_groups: Priya is a member (not an owner) of p4-legal');
ok(count(tdata(26, 'find_groups', ['mine' => true])) < count($fg) || count($fg) <= 2, 'mine narrows to hers');
ok(array_column(tdata(26, 'find_groups', ['q' => 'legal']), 'name') === ['SMOKE p4 Legal'] && array_keys(tdata(26, 'find_groups', ['q' => 'legal'])[0]) === ['group_id', 'name', 'key'], 'q alone resolves a group (a plain list)');
ok(array_column(tdata(26, 'find_groups', ['kind' => 'custom', 'limit' => 50]), 'kind') === array_fill(0, count(tdata(26, 'find_groups', ['kind' => 'custom', 'limit' => 50])), 'custom'), 'kind custom');
ok(tool(29, 'find_groups')['code'] === 'managers_only', 'a guest has no groups tool (a member\'s)');
$gg = tdata(26, 'get_group', ['group' => 'p4-legal']);
ok($gg['group']['name'] === 'SMOKE p4 Legal' && ids($gg['owners'], 'display_name') === ['SMOKE Marco'] && $gg['library']['root_folder_id'] === $w['legal_root'], 'get_group: its owner Marco and its library');
$gm = tdata(26, 'group_members', ['group' => $gg['group']['group_id']]);
ok(ids($gm, 'display_name') === ['SMOKE Marco', 'SMOKE Priya'] && $gm[0]['role'] === 'owner', 'group_members: the owner first, then Priya');
ok(count(tdata(26, 'group_members', ['group' => 'p4-legal', 'role' => 'member'])) === 1, 'role filter');
ok(count(tdata(26, 'my_groups')) >= 2 && !in_array('p4-legal', array_column(tdata(30, 'my_groups'), 'key'), true), 'my_groups: Priya\'s, and not Dana\'s');
ok(tool(30, 'get_group', ['group' => 'No Group'])['code'] === 'not_found', 'an unknown group: not_found');

echo "4. Storage and quota\n";
$sl = tdata(1, 'storage_by_library');
ok(count($sl) >= 6 && $sl[0]['used_bytes'] >= $sl[count($sl) - 1]['used_bytes'], 'storage_by_library for the admin: every library, the fullest first');
$mine = tdata(26, 'storage_by_library');
ok(ids($mine, 'name') === ['SMOKE Priya'] || count($mine) <= 1, 'for Priya (no reports.storage) only the libraries she manages: ' . implode(', ', ids($mine, 'name')));
$near = tdata(1, 'storage_by_library', ['near_only' => true]);
ok(ids($near, 'name') === ['SMOKE Board'] && $near[0]['pct'] >= 90, 'near_only: the Board at 92%');
$so = tdata(27, 'storage_by_owner');
ok(count($so) >= 2 && $so[0]['live_bytes'] >= $so[1]['live_bytes'], 'storage_by_owner for Marco (curator, reports.storage)');
ok(tool(26, 'storage_by_owner')['code'] === 'managers_only', '...not for Priya');
$qs = tdata(26, 'quota_state', ['library' => 'SMOKE Board']);
ok($qs['pct'] >= 90 && $qs['near'] === true && $qs['quota_bytes'] === 1000000, 'quota_state: the Board is near');
ok(tool(30, 'quota_state', ['library' => 'SMOKE Board'])['code'] === 'not_found', 'Dana cannot ask about the Board');
finish();
