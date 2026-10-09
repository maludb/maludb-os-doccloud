<?php
declare(strict_types=1);
/**
 * GET /x/{document}/{version}/{expires}/{consumer}/{sig} — the signed download of the K7 share `document_link` (slice 7). No session, no login: the signature (a key derived from ACTION_TOKEN_KEY),
 * the ten minutes, and — at serve time — the consumer's reach (dc_application_can_see_document: the folder still shared with it, the document not trashed, not given rows of its own that
 * leave it out). Any failure answers the same 404. Attachment only (inline when ?disposition=inline AND the type is inline-safe); ranges and ETag by store_stream(). Logged
 * `document.download` with source `application`, the consumer in `after`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/sign.php';
$GLOBALS['__public_door'] = 'application';
header_remove('Set-Cookie');
$nothing = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not found.';
    exit;
};
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $nothing();
}
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$facts = signed_download_verify($path);
if ($facts === null) {
    $nothing();
}
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '', false)");
if (!(bool) one_value($pdo, 'SELECT dc_application_can_see_document(:d, :a)', ['d' => $facts['document_id'], 'a' => $facts['consumer']])) {
    $nothing();
}
$st = $pdo->prepare('SELECT d.id, d.folder_id, d.library_id, v.id AS version_id, v.version_no, v.filename, v.mime_type, v.byte_size, v.sha256, b.storage_path
                       FROM documents d JOIN document_versions v ON v.document_id = d.id JOIN blobs b ON b.id = v.blob_id
                      WHERE d.id = :d AND v.id = :v AND v.purged_at IS NULL AND d.trashed_at IS NULL');
$st->execute(['d' => $facts['document_id'], 'v' => $facts['version_id']]);
$v = $st->fetch();
if ($v === false) {
    $nothing();
}
$inline = ($_GET['disposition'] ?? '') === 'inline' && dc_inline_safe((string) $v['mime_type'], (string) $v['filename'], dc_storage_path((string) $v['storage_path']));
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '"' . $v['sha256'] . '"' && !isset($_SERVER['HTTP_RANGE'])) {
    log_activity($pdo, 'document.download', 'document', (int) $v['id'], ['document_id' => (int) $v['id'], 'folder_id' => (int) $v['folder_id'], 'library_id' => (int) $v['library_id'], 'actor_member_id' => null, 'source' => 'application',
        'after' => ['consumer' => $facts['consumer'], 'door' => 'share', 'version_no' => (int) $v['version_no'], 'bytes' => (int) $v['byte_size']]]);
}
store_stream((string) $v['storage_path'], ['filename' => $v['filename'], 'mime' => $v['mime_type'], 'inline' => $inline, 'sha256' => $v['sha256'], 'byte_size' => (int) $v['byte_size']]);
