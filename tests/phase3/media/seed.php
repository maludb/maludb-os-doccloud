<?php
/** The browser proof's seed: the fixtures uploaded (media_seed), their thumbnails made, every preview shown but the JPEG's; prints the ids as JSON. */
ob_start();
require __DIR__ . '/lib.php';
$s = media_seed();
worker('extract_text,thumbnails');
foreach (['png', 'mp4', 'mov', 'webm'] as $k) { post(27, '/documents/preview-show.php', ['document' => $s['ids'][$k]]); }
post(27, '/documents/preview-hide.php', ['document' => $s['ids']['jpg']]);
ob_end_clean();
echo json_encode(['media' => $s['media'], 'ids' => $s['ids']]);
