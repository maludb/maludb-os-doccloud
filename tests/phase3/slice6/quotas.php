<?php
/** Slice 6 proof 6 — quotas: set in GB or MB by the admin (blank removes; an agent pauses), the bar on the library view (primary, warning at 90%, danger at 100%), an upload refused at the quota in words, quota_near once a month, used_bytes following moves and purges. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$p = pdo();
$bea = 28; $beaRoot = root_of($bea); $beaLib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $beaRoot]);
$used = static fn (int $lib): int => (int) one('SELECT used_bytes FROM libraries WHERE id = :l', ['l' => $lib]);

echo "1. quota_set\n";
$r = post_json(26, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 1]);
ok($r['code'] === 403, 'a member may not set a quota: 403');
$r = post_json(27, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 1]);
ok($r['code'] === 403, '...nor a curator (quotas.manage is the admin\'s)');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 'lots']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['quota_mb']), 'a word for a number: 422 on the field');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => -5]);
ok($r['code'] === 422, 'a negative quota: 422');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 1, 'quota_gb' => 1]);
ok($r['code'] === 422, 'both MB and GB: 422');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib]);
ok($r['code'] === 422, 'neither: 422');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_gb' => '0.5']);
ok($r['code'] === 200 && (int) one('SELECT quota_bytes FROM libraries WHERE id = :l', ['l' => $beaLib]) === 536870912 && (int) $r['json']['record_id'] === $beaLib, 'quota_gb 0.5 → 536,870,912 bytes; record_id = the library');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 1]);
ok($r['code'] === 200 && (int) one('SELECT quota_bytes FROM libraries WHERE id = :l', ['l' => $beaLib]) === 1048576 && ($r['json']['was_bytes'] ?? 0) === 536870912, 'quota_mb 1 → 1,048,576 bytes; the reply says what it was');
$ev = last_event('quota.set');
ok($ev !== null && json_decode($ev['before'], true)['quota_bytes'] === 536870912 && json_decode($ev['after'], true)['quota_bytes'] === 1048576 && (int) $ev['library_id'] === $beaLib, 'quota.set logged with before and after and the library');
$ag = post_agent(40, 6301, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => 5]);
ok($ag['code'] === 403 && $used($beaLib) >= 0 && (int) one('SELECT quota_bytes FROM libraries WHERE id = :l', ['l' => $beaLib]) === 1048576, 'an agent without quotas.manage is refused');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$cat = ''; foreach ($reg['actions'] as $x) { if ($x['action'] === 'quota_set') { $cat = $x['approval'] ?? $x['agent_approval'] ?? ''; } }
ok($cat === 'other', 'the registry pauses quota_set for an agent that holds the right (other)');

echo "2. The bar\n";
$b = page(actor(28), '/libraries/' . $beaLib)['body'];
ok(str_contains($b, 'id="quota-bar"') && str_contains($b, 'data-status="primary"') && str_contains($b, 'of 1.0 MB'), 'the library view: #quota-bar, primary under 90%, "of 1.0 MB"');
ok(!str_contains($b, 'id="library-quota-link"'), 'no "Set the quota" link for a member');
$b = page(actor(1), '/libraries/' . $beaLib)['body'];
ok(str_contains($b, 'id="library-quota-link"'), '...the admin sees the link');
$j = get_json(28, '/libraries/' . $beaLib);
ok(($j['json']['data']['quota']['quota_bytes'] ?? 0) === 1048576, 'JSON carries the quota state');

echo "3. An upload refused at the quota\n";
$u = upload_file($bea, $beaRoot, tmpfile_of(950000, 'q6-a', 'q6-a.bin'));
ok(($u['finish']['code'] ?? 0) === 200 && $used($beaLib) >= 950000, 'a 950,000-byte file fits (' . $used($beaLib) . ' used)');
$b = page(actor(28), '/libraries/' . $beaLib)['body'];
ok(str_contains($b, 'data-status="warning"') && str_contains($b, 'id="quota-bar-state"') && str_contains($b, 'nearly full'), 'at 90% the bar turns warning, "nearly full"');
$u = upload_file($bea, $beaRoot, tmpfile_of(300000, 'q6-b', 'q6-b.bin'));
ok($u['start']['code'] === 422 && str_contains($u['start']['body'], 'over its quota') && ($u['start']['json']['error']['code'] ?? $u['start']['json']['code'] ?? '') === 'quota', 'the next upload is refused at its start, in words: "The library is over its quota."');
$u = upload_file($bea, $beaRoot, tmpfile_of(60000, 'q6-c', 'q6-c.bin'));
ok(($u['finish']['code'] ?? 0) === 200 && $used($beaLib) > 1000000 && $used($beaLib) <= 1048576, 'a file that still fits goes in (' . $used($beaLib) . ')');
$p->exec("UPDATE libraries SET used_bytes = 1048576 WHERE id = $beaLib");
$b = page(actor(28), '/libraries/' . $beaLib)['body'];
ok(str_contains($b, 'data-status="danger"') && str_contains($b, '>full<'), 'at 100% the bar is danger, "full"');
$p->exec("SELECT dc_recount_bulk()");

echo "4. quota_near, once a month\n";
$p->exec("DELETE FROM notifications WHERE kind = 'quota_near'");
$before = count(bell($bea, 'quota_near'));
$r = worker('quota_watch');
ok(($r['ran']['quota_watch']['near'] ?? 0) >= 1 && ($r['ran']['quota_watch']['noticed'] ?? 0) >= 1, 'the pass finds the library at 90% and notices its owner');
$n = bell($bea, 'quota_near');
ok(count($n) === $before + 1 && str_contains($n[0]['title'], '% full'), 'Bea is told (quota_near): "' . ($n[0]['title'] ?? '') . '"');
ok(last_event('quota.near') !== null && (int) last_event('quota.near')['library_id'] === $beaLib, 'quota.near logged for the library');
$r = worker('quota_watch');
ok(($r['ran']['quota_watch']['noticed'] ?? -1) === 0 && count(bell($bea, 'quota_near')) === $before + 1, 'a second pass the same month tells nobody again');
$p->exec("UPDATE notifications SET created_at = created_at - interval '40 days' WHERE kind = 'quota_near'");
$p->exec("UPDATE notification_outbox SET dedupe_key = dedupe_key || ':last-month' WHERE kind = 'quota_near'");     // the outbox's key carries the month: last month's is another key
$r = worker('quota_watch');
ok(($r['ran']['quota_watch']['noticed'] ?? 0) >= 1 && count(bell($bea, 'quota_near')) === $before + 2, 'next month she is told again');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $beaLib, 'quota_mb' => '']);
ok($r['code'] === 200 && one('SELECT quota_bytes IS NULL FROM libraries WHERE id = :l', ['l' => $beaLib]) && array_key_exists('quota_bytes', $r['json']) && $r['json']['quota_bytes'] === null, 'a blank quota removes it');
$b = page(actor(28), '/libraries/' . $beaLib)['body'];
ok(str_contains($b, 'no quota') && str_contains($b, 'data-status="none"'), 'the bar reads "no quota"');
$r = worker('quota_watch');
ok(($r['ran']['quota_watch']['near'] ?? -1) === 0 || ($r['ran']['quota_watch']['noticed'] ?? -1) === 0, 'a library without a quota is never near');
echo "   a group library tells its owners\n";
$gl = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $s['board_root']]);
$p->exec("UPDATE libraries SET quota_bytes = GREATEST(used_bytes, 1) * 10 / 9 WHERE id = $gl");
$p->exec("DELETE FROM notifications WHERE kind = 'quota_near'");
worker('quota_watch');
ok(count(bell(27, 'quota_near')) === 1 && count(bell(26, 'quota_near')) === 0, 'the Board\'s owner (Marco) is told, its members are not');
$p->exec("UPDATE libraries SET quota_bytes = NULL WHERE id = $gl");

echo "5. The screen\n";
$p->exec("UPDATE libraries SET quota_bytes = 1000000, used_bytes = 950000 WHERE id = $beaLib");
$j = get_json(1, '/admin/quotas');
$rows = $j['json']['data']['libraries'] ?? [];
$mine = null; foreach ($rows as $x) { if ((int) $x['library_id'] === $beaLib) { $mine = $x; } }
ok($j['code'] === 200 && $mine !== null && $mine['status'] === 'warning' && (float) $mine['pct'] === 95.0, 'the admin\'s list carries every library with its status (Bea: warning 95%)');
ok((int) $rows[0]['library_id'] === $beaLib, '...the nearly full first');
$j = get_json(1, '/admin/quotas?near=1');
ok(count($j['json']['data']['libraries']) >= 1 && count(array_filter($j['json']['data']['libraries'], fn ($x) => $x['quota_bytes'] === null)) === 0, '?near=1 lists only the ones near their quota');
$j = get_json(1, '/admin/quotas?library=' . $beaLib);
ok(($j['json']['data']['focus'] ?? 0) === $beaLib && count($j['json']['data']['top_owners'] ?? []) >= 1 && $j['json']['data']['top_owners'][0]['display_name'] === 'SMOKE Bea', 'a library\'s page names who holds the most');
$b = page(actor(1), '/admin/quotas')['body'];
ok(str_contains($b, 'id="quota-table"') && str_contains($b, 'id="quota-form-' . $beaLib . '"') && str_contains($b, 'id="quota-input-' . $beaLib . '"') && str_contains($b, 'id="quota-row-' . $beaLib . '-bar"'), 'the page: a table, a form and a bar per library');
ok(page(actor(26), '/admin/quotas')['code'] === 403 && page(actor(27), '/admin/quotas')['code'] === 403, 'a member and a curator get 403');
ok(page(actor(1), '/admin/quotas?library=999999')['code'] === 404, 'an unknown library: 404');

echo "6. used_bytes follows moves and purges\n";
$polLib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $s['pol_root']]);
$mv = real_doc(27, $s['board_root'], 'q6-mover.bin', 'q6-mover', 7000);
$boardLib = $gl;
$b0 = $used($boardLib); $p0 = $used($polLib);
$r = post_json(27, '/documents/move.php', ['document' => $mv, 'target_folder' => $s['pol_root']]);
ok($r['code'] === 200 && (int) one('SELECT library_id FROM documents WHERE id = :d', ['d' => $mv]) === $polLib, 'a document moves across libraries (' . $r['code'] . ')');
ok($used($boardLib) === $b0 - 7000 && $used($polLib) === $p0 + 7000, 'used_bytes left one library and joined the other');
post(27, '/documents/trash.php', ['document' => $mv]);
ok($used($polLib) === $p0 + 7000, 'a trashed document still counts (it can come back)');
post_json(1, '/trash/purge.php', ['document' => $mv, 'confirm' => 'yes']);
ok($used($polLib) === $p0, 'a purge gives the bytes back');
finish();
