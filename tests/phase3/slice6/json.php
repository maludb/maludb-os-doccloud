<?php
/** Slice 6 proof 9 — JSON mode on every screen and handler (ok, did, record_id, location), the run-token gate, no session. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$boardRoot = $s['board_root']; $p = pdo();
echo "1. Every screen answers JSON\n";
$lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $boardRoot]);
foreach ([[27, '/trash/'], [27, '/f/' . $boardRoot . '/trash'], [27, '/trash/?folder=' . $boardRoot], [1, '/trash/'], [1, '/admin/quotas'], [1, '/admin/quotas?near=1'], [1, '/admin/quotas?library=' . $lib], [1, '/admin/worker'], [1, '/admin/worker?limit=5'], [1, '/admin/settings'], [1, '/admin/retention'], [27, '/libraries/' . $lib], [27, '/documents/' . $s['minutes']]] as [$m, $path]) {
    $r = get_json($m, $path);
    ok($r['code'] === 200 && isset($r['json']['data']) && !str_contains($r['body'], '<html'), "JSON: $path (member $m)");
}
$r = req('GET', '/trash/', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'no session: 401');
ok(get_json(29, '/trash/')['code'] === 403, 'a guest holds no trash.own: 403');

echo "2. Every action under a person's action token\n";
$ok = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('record_id', $r['json']) && isset($r['json']['location'], $r['json']['did']);
$d = seed_doc($boardRoot, 'j6-doc.pdf', 27, 'j6');
$r = post_json(27, '/documents/trash.php', ['document' => $d]);
ok($ok($r) && (int) $r['json']['record_id'] === $d && str_contains($r['json']['location'], 'notice=trashed'), 'document_trash: record_id = the document, location lands in its folder');
$r = post_json(27, '/trash/restore.php', ['document' => $d]);
ok($ok($r) && (int) $r['json']['record_id'] === $d && str_ends_with(explode('?', $r['json']['location'])[0], '/documents/' . $d), 'document_restore: lands on the document');
post_json(27, '/documents/trash.php', ['document' => $d]);
$r = post_json(1, '/trash/purge.php', ['document' => $d, 'confirm' => 'yes']);
ok($ok($r) && (int) $r['json']['record_id'] === $d && !exists_doc($d), 'document_purge');
$F = folder_in($boardRoot, 'J6 F'); post(27, '/folders/trash.php', ['folder' => $F]);
$r = post_json(1, '/trash/delete.php', ['folder' => $F, 'confirm_name' => 'J6 F']);
ok($ok($r) && (int) $r['json']['record_id'] === $F, 'folder_delete');
$r = post_json(1, '/trash/purge-all.php', ['confirm' => 'yes']);
ok($ok($r) && array_key_exists('documents', $r['json']) && $r['json']['record_id'] === null, 'trash_purge: counts, record_id null (the settings)');
$r = post_json(1, '/admin/quotas/save.php', ['library' => $lib, 'quota_mb' => 500]);
ok($ok($r) && (int) $r['json']['record_id'] === $lib && str_contains($r['json']['location'], '/admin/quotas?library=' . $lib), 'quota_set');
post_json(1, '/admin/quotas/save.php', ['library' => $lib, 'quota_mb' => '']);
$l = seed_doc($boardRoot, 'j6-lock.pdf', 27, 'lock'); post_json(26, '/documents/lock.php', ['document' => $l]);
$r = post_json(27, '/documents/lock-break.php', ['document' => $l]);
ok($ok($r) && (int) $r['json']['record_id'] === $l, 'document_lock_break');
$r = post_json(1, '/admin/worker/run.php', ['step' => 'quota_watch']);
ok($ok($r) && $r['json']['step'] === 'quota_watch', 'worker_run');
$r = post_json(1, '/admin/settings/retention.php', ['trash_days' => 30]);
ok($ok($r), 'retention_set');
echo "   refusals carry their words\n";
$r = post_json(27, '/documents/trash.php', ['document' => 999999]);
ok($r['code'] === 404 && ($r['json']['ok'] ?? false) === false, 'a document that does not exist: 404');
$r = post_json(27, '/documents/trash.php', []);
ok($r['code'] === 422, 'no document named: 422');
$r = post_json(26, '/documents/trash.php', ['document' => $s['board_doc']]);
ok($r['code'] === 403 && str_contains($r['json']['error']['message'] ?? '', 'manage'), 'a member without manage: 403 in words');
$r = post_json(29, '/documents/trash.php', ['document' => $s['guest_doc']]);
ok(in_array($r['code'], [403, 404], true), 'a guest cannot trash what is shared with them (' . $r['code'] . ')');
$r = req('POST', '/documents/trash.php', ['headers' => ['Accept: application/json'], 'form' => ['document' => $s['board_doc']]]);
ok($r['code'] === 401, 'a POST with no session or token: 401');

echo "3. The run-token gate\n";
$r = req('GET', '/trash/', ['headers' => as_agent(run_token(40, 6501))]);
ok($r['code'] === 200, 'Seamus (an agent granted Member) reads the trash under a run token: 200');
$tok = run_token(40, 6502);
$r = req('POST', '/documents/trash.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok], 'form' => ['document' => $s['minutes']]]);
ok(in_array($r['code'], [401, 403], true), 'a run token without the relay: refused (' . $r['code'] . ')');
$r = req('POST', '/documents/trash.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)], 'form' => ['document' => $s['minutes']]]);
ok(in_array($r['code'], [401, 403], true), '...nor a wrong relay (' . $r['code'] . ')');
$r = post_agent(41, 6503, '/documents/trash.php', ['document' => $s['minutes']]);
ok(in_array($r['code'], [401, 403], true), 'an agent the kernel has not admitted: refused');
$mine = seed_doc($boardRoot, 'j6-seamus.pdf', 40, 'mine');
$r = post_agent(40, 6504, '/documents/trash.php', ['document' => $mine]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false), 'an agent trashes a document it owns (free: the trash is undoable)');
$r = post_agent(40, 6505, '/trash/restore.php', ['document' => $mine]);
ok($r['code'] === 200, '...and restores it');
foreach ([['/trash/purge.php', ['document' => $mine, 'confirm' => 'yes']], ['/trash/delete.php', ['folder' => $boardRoot, 'confirm_name' => 'x']], ['/trash/purge-all.php', ['confirm' => 'yes']], ['/admin/quotas/save.php', ['library' => $lib, 'quota_mb' => 1]],
          ['/admin/settings/retention.php', ['trash_days' => 5]], ['/admin/worker/run.php', []]] as $i => [$path, $form]) {
    $r = post_agent(40, 6510 + $i, $path, $form);
    ok($r['code'] === 403, "an agent without the right is refused: $path");
}
$agentRun = post_agent(40, 6520, '/documents/lock-break.php', ['document' => $l]);
ok(in_array($agentRun['code'], [403, 422], true), 'an agent without manage cannot break a lock');
$ev = q("SELECT source, actor_member_id FROM activity_log WHERE action = 'document.trash' AND document_id = :d ORDER BY id DESC LIMIT 1", ['d' => $mine])[0] ?? [];
ok(($ev['source'] ?? '') === 'agent' && (int) ($ev['actor_member_id'] ?? 0) === 40, 'the agent\'s trash is logged with source agent');
finish();
