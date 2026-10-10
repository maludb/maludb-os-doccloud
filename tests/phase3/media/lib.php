<?php
/**
 * The grid-and-media proof's helpers (docs/build-specs/grid-and-media.md) on top of slice 2's: the fixtures — a PNG, a JPEG whose EXIF says
 * "turn me a quarter clockwise", a PDF, an MP4, a MOV and a WebM (H.264 / VP8 through ffmpeg) and an AVI (MPEG-4 part 2), a DOCX, an XLSX — uploaded
 * once into SMOKE Board / Media (Marco 27 owns it; Priya 26 and the agent 40 are members), and the readers the files share.
 */
require dirname(__DIR__) . '/slice2/lib.php';

/** A JPEG with an EXIF APP1 segment carrying one tag: Orientation. GD writes none, so the segment is spliced in after SOI. */
function fx_jpeg_oriented(string $name, int $orientation, int $w = 400, int $h = 200): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 220, 30, 30));      // the left half red
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 30, 220));     // the right half blue
    ob_start(); imagejpeg($im, null, 90); $jpg = (string) ob_get_clean(); imagedestroy($im);
    $tiff = "II\x2A\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00" . $tiff;
    return fx_write($name, substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpg, 2));
}

/** A short test-pattern video through ffmpeg: mp4 / mov (H.264), webm (VP8), avi (MPEG-4 part 2). */
function fx_video(string $name, float $seconds = 3.0): string
{
    $path = fx_dir() . '/' . $name;
    if (!is_file($path)) {
        $codec = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) { 'webm' => '-c:v libvpx -b:v 200k', 'avi' => '-c:v mpeg4', default => '-c:v libx264 -preset ultrafast -pix_fmt yuv420p' };
        exec('ffmpeg -nostdin -v error -f lavfi -i testsrc=duration=' . $seconds . ':size=320x240:rate=10 ' . $codec . ' -y ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
        if ($rc !== 0) { fwrite(STDERR, "ffmpeg could not make $name: " . implode(' ', $o) . "\n"); }
    }
    return $path;
}

/** SMOKE Board / Media with every fixture in it, uploaded by Marco (27) through the real upload door; idempotent within a run. */
function media_seed(): array
{
    static $seed = null;
    if ($seed !== null) { return $seed; }
    $base = seed_store();
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '27', false)");
    $media = one('SELECT id FROM folders WHERE parent_id = :r AND name = :n AND trashed_at IS NULL', ['r' => $base['board_root'], 'n' => 'Media']);
    if ($media === false) {
        $p->exec("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = {$base['board_root']}), {$base['board_root']}, 'Media', 27, 27)");
        $media = one('SELECT id FROM folders WHERE parent_id = :r AND name = :n', ['r' => $base['board_root'], 'n' => 'Media']);
    }
    $media = (int) $media;
    $png = fx_dir() . '/beach.png';
    if (!is_file($png)) { $im = imagecreatetruecolor(640, 400); imagefill($im, 0, 0, imagecolorallocate($im, 40, 150, 210)); imagepng($im, $png); imagedestroy($im); }
    $files = [
        'png' => $png,
        'jpg' => fx_jpeg_oriented('phone-photo.jpg', 6),
        'pdf' => pdf_fixture('board-pack.pdf'),
        'mp4' => fx_video('launch.mp4'),
        'mov' => fx_video('interview.mov'),
        'webm' => fx_video('demo.webm'),
        'avi' => fx_video('old-camera.avi'),
        'docx' => fx_docx('minutes.docx', [['h1', 'Minutes'], ['p', fx_para(4, 600)]]),
        'xlsx' => fx_xlsx('budget.xlsx', ['Budget' => [['Line', 'Amount'], ['Rent', '1200']]]),
    ];
    $ids = [];
    foreach ($files as $k => $path) {
        $id = one('SELECT id FROM documents WHERE folder_id = :f AND name = :n AND trashed_at IS NULL', ['f' => $media, 'n' => basename($path)]);
        if ($id === false) {
            $up = upload_file(27, $media, $path);
            $id = $up['finish']['json']['record_id'] ?? null;
            if ($id === null) { fwrite(STDERR, "upload of $k failed: " . json_encode($up['finish']['json'] ?? $up['start']['json'] ?? []) . "\n"); }
        }
        $ids[$k] = (int) $id;
    }
    return $seed = $base + ['media' => $media, 'ids' => $ids, 'files' => $files];
}

/** The version a document is on now. */
function current_version(int $doc): int { return (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $doc]); }
/** A version's thumbnail state and length. */
function thumb_of(int $doc): array { return q('SELECT v.thumb_state, v.duration_ms FROM documents d JOIN document_versions v ON v.id = d.current_version_id WHERE d.id = :d', ['d' => $doc])[0] ?? []; }
/** One tile's HTML out of a grid page. */
function tile_html(string $page, string $kind, int $id): string
{
    $start = strpos($page, 'id="tile-' . $kind . '-' . $id . '"');
    if ($start === false) { return ''; }
    $start = strrpos(substr($page, 0, $start), '<div');
    $next = strpos($page, 'class="col"', $start + 10);
    return substr($page, $start, ($next === false ? strlen($page) : $next) - $start);
}
/** Hidden or not, read from the table. */
function is_hidden(int $doc): bool { return (bool) one('SELECT EXISTS (SELECT 1 FROM document_preview_hidden WHERE document_id = :d)', ['d' => $doc]); }
