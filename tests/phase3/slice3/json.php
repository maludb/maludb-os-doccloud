<?php
/** Slice 3 proof — JSON mode and agents (spec "Proof" 11, 13): every screen answers data from a presenter's whitelist; every action reports record_id and location; an agent runs the free shares and the paused ones are the kernel's by the registry's category; the run-token gate; the registry. */
require __DIR__ . '/lib.php';
$seed = seed_store();
seed_people();
$contracts = $seed['contracts'];
$acme = $seed['board_doc'];
$board = $seed['board_root'];
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$agentFolder = (int) one("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = $board), $board, 'Agent folder', 40, 40) RETURNING id");

echo "1. The screens' JSON\n";
foreach (['/folders/' . $contracts . '/share' => ['access', 'mine', 'links'], '/documents/' . $acme . '/share' => ['access', 'mine'], '/folders/' . $contracts . '/links' => ['links', 'requests', 'may'], '/documents/' . $acme . '/links' => ['links', 'may'],
          '/links/new?folder=' . $contracts => ['allow_never', 'default_days'], '/requests/new?folder=' . $contracts => ['default_days'], '/requests' => ['requests'], '/shared/' => ['rows'], '/shares/mine' => ['rows', 'stale_days'],
          '/folders/' . $contracts . '/history' => ['rows', 'changes']] as $path => $keys) {
    $j = get_json(27, $path);
    $missing = array_filter($keys, static fn (string $k): bool => !array_key_exists($k, $j['json']['data'] ?? []));
    ok($j['code'] === 200 && $missing === [], "$path answers JSON with " . implode(', ', $keys) . ($missing ? ' — missing ' . implode(', ', $missing) : ''));
}
ok(!str_contains(json_encode(get_json(27, '/folders/' . $contracts . '/links')['json']), 'token_hash') && !str_contains(json_encode(get_json(27, '/folders/' . $contracts . '/share')['json']), 'password_hash'), 'no hash in any screen\'s JSON');

echo "2. Actions under a person's action token\n";
$r = post_json(27, '/access/share.php', ['folder' => $contracts, 'member' => 28, 'level' => 'view']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0 && str_contains($r['json']['location'] ?? '', '/folders/' . $contracts . '/share'), 'share_member: ok, record_id, location');
$aid = (int) $r['json']['record_id'];
$r = post_json(27, '/access/level.php', ['access' => $aid, 'kind' => 'folder', 'level' => 'edit']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $aid, 'share_level_set: the row');
$r = post_json(27, '/links/make.php', ['folder' => $contracts, 'label' => 'token link']);
ok($r['code'] === 200 && preg_match('#/s/[a-f0-9]{48}$#', $r['json']['url'] ?? '') === 1 && isset($r['json']['expires_at']), 'link_make under an action token: the URL in the answer (once), the expiry');
$r = post_json(27, '/requests/make.php', ['folder' => $contracts, 'label' => 'token request']);
ok($r['code'] === 200 && preg_match('#/r/[a-f0-9]{48}$#', $r['json']['url'] ?? '') === 1, 'request_make: the /r/ URL');
$r = post_json(27, '/access/restrict.php', ['folder' => $contracts, 'keep' => 'member:28:view,member:27:manage']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $contracts, 'folder_restrict: the folder');
$r = post_json(27, '/access/remove.php', ['access' => access_id('folder', $contracts, 'member', 28), 'kind' => 'folder']);
ok($r['code'] === 200, 'share_remove');
$r = post_json(27, '/access/share.php', ['folder' => $contracts, 'level' => 'view']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['member']), 'a share with no member: 422 on the field');

echo "3. An agent under a run token\n";
$r = post_agent(40, 923, '/access/share.php', ['folder' => $agentFolder, 'member' => 26, 'level' => 'view']);
ok($r['code'] === 200 && last_event('access.share')['source'] === 'agent' && (int) last_event('access.share')['agent_run_id'] === 923, 'Seamus shares his folder with Priya (share_member is free): logged source agent with the run');
$r = post_agent(40, 923, '/access/share-group.php', ['folder' => $agentFolder, 'group' => $seed['group'], 'level' => 'view']);
ok($r['code'] === 200, 'share_group by the agent');
$r = post_agent(40, 923, '/access/share-department.php', ['folder' => $agentFolder, 'department' => 8, 'level' => 'view']);
ok($r['code'] === 200, 'share_department by the agent');
$r = post_agent(40, 923, '/links/make.php', ['folder' => $agentFolder, 'label' => 'agent link']);
ok($r['code'] === 200, 'link_make by the agent is done HERE (the kernel\'s hook pauses it before the call reaches us: external_send in the registry)');
$r = post_agent(40, 923, '/links/revoke.php', ['link' => (int) $r['json']['record_id']]);
ok($r['code'] === 200, 'link_revoke by the agent of its own link (deletion: the kernel pauses)');
$r = post_agent(40, 923, '/access/share-application.php', ['folder' => $agentFolder, 'application' => 'spaces']);
ok($r['code'] === 200 && last_event('access.share_application')['source'] === 'agent', 'share_application by the agent: done here, paused by the kernel');
$r = post_agent(40, 923, '/access/share.php', ['folder' => $contracts, 'member' => 31, 'level' => 'view']);
ok($r['code'] === 403 || $r['code'] === 404, 'the agent at edit on Contracts (not manage) cannot share it (' . $r['code'] . ')');
$r = post_agent(41, 924, '/access/share.php', ['folder' => $agentFolder, 'member' => 26, 'level' => 'view']);
ok($r['code'] === 401, 'an agent the directory has not admitted: 401');
// An eval run's write never reaches an application: the kernel's actions server records it and does not send it (two independent controls there).
// The application fetches run facts only at an agent's first admission, so no is_eval wall is built here — recorded in the spec's Open questions.

echo "4. The registry\n";
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$byName = []; foreach ($reg['actions'] as $a) { $byName[$a['action']] = $a; }
$cat = static fn (string $k): string => (string) ($byName[$k]['approval'] ?? $byName[$k]['agent_approval'] ?? '');
ok($cat('share_guest') === 'external_send' && $cat('share_application') === 'external_send' && $cat('link_make') === 'external_send' && $cat('link_rotate') === 'external_send' && $cat('request_make') === 'external_send' && $cat('link_revoke') === 'deletion', 'the registry pauses share_guest, share_application, link_make, link_rotate, request_make (external_send) and link_revoke (deletion)');
ok($cat('share_member') === '' && $cat('share_level_set') === '' && $cat('folder_restrict') === '' && $cat('request_close') === '' && $cat('link_update') === '', '...and frees the inside shares, the level, restrict, close and update');
$screens = $reg['screens'];
ok(($screens['public-link']['built'] ?? false) && ($screens['public-request']['built'] ?? false) && ($screens['shared-with-me']['built'] ?? false) && empty($screens['shared-with-me']['stub']), 'the public doors and Shared with me are built, no stub');
finish();
