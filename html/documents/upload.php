<?php
declare(strict_types=1);
/**
 * Action `document_upload` — the ONE-REQUEST upload (≤ 64 MB; uploads-versions.md divergence 1): folder, file (a multipart field, or `file_base64`
 * + `filename` for an agent that cannot post multipart), name?, document? (a new version of it), on_conflict? (version | keep_both | refuse;
 * version by default), note?. The same finish as the parts (upload_one). Logged document.upload / document.version; record_id = the document,
 * location its page. A browser without JavaScript posts here from the upload screen's plain form, one file at a time.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('folder_id') ?? request_integer('id'), 'upload');
$documentId = request_integer('document') ?? request_integer('document_id');
if ($documentId !== null) {
    require_right('documents.edit');
    $doc = document_at_level($pdo, $documentId, 'edit');
} else {
    require_right('documents.upload');
}
$onConflict = (string) (req_val('on_conflict') ?? 'version');
if (!in_array($onConflict, ['version', 'keep_both', 'refuse'], true)) {
    dc_refuse_fields(['on_conflict' => 'on_conflict is version, keep_both or refuse.']);
}
$file = null;
$tmpMade = null;
if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    if ((int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        dc_refuse_fields(['file' => (int) $_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE ? 'One request takes up to 64 MB; larger files go in parts.' : 'The file did not arrive.']);
    }
    $file = ['name' => (string) $_FILES['file']['name'], 'tmp_name' => (string) $_FILES['file']['tmp_name'], 'size' => (int) $_FILES['file']['size']];
} elseif (req_val('file_base64') !== null) {
    $bytes = base64_decode((string) req_val('file_base64'), true);
    if ($bytes === false) {
        dc_refuse_fields(['file_base64' => 'file_base64 is not valid base64.']);
    }
    $dir = dc_storage_path('uploads/tmp');
    @mkdir($dir, 0750, true);
    $tmpMade = $dir . '/' . bin2hex(random_bytes(8));
    file_put_contents($tmpMade, $bytes);
    $file = ['name' => (string) (req_val('filename') ?? req_val('name') ?? 'file'), 'tmp_name' => $tmpMade, 'size' => strlen($bytes)];
} else {
    dc_refuse_fields(['file' => 'Send the file (multipart `file`, or `file_base64` with `filename`).']);
}
try {
    $r = upload_one($pdo, (int) $folder['id'], $file, $me, ['name' => req_val('name'), 'document_id' => $documentId, 'on_conflict' => $onConflict, 'note' => req_val('note'), 'source' => default_activity_source() === 'agent' ? 'agent' : 'web']);
} catch (UploadRefused $e) {
    if ($tmpMade !== null) { @unlink($tmpMade); }
    log_activity($pdo, 'upload.refused', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['code' => $e->reason, 'byte_size' => $file['size'], 'filename' => mb_substr($file['name'], 0, 120)]]);
    upload_refused($e);
} catch (DomainException $e) {
    if ($tmpMade !== null) { @unlink($tmpMade); }
    dc_refuse_fields(['file' => $e->getMessage()]);
}
$event = $r['new_document'] ? 'document.upload' : 'document.version';
log_activity($pdo, $event, 'document', $r['document_id'], ['document_id' => $r['document_id'], 'folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'],
    'after' => ['name' => $r['name'], 'version_no' => $r['version_no'], 'byte_size' => $file['size'], 'mime_kind' => $r['kind'], 'deduplicated' => $r['deduplicated'], 'inline_ok' => $r['inline_ok']]]);
$did = $r['new_document'] ? 'Uploaded ' . $r['name'] : 'Added version ' . $r['version_no'] . ' of ' . $r['name'];
dc_done($did, $r['document_id'], dc_land(return_path('/documents/' . $r['document_id']), $r['new_document'] ? 'uploaded' : 'version'), 'documentChanged',
    ['version_id' => $r['version_id'], 'version_no' => $r['version_no'], 'deduplicated' => $r['deduplicated'], 'name' => $r['name'], 'new_document' => $r['new_document'], 'mime_kind' => $r['kind']]);
