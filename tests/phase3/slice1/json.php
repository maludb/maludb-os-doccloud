<?php
/** Slice 1 proof — JSON mode: every screen answers `data` from a presenter's whitelist; every handler reports through emit_action_status and a create's location ends in the id; an agent's run token runs folder_create and group_member_add and is refused library_delete without the right (spec "Proof" 12). */
require __DIR__ . '/lib.php';
$seed = seed_store();
$priyaRoot = root_of(26);
$lib26 = (int) one("SELECT id FROM libraries WHERE kind = 'personal' AND owner_member_id = 26");

echo "1. Every screen answers JSON from a whitelist\n";
$screens = [
    ['/f/' . $seed['contracts'], 26, ['folder', 'crumbs', 'access_line', 'listing', 'may', 'library', 'size']],
    ['/folders/new?parent=' . $seed['contracts'], 27, ['parent', 'values', 'colours']],
    ['/folders/' . $seed['contracts'] . '/edit', 27, ['folder', 'parent', 'values']],
    ['/folders/' . $seed['contracts'] . '/move', 27, ['folder', 'path', 'picker']],
    ['/libraries', 26, ['libraries', 'may']],
    ['/libraries/' . $lib26, 26, ['library', 'members', 'quota', 'may', 'timeline']],
    ['/libraries/new', 27, ['values', 'everyone_levels']],
    ['/groups', 26, ['groups', 'filters', 'may']],
    ['/groups/' . $seed['group'], 26, ['group', 'members', 'may', 'timeline']],
    ['/groups/' . $seed['group'] . '/members', 27, ['group', 'members', 'may']],
    ['/groups/new', 27, ['values', 'departments_without_group', 'member_levels']],
    ['/groups/' . $seed['group'] . '/edit', 27, ['group', 'values']],
    ['/recent', 26, ['rows']],
    ['/favourites', 26, ['rows']],
];
$bad = [];
foreach ($screens as [$url, $m, $keys]) {
    $r = get_json($m, $url);
    $data = $r['json']['data'] ?? null;
    if ($r['code'] !== 200 || !is_array($data) || array_diff($keys, array_keys($data)) !== []) {
        $bad[] = $url . ' (' . $r['code'] . ': ' . implode(',', array_keys($data ?? [])) . ')';
    }
}
ok($bad === [], count($screens) . ' screens answer 200 with the presenter\'s keys' . ($bad ? ' — wrong: ' . implode('; ', $bad) : ''));
$r = get_json(26, '/files/');
ok($r['code'] === 200 && ($r['json']['location'] ?? '') === '/f/' . $priyaRoot, 'My files as JSON answers the location of the root');
$j = get_json(26, '/f/' . $seed['contracts'])['json']['data'];
$docRow = array_values(array_filter($j['listing']['rows'], static fn (array $r): bool => $r['kind'] === 'document'))[0] ?? [];
ok(!isset($j['folder']['path']) && !isset($docRow['storage_path']) && $j['folder']['my_level'] === 'edit' && ($docRow['url'] ?? '') === '/documents/' . $seed['board_doc'] && ($docRow['my_level'] ?? '') === 'edit', 'the folder\'s JSON carries my_level and the row\'s url, never a path or a storage path');
$j = get_json(26, '/libraries/' . $lib26)['json']['data'];
ok($j['library']['kind'] === 'personal' && $j['library']['my_level'] === 'manage' && is_array($j['timeline']), 'a library\'s JSON: kind, my_level, the timeline');
ok(get_json(29, '/f/' . $seed['contracts'])['code'] === 404 && (get_json(29, '/f/' . $seed['contracts'])['json']['error']['code'] ?? '') === 'not_found', 'a guest\'s JSON on a group folder: 404 not_found');
ok(get_json(29, '/groups')['code'] === 403, 'a guest\'s JSON on Groups: 403');

echo "2. Every handler reports; a create's location ends in the id\n";
$r = post_json(27, '/folders/save.php', ['parent' => $seed['contracts'], 'name' => 'JSON folder']);
$fid = (int) one("SELECT id FROM folders WHERE name = 'JSON folder'");
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && (int) ($r['json']['record_id'] ?? 0) === $fid && ($r['json']['location'] ?? '') === '/f/' . $fid . '?notice=created' && ($r['json']['did'] ?? '') === 'Made the folder JSON folder', 'folder_create: ok, did, record_id = the new id, location ends in the id');
$r = post_json(27, '/folders/save.php', ['parent' => $seed['contracts'], 'name' => 'JSON folder']);
ok($r['code'] === 422 && ($r['json']['error']['code'] ?? '') === 'invalid' && str_contains($r['json']['error']['message'] ?? '', 'already here') && ($r['json']['error']['fields']['name'] ?? '') !== '', 'a refusal: 422 invalid with the sentence on the field');
$r = post_json(27, '/folders/save.php', ['folder' => $fid, 'name' => 'JSON folder 2']);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Renamed the folder to JSON folder 2' && ($r['json']['refresh'] ?? '') === 'folderChanged', 'folder_rename: did and refresh');
$r = post_json(27, '/folders/move.php', ['folder' => $fid, 'target_folder' => $seed['board_root']]);
ok($r['code'] === 200 && (int) one('SELECT parent_id FROM folders WHERE id = :id', ['id' => $fid]) === $seed['board_root'], 'folder_move under an action token');
$r = post_json(27, '/folders/move.php', ['folder' => $fid, 'target_folder' => $fid]);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'under itself'), 'the database\'s refusal reaches the JSON caller');
$r = post_json(27, '/folders/trash.php', ['folder' => $fid]);
ok($r['code'] === 200 && ($r['json']['folders'] ?? 0) === 1 && (int) $r['json']['record_id'] === $fid, 'folder_trash answers the count');
$r = post_json(27, '/folders/restore.php', ['folder' => $fid]);
ok($r['code'] === 200, 'folder_restore');
$r = post_json(27, '/libraries/save.php', ['name' => 'JSON shared', 'everyone_level' => 'view']);
$lid = (int) one("SELECT id FROM libraries WHERE name = 'JSON shared'");
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $lid && ($r['json']['location'] ?? '') === '/libraries/' . $lid . '?notice=created', 'library_create: record_id and location');
$r = post_json(27, '/libraries/everyone.php', ['library' => $lid, 'everyone_level' => 'none']);
ok($r['code'] === 200 && ($r['json']['everyone_level'] ?? '') === 'none', 'library_everyone_set answers the level');
$r = post_json(27, '/libraries/save.php', ['library' => $lid, 'archived' => 'yes']);
ok($r['code'] === 200 && str_contains($r['json']['did'] ?? '', 'rchived'), 'library_update archives (did says so)');
$r = post_json(27, '/libraries/delete.php', ['library' => $lid, 'confirm_name' => 'JSON shared']);
ok($r['code'] === 200 && ($r['json']['location'] ?? '') === '/libraries?notice=deleted', 'library_delete lands on the list');
$r = post_json(27, '/groups/save.php', ['name' => 'JSON group', 'member_level' => 'view']);
$gid = (int) one("SELECT id FROM groups WHERE name = 'JSON group'");
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $gid && str_ends_with($r['json']['location'] ?? '', '/groups/' . $gid . '?notice=created'), 'group_create: record_id and location');
$r = post_json(27, '/groups/members/add.php', ['group' => $gid, 'member' => '26,40', 'role' => 'member']);
ok($r['code'] === 200 && ($r['json']['added'] ?? 0) === 2, 'group_member_add takes a comma-separated list (an agent\'s shape) and answers how many');
$r = post_json(27, '/groups/members/owner.php', ['group' => $gid, 'member' => 26, 'owner' => 'yes']);
ok($r['code'] === 200 && str_contains($r['json']['did'] ?? '', 'now owns'), 'group_owner_set');
$r = post_json(27, '/groups/members/remove.php', ['group' => $gid, 'member' => 40]);
ok($r['code'] === 200 && str_contains($r['json']['did'] ?? '', 'Removed'), 'group_member_remove');
$r = post_json(27, '/groups/save.php', ['group' => $gid, 'description' => 'via json']);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Saved the group JSON group', 'group_update (a partial update: the name stays)');
$r = post_json(27, '/groups/archive.php', ['group' => $gid]);
ok($r['code'] === 200, 'group_archive');
$r = post_json(27, '/groups/restore.php', ['group' => $gid]);
ok($r['code'] === 200, 'group_restore');
$r = post_json(26, '/favourites/add.php', ['folder' => $seed['contracts']]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $seed['contracts'], 'favourite_add');
$r = post_json(26, '/favourites/remove.php', ['folder' => $seed['contracts']]);
ok($r['code'] === 200, 'favourite_remove');
$r = post_json(27, '/folders/auto-tags.php', ['folder' => $fid, 'auto_tags' => 'a, b']);
ok($r['code'] === 200 && ($r['json']['auto_tags'] ?? []) === ['a', 'b'], 'folder_auto_tags_set answers the slugs');
$r = req('POST', '/folders/save.php', ['headers' => ['Accept: application/json'], 'form' => ['parent' => $seed['contracts'], 'name' => 'Anon']]);
ok($r['code'] === 401, 'a JSON write with no token and no session: 401');
$r = req('GET', '/folders/save.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(27)]]);
ok($r['code'] === 405, 'a GET on a handler: 405');

echo "3. An agent under a run token\n";
$tok = run_token(40, 801);
$r = post_agent(40, 801, '/folders/save.php', ['parent' => $seed['contracts'], 'name' => 'Agent folder']);
$afid = (int) one("SELECT id FROM folders WHERE name = 'Agent folder'");
ok($r['code'] === 200 && $afid > 0 && (int) one('SELECT owner_member_id FROM folders WHERE id = :id', ['id' => $afid]) === 40, 'Seamus (an agent in the Board) runs folder_create: the folder is his');
$ev = last_event('folder.create');
ok($ev['source'] === 'agent' && (int) $ev['agent_run_id'] === 801 && (int) $ev['actor_member_id'] === 40, '...logged with source agent and the run id');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$cg = (int) one("SELECT id FROM groups WHERE name = 'JSON group'");
pdo()->exec("INSERT INTO group_members (group_id, member_id, role, added_by) VALUES ($cg, 40, 'owner', 27) ON CONFLICT (group_id, member_id) DO UPDATE SET left_at = NULL, role = 'owner'");
$r = post_agent(40, 801, '/groups/members/add.php', ['group' => $cg, 'member' => 30]);
ok($r['code'] === 200 && ($r['json']['added'] ?? 0) === 1, 'as an owner of a group the agent runs group_member_add');
$r = post_agent(40, 801, '/libraries/delete.php', ['library' => $lib26, 'confirm_name' => 'x']);
ok($r['code'] === 403, 'the agent is refused library_delete (no libraries.shared): 403');
$r = post_agent(40, 801, '/groups/save.php', ['name' => 'Agent group']);
ok($r['code'] === 403, '...and group_create (no groups.manage)');
$r = post_agent(41, 802, '/folders/save.php', ['parent' => $seed['contracts'], 'name' => 'Watcher folder']);
ok($r['code'] === 401, 'an agent the directory has not admitted: 401');
finish();
