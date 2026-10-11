<?php
/** Grid & media proof — types (2026-10-11, the owner's C3724.MP4 from a Sony camera stored as application/octet-stream, no player): an MP4-family file PHP's own magic does not know is typed by its `ftyp` brands (dc_iso_media_mime), a camera's XAVC MP4 goes up as a video that plays with its poster, noise named .MP4 stays unknown (the bytes decide, never the extension), and bin/retype.php judges again what was stored before — a dry run first, idempotent, logged. */
require __DIR__ . '/lib.php';
$s = media_seed();
$root = dirname(__DIR__, 3);

/** A head of an ISO base media file: an ftyp box with $major and $compatible brands, then a free box. */
function ftyp_head(string $major, array $compatible): string
{
    $box = 'ftyp' . $major . "\x00\x00\x02\x00" . implode('', $compatible);
    return pack('N', strlen($box) + 4) . $box . "\x00\x00\x00\x08free";
}
/** The store's judgement in the application itself: [dc_iso_media_mime of each head, dc_sniff_mime of each file]. */
function judged(array $heads, array $files): array
{
    $code = 'require "app/bootstrap.php"; require "app/features/files/handler.php"; $in = json_decode(base64_decode($argv[1]), true);'
          . ' echo json_encode(["heads" => array_map(fn ($h) => dc_iso_media_mime(base64_decode($h)), $in["heads"]), "files" => array_map(fn ($f) => dc_sniff_mime($f, basename($f)), $in["files"])]);';
    $arg = base64_encode(json_encode(['heads' => array_map('base64_encode', $heads), 'files' => $files]));
    return json_decode((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php -r ' . escapeshellarg($code) . ' ' . escapeshellarg($arg) . ' 2>&1'), true) ?: [];
}
function retype(string $args = ''): array
{
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/retype.php ' . $args . ' 2>&1');
    $lines = array_values(array_filter(explode("\n", $out)));
    return ['lines' => $lines, 'summary' => json_decode(end($lines) ?: '{}', true) ?: []];
}

// A Sony camera's file: H.264 with big-endian PCM sound in an MP4 whose major brand is XAVC (ffmpeg's -brand), named in capitals.
$camera = fx_dir() . '/C0001.MP4';
if (!is_file($camera)) {
    $tmp = fx_dir() . '/camera-source.mov';
    exec('ffmpeg -nostdin -v error -f lavfi -i testsrc=duration=2:size=320x240:rate=10 -f lavfi -i sine=duration=2 -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a pcm_s16be -y ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc1);
    exec('ffmpeg -nostdin -v error -i ' . escapeshellarg($tmp) . ' -c copy -brand XAVC -y ' . escapeshellarg($camera) . ' 2>&1', $o, $rc2);
    @unlink($tmp);
}
$noise = fx_write('noise.MP4', random_bytes(70000));

echo "1. Typed by the bytes\n";
$cases = [
    'XAVC, compatible mp42 iso6 (the owner\'s camera)' => [ftyp_head('XAVC', ['XAVC', 'mp42', 'iso6']), 'video/mp4'],
    'qt' => [ftyp_head('qt  ', ['qt  ']), 'video/quicktime'],
    'M4A' => [ftyp_head('M4A ', ['M4A ', 'mp42', 'isom']), 'audio/mp4'],
    '3gp5' => [ftyp_head('3gp5', ['3gp5', 'isom']), 'video/3gpp'],
    '3g2a' => [ftyp_head('3g2a', ['3g2a']), 'video/3gpp2'],
    'an unknown major brand, compatible iso6' => [ftyp_head('ABCD', ['ABCD', 'iso6']), 'video/mp4'],
    'an image brand (heic, mif1): finfo\'s to judge' => [ftyp_head('heic', ['mif1', 'heic']), null],
    'an unknown brand alone' => [ftyp_head('ABCD', ['ABCD']), null],
    'no ftyp box' => [str_repeat("\x00", 32), null],
    'an ftyp box too short to be one' => [pack('N', 8) . 'ftypXAVC' . str_repeat("\x00", 8), null],
];
$j = judged(array_column($cases, 0), [$camera, $noise]);
$i = 0;
foreach ($cases as $label => [, $want]) {
    $got = array_key_exists($i, $j['heads'] ?? []) ? $j['heads'][$i] : 'missing';
    $i++;
    ok($got === $want, $label . ' → ' . var_export($got, true));
}
ok(($j['files'][0] ?? null) === 'video/mp4', 'the camera\'s file is video/mp4 to the store (PHP\'s magic alone says application/octet-stream)');
ok(($j['files'][1] ?? null) === 'application/octet-stream', 'noise named .MP4 stays application/octet-stream: the extension never decides');

echo "2. The camera's MP4 through the upload door\n";
$up = upload_file(27, $s['media'], $camera);
$cam = (int) ($up['finish']['json']['record_id'] ?? 0);
ok($cam > 0 && ($up['finish']['json']['mime_kind'] ?? null) === 'video' && ($up['finish']['json']['inline_ok'] ?? null) === true && doc_row($cam)['mime_type'] === 'video/mp4', 'C0001.MP4 finishes as video/mp4, kind video, shown inline');
ok(doc_row($cam)['mime_kind'] === 'video', '...the document\'s kind is video (the grid\'s video tile, the thumbnail queue)');
worker('extract_text,thumbnails');
$t = thumb_of($cam);
ok($t['thumb_state'] === 'made' && abs((int) $t['duration_ms'] - 2000) <= 300, 'the worker makes its poster and keeps its length (' . $t['thumb_state'] . ', ' . $t['duration_ms'] . ' ms)');
$b = page(actor(26), '/documents/' . $cam)['body'];
ok(str_contains($b, '<source src="/d/' . $cam . '" type="video/mp4" onerror=') && str_contains($b, 'poster="/d/' . $cam . '/thumb?v='), 'the document page offers the player, with the poster');
$r = req('GET', '/d/' . $cam, ['jar' => actor(26), 'headers' => ['Range: bytes=0-']]);
ok($r['code'] === 206 && preg_match('/^Content-Type: video\/mp4/mi', $r['headers']) && !preg_match('/^Content-Disposition: attachment/mi', $r['headers']), 'the gate streams it inline as video/mp4 (206)');
$up = upload_file(27, $s['media'], $noise);
$nz = (int) ($up['finish']['json']['record_id'] ?? 0);
ok($nz > 0 && doc_row($nz)['mime_type'] === 'application/octet-stream' && !str_contains(page(actor(26), '/documents/' . $nz)['body'], '<video'), 'noise.MP4 goes up as application/octet-stream, no player');

echo "3. bin/retype.php — what was stored before the store learned the type\n";
$v = current_version($cam);
pdo()->exec("UPDATE document_versions SET mime_type = 'application/octet-stream', thumb_state = 'none' WHERE id = $v");
pdo()->exec("UPDATE documents SET mime_type = 'application/octet-stream' WHERE id = $cam");
ok(doc_row($cam)['mime_kind'] === 'other' && !str_contains(page(actor(26), '/documents/' . $cam)['body'], '<video'), 'as the owner\'s file was: application/octet-stream, kind other, no player');
$logged = fn (): int => (int) one("SELECT count(*) FROM activity_log WHERE action = 'document.retype' AND document_id = :d", ['d' => $cam]);
$dry = retype('--dry-run');
ok(($dry['summary']['would_change'] ?? null) === 1 && in_array("would retype version $v of document $cam (C0001.MP4): application/octet-stream → video/mp4", $dry['lines'], true), '--dry-run says what it would change: ' . ($dry['lines'][0] ?? ''));
ok(doc_row($cam)['mime_type'] === 'application/octet-stream' && $logged() === 0, '...and changes nothing, logs nothing');
$run = retype();
ok(($run['summary']['changed'] ?? null) === 1 && ($run['summary']['same'] ?? 0) >= 1 && ($run['summary']['unreadable'] ?? null) === 0, 'the run retypes the one version and leaves the noise alone (' . json_encode($run['summary']) . ')');
$vr = q('SELECT mime_type, thumb_state FROM document_versions WHERE id = :v', ['v' => $v])[0];
ok($vr['mime_type'] === 'video/mp4' && $vr['thumb_state'] === null && doc_row($cam)['mime_type'] === 'video/mp4' && doc_row($cam)['mime_kind'] === 'video', 'the version and the document are video/mp4, kind video; the poster queued again');
$log = q("SELECT source, actor_member_id, before, after FROM activity_log WHERE action = 'document.retype' AND document_id = :d", ['d' => $cam]);
ok(count($log) === 1 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && json_decode($log[0]['before'], true)['mime_type'] === 'application/octet-stream' && json_decode($log[0]['after'], true)['mime_type'] === 'video/mp4', 'document.retype logged once, source cron, the type before and after');
worker('extract_text,thumbnails');
ok(thumb_of($cam)['thumb_state'] === 'made' && str_contains(page(actor(26), '/documents/' . $cam)['body'], '<source src="/d/' . $cam . '" type="video/mp4" onerror='), 'the next worker pass makes the poster; the page plays it');
$again = retype();
ok(($again['summary']['changed'] ?? null) === 0 && $logged() === 1, 'a second run changes nothing (idempotent)');
ok(doc_row($nz)['mime_type'] === 'application/octet-stream', 'the noise is still application/octet-stream');
finish();
