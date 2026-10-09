<?php
declare(strict_types=1);
/**
 * POST /api/v1/upload — the one-request upload of the token API (uploads-and-versions.md §2.6; slice 7 documents the API): multipart `file` +
 * folder_id, document_id?, mode? (version | keep_both | refuse — version by default), name?, note?; bearer = a person's key acting as them.
 * ≤ 64 MB (Apache's ceiling); more → 413 "use the parts API". Answers the finish's JSON; source 'api'.
 */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
api_require_method('POST');
$member = api_authenticate();
$pdo = db();
$me = (int) $member['id'];
if (!has_right('documents.upload')) {
    api_error('forbidden', 'You may not upload.', 403);
}
$folderId = request_integer('folder_id') ?? request_integer('folder');
$st = $pdo->prepare('SELECT * FROM folders WHERE id = :id');
$st->execute(['id' => $folderId ?? 0]);
$folder = $st->fetch();
if ($folder === false || !dc_level_at_least(dc_folder_level($pdo, (int) $folder['id']), 'upload')) {
    api_error('not_found', 'Not found.', 404);
}
if (!isset($_FILES['file'])) {
    $postMax = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postMax > 64 * 1048576) {
        api_error('too_large', 'One request takes up to 64 MB; use the parts API (/upload/start).', 413);
    }
    api_error('invalid', 'Send the file as a multipart field named file.', 422);
}
$f = $_FILES['file'];
if ((int) $f['error'] !== UPLOAD_ERR_OK) {
    api_error((int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 'too_large' : 'invalid', (int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 'One request takes up to 64 MB; use the parts API (/upload/start).' : 'The file did not arrive.', (int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 413 : 422);
}
$documentId = request_integer('document_id') ?? request_integer('document');
if ($documentId !== null) {
    $doc = document_at_level($pdo, $documentId, 'edit');
}
$mode = (string) (req_val('mode') ?? req_val('on_conflict') ?? 'version');
try {
    $r = upload_one($pdo, (int) $folder['id'], ['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'size' => (int) $f['size']], $me,
                    ['name' => req_val('name'), 'document_id' => $documentId, 'on_conflict' => $mode, 'note' => req_val('note'), 'source' => 'api']);
} catch (UploadRefused $e) {
    api_json(['ok' => false, 'error' => ['code' => $e->reason, 'message' => $e->getMessage()] + $e->extra], $e->status);
} catch (DomainException $e) {
    api_error('invalid', $e->getMessage(), 422);
}
log_activity($pdo, $r['new_document'] ? 'document.upload' : 'document.version', 'document', $r['document_id'], ['document_id' => $r['document_id'], 'folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'source' => 'api',
    'after' => ['name' => $r['name'], 'version_no' => $r['version_no'], 'byte_size' => (int) $f['size'], 'mime_kind' => $r['kind'], 'deduplicated' => $r['deduplicated'], 'key_label' => api_key()['label'] ?? null]]);
api_json(['ok' => true, 'document_id' => $r['document_id'], 'version_id' => $r['version_id'], 'version_no' => $r['version_no'], 'name' => $r['name'], 'deduplicated' => $r['deduplicated'], 'new_document' => $r['new_document'],
          'mime_kind' => $r['kind'], 'location' => '/documents/' . $r['document_id']], 201);
