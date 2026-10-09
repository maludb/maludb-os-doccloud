<?php
/** Slice 3 proof — restrict and the trail (spec "Proof" 5): the group loses the folder, the named rows keep it, the admin and the library's owner keep it, access history answers an earlier set, a root is never restricted. */
require __DIR__ . '/lib.php';
$seed = seed_store();
seed_people();
$contracts = $seed['contracts'];
$acme = $seed['board_doc'];
$board = $seed['board_root'];
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$legal = (int) one("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = $board), $board, 'Legal', 27, 27) RETURNING id");
$y = (int) one("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = $legal), $legal, '2026', 27, 27) RETURNING id");
$yDoc = (int) one('SELECT document_id FROM dc_version_add(NULL, :f, :n, (SELECT blob_id FROM dc_blob_claim(:s, 500)), :n, :m, 500, :s, 27)', ['f' => $y, 'n' => 'renewal.pdf', 'm' => 'application/pdf', 's' => hash('sha256', 's3:renewal')]);

echo "1. Before: the group edits 2026 through the library\n";
ok(level_of(26, 'folder', $y) === 'edit' && level_of(26, 'document', $yDoc) === 'edit' && level_of(28, 'folder', $y) === 'none', 'Priya edits 2026 and its document; Bea sees nothing');
$r = post_j(27, '/access/share.php', ['folder' => $y, 'member' => 28, 'level' => 'view']);
ok($r['code'] === 200 && count(access_of('folder', $y)) === 2, 'Bea at view on 2026: two rows (the group carried along)');
$t1 = (string) one('SELECT now()');
usleep(1100000);

echo "2. Restrict 2026 to Bea (view) and Marco (manage)\n";
$keep = ['member:28:view', 'member:27:manage'];
$r = post_j(27, '/access/restrict.php', ['folder' => $y, 'keep' => implode(',', $keep)]);
ok($r['code'] === 200 && str_contains($r['json']['did'] ?? '', '2 rows kept'), 'folder_restrict: 2 rows kept (' . ($r['json']['did'] ?? $r['code']) . ')');
$rows = access_of('folder', $y);
$kinds = array_map(static fn (array $x): string => $x['principal_kind'] . ':' . $x['principal_id'] . ':' . $x['level'], $rows); sort($kinds);
ok($kinds === ['member:27:manage', 'member:28:view'], 'the explicit set is exactly the kept rows; the group\'s row is gone');
ok(level_of(26, 'folder', $y) === 'none' && page(actor(26), '/documents/' . $yDoc)['code'] === 404 && page(actor(26), '/f/' . $legal)['code'] === 200, 'Priya (the group) lost 2026 and its document; she still edits Legal');
ok(level_of(28, 'folder', $y) === 'view' && page(actor(28), '/documents/' . $yDoc)['code'] === 200, 'Bea keeps 2026 by name');
ok(level_of(27, 'folder', $y) === 'manage' && level_of(1, 'folder', $y) === 'manage' && page(actor(1), '/f/' . $y)['code'] === 200, 'the library\'s owner (Marco, a group owner) and the admin keep it');
ok(level_of(40, 'folder', $y) === 'none', 'an agent in the group is restricted out like any member');
$ev = last_event('access.restrict');
ok($ev !== null && (int) $ev['folder_id'] === $y && json_decode($ev['after'], true)['kept'] === 2, 'logged access.restrict {kept: 2}');
ok((int) one("SELECT count(*) FROM access_changes WHERE folder_id = :f", ['f' => $y]) >= 5, 'the trail holds every change (' . one("SELECT count(*) FROM access_changes WHERE folder_id = :f", ['f' => $y]) . ')');

echo "3. The share screen after a restrict; the access line\n";
$r = page(actor(27), '/folders/' . $y . '/share');
ok($r['code'] === 200 && substr_count($r['body'], 'id="share-row-') === 2 && !str_contains($r['body'], 'id="share-inherited-'), 'the share screen shows the two own rows and nothing inherited');
$r = page(actor(27), '/f/' . $y);
ok($r['code'] === 200 && preg_match('/id="folder-access-text">([^<]+)</', $r['body'], $m) && str_contains($m[1], 'restricted'), 'the folder page\'s access line says restricted (' . ($m[1] ?? '') . ')');
$sub = (int) one("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = $y), $y, 'Drafts', 27, 27) RETURNING id");
ok((int) one('SELECT access_root_id FROM folders WHERE id = :s', ['s' => $sub]) === $y && level_of(26, 'folder', $sub) === 'none' && level_of(28, 'folder', $sub) === 'view', 'a subfolder made under the restricted folder inherits its rows (db/019)');

echo "4. Access history: the earlier set at the earlier time\n";
$r = get_json(27, '/folders/' . $y . '/history?at=' . date('Y-m-d'));
$names = array_column($r['json']['data']['rows'] ?? [], 'principal_kind');
ok($r['code'] === 200 && count($r['json']['data']['rows'] ?? []) === 2 && !in_array('group', $names, true), 'history at the end of today: Bea and Marco, the group gone');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$st = pdo()->prepare("SELECT principal_kind, level FROM dc_access_history(NULL, :f, :t::timestamptz)"); $st->execute(['f' => $y, 't' => $t1]);
$then = array_column($st->fetchAll(), 'principal_kind'); sort($then);
ok($then === ['group', 'member'], 'the function at the time between the share and the restrict: the group was still there (' . implode(',', $then) . ' at ' . $t1 . ')');
$r = page(actor(27), '/folders/' . $y . '/history');
ok($r['code'] === 200 && str_contains($r['body'], 'id="access-history-table"') && str_contains($r['body'], 'id="access-changes-table"') && str_contains($r['body'], 'removed'), 'the history screen: the set and every change (the group\'s removal among them)');
ok(page(actor(28), '/folders/' . $y . '/history')['code'] === 403, 'a viewer may not read the history');
ok(get_json(27, '/folders/' . $y . '/history?at=yesterday')['code'] === 422, 'a bad date: 422');

echo "5. Rules\n";
$r = post_j(27, '/access/restrict.php', ['folder' => $board, 'keep' => '']);
ok($r['code'] === 422 && str_contains($r['body'], 'root'), 'a root is never restricted');
$r = post_j(27, '/access/restrict.php', ['folder' => $y, 'keep' => 'nonsense']);
ok($r['code'] === 422, 'a malformed kept row: 422');
$r = post_j(27, '/access/restrict.php', ['folder' => $y, 'keep' => '']);
$rows = access_of('folder', $y);
ok($r['code'] === 200 && count($rows) === 1 && (int) $rows[0]['principal_id'] === 27 && $rows[0]['level'] === 'manage', 'an empty restrict keeps the maker at manage (so inheritance stays stopped)');
ok(level_of(28, 'folder', $y) === 'none', '...Bea is gone now');
$r = post_j(26, '/access/restrict.php', ['folder' => $legal, 'keep' => '']);
ok($r['code'] === 403, 'an editor cannot restrict: 403');
$r = post_j(27, '/access/share.php', ['document' => $yDoc, 'member' => 26, 'level' => 'view']);
ok($r['code'] === 200 && level_of(26, 'document', $yDoc) === 'view', 'a document in a restricted folder shared alone with Priya: she views that file only');
finish();
