<?php
/**
 * The large-uploads proof (docs/build-specs/large-uploads.md "Proof" 1–9): a file over 2 GB through the parts routes, written at offsets with no
 * per-part files and no assembly copy, hashed as the parts came (in order, in reverse, then the gap filled), a finish that reads nothing back;
 * the bytes past 2 GB served by range; the same file chosen again resumes; a part sent again; a session from before db/024; the expiry that
 * moves on; the store's room; the zone's words; the worker's sweep of orphaned bodies; a folder export holding the big file (Zip64, stored).
 */
require dirname(__DIR__) . '/slice2/lib.php';
$seed = seed_store();
$contracts = $seed['contracts'];
const PART = 8388608;

/** A file of $parts parts of 8 MB (the last $tail bytes when $tail > 0) whose every part begins with its number: [path, sha256, the 8 MB block]. */
function big_file(string $name, int $parts, int $tail): array
{
    $dir = sys_get_temp_dir() . '/dc-big-' . getmypid(); @mkdir($dir, 0700, true);
    $path = $dir . '/' . $name;
    $block = random_bytes(PART);
    $h = hash_init('sha256');
    $f = fopen($path, 'wb');
    $total = $tail > 0 ? $parts + 1 : $parts;
    for ($n = 1; $n <= $total; $n++) {
        $p = pack('J', $n) . substr($block, 8);
        if ($n === $total && $tail > 0) { $p = substr($p, 0, $tail); }
        fwrite($f, $p); hash_update($h, $p);
    }
    fclose($f);
    return [$path, hash_final($h), $block];
}
/** The byte the big file has at $off (big_file's rule). */
function big_byte(string $block, int $off): string { $o = $off % PART; return $o < 8 ? pack('J', intdiv($off, PART) + 1)[$o] : $block[$o]; }
/** One part's raw POST with a CSRF token already read; answers [code, json, seconds]. */
function send_part(string $jar, string $csrf, int $sid, int $n, string $bytes): array
{
    $t = microtime(true);
    $r = req('POST', '/upload/part?session=' . $sid . '&n=' . $n, ['jar' => $jar, 'raw' => $bytes, 'headers' => ['Content-Type: application/octet-stream', 'X-CSRF-Token: ' . $csrf, 'Accept: application/json']]);
    return [$r['code'], json_decode($r['body'], true) ?: [], microtime(true) - $t];
}
function part_of(string $path, int $n): string { return (string) file_get_contents($path, false, null, ($n - 1) * PART, PART); }
function session_row(int $sid): array { return q('SELECT * FROM upload_sessions WHERE id = :s', ['s' => $sid])[0] ?? []; }
$jar = actor(27);
$csrf = page_csrf($jar);
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE status = 'open'");

echo "1. A 2.5 GB file starts: no ceiling by default\n";
ok(one('SELECT max_document_bytes FROM dc_settings') === null, 'dc_settings.max_document_bytes is NULL after db/024: no per-file ceiling');
[$big, $bigSha, $block] = big_file('archive-2026.bin', 320, 12345);
$bigSize = filesize($big);
ok($bigSize === 320 * PART + 12345 && $bigSize > 2147483648, 'the fixture is ' . number_format($bigSize) . ' bytes, over 2 GB');
$big1 = post_j(27, '/folders/save.php', ['parent' => $contracts, 'name' => 'SMOKE Big']);
$bigFolder = (int) ($big1['json']['record_id'] ?? 0);
$st = post_j(27, '/upload/start', ['folder_id' => $bigFolder, 'filename' => 'archive-2026.bin', 'byte_size' => $bigSize]);
$sid = (int) ($st['json']['session_id'] ?? 0);
ok($st['code'] === 200 && (int) $st['json']['parts_total'] === 321 && ($st['json']['resumed'] ?? null) === false && $st['json']['parts_received'] === [], 'start: 200, 321 parts of 8 MB, not a resume (' . $st['code'] . ' ' . substr($st['body'], 0, 160) . ')');
ok((bool) session_row($sid)['writes_at_offset'] === true, 'the session is written at offsets (db/024)');

echo "2. Parts 1–100 in order, 321 down to 201, then 101–200\n";
$codes = []; $slowest = 0.0;
foreach (array_merge(range(1, 100), range(321, 201), range(101, 200)) as $i => $n) {
    [$c, $j, $s] = send_part($jar, $csrf, $sid, $n, part_of($big, $n));
    $codes[$c] = ($codes[$c] ?? 0) + 1;
    if ($n === 100) {
        $row = session_row($sid);
        ok((int) $row['hashed_parts'] === 100 && $row['hash_state'] !== null, 'after part 100 in order the running sha256 has seen 100 parts (it keeps up)');
        $files = array_map('basename', glob(store() . '/uploads/' . $sid . '/*'));
        ok($files === ['data'], 'one file in the session\'s directory — no file per part (' . implode(',', array_slice($files, 0, 3)) . ')');
    }
    if ($n === 201) {
        $row = session_row($sid);
        ok((int) $row['hashed_parts'] === 100 && (int) $row['parts_done'] === 221, 'parts 321…201 out of order: 221 here, the hash still waits at 100 for part 101');
        clearstatcache();
        ok(filesize(store() . '/uploads/' . $sid . '/data') === $bigSize, 'the last part written first made the file its full length (the gap is a hole until it arrives)');
    }
}
ok($codes === [200 => 321], 'all 321 parts answered 200 (' . json_encode($codes) . ')');
$row = session_row($sid);
ok((int) $row['parts_done'] === 321 && (int) $row['hashed_parts'] === 321, 'the gap filled: the hash caught up to 321 of 321 before the finish');

echo "3. The finish reads nothing back\n";
$t = microtime(true);
$f = post_j(27, '/upload/finish', ['session' => $sid]);
$secs = microtime(true) - $t;
$bigId = (int) ($f['json']['record_id'] ?? 0);
ok($f['code'] === 200 && $bigId > 0 && (int) $f['json']['version_no'] === 1, 'finish: 200, version 1 (' . $f['code'] . ' ' . substr($f['body'], 0, 160) . ')');
ok($secs < 2.0, sprintf('the finish took %.2f s for 2.5 GB (no assembly, no full re-hash)', $secs));
$d = doc_row($bigId);
ok($d['sha256'] === $bigSha && (int) $d['byte_size'] === $bigSize, 'the document\'s sha256 and size are the file\'s');
$blobPath = store() . '/blobs/' . substr($bigSha, 0, 2) . '/' . substr($bigSha, 2, 2) . '/' . $bigSha;
clearstatcache();
ok(is_file($blobPath) && filesize($blobPath) === $bigSize && stat($blobPath)['nlink'] === 1, 'one blob file of the full size, one link (the upload\'s file became it)');
ok(!is_dir(store() . '/uploads/' . $sid), 'the session\'s directory is gone');
ok(hash_file('sha256', $blobPath) === $bigSha, 'the stored bytes hash to the sha256 (every part at its offset)');

echo "4. The bytes past 2 GB, by range\n";
$off = 2600000000;
$r = req('GET', '/d/' . $bigId . '?download=1', ['jar' => $jar, 'headers' => ['Range: bytes=' . $off . '-' . ($off + 99)]]);
$want = ''; for ($i = 0; $i < 100; $i++) { $want .= big_byte($block, $off + $i); }
ok($r['code'] === 206 && str_contains($r['headers'], 'Content-Range: bytes ' . $off . '-' . ($off + 99) . '/' . $bigSize) && $r['body'] === $want, 'a range at 2.6 GB: 206, the right Content-Range and the right bytes');
$r = req('GET', '/d/' . $bigId . '?download=1', ['jar' => $jar, 'headers' => ['Range: bytes=-10']]);
ok($r['code'] === 206 && strlen($r['body']) === 10 && $r['body'] === substr(file_get_contents($big, false, null, $bigSize - 10), 0, 10), 'the last ten bytes');

echo "5. The folder export holds it\n";
$x = post_j(27, '/folders/export.php', ['folder' => $bigFolder]);
$eid = (int) ($x['json']['record_id'] ?? 0);
$rep = worker('exports');
$e = q('SELECT * FROM exports WHERE id = :i', ['i' => $eid])[0] ?? [];
$zip = new ZipArchive();
$opened = !empty($e['storage_path']) && $zip->open(store() . '/' . $e['storage_path']) === true;
$entry = $opened ? $zip->statIndex(0) : false;
ok((int) ($e['item_count'] ?? 0) === 1 && $entry !== false && (int) $entry['size'] === $bigSize && (int) $entry['comp_method'] === ZipArchive::CM_STORE, 'export_folder: the 2.5 GB file is in the zip, stored as it is (no 2 GB cap any more) (' . json_encode(['items' => $e['item_count'] ?? null, 'size' => $entry['size'] ?? null, 'method' => $entry['comp_method'] ?? null]) . ')');
if ($opened) { $zip->close(); }
@unlink(store() . '/' . ($e['storage_path'] ?? 'none'));
@unlink($big);

echo "6. The same file chosen again resumes\n";
$small = tmpfile_of(5 * PART - 1000, 'resume', 'site-video.mp4');
$smallSha = hash_file('sha256', $small);
$key = hash('sha256', 'site-video.mp4|' . filesize($small) . '|resume');
$a = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'site-video.mp4', 'byte_size' => filesize($small), 'resume_key' => $key]);
$sa = (int) $a['json']['session_id'];
send_part($jar, $csrf, $sa, 1, part_of($small, 1));
send_part($jar, $csrf, $sa, 2, part_of($small, 2));
pdo()->exec("UPDATE upload_sessions SET expires_at = now() + interval '5 minutes' WHERE id = $sa");
$b = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'site-video.mp4', 'byte_size' => filesize($small), 'resume_key' => $key]);
ok($b['code'] === 200 && (int) $b['json']['session_id'] === $sa && $b['json']['resumed'] === true && $b['json']['parts_received'] === [1, 2], 'the same key, size and place: the same session back, resumed, parts_received [1, 2]');
ok(strtotime((string) one('SELECT expires_at FROM upload_sessions WHERE id = :s', ['s' => $sa])) > time() + 23 * 3600, '...and its expiry moved on a day');
ok(json_decode(last_event('upload.start')['after'], true)['resumed'] === true, 'upload.start logged with resumed true');
$c = post_j(26, '/upload/start', ['folder_id' => $contracts, 'filename' => 'site-video.mp4', 'byte_size' => filesize($small), 'resume_key' => $key]);
ok($c['code'] === 200 && (int) $c['json']['session_id'] !== $sa && $c['json']['resumed'] === false, 'another person with the same key: a session of their own, never mine');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE id = " . (int) $c['json']['session_id']);
$c = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'site-video.mp4', 'byte_size' => filesize($small) + 1, 'resume_key' => $key]);
ok($c['code'] === 200 && (int) $c['json']['session_id'] !== $sa && $c['json']['resumed'] === false, 'another size with the same key: a new session');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE id = " . (int) $c['json']['session_id']);
foreach ([3, 4, 5] as $n) { send_part($jar, $csrf, $sa, $n, part_of($small, $n)); }
$f = post_j(27, '/upload/finish', ['session' => $sa]);
ok($f['code'] === 200 && doc_row((int) $f['json']['record_id'])['sha256'] === $smallSha, 'the resumed upload finishes with the file\'s sha256');
$c = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'site-video.mp4', 'byte_size' => filesize($small), 'resume_key' => $key]);
ok($c['code'] === 409 && ($c['json']['error']['code'] ?? '') === 'name_taken', 'once finished, the same file again meets the same-name rule (nothing to resume)');

echo "7. A part sent again\n";
$p = tmpfile_of(5 * PART - 77, 'again', 'again.bin');
$s = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'again.bin', 'byte_size' => filesize($p)]);
$sp = (int) $s['json']['session_id'];
send_part($jar, $csrf, $sp, 1, part_of($p, 1));
[$code, $j] = send_part($jar, $csrf, $sp, 1, part_of($p, 1));
ok($code === 200 && (int) $j['parts_done'] === 1 && (int) session_row($sp)['hashed_parts'] === 1, 'part 1 again with the same bytes: 200, nothing changes');
$other = part_of($p, 1); $other[100] = $other[100] === 'x' ? 'y' : 'x';
[$code, $j] = send_part($jar, $csrf, $sp, 1, $other);
ok($code === 409 && ($j['error']['code'] ?? '') === 'part_differs', 'part 1 again with OTHER bytes after it was hashed: 409 part_differs (the running sha256 stays the file\'s)');
send_part($jar, $csrf, $sp, 3, part_of($p, 3));
$three = part_of($p, 3); $three[5] = $three[5] === 'q' ? 'r' : 'q';
[$code] = send_part($jar, $csrf, $sp, 3, $three);
ok($code === 200 && (int) session_row($sp)['hashed_parts'] === 1, 'part 3 again with other bytes before it was hashed: accepted, replaced');
foreach ([2, 4, 5] as $n) { send_part($jar, $csrf, $sp, $n, part_of($p, $n)); }
$expect = hash('sha256', part_of($p, 1) . part_of($p, 2) . $three . part_of($p, 4) . part_of($p, 5));
$f = post_j(27, '/upload/finish', ['session' => $sp]);
ok($f['code'] === 200 && doc_row((int) $f['json']['record_id'])['sha256'] === $expect, 'the stored sha256 is the bytes stored (with the replaced part 3)');

echo "8. Sessions, expiry, room\n";
$s = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'legacy.bin', 'byte_size' => 2 * PART]);
$sl = (int) $s['json']['session_id'];
pdo()->exec("UPDATE upload_sessions SET writes_at_offset = false WHERE id = $sl");
[$code, $j] = send_part($jar, $csrf, $sl, 1, str_repeat('a', PART));
ok($code === 409 && ($j['error']['code'] ?? '') === 'restart' && str_contains($j['error']['message'] ?? '', 'Start it again'), 'a session begun before db/024: a part answers 409 restart in words');
$f = post_j(27, '/upload/finish', ['session' => $sl]);
ok($f['code'] === 409 && ($f['json']['error']['code'] ?? '') === 'restart', '...and so does its finish');
pdo()->exec("UPDATE upload_sessions SET writes_at_offset = true, expires_at = now() + interval '1 minute' WHERE id = $sl");
send_part($jar, $csrf, $sl, 1, str_repeat('a', PART));
ok(strtotime((string) session_row($sl)['expires_at']) > time() + 23 * 3600, 'a part moves the expiry on a day: a long upload that keeps arriving never expires');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE status = 'open'");
$r = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'petabyte.bin', 'byte_size' => 1125899906842624]);
ok($r['code'] === 507 && ($r['json']['error']['code'] ?? '') === 'no_space' && str_contains($r['json']['error']['message'] ?? '', 'room for'), 'a file larger than the store\'s room: 507 no_space in words');
ok(json_decode(last_event('upload.refused')['after'], true)['code'] === 'no_space', '...logged upload.refused with its code');
$room = (int) disk_free_space(store()) - 1073741824;
$a = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'claim-most.bin', 'byte_size' => $room - 3 * 1073741824]);
$b = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'claim-more.bin', 'byte_size' => 4 * 1073741824]);
ok($a['code'] === 200 && $b['code'] === 507, 'an open upload\'s promised bytes count: one claiming all but 3 GB starts, a second of 4 GB is refused (' . $a['code'] . ', ' . $b['code'] . ')');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE id = " . (int) ($a['json']['session_id'] ?? 0));
$b = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'claim-more.bin', 'byte_size' => 4 * 1073741824]);
ok($b['code'] === 200, '...once the first is gone the second starts');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE status = 'open'");

echo "9. The words, the sweep\n";
$h = page($jar, '/f/' . $contracts . '?upload=1')['body'];
ok(str_contains($h, 'data-max-bytes="0"') && str_contains($h, 'Files of any size.') && str_contains($h, 'choose the same file again to carry on'), 'the drop zone: no ceiling for the browser, "Files of any size", how to carry on');
pdo()->exec('UPDATE dc_settings SET max_document_bytes = 104857600');
$h = page($jar, '/f/' . $contracts . '?upload=1')['body'];
ok(str_contains($h, 'data-max-bytes="104857600"') && str_contains($h, 'Up to 100.0 MB a file.'), 'under a ceiling an admin set, the zone says it and the browser knows it');
pdo()->exec('UPDATE dc_settings SET max_document_bytes = NULL');
$t = store() . '/uploads/tmp'; @mkdir($t, 0750, true);
file_put_contents("$t/old-body", 'x'); touch("$t/old-body", time() - 2 * 86400); file_put_contents("$t/new-body", 'y');
$rep = worker('upload_sessions_expire');
clearstatcache();
ok(!is_file("$t/old-body") && is_file("$t/new-body") && ($rep['ran']['upload_sessions_expire']['temp_removed'] ?? -1) === 1, 'the worker removes a part\'s body left in uploads/tmp for a day, never a fresh one');
@unlink("$t/new-body");

echo "10. Cancel\n";
$s = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'cancel-me.bin', 'byte_size' => 3 * PART]);
$sc = (int) $s['json']['session_id'];
send_part($jar, $csrf, $sc, 1, str_repeat('c', PART));
ok(post_j(26, '/upload/cancel', ['session' => $sc])['code'] === 404, 'another person cannot cancel my upload (404)');
$r = post_j(27, '/upload/cancel', ['session' => $sc]);
$row = session_row($sc);
ok($r['code'] === 200 && (int) $r['json']['parts_done'] === 1 && $row['status'] === 'expired' && $row['error'] === 'cancelled', 'Cancel: 200, the session expired with the error "cancelled" (' . $r['code'] . ')');
clearstatcache();
ok(!is_dir(store() . '/uploads/' . $sc), '...and what arrived is removed');
$ev = last_event('upload.cancel');
ok($ev !== null && (int) $ev['entity_id'] === $sc && json_decode($ev['after'], true)['parts_done'] === 1, 'logged upload.cancel with the parts that had arrived');
[$code] = send_part($jar, $csrf, $sc, 2, str_repeat('c', PART));
ok($code === 409, 'a part after the cancel is refused (409)');
$r = post_j(27, '/upload/cancel', ['session' => $sc]);
ok($r['code'] === 409 && str_contains($r['json']['error']['message'] ?? '', 'over'), 'cancelled twice: 409, it is over');
$s = post_j(27, '/upload/start', ['folder_id' => $contracts, 'filename' => 'storing.bin', 'byte_size' => 10]);
pdo()->exec("UPDATE upload_sessions SET status = 'assembling' WHERE id = " . (int) $s['json']['session_id']);
$r = post_j(27, '/upload/cancel', ['session' => (int) $s['json']['session_id']]);
ok($r['code'] === 409 && str_contains($r['json']['error']['message'] ?? '', 'being stored already'), 'too late while it is being stored: 409 in words');
pdo()->exec("UPDATE upload_sessions SET status = 'expired' WHERE id = " . (int) $s['json']['session_id']);
finish();
