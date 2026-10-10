<?php
/**
 * Slice 9 proof 5 — JSON mode on every screen and handler of the slice (the whitelisted data; {ok, did, record_id, location}; errors in the {error: {code, message}} shape), the run-token gate (a run token without its relay,
 * an agent without the right), the CSRF wall for browsers, GET on a handler, the notification actions at the manifest's addresses, and every event this slice promises is in the log.
 */
require __DIR__ . '/lib.php';
$w = world9();
$p = pdo();

echo "1. Every screen answers JSON\n";
$screens = ['/', '/curate/', '/curate/ownership', '/curate/ownership?member=' . $w['lee_docs'][0] * 0 + 31, '/curate/storage', '/curate/search', '/admin/', '/admin/settings', '/admin/links', '/admin/libraries', '/admin/storage', '/admin/connections', '/admin/graph', '/settings/', '/documents/' . $w['board_doc']];
$bad = [];
foreach ($screens as $u) {
    $r = get_json(1, $u);
    if ($r['code'] !== 200 || !isset($r['json']['data'])) { $bad[] = "$u → " . $r['code']; }
}
ok($bad === [], count($screens) . ' screens answer {data: …} to the admin' . ($bad ? ' — ' . implode('; ', $bad) : ''));
$r = get_json(1, '/admin/');
ok(array_keys($r['json']['data']) === ['counts', 'health'], 'the admin index carries counts and health, nothing else');
$j = get_json(1, '/admin/storage')['json']['data'];
ok(!array_key_exists('directory', $j) && !str_contains(json_encode($j), 'storage_path'), 'the disk screen leaves the directory and every storage path out of the JSON');
$j = get_json(1, '/admin/libraries')['json']['data']['libraries'][0];
ok(array_keys($j) === array_keys(array_intersect_key($j, array_flip(['library_id', 'kind', 'name', 'description', 'owner_member_id', 'group_id', 'everyone_level', 'graph_policy', 'quota_bytes', 'used_bytes', 'root_folder_id', 'my_level', 'archived', 'documents', 'admin_view', 'owner_name', 'group_name', 'pct', 'personal', 'i_belong']))), 'a library row is a whitelist (no raw column)');
$j = get_json(1, '/admin/links')['json']['data']['links'][0];
ok(!array_key_exists('token_hash', $j) && !array_key_exists('password_hash', $j) && !str_contains(json_encode($j), 'hash'), 'a link row carries no hash and no token');
$bad = [];
foreach (['/curate/', '/curate/ownership', '/curate/storage', '/curate/search', '/admin/', '/admin/settings', '/admin/links', '/admin/libraries', '/admin/storage'] as $u) {
    $r = req('GET', $u, ['headers' => ['Accept: application/json']]);
    if ($r['code'] !== 401 || (json_decode($r['body'], true)['error']['code'] ?? '') !== 'unauthorized') { $bad[] = $u . ' → ' . $r['code']; }
}
ok($bad === [], 'no session, JSON: 401 unauthorized on every one' . ($bad ? ' — ' . implode('; ', $bad) : ''));
$r = get_json(26, '/admin/links');
ok($r['code'] === 403 && ($r['json']['error']['code'] ?? '') === 'forbidden' && ($r['json']['error']['message'] ?? '') !== '', 'a refusal is {error: {code: forbidden, message}} in words');
$r = get_json(27, '/curate/ownership?member=999999');
ok($r['code'] === 404 && ($r['json']['error']['code'] ?? '') === 'not_found', '...a missing person {error: {code: not_found}}');

echo "2. The run-token gate\n";
$tok = run_token(EXPERT, 9401);
$r = req('GET', '/curate/storage', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok(in_array($r['code'], [401, 403], true), 'a run token without its relay: refused (' . $r['code'] . ')');
$r = req('GET', '/curate/storage', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)]]);
ok(in_array($r['code'], [401, 403], true), '...nor a wrong relay (' . $r['code'] . ')');
$r = req('GET', '/curate/storage', ['headers' => as_agent(run_token(EXPERT, 9402))]);
ok($r['code'] === 403, 'the expert agent holds no reports.storage: 403');
ok(req('GET', '/admin/', ['headers' => as_agent(run_token(EXPERT, 9403))])['code'] === 403 && req('GET', '/admin/links', ['headers' => as_agent(run_token(EXPERT, 9404))])['code'] === 403, '...nor settings.manage or links.all');
ok(req('GET', '/', ['headers' => as_agent(run_token(EXPERT, 9405))])['code'] === 200, 'but the home answers an agent (its libraries, none of the blocks)');
$agentHome = json_decode(req('GET', '/', ['headers' => as_agent(run_token(EXPERT, 9406))])['body'], true)['data'] ?? [];
ok(array_key_exists('admin', $agentHome) && $agentHome['admin'] === null && array_key_exists('curator', $agentHome) && $agentHome['curator'] === null, '...the curator and admin blocks null');
foreach ([['/admin/settings/save.php', ['business_name' => 'Robot']], ['/curate/ownership/transfer.php', ['owner' => 31, 'new_owner' => 28]], ['/curate/storage/export.php', ['report' => 'storage']], ['/admin/settings/retention.php', ['trash_days' => 5]]] as $i => [$path, $form]) {
    ok(post_agent(EXPERT, 9410 + $i, $path, $form)['code'] === 403, "an agent without the right is refused $path");
}
ok(post_agent(CLERK, 9420, '/notes/add.php', ['document' => $w['board_doc'], 'body' => 'x'])['code'] === 404, 'the Clerk is no member of the Board: it cannot comment on a document it cannot see (404)');

echo "3. Browsers: CSRF, and GET on a handler\n";
foreach (['/admin/settings/save.php' => ['business_name' => 'x'], '/curate/ownership/transfer.php' => ['owner' => 31, 'new_owner' => 28], '/curate/storage/export.php' => ['report' => 'storage'], '/notes/add.php' => ['document' => $w['board_doc'], 'body' => 'x'], '/notes/delete.php' => ['note' => 1], '/notifications/read-all.php' => []] as $path => $form) {
    $r = req('POST', $path, ['jar' => actor(1), 'form' => $form]);
    ok($r['code'] === 403, "a POST to $path without the CSRF token: 403");
    $g = req('GET', $path, ['jar' => actor(1)]);
    ok($g['code'] === 405, "GET $path: 405");
}

echo "4. The notification actions at the manifest's addresses\n";
$p->exec("DELETE FROM notifications WHERE member_id = 28");
foreach (['One', 'Two', 'Three'] as $t) { $p->prepare("INSERT INTO notifications (member_id, kind, title, body) VALUES (28, 'comment', :t, 'x')")->execute(['t' => 'W9 ' . $t]); }
$ids = array_column(q('SELECT id FROM notifications WHERE member_id = 28 ORDER BY id'), 'id');
$other = (int) one("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'comment', 'W9 hers', 'x') RETURNING id");
$x = post_json(28, '/notifications/read.php', ['notification' => $ids[0]]);
ok($x['code'] === 200 && $x['json']['count'] === 1 && $x['json']['record_id'] === (int) $ids[0], 'notification_read marks one read (count 1, record_id)');
$x = post_json(28, '/notifications/read.php', ['notification' => $other]);
ok($x['code'] === 200 && $x['json']['count'] === 0 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $other]) === null, '...another person\'s notice is not touched (count 0)');
$since = last_activity_id();
$x = post_json(28, '/notifications/read-all.php', ['all' => 'yes']);
ok($x['code'] === 200 && $x['json']['count'] === 2 && $x['json']['record_id'] === 2 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL') === 0, 'notifications_read_all marks the other two read: count 2');
ok(count(activity('notification.read_all', $since)) === 1 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $other]) === null, '...logged notification.read_all; Priya\'s notice still unread');
ok(post_json(28, '/notifications/read-all.php', [])['json']['count'] === 0, 'again: count 0');
$x = post(28, '/notifications/read-all.php', []);
ok(in_array($x['code'], [302, 303], true) && str_contains($x['location'], '/notifications'), 'from a browser it lands on the notifications page');
ok(req('POST', '/notifications/read-all.php', ['headers' => ['Accept: application/json']])['code'] === 401, 'no session: 401');

echo "5. Every handler answers {ok, did, record_id, location}\n";
$bea = root_of(28);
$beaLib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $bea]);
seed_doc($bea, 'w9-json-1.pdf', 28, 'Json one. ' . WORLD_SECRET); seed_doc($bea, 'w9-json-2.pdf', 28, 'Json two. ' . WORLD_SECRET);
$since = last_activity_id();
$cases = [['/admin/settings/save.php', ['link_default_days' => 31], 'link_default_days'], ['/admin/settings/save.php', ['key_rate_per_minute' => 61], 'limits'], ['/curate/ownership/transfer.php', ['owner' => 28, 'new_owner' => 30, 'library' => $beaLib], 'transfer'],
         ['/notes/add.php', ['document' => $w['board_doc'], 'body' => 'JSON note'], 'note']];
foreach ($cases as [$path, $form, $label]) {
    $x = post_json(1, $path, $form);
    ok($x['code'] === 200 && ($x['json']['ok'] ?? false) === true && isset($x['json']['did']) && array_key_exists('record_id', $x['json']) && isset($x['json']['location']), "$path ($label): ok, did, record_id, location — " . ($x['json']['did'] ?? '?'));
}
$x = post_json(1, '/notes/delete.php', ['note' => (int) q('SELECT max(id) AS i FROM notes')[0]['i']]);
ok($x['code'] === 200 && isset($x['json']['did']) && isset($x['json']['location']), '/notes/delete.php: ok, did, location');
post_json(1, '/admin/settings/save.php', ['link_default_days' => 30, 'key_rate_per_minute' => 60]);
$x = post_json(1, '/curate/storage/export.php', ['report' => 'storage']);
ok($x['code'] === 200 && $x['json']['ok'] === true && isset($x['json']['file']['name']), '/curate/storage/export.php: ok with the file\'s name');
$x = post_json(26, '/curate/ownership/transfer.php', ['owner' => 28, 'new_owner' => 30]);
ok($x['code'] === 403 && ($x['json']['error']['code'] ?? '') === 'forbidden', 'a refusal from a handler is the same error shape');

echo "6. Every event the slice promises is in the log\n";
foreach (['/', '/curate/', '/curate/storage', '/curate/search', '/admin/', '/settings/'] as $u) { page(actor(1), $u); }          // a JSON read logs no screen.view (log_screen_view); a page does
foreach (['screen.view', 'settings.save', 'limits.save', 'document.transfer', 'note.add', 'note.delete', 'report.storage', 'report.search', 'report.export', 'notification.read_all'] as $e) {
    ok((int) one('SELECT count(*) FROM activity_log WHERE action = :a', ['a' => $e]) > 0, "$e is in the activity log");
}
$screens = array_column(q("SELECT DISTINCT screen FROM activity_log WHERE action = 'screen.view' AND screen IS NOT NULL"), 'screen');
foreach (['home', 'curate', 'curate-ownership', 'admin', 'admin-settings', 'admin-links', 'admin-libraries', 'admin-storage', 'admin-connections', 'settings'] as $s) { ok(in_array($s, $screens, true), "screen.view is logged for $s"); }
$bad = q("SELECT action FROM activity_log WHERE action IN ('note.add', 'settings.save', 'limits.save', 'document.transfer') AND (after::text ILIKE '%" . WORLD_SECRET . "%' OR after::text ILIKE '%JSON note%' OR after::text ILIKE '%Please check%')");
ok($bad === [], 'no comment, no document text is in any payload of this slice');
$h = page(actor(1), '/admin/settings')['body'];
ok(!str_contains($h, 'MALUDB_API_TOKEN') && !str_contains($h, (string) getenv('ACTION_TOKEN_KEY')), 'the settings page carries no key or token');
finish();
