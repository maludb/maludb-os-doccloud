<?php
/** Slice 6 proof 4–5 — the windows: versions kept and expired (the days, the count, the current never), retention_set, locks (expire, the holder told, a manager breaks), uploads expire and their parts vanish, exports expire and unlink, links log link.expire once. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$contracts = $s['contracts']; $boardRoot = $s['board_root'];
$lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $boardRoot]);
$p = pdo();

/** A document with $n versions (real rows, distinct bytes), the first $old aged $days days; returns its id. */
function versioned(int $folder, string $name, int $n, int $old, int $days, int $by = 27): int
{
    $p = pdo(); $p->exec("SELECT set_config('app.member_id', '$by', false)");
    $doc = null;
    for ($i = 1; $i <= $n; $i++) {
        $sha = hash('sha256', "w6:$name:$i"); $b = q('SELECT blob_id FROM dc_blob_claim(:s, 1000)', ['s' => $sha])[0]['blob_id'];
        $r = q('SELECT document_id FROM dc_version_add(:d, :f, :n, :b, :n, :m, 1000, :s, :by)', ['d' => $doc, 'f' => $folder, 'n' => $name, 'b' => $b, 'm' => 'application/pdf', 's' => $sha, 'by' => $by]);
        $doc = (int) $r[0]['document_id'];
    }
    $p->prepare("UPDATE document_versions SET uploaded_at = now() - make_interval(days => :d) WHERE document_id = :doc AND version_no <= :o")->execute(['d' => $days, 'doc' => $doc, 'o' => $old]);
    return $doc;
}
function live_versions(int $doc): array { return array_map('intval', array_column(q('SELECT version_no FROM document_versions WHERE document_id = :d AND purged_at IS NULL ORDER BY version_no', ['d' => $doc]), 'version_no')); }

echo "4. Versions\n";
$young = versioned($boardRoot, 'w6-young.pdf', 14, 0, 0);
$mixed = versioned($boardRoot, 'w6-mixed.pdf', 14, 12, 40);
$ancient = versioned($boardRoot, 'w6-ancient.pdf', 1, 1, 400);
$usedBefore = (int) one('SELECT used_bytes FROM libraries WHERE id = :l', ['l' => $lib]);
$r = worker('versions_expire');
ok(($r['ran']['versions_expire']['purged'] ?? -1) === 4, 'the pass purged 4 versions (' . ($r['ran']['versions_expire']['purged'] ?? '?') . ')');
ok(count(live_versions($young)) === 14, 'all kept within 30 days (14 recent versions: none purged)');
ok(live_versions($mixed) === [5, 6, 7, 8, 9, 10, 11, 12, 13, 14], 'past 30 days the last 10 are kept, the 4 older are purged');
ok(live_versions($ancient) === [1], 'the current version is never purged, however old');
ok((int) one('SELECT version_no FROM document_versions WHERE id = (SELECT current_version_id FROM documents WHERE id = :d)', ['d' => $mixed]) === 14, 'the current version is still the newest');
ok((int) one('SELECT used_bytes FROM libraries WHERE id = :l', ['l' => $lib]) === $usedBefore - 4000, 'the library\'s used_bytes fell by the four purged versions');
$ev = last_event('version.expire');
ok($ev !== null && json_decode($ev['after'], true)['versions'] === 4 && $ev['source'] === 'cron', 'version.expire logged by the worker with the count');
$r = worker('versions_expire');
ok(($r['ran']['versions_expire']['purged'] ?? -1) === 0, 'a second run purges nothing (idempotent)');
age_blobs();
$b3 = q('SELECT b.ref_count FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.document_id = :d AND v.version_no = 1', ['d' => $mixed])[0]['ref_count'];
ok((int) $b3 === 0, 'a purged version releases its blob (ref_count 0)');

echo "   retention_set\n";
$r = post_json(26, '/admin/settings/retention.php', ['versions_keep_count' => 2]);
ok($r['code'] === 403, 'a member may not set the windows: 403');
$r = post_json(27, '/admin/settings/retention.php', ['versions_keep_count' => 2]);
ok($r['code'] === 403, '...nor a curator (retention.manage is the admin\'s)');
$r = post_json(1, '/admin/settings/retention.php', ['versions_keep_count' => 0]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['versions_keep_count']), 'versions_keep_count 0: 422 on the field (bounds 1–1000)');
$r = post_json(1, '/admin/settings/retention.php', ['lock_hours' => 721, 'trash_days' => 3651]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['lock_hours'], $r['json']['error']['fields']['trash_days']), 'lock_hours 721 and trash_days 3651: both refused');
$r = post_json(1, '/admin/settings/retention.php', []);
ok($r['code'] === 422, 'nothing named: 422');
$r = post_json(1, '/admin/settings/retention.php', ['versions_keep_count' => 2]);
ok($r['code'] === 200 && ($r['json']['changed'] ?? []) === ['versions_keep_count' => 2] && ($r['json']['was'] ?? []) === ['versions_keep_count' => 10], 'versions_keep_count 10 → 2: ok, the change and what it was');
$ev = last_event('retention.set');
ok($ev !== null && json_decode($ev['before'], true) === ['versions_keep_count' => 10] && json_decode($ev['after'], true) === ['versions_keep_count' => 2], 'retention.set logged with before and after');
ok((int) one('SELECT versions_keep_days FROM dc_settings') === 30 && (int) one('SELECT trash_days FROM dc_settings') === 30, 'the windows left out stay as they were');
$r = worker('versions_expire');
ok(live_versions($mixed) === [13, 14] && ($r['ran']['versions_expire']['purged'] ?? 0) === 8, 'the pass follows: only the last 2 of the aged document remain (' . json_encode(live_versions($mixed)) . ')');
ok(count(live_versions($young)) === 14, '...and the young document keeps all 14 (inside the days)');
$r = post_json(1, '/admin/settings/retention.php', ['versions_keep_days' => 3, 'versions_keep_count' => 10]);
ok($r['code'] === 200 && (int) one('SELECT versions_keep_days FROM dc_settings') === 3, 'versions_keep_days 3 (any of the four in one go)');
$p->exec("UPDATE document_versions SET uploaded_at = now() - interval '5 days' WHERE document_id = $young AND version_no <= 3");
$r = worker('versions_expire');
ok(live_versions($young) === [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14], 'a shorter window is felt by the next pass: versions 1–3 are aged and past the last 10, so they go');
post_json(1, '/admin/settings/retention.php', ['versions_keep_days' => 30, 'versions_keep_count' => 10]);
$b = page(actor(1), '/admin/settings')['body'];
ok(str_contains($b, 'id="retention-form"') && str_contains($b, 'name="versions_keep_count"') && str_contains($b, 'name="lock_hours"') && str_contains($b, 'name="trash_days"'), 'the Settings page holds the retention form with its four fields');
ok(page(actor(1), '/admin/retention')['code'] === 200 && page(actor(26), '/admin/settings')['code'] === 403, '/admin/retention is the same page; a member gets 403');
$ag = post_agent(40, 6201, '/admin/settings/retention.php', ['trash_days' => 20]);
ok($ag['code'] === 403, 'an agent without retention.manage is refused');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$cat = ''; foreach ($reg['actions'] as $x) { if ($x['action'] === 'retention_set') { $cat = $x['approval'] ?? $x['agent_approval'] ?? ''; } }
ok($cat === 'other', 'the registry pauses retention_set for an agent (other)');

echo "5. Locks\n";
$d = seed_doc($boardRoot, 'w6-lock.pdf', 27, 'lock me');
$r = post_json(26, '/documents/lock.php', ['document' => $d]);
ok($r['code'] === 200, 'Priya locks it');
$e = one('SELECT lock_expires_at FROM documents WHERE id = :d', ['d' => $d]);
ok(abs(strtotime($e) - time() - 24 * 3600) < 120, 'the lock lasts lock_hours (24)');
post_json(1, '/admin/settings/retention.php', ['lock_hours' => 2]);
post_json(26, '/documents/unlock.php', ['document' => $d]); $r = post_json(26, '/documents/lock.php', ['document' => $d]);
$e = one('SELECT lock_expires_at FROM documents WHERE id = :d', ['d' => $d]);
ok(abs(strtotime($e) - time() - 2 * 3600) < 120, 'with lock_hours 2 the next lock lasts two hours');
post_json(1, '/admin/settings/retention.php', ['lock_hours' => 24]);
$before = count(bell(26, 'lock_broken'));
$r = worker('locks_expire');
ok(($r['ran']['locks_expire']['released'] ?? -1) === 0 && one('SELECT locked_by FROM documents WHERE id = :d', ['d' => $d]) == 26, 'before its time the pass leaves the lock');
$p->exec("UPDATE documents SET lock_expires_at = now() - interval '1 minute' WHERE id = $d");
$r = worker('locks_expire');
ok(($r['ran']['locks_expire']['released'] ?? 0) === 1 && one('SELECT locked_by IS NULL FROM documents WHERE id = :d', ['d' => $d]), 'past its time the pass releases it');
ok(count(bell(26, 'lock_broken')) === $before + 1 && str_contains(bell(26, 'lock_broken')[0]['title'], 'w6-lock.pdf'), 'the holder is told (lock_broken)');
ok(last_event('document.lock_expire') !== null, 'document.lock_expire logged');
$r = worker('locks_expire');
ok(($r['ran']['locks_expire']['released'] ?? -1) === 0 && count(bell(26, 'lock_broken')) === $before + 1, 'a second run neither releases nor tells again');
echo "   a manager breaks another's lock\n";
post_json(26, '/documents/lock.php', ['document' => $d]);
$r = post_json(26, '/documents/lock-break.php', ['document' => $d]);
ok($r['code'] === 403 || $r['code'] === 422, 'the holder is not a manager: lock_break refused (' . $r['code'] . ')');
$r = post_json(30, '/documents/lock-break.php', ['document' => $d]);
ok($r['code'] === 404, 'someone with no reach gets Not found');
$bb = count(bell(26, 'lock_broken'));
$r = post_json(27, '/documents/lock-break.php', ['document' => $d]);
ok($r['code'] === 200 && one('SELECT locked_by IS NULL FROM documents WHERE id = :d', ['d' => $d]), 'Marco (manage) breaks Priya\'s lock');
ok(count(bell(26, 'lock_broken')) === $bb + 1, 'and Priya is told');
$ev = last_event('document.lock_break');
ok($ev !== null && (int) $ev['actor_member_id'] === 27 && json_decode($ev['after'], true)['holder_member_id'] === 26, 'document.lock_break logged with the holder');
ok(post_json(27, '/documents/lock-break.php', ['document' => $d])['code'] === 422, 'breaking what is not locked: 422');

echo "   uploads expire and their parts vanish\n";
$st = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'w6-abandoned.bin', 'byte_size' => 20 * 1024 * 1024]);
$sid = (int) ($st['json']['session_id'] ?? 0);
$bytes = str_repeat('a', (int) $st['json']['part_bytes']);
post_raw(27, '/upload/part?session=' . $sid . '&n=1', $bytes);
$dir = store() . '/uploads/' . $sid;
ok($sid > 0 && is_dir($dir) && count(glob($dir . '/*')) >= 1, 'an open session with a part on disk');
$r = worker('upload_sessions_expire');
ok(one('SELECT status FROM upload_sessions WHERE id = :i', ['i' => $sid]) === 'open' && is_dir($dir), 'before its hours it is left alone');
$p->exec("UPDATE upload_sessions SET expires_at = now() - interval '1 minute' WHERE id = $sid");
$r = worker('upload_sessions_expire');
clearstatcache();
ok(one('SELECT status FROM upload_sessions WHERE id = :i', ['i' => $sid]) === 'expired' && !is_dir($dir), 'past its hours it is expired and its parts are removed');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'upload.expire' AND entity_id = :i", ['i' => $sid]) === 1, 'upload.expire logged');
$r = post_raw(27, '/upload/part?session=' . $sid . '&n=2', $bytes);
ok($r['code'] >= 400, 'a part sent to an expired session is refused (' . $r['code'] . ')');

echo "   exports expire and unlink\n";
$xf = folder_in($boardRoot, 'W6 Export'); real_doc(27, $xf, 'w6-export.pdf', 'w6-export');
$r = post_j(27, '/folders/export.php', ['folder' => $xf]);
$eid = (int) ($r['json']['record_id'] ?? 0);
worker('exports');
$e = q('SELECT * FROM exports WHERE id = :i', ['i' => $eid])[0] ?? [];
$file = store() . '/' . ($e['storage_path'] ?? 'none');
ok(($e['status'] ?? '') === 'done' && is_file($file), 'a zip is built and on disk');
$p->exec("UPDATE exports SET expires_at = now() - interval '1 minute' WHERE id = $eid");
$r = worker('exports');
clearstatcache();
ok(one('SELECT storage_path IS NULL FROM exports WHERE id = :i', ['i' => $eid]) && !is_file($file), 'past its 7 days the path is cleared and the file unlinked');
ok(last_event('export.expire') !== null, 'export.expire logged');
ok(page(actor(27), '/exports/' . $eid)['code'] === 404 || page(actor(27), '/exports/' . $eid)['code'] === 410, 'its download is gone');

echo "   links log link.expire once\n";
$mk = make_link(27, ['document' => $d, 'label' => 'w6 expiring', 'expires_days' => 7]);
$lid = $mk['link_id'];
ok($lid > 0, 'a link is made (' . ($mk['r']['code'] ?? '?') . ')');
$p->exec("UPDATE share_links SET expires_at = now() - interval '1 minute' WHERE id = $lid");
worker('links_expire'); worker('links_expire');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'link.expire' AND entity_id = :i", ['i' => $lid]) === 1, 'link.expire is logged once, however many passes');
finish();
