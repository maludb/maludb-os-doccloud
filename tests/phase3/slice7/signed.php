<?php
/** Slice 7 proof 5 (the link) — document_link's signed URL: it serves the bytes within ten minutes, again and with ranges; every change to it is the same 404; it is judged again at serve time (the folder unshared, the document trashed, moved, given rows of its own, a version purged); expiry; logged under the consumer; no session. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$contracts = $s['contracts']; $board = $s['board_root'];
share_folder_with($contracts, 'knowledge');
$kn = app_caller('knowledge', 70);
function dc_sign(int $d, int $v, int $e, string $c): string { return hash_hmac('sha256', "$d|$v|$e|$c", hash_hmac('sha256', 'doccloud-link-signing-v1', need('ACTION_TOKEN_KEY'))); }
function url_for(int $doc, array $caller): string { return door('share_link', $caller, ['document' => $doc])['json']['url'] ?? ''; }
$real = real_doc(27, $contracts, 'signed-bytes.bin', 'sg', 6000);
$bytes = file_get_contents(blob_of($real)[0]);
$since = last_activity_id();

echo "1. The URL serves the bytes\n";
$u = url_for($real, $kn);
ok(preg_match('#^http://127\.0\.0\.1:8701(/x/\d+/\d+/\d+/knowledge/[a-f0-9]{64})$#', $u, $m) === 1, 'the URL: /x/{document}/{version}/{expires}/knowledge/{64 hex}');
$path = $m[1];
$r = req('GET', $path);
ok($r['code'] === 200 && $r['body'] === $bytes, 'GET: 200, the bytes, whole');
ok(header_of($r, 'X-Content-Type-Options') === 'nosniff' && str_starts_with((string) header_of($r, 'Content-Disposition'), 'attachment;') && header_of($r, 'Content-Type') === 'application/octet-stream', 'attachment, nosniff');
ok(header_of($r, 'ETag') === '"' . hash('sha256', $bytes) . '"' && header_of($r, 'Set-Cookie') === null, 'an ETag of the sha256, no session cookie');
$r2 = req('GET', $path, ['headers' => ['Range: bytes=100-199']]);
ok($r2['code'] === 206 && $r2['body'] === substr($bytes, 100, 100) && str_contains((string) header_of($r2, 'Content-Range'), '100-199/6000'), 'a Range request: 206, those 100 bytes');
ok(req('GET', $path, ['headers' => ['If-None-Match: "' . hash('sha256', $bytes) . '"']])['code'] === 304, 'If-None-Match: 304');
ok(req('GET', $path)['code'] === 200 && req('GET', $path)['body'] === $bytes, 'it works again within its ten minutes (a retry, a resume) — it is not single-use');
$ch = curl_init(BASE . $path); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]); $hh = (string) curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && preg_match('/Content-Length: 6000/i', $hh) === 1, 'HEAD: 200 with the length');
ok(req('POST', $path)['code'] === 404, 'POST: 404');
ok(req('GET', $path . '?disposition=inline')['code'] === 200 && str_starts_with((string) header_of(req('GET', $path . '?disposition=inline'), 'Content-Disposition'), 'attachment;'), '?disposition=inline on a type that is not inline-safe stays an attachment');
$txt = sys_get_temp_dir() . '/dc7-note.txt'; file_put_contents($txt, "A plain note.\nSecond line.\n");
$up = upload_file(27, $contracts, $txt, ['filename' => 'note.txt']); $note = (int) ($up['finish']['json']['record_id'] ?? 0);
$nu = door('share_link', $kn, ['document' => $note, 'disposition' => 'inline'])['json']['url'];
$rn = req('GET', parse_url($nu, PHP_URL_PATH) . '?disposition=inline');
ok($rn['code'] === 200 && str_starts_with((string) header_of($rn, 'Content-Disposition'), 'inline;') && str_contains((string) header_of($rn, 'Content-Security-Policy'), 'sandbox'), 'a text note with ?disposition=inline: inline, in a sandbox');
$ev = array_values(array_filter(activity('document.download', $since), fn ($e) => (int) $e['document_id'] === $real));
$a = json_decode($ev[0]['after'], true);
ok($ev !== [] && $ev[0]['source'] === 'application' && $ev[0]['actor_member_id'] === null && $a['consumer'] === 'knowledge' && $a['door'] === 'share' && $a['bytes'] === 6000 && $a['version_no'] === 1, 'logged document.download: source application, no actor, the consumer, door share, the bytes');
$n0 = count(activity('document.download', $since));
req('GET', $path, ['headers' => ['Range: bytes=0-9']]); req('GET', $path, ['headers' => ['If-None-Match: "' . hash('sha256', $bytes) . '"']]);
ok(count(activity('document.download', $since)) === $n0, 'a ranged or revalidating request is not logged again');
ok(!str_contains(json_encode(q('SELECT * FROM activity_log WHERE id > :s', ['s' => $since])), substr($path, -64)), 'the signature is nowhere in the log — the route column shows /x/…/…');
ok(str_ends_with((string) $ev[0]['route'], '/knowledge/…'), 'the route is logged with the signature cut: ' . $ev[0]['route']);

echo "2. Any change is the same nothing\n";
preg_match('#^/x/(\d+)/(\d+)/(\d+)/(knowledge)/([a-f0-9]{64})$#', $path, $p);
[, $d, $v, $e, $c, $sig] = $p;
$flip = $sig; $flip[0] = $flip[0] === 'a' ? 'b' : 'a';
$tries = ['a flipped signature' => "/x/$d/$v/$e/$c/$flip", 'another consumer (helpdesk)' => "/x/$d/$v/$e/helpdesk/$sig", 'another document' => '/x/' . ($d + 1) . "/$v/$e/$c/$sig", 'another version' => "/x/$d/" . ($v + 1) . "/$e/$c/$sig",
          'a later expiry' => "/x/$d/$v/" . ($e + 3600) . "/$c/$sig", 'a shortened signature' => "/x/$d/$v/$e/$c/" . substr($sig, 0, 63), 'an uppercase signature' => "/x/$d/$v/$e/$c/" . strtoupper($sig),
          'no signature' => "/x/$d/$v/$e/$c", 'an extra segment' => "/x/$d/$v/$e/$c/$sig/more"];
$shaped = ['a flipped signature', 'another consumer (helpdesk)', 'another document', 'another version', 'a later expiry'];
foreach ($tries as $label => $t) { $r = req('GET', $t); ok($r['code'] === 404 && (!in_array($label, $shaped, true) || $r['body'] === 'Not found.'), "$label: 404" . (in_array($label, $shaped, true) ? ' \"Not found.\"' : '')); }
$bodies = array_unique(array_map(fn ($l) => req('GET', $tries[$l])['body'], $shaped));
ok(count($bodies) === 1, 'every refusal of a well-formed URL says the same words');

echo "3. Expiry, and the consumer\n";
$exp = time() - 1; $r = req('GET', "/x/$d/$v/$exp/$c/" . dc_sign((int) $d, (int) $v, $exp, $c));
ok($r['code'] === 404, 'a correctly signed URL that has expired: 404');
$exp = time() + 3; $soon = "/x/$d/$v/$exp/$c/" . dc_sign((int) $d, (int) $v, $exp, $c);
ok(req('GET', $soon)['code'] === 200, 'signed for 3 seconds: 200 now');
sleep(4);
ok(req('GET', $soon)['code'] === 404, '...404 after the 3 seconds');
$exp = time() + 600; $r = req('GET', "/x/$d/$v/$exp/helpdesk/" . dc_sign((int) $d, (int) $v, $exp, 'helpdesk'));
ok($r['code'] === 404, 'a URL validly signed for helpdesk, which holds no share of the folder: 404 (the reach is judged at serve time)');
ok(abs(strtotime(door('share_link', $kn, ['document' => $real])['json']['expires_at']) - time() - 600) < 5, 'a fresh link is good for ten minutes');

echo "4. Judged again when it is served\n";
$fa = "(SELECT id FROM folder_access WHERE folder_id = $contracts AND principal_kind = 'application' AND principal_ref = 'knowledge')";
pdo()->exec("DELETE FROM folder_access WHERE id = $fa");
ok(req('GET', $path)['code'] === 404, 'the folder unshared from knowledge meanwhile: 404');
share_folder_with($contracts, 'knowledge');
ok(req('GET', $path)['code'] === 200, '...shared again: 200');
pdo()->exec("UPDATE documents SET trashed_at = now(), trashed_by = 27, trashed_from_folder_id = folder_id WHERE id = $real");
ok(req('GET', $path)['code'] === 404, 'the document trashed meanwhile: 404');
pdo()->exec("UPDATE documents SET trashed_at = NULL, trashed_by = NULL, trashed_from_folder_id = NULL WHERE id = $real");
ok(req('GET', $path)['code'] === 200, '...restored: 200');
pdo()->exec("INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by) VALUES ($real, 'member', 26, 'view', 27)");
ok(req('GET', $path)['code'] === 404, 'the document given rows of its own (leaving knowledge out): 404');
share_document_with($real, 'knowledge');
ok(req('GET', $path)['code'] === 200, '...with a row of its own for knowledge: 200');
pdo()->exec("DELETE FROM document_access WHERE document_id = $real");
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
pdo()->prepare('UPDATE documents SET folder_id = :f WHERE id = :d')->execute(['f' => root_of(27), 'd' => $real]);
ok(req('GET', $path)['code'] === 404, 'the document moved out of the shared folder: 404');
pdo()->prepare('UPDATE documents SET folder_id = :f WHERE id = :d')->execute(['f' => $contracts, 'd' => $real]);
ok(req('GET', $path)['code'] === 200, '...moved back: 200');

echo "5. A URL is for one version\n";
$f2 = tmpfile_of(3000, 'sg2', 'signed-bytes.bin');
$up = upload_file(27, $contracts, $f2, ['document_id' => $real, 'mode' => 'version', 'conflict' => 'version']);
ok((int) one('SELECT version_count FROM documents WHERE id = :d', ['d' => $real]) === 2, 'a second version was added');
ok(req('GET', $path)['body'] === $bytes, 'the old URL still serves the version it was made for');
$new = url_for($real, $kn); $nb = req('GET', parse_url($new, PHP_URL_PATH))['body'];
ok(strlen($nb) === 3000 && $nb !== $bytes, 'a new link gives the current version');
pdo()->exec("UPDATE document_versions SET purged_at = now() WHERE document_id = $real AND version_no = 1");
ok(req('GET', $path)['code'] === 404 && req('GET', parse_url($new, PHP_URL_PATH))['code'] === 200, 'the first version purged: its URL is 404, the current one still serves');

echo "6. Beyond the URL\n";
ok(req('GET', '/x/1/2/3')['code'] === 404 && req('GET', '/x/')['code'] === 404, 'a malformed /x path: 404');
ok(header_of(req('GET', $path), 'Set-Cookie') === null, 'no cookie on a refusal either');
finish();
