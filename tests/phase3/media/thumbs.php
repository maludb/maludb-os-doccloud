<?php
/** Grid & media proof — thumbnails (spec "Thumbnails"): videos through ffmpeg with their length, a missing tool waiting no_tool and retried once it exists, a JPEG turned upright by its EXIF orientation, a PDF's page, files made before db/023 recorded without being remade, the old .none marker, idempotence, the listing's columns. */
require __DIR__ . '/lib.php';
$s = media_seed();
$ids = $s['ids'];
ok(count(array_filter($ids)) === 9, 'nine fixtures uploaded through the upload door (' . json_encode($ids) . ')');

echo "1. Before the worker\n";
ok(thumb_of($ids['png'])['thumb_state'] === null && thumb_of($ids['mp4'])['thumb_state'] === null, 'thumb_state is NULL (still to do) on a fresh upload');

echo "2. A missing tool waits, and is retried once the tool exists\n";
$r = worker('extract_text,thumbnails', ['DC_FFMPEG' => '/nonexistent/ffmpeg', 'DC_FFPROBE' => '/nonexistent/ffprobe']);
ok(array_unique(array_map(fn ($k) => thumb_of($ids[$k])['thumb_state'], ['mp4', 'mov', 'webm', 'avi'])) === ['no_tool'], 'without ffmpeg every video waits as no_tool');
ok(thumb_of($ids['png'])['thumb_state'] === 'made' && thumb_of($ids['jpg'])['thumb_state'] === 'made' && thumb_of($ids['pdf'])['thumb_state'] === 'made', '...while the PNG, the JPEG (GD) and the PDF (pdftoppm) are made');
ok(thumb_of($ids['docx'])['thumb_state'] === null && thumb_of($ids['xlsx'])['thumb_state'] === null, 'a DOCX and an XLSX are not in the queue (no thumbnail for their kind)');
ok(($r['ran']['thumbnails']['waiting'] ?? 0) === 4, 'the pass reports 4 waiting on a tool (' . json_encode($r['ran']['thumbnails'] ?? $r) . ')');
$r = worker('thumbnails');
$mp4 = thumb_of($ids['mp4']);
ok($mp4['thumb_state'] === 'made' && thumb_of($ids['mov'])['thumb_state'] === 'made' && thumb_of($ids['webm'])['thumb_state'] === 'made', 'with ffmpeg: the MP4, the MOV and the WebM are made');
ok(thumb_of($ids['avi'])['thumb_state'] === 'made', '...and the AVI (it cannot play in a browser, but ffmpeg reads its frames)');
ok($mp4['duration_ms'] !== null && abs((int) $mp4['duration_ms'] - 3000) <= 300, 'the MP4\'s length from ffprobe: ' . $mp4['duration_ms'] . ' ms (3 s made)');
$file = store() . '/thumbs/' . $ids['mp4'] . '/' . current_version($ids['mp4']) . '.jpg';
$size = is_file($file) ? getimagesize($file) : false;
ok($size !== false && $size[0] === 320 && $size[1] === 240 && $size['mime'] === 'image/jpeg', 'the video\'s frame is a 320 × 240 JPEG in thumbs/<document>/<version>.jpg');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'thumb.made' AND document_id = :d", ['d' => $ids['mp4']]) === 1, 'thumb.made logged once for the MP4');

echo "3. Upright\n";
ok(exif_read_data($s['files']['jpg'])['Orientation'] === 6, 'the fixture JPEG says Orientation 6 (stored on its side: 400 × 200)');
$jt = store() . '/thumbs/' . $ids['jpg'] . '/' . current_version($ids['jpg']) . '.jpg';
$js = getimagesize($jt);
ok($js[0] === 160 && $js[1] === 320, 'its thumbnail stands upright: ' . $js[0] . ' × ' . $js[1] . ' (the quarter turn applied)');
$im = imagecreatefromjpeg($jt);
$top = imagecolorsforindex($im, imagecolorat($im, 80, 60));
$bottom = imagecolorsforindex($im, imagecolorat($im, 80, 260));
ok($top['red'] > 150 && $top['blue'] < 100 && $bottom['blue'] > 150 && $bottom['red'] < 100, 'the left half (red) is now on top and the right half (blue) below: turned clockwise');
$pt = getimagesize(store() . '/thumbs/' . $ids['pdf'] . '/' . current_version($ids['pdf']) . '.jpg');
ok(max($pt[0], $pt[1]) === 320, 'the PDF\'s first page at 320 px');

echo "4. Made before db/023\n";
$legacy = fx_dir() . '/legacy.png'; $im = imagecreatetruecolor(100, 80); imagefill($im, 0, 0, imagecolorallocate($im, 10, 200, 10)); imagepng($im, $legacy); imagedestroy($im);
$lid = (int) upload_file(27, $s['media'], $legacy)['finish']['json']['record_id'];
$lt = store() . '/thumbs/' . $lid . '/' . current_version($lid) . '.jpg';
@mkdir(dirname($lt), 0750, true);
copy($jt, $lt);
$md5 = md5_file($lt);
$marker = fx_dir() . '/marker.png'; copy($legacy, $marker);
$mid = (int) upload_file(27, $s['media'], $marker)['finish']['json']['record_id'];
$mt = store() . '/thumbs/' . $mid . '/' . current_version($mid) . '.jpg';
@mkdir(dirname($mt), 0750, true);
touch($mt . '.none');
worker('extract_text,thumbnails');
ok(thumb_of($lid)['thumb_state'] === 'made' && md5_file($lt) === $md5, 'a thumbnail already on disk is recorded made and left as it is');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'thumb.made' AND document_id = :d", ['d' => $lid]) === 0, '...and not logged as made again');
ok(thumb_of($mid)['thumb_state'] === 'none' && !is_file($mt . '.none') && !is_file($mt), 'slice 2\'s .none marker is recorded none and removed (nothing remade)');

echo "5. Idempotent\n";
$r = worker('thumbnails');
ok(($r['ran']['thumbnails']['made'] ?? 1) === 0 && ($r['ran']['thumbnails']['waiting'] ?? 1) === 0 && ($r['ran']['thumbnails']['recorded'] ?? 1) === 0, 'a second pass finds nothing to do');

echo "6. The listing\n";
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$rows = []; foreach (q('SELECT * FROM dc_folder_listing(:f)', ['f' => $s['media']]) as $row) { $rows[$row['name']] = $row; }
ok($rows['beach.png']['has_thumbnail'] === true && $rows['launch.mp4']['has_thumbnail'] === true && $rows['minutes.docx']['has_thumbnail'] === false, 'has_thumbnail = a thumbnail was made (PNG, MP4 yes; DOCX no)');
ok(abs((int) $rows['launch.mp4']['duration_ms'] - 3000) <= 300 && $rows['beach.png']['duration_ms'] === null, 'duration_ms on the video, NULL on the image');
ok($rows['launch.mp4']['mime_type'] === 'video/mp4' && $rows['interview.mov']['mime_type'] === 'video/quicktime' && $rows['old-camera.avi']['mime_type'] === 'video/x-msvideo', 'mime_type: video/mp4, video/quicktime, video/x-msvideo (' . $rows['old-camera.avi']['mime_type'] . ')');
ok($rows['beach.png']['preview_hidden'] === false, 'preview_hidden false by default');
finish();
