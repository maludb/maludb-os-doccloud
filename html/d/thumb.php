<?php
declare(strict_types=1);
/** GET /d/{id}/thumb — the current version's 320 px thumbnail (the same gate as the bytes), cacheable an hour; the kind's icon as a small SVG when there is none. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
$pdo = db();
$id = request_integer('id');
if ($id === null) {
    refuse(404, 'Not found.');
}
if (!is_logged_in()) {
    $ok = false;
    foreach ($_SESSION['link'] ?? [] as $lid => $info) {
        if ((bool) one_value($pdo, 'SELECT dc_link_reaches_document(:l, :d)', ['l' => (int) $lid, 'd' => $id])) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        http_response_code(401);
        exit;
    }
    $pdo->exec("SELECT set_config('app.member_id', '', false)");
    $st = $pdo->prepare('SELECT * FROM documents WHERE id = :id AND trashed_at IS NULL');
    $st->execute(['id' => $id]);
    $doc = $st->fetch() ?: refuse(404, 'Not found.');
} else {
    $doc = document_at_level($pdo, $id, 'view');
}
$thumb = $doc['current_version_id'] === null ? null : store_thumb_path($id, (int) $doc['current_version_id']);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if ($thumb !== null && is_file($thumb)) {
    $etag = '"t' . md5_file($thumb) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($thumb));
    readfile($thumb);
    exit;
}
$word = match ((string) $doc['mime_kind']) { 'image' => 'IMG', 'pdf' => 'PDF', 'office' => 'DOC', 'text' => 'TXT', 'audio' => 'AUD', 'video' => 'VID', 'archive' => 'ZIP', default => 'FILE' };
header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 320 320"><rect width="320" height="320" rx="24" fill="#eef1f6"/><path d="M96 48h96l48 48v176H96z" fill="#fff" stroke="#c9d0dc" stroke-width="6"/><text x="160" y="204" text-anchor="middle" font-family="sans-serif" font-size="44" fill="#6b7280">' . $word . '</text></svg>';
