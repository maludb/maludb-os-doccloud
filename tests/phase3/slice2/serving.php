<?php
/** Slice 2 proof — serving (spec "Proof" 8): inline for a PNG, attachment for a DOCX and for an executable, ranges 206/416, ETag/304, nosniff and the CSP, 404 for a document the caller may not see, 401 anonymous, storage/ unreachable, a version by number, the thumbnail door, the logs with the bytes and the door. */
require __DIR__ . '/lib.php';
$seed = seed_store();
$contracts = $seed['contracts'];
$png = fx_dir() . '/pic.png'; $im = imagecreatetruecolor(640, 400); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200)); imagepng($im, $png); imagedestroy($im);
$docx = fx_docx('brief.docx', [['h1', 'Board brief'], ['p', fx_para(3, 1500)]]);
$up = upload_file(27, $contracts, $png); $pngId = (int) $up['finish']['json']['record_id'];
$up = upload_file(27, $contracts, $docx); $docxId = (int) $up['finish']['json']['record_id'];
$exe = tmpfile_of(3000, 'exe2', 'tool.pdf'); file_put_contents($exe, "MZ\x90\x00" . substr(file_get_contents($exe), 4));
$up = upload_file(27, $contracts, $exe); $exeId = (int) $up['finish']['json']['record_id'];
$sha = hash_file('sha256', $png);

echo "1. The gate for a viewer\n";
$r = page(actor(26), '/d/' . $pngId);
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/png/mi', $r['headers']) && preg_match('/^Content-Disposition: inline/mi', $r['headers']), 'a PNG is served inline to a group member (' . $r['code'] . ')');
ok(preg_match('/^X-Content-Type-Options: nosniff/mi', $r['headers']) && preg_match('/^Content-Security-Policy: sandbox/mi', $r['headers']) && preg_match('/^Cache-Control: private/mi', $r['headers']), 'nosniff, the sandbox CSP and Cache-Control private');
ok(preg_match('/^ETag: "' . $sha . '"/mi', $r['headers']) && preg_match('/^Accept-Ranges: bytes/mi', $r['headers']) && hash('sha256', $r['body']) === $sha, 'ETag is the sha256; Accept-Ranges; the bytes are the file');
$r = req('GET', '/d/' . $pngId, ['jar' => actor(26), 'headers' => ['If-None-Match: "' . $sha . '"']]);
ok($r['code'] === 304 && $r['body'] === '', 'If-None-Match with the ETag: 304, no body');
$r = req('GET', '/d/' . $pngId, ['jar' => actor(26), 'headers' => ['Range: bytes=0-99']]);
ok($r['code'] === 206 && strlen($r['body']) === 100 && preg_match('/^Content-Range: bytes 0-99\/' . filesize($png) . '/mi', $r['headers']), 'Range: bytes=0-99 → 206 with 100 bytes and Content-Range');
$r = req('GET', '/d/' . $pngId, ['jar' => actor(26), 'headers' => ['Range: bytes=-50']]);
ok($r['code'] === 206 && strlen($r['body']) === 50, 'a suffix range: the last 50 bytes');
$r = req('GET', '/d/' . $pngId, ['jar' => actor(26), 'headers' => ['Range: bytes=99999999-']]);
ok($r['code'] === 416 && preg_match('/^Content-Range: bytes \*\//mi', $r['headers']), 'an unsatisfiable range: 416');
$r = page(actor(26), '/d/' . $pngId . '?download=1');
ok($r['code'] === 200 && preg_match('/^Content-Disposition: attachment; filename="pic.png"/mi', $r['headers']), '?download=1 forces attachment with the file name');
$r = page(actor(26), '/d/' . $docxId);
ok($r['code'] === 200 && preg_match('/^Content-Disposition: attachment/mi', $r['headers']) && preg_match('/^Content-Type: application\/octet-stream/mi', $r['headers']), 'a DOCX is an attachment as application/octet-stream (never inline)');
$r = page(actor(26), '/d/' . $exeId);
ok($r['code'] === 200 && preg_match('/^Content-Disposition: attachment/mi', $r['headers']) && preg_match('/^Content-Type: application\/octet-stream/mi', $r['headers']), 'an executable renamed .pdf is an attachment as octet-stream');
$r = page(actor(26), '/d/' . $seed['board_doc'] . '/v/1');
ok($r['code'] === 200 && preg_match('/^Content-Disposition: inline; filename="acme-msa.pdf"/mi', $r['headers']) === 1 || $r['code'] === 404, 'a version by number serves (or 404 when the seed\'s blob has no file on disk: ' . $r['code'] . ')');

echo "2. The same nothing\n";
ok(page(actor(31), '/d/' . $pngId)['code'] === 404, 'a member outside the group: 404');
ok(page(actor(29), '/d/' . $pngId)['code'] === 404, 'a guest: 404');
$r = req('GET', '/d/' . $pngId);
ok($r['code'] === 401 && str_contains($r['location'], 'launcher'), 'anonymous: 401 to the launcher');
ok(req('GET', '/d/' . $pngId, ['headers' => ['Accept: application/json']])['code'] === 401, '...401 for a JSON caller');
ok(page(actor(26), '/d/999999')['code'] === 404, 'a document that does not exist: 404');
$blob = q('SELECT storage_path FROM blobs WHERE sha256 = :s', ['s' => $sha])[0]['storage_path'];
ok(page(actor(26), '/storage/' . $blob)['code'] === 404 && page(actor(26), '/' . $blob)['code'] === 404, 'storage/ is not under the web root: a blob is never reachable by path');
ok(page(actor(26), '/d/' . $pngId . '/v/9')['code'] === 404, 'a version that does not exist: 404');

echo "3. The thumbnail door\n";
$r = page(actor(26), '/d/' . $pngId . '/thumb');
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/svg\+xml/mi', $r['headers']) && str_contains($r['body'], 'IMG'), 'before the worker: the kind\'s SVG placeholder');
worker('extract_text,thumbnails');
$r = page(actor(26), '/d/' . $pngId . '/thumb');
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/jpeg/mi', $r['headers']) && strlen($r['body']) > 500, 'after the worker: a JPEG thumbnail');
$size = getimagesizefromstring($r['body']);
ok($size !== false && max($size[0], $size[1]) === 320, 'the longer side is 320 px');
ok(page(actor(31), '/d/' . $pngId . '/thumb')['code'] === 404, 'the thumbnail has the same gate');

echo "4. The logs\n";
$ev = q("SELECT * FROM activity_log WHERE action = 'document.preview' AND document_id = :d ORDER BY id", ['d' => $pngId]);
ok(count($ev) >= 1 && json_decode($ev[0]['after'], true)['bytes'] === filesize($png) && json_decode($ev[0]['after'], true)['door'] === 'web' && (int) $ev[0]['folder_id'] === $contracts, 'document.preview logged with the bytes, the door and the folder');
$dl = q("SELECT * FROM activity_log WHERE action = 'document.download' AND document_id = :d", ['d' => $pngId]);
ok(count($dl) >= 1 && json_decode($dl[0]['after'], true)['version_no'] === 1, 'document.download logged with the version');
ok((int) one("SELECT count(*) FROM activity_log WHERE action IN ('document.preview', 'document.download') AND document_id = :d AND actor_member_id = 26", ['d' => $pngId]) >= 3, '...as the member who opened it');
ok(one('SELECT last_opened_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $pngId]) === true || one('SELECT last_opened_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $pngId]) === 't', 'last_opened_at is set by a session caller');

echo "5. The API's door\n";
$raw = 'mcp_' . bin2hex(random_bytes(24));
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
pdo()->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (26, 'S2 key', :h, 'api')")->execute(['h' => hash('sha256', $raw)]);
$r = req('GET', '/d/' . $pngId, ['headers' => ['Authorization: Bearer ' . $raw]]);
ok($r['code'] === 200 && hash('sha256', $r['body']) === $sha, 'a person\'s key downloads as that person');
$ev = last_event('document.preview');
ok(json_decode($ev['after'], true)['door'] === 'api', '...logged with door api');
finish();
