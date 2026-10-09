<?php
declare(strict_types=1);
/**
 * POST /upload/start — the first of the three upload routes (docs/build-specs/uploads-and-versions.md §2.1; the action is `document_upload`,
 * these are routes): folder_id, filename, byte_size, mime_hint?, sha256?, document_id? (a new version), mode? (version|keep_both), name?,
 * note?. The gate: `upload`+ on the folder for a new document; `edit`+ on the document (and no other's lock) for a version. Answers the
 * session {session_id, part_bytes, parts_total, deduplicated, expires_at, document_name, mode} — or 409 name_taken {existing_document_id},
 * 413 too_large, 422 quota | empty, 423 locked. JSON always (the browser's uploader speaks JSON); an action token stands in for CSRF.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder_id') ?? request_integer('folder'), 'upload');
$documentId = request_integer('document_id') ?? request_integer('document');
if ($documentId !== null) {
    $doc = document_at_level($pdo, $documentId, 'edit');
    if ((int) $doc['folder_id'] !== (int) $folder['id']) {
        refuse(422, 'That document is in another folder.');
    }
    require_right('documents.edit');
} else {
    require_right('documents.upload');
}
$mode = (string) (req_val('mode') ?? '');
if (!in_array($mode, ['', 'new', 'version', 'keep_both'], true)) {
    refuse(422, 'mode is version or keep_both.');
}
try {
    $s = upload_start($pdo, (int) $folder['id'], ['filename' => (string) (req_val('filename') ?? ''), 'byte_size' => (int) (req_val('byte_size') ?? -1), 'mime_hint' => req_val('mime_hint'), 'sha256' => req_val('sha256'),
                                                   'document_id' => $documentId, 'mode' => $mode === '' ? 'new' : $mode, 'name' => req_val('name'), 'note' => req_val('note'), 'source' => default_activity_source() === 'agent' ? 'agent' : 'web'], $me);
} catch (UploadRefused $e) {
    log_activity($pdo, 'upload.refused', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['code' => $e->reason, 'byte_size' => (int) (req_val('byte_size') ?? 0), 'filename' => mb_substr((string) (req_val('filename') ?? ''), 0, 120)]]);
    upload_refused($e);
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
log_activity($pdo, 'upload.start', 'upload_session', $s['session_id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'document_id' => $documentId,
    'after' => ['byte_size' => (int) (req_val('byte_size') ?? 0), 'parts_total' => $s['parts_total'], 'mode' => $s['mode'], 'deduplicated' => $s['deduplicated']]]);
emit_action_status(true, ['did' => 'Started the upload', 'record_id' => $s['session_id']] + $s);
json_response(['ok' => true] + $s + ['expires_at' => json_ts($s['expires_at'])]);
