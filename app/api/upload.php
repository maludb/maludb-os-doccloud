<?php
declare(strict_types=1);

/**
 * The upload routes of the token API (slice 7). The one-request form (≤ 64 MB, POST /upload: multipart `file`, `folder`, `conflict`) is api_upload_one(); the parts form
 * (POST /upload/start · PUT /upload/{session}/{n} · POST /upload/{session}/finish) mirrors slice 2's routes with the same engine — api_upload_start/part/finish run those
 * handlers under the key's person. A name already taken is a 409 {existing_document_id} unless `conflict` says version (a new version of it) or keep_both (a copy named for it).
 */

/** POST /api/v1/upload — answers [status, payload]. */
function api_upload_one(PDO $pdo, array $member): never
{
    $me = (int) $member['id'];
    if (!has_right('documents.upload')) {
        api_error('forbidden', 'You may not upload.', 403);
    }
    $folderId = request_integer('folder') ?? request_integer('folder_id');
    $st = $pdo->prepare('SELECT * FROM folders WHERE id = :id AND trashed_at IS NULL');
    $st->execute(['id' => $folderId ?? 0]);
    $folder = $st->fetch();
    $level = $folder === false ? 'none' : dc_folder_level($pdo, (int) $folder['id']);
    if ($folder === false || $level === 'none') {
        api_error('not_found', 'Not found.', 404);
    }
    if (!dc_level_at_least($level, 'upload')) {
        api_error('forbidden', 'You may not add to this folder.', 403);
    }
    if (!isset($_FILES['file'])) {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 64 * 1048576) {
            api_error('too_large', 'One request takes up to 64 MB; use the parts API (/upload/start).', 413);
        }
        api_error('invalid', 'Send the file as a multipart field named file.', 422, ['field' => 'file']);
    }
    $f = $_FILES['file'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        $big = (int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE;
        api_error($big ? 'too_large' : 'invalid', $big ? 'One request takes up to 64 MB; use the parts API (/upload/start).' : 'The file did not arrive.', $big ? 413 : 422, ['field' => 'file']);
    }
    $documentId = request_integer('document_id') ?? request_integer('document');
    if ($documentId !== null) {
        $doc = document_at_level($pdo, $documentId, 'edit');
        if ((int) $doc['folder_id'] !== (int) $folder['id']) {
            api_error('invalid', 'That document is in another folder.', 422);
        }
    }
    $conflict = (string) (req_val('conflict') ?? req_val('mode') ?? req_val('on_conflict') ?? 'refuse');
    if (!in_array($conflict, ['refuse', 'version', 'keep_both'], true)) {
        api_error('invalid', 'conflict is version, keep_both or refuse.', 422, ['field' => 'conflict']);
    }
    try {
        $r = upload_one($pdo, (int) $folder['id'], ['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'size' => (int) $f['size']], $me,
                        ['name' => req_val('name'), 'document_id' => $documentId, 'on_conflict' => $conflict, 'note' => req_val('note'), 'source' => 'api']);
    } catch (UploadRefused $e) {
        log_activity($pdo, 'upload.refused', 'folder', (int) $folder['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['code' => $e->reason, 'filename' => mb_substr((string) $f['name'], 0, 120), 'key_label' => api_key_label()]]);
        api_json(['error' => ['code' => $e->reason, 'message' => $e->getMessage()] + $e->extra], $e->status);
    } catch (DomainException $e) {
        api_error('invalid', $e->getMessage(), 422);
    }
    log_activity($pdo, $r['new_document'] ? 'document.upload' : 'document.version', 'document', $r['document_id'], ['document_id' => $r['document_id'], 'folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'],
        'after' => ['name' => $r['name'], 'version_no' => $r['version_no'], 'byte_size' => (int) $f['size'], 'mime_kind' => $r['kind'], 'deduplicated' => $r['deduplicated'], 'key_label' => api_key_label()]]);
    api_json(['document_id' => $r['document_id'], 'version_id' => $r['version_id'], 'version_no' => $r['version_no'], 'name' => $r['name'], 'deduplicated' => $r['deduplicated'], 'new_document' => $r['new_document'], 'mime_kind' => $r['kind']], 201);
}

/** The parts form: run slice 2's handler under the key and relay its answer (its own words, its own codes). */
function api_upload_relay(string $handlerFile, array $form): never
{
    [$status, $out] = api_run_handler($handlerFile, $form);
    unset($out['location'], $out['refresh']);
    api_relay($status, $out);
}
