<?php
/** Grid & media proof — serving (spec "Video and the document page"): the thumbnail door's placeholder while hidden (never cached), one log row per viewing, the session released before the bytes (a CLI probe and a real concurrent request), the player (playsinline, the poster, a MOV as MP4, a WebM), the unplayable message, the placeholder with Show on the document page and on the link page, hidden is not a wall. */
require __DIR__ . '/lib.php';
$s = media_seed();
$ids = $s['ids'];
$png = $ids['png']; $mp4 = $ids['mp4']; $mov = $ids['mov']; $webm = $ids['webm']; $avi = $ids['avi'];
worker('extract_text,thumbnails');
post_j(27, '/access/share.php', ['folder' => $s['media'], 'member' => 28, 'level' => 'view']);
post(27, '/documents/preview-show.php', ['document' => $png]);
post(27, '/documents/preview-show.php', ['document' => $mp4]);

echo "1. The thumbnail door\n";
$r = page(actor(26), '/d/' . $png . '/thumb?v=1');
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/jpeg/mi', $r['headers']) && preg_match('/^Cache-Control: private, max-age=3600/mi', $r['headers']), 'shown: the JPEG, cacheable an hour (the page\'s ?v= is the cache key)');
post(27, '/documents/preview-hide.php', ['document' => $png]);
$r = page(actor(26), '/d/' . $png . '/thumb?v=1');
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/svg\+xml/mi', $r['headers']) && str_contains($r['body'], 'Preview hidden') && preg_match('/^Cache-Control: no-store/mi', $r['headers']), 'hidden: the "Preview hidden" SVG, never cached');
ok(str_contains(page(actor(28), '/d/' . $png . '/thumb')['body'], 'Preview hidden'), '...for a viewer too');
$r = page(actor(28), '/d/' . $png);
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/png/mi', $r['headers']), 'hidden is not a wall: a viewer still opens the image itself');
ok(page(actor(31), '/d/' . $png . '/thumb')['code'] === 404, 'the door keeps its gate (31: 404)');

echo "2. One log row per viewing\n";
pdo()->exec("UPDATE documents SET last_opened_at = NULL WHERE id = $mp4");
$count = fn (): int => (int) one("SELECT count(*) FROM activity_log WHERE action = 'document.preview' AND document_id = :d", ['d' => $mp4]);
$n = $count();
$r = req('GET', '/d/' . $mp4, ['jar' => actor(26), 'headers' => ['Range: bytes=0-']]);
ok($r['code'] === 206 && $count() === $n + 1, 'the player\'s opening request (bytes=0-): 206, one row');
ok(doc_row($mp4)['last_opened_at'] !== null, '...and last_opened_at set by it');
req('GET', '/d/' . $mp4, ['jar' => actor(26), 'headers' => ['Range: bytes=1000-']]);
req('GET', '/d/' . $mp4, ['jar' => actor(26), 'headers' => ['Range: bytes=500-999']]);
req('GET', '/d/' . $mp4, ['jar' => actor(26), 'headers' => ['Range: bytes=0-1']]);
ok($count() === $n + 1, 'seeking and buffering (bytes=1000-, 500-999) and Safari\'s two-byte probe: no more rows');
$sha = one('SELECT sha256 FROM documents WHERE id = :d', ['d' => $mp4]);
ok(req('GET', '/d/' . $mp4, ['jar' => actor(26), 'headers' => ['If-None-Match: "' . $sha . '"']])['code'] === 304 && $count() === $n + 1, 'a revalidation (304): none');
req('GET', '/d/' . $mp4, ['jar' => actor(26)]);
ok($count() === $n + 2, 'a plain request (no Range) is a viewing: one more row');

echo "3. The session is released before the bytes\n";
$probe = sys_get_temp_dir() . '/dc-media-probe-' . getmypid();
@mkdir($probe . '/store/blobs', 0700, true); @mkdir($probe . '/sess', 0700, true);
file_put_contents($probe . '/store/blobs/x', str_repeat('v', 4096));
$script = $probe . '/probe.php';
file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/app/bootstrap.php', true) . '; session_save_path(' . var_export($probe . '/sess', true) . '); session_start(); $_SESSION["x"] = 1;'
    . ' register_shutdown_function(static function () { file_put_contents(' . var_export($probe . '/status', true) . ', (string) session_status()); });'
    . ' store_stream("blobs/x", ["filename" => "x.mp4", "mime" => "video/mp4", "inline" => true, "sha256" => str_repeat("a", 64), "byte_size" => 4096]);');
shell_exec('DC_STORAGE_DIR=' . escapeshellarg($probe . '/store') . ' php ' . escapeshellarg($script) . ' > /dev/null 2>&1');
ok(trim((string) @file_get_contents($probe . '/status')) === (string) PHP_SESSION_NONE, 'store_stream() closes the session before it sends a byte (session_status after a stream: none)');
$big = tmpfile_of(48 * 1048576, 'big', 'big-video.mp4');
$bid = (int) (upload_file(27, $s['media'], $big)['finish']['json']['record_id'] ?? 0);
$jar = actor(27);
// the slow reader is its own process (curl at 1 MB/s: 48 s for the file), so nothing in this process waits on it
$proc = proc_open(['curl', '-s', '-o', '/dev/null', '--limit-rate', '1M', '-b', $jar, getenv('APP_URL') . '/d/' . $bid], [], $pipes);
usleep(1200000);
$t0 = microtime(true);
$r = page($jar, '/f/' . $s['media']);
$took = microtime(true) - $t0;
$running = proc_get_status($proc)['running'];
proc_terminate($proc);
proc_close($proc);
ok($bid > 0 && $r['code'] === 200 && $took < 2.0 && $running, 'the same person opens a folder in ' . round($took, 2) . ' s while their 48 MB stream is still being read (' . (getenv('DC_APP') ?: 'php -S, four workers') . ')');

echo "4. The player\n";
$b = page(actor(26), '/documents/' . $mp4)['body'];
ok(str_contains($b, '<video controls playsinline preload="metadata"') && str_contains($b, '<source src="/d/' . $mp4 . '" type="video/mp4" onerror='), 'an MP4: the player, playsinline, streamed from the gate');
ok(str_contains($b, 'poster="/d/' . $mp4 . '/thumb?v='), '...its poster is the thumbnail');
ok(str_contains($b, 'id="document-preview-video-fallback"') && str_contains($b, "v.addEventListener('error', fail)"), '...and a fallback if this browser rejects it');
ok(str_contains(page(actor(26), '/documents/' . $mov)['body'], '<source src="/d/' . $mov . '" type="video/mp4" onerror='), 'a MOV is offered to the player as MP4');
ok(str_contains(page(actor(26), '/documents/' . $webm)['body'], '<source src="/d/' . $webm . '" type="video/webm" onerror='), 'a WebM as WebM');
$b = page(actor(26), '/documents/' . $avi)['body'];
ok(str_contains($b, 'id="document-preview-unplayable"') && str_contains($b, "This format can't play in the browser") && !str_contains($b, '<video') && str_contains($b, 'href="/d/' . $avi . '?download=1"'), 'an AVI: "This format can\'t play in the browser" and Download, no player');

echo "5. The document page of a hidden preview\n";
post(27, '/documents/preview-hide.php', ['document' => $mp4]);
$b = page(actor(27), '/documents/' . $mp4)['body'];
$head = substr($b, 0, (int) strpos($b, '<template id="document-preview-template">'));
ok(str_contains($b, 'id="document-preview-hidden"') && str_contains($b, 'id="document-preview-show-btn"') && str_contains($b, '<template id="document-preview-template">'), 'hidden: the placeholder, Show and the preview kept in a <template>');
ok(!str_contains($head, '<video') && !str_contains($head, 'poster=') && str_contains(substr($b, strlen($head)), '<video controls playsinline'), '...nothing of the video outside the template — it loads only when asked');
ok(preg_match('/id="document-preview-switch" checked/', $b) === 1 && str_contains($b, 'action="/documents/preview-show.php"'), '...and for Marco the switch, on');
$b28 = page(actor(28), '/documents/' . $mp4)['body'];
ok(str_contains($b28, 'id="document-preview-hidden"') && !str_contains($b28, 'id="document-preview-switch"'), 'a viewer: the placeholder and Show, no switch');
$bp = page(actor(26), '/documents/' . $png)['body'];
$hp = substr($bp, 0, (int) strpos($bp, '<template id="document-preview-template">'));
ok(!str_contains($hp, 'src="/d/' . $png . '"') && str_contains($bp, 'id="document-preview-image"'), 'the hidden image: no <img> of it outside the template');
ok(str_contains($bp, 'id="document-preview-switch"') && preg_match('/id="document-preview-switch" checked/', $bp) === 1, 'Priya (edit) has the switch too');
$j = get_json(26, '/documents/' . $png);
ok(($j['json']['data']['document']['preview_hidden'] ?? null) === true && ($j['json']['data']['preview']['hidden'] ?? null) === true, 'JSON: document.preview_hidden and preview.hidden');

echo "6. The link page\n";
$l = post_json(27, '/links/make.php', ['document' => $png, 'label' => 'SMOKE media link']);
$url = (string) ($l['json']['url'] ?? '');
ok(preg_match('#/s/[a-f0-9]{48}$#', $url) === 1, 'Marco makes a link to the hidden image');
$anon = jar();
$path = (string) parse_url($url, PHP_URL_PATH);
$r = req('GET', $path, ['jar' => $anon]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="document-preview-hidden"') && !str_contains($r['body'], 'id="document-preview-switch"'), 'the link holder sees the placeholder and Show, no switch (' . $r['code'] . ')');
ok(str_contains(req('GET', '/d/' . $png . '/thumb', ['jar' => $anon])['body'], 'Preview hidden'), '...the thumbnail door gives them the placeholder');
$r = req('GET', '/d/' . $png, ['jar' => $anon]);
ok($r['code'] === 200 && preg_match('/^Content-Type: image\/png/mi', $r['headers']) === 1, '...and Show still opens the image for them');
post(27, '/documents/preview-show.php', ['document' => $png]);
post(27, '/documents/preview-show.php', ['document' => $mp4]);
finish();
