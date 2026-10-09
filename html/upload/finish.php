<?php
declare(strict_types=1);
/**
 * POST /upload/finish — session (and `conflict`: version | keep_both, the answer to a 409 met now): assemble, hash, judge, claim the blob,
 * add the version (uploads-and-versions.md §2.3). Logged document.upload (a new document) or document.version. Answers
 * {document_id, version_id, version_no, deduplicated, name, location}; 409 name_taken keeps the session open for the choice; 422 missing_parts
 * {missing}; 422 size_mismatch | hash_mismatch | refused (the database's words).
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$sessionId = request_integer('session') ?? request_integer('session_id');
$session = $sessionId === null ? null : upload_session($pdo, $sessionId);
if ($session === null || (int) $session['member_id'] !== $me) {
    refuse(404, 'Not found.');
}
$conflict = (string) (req_val('conflict') ?? '');
if (!in_array($conflict, ['', 'version', 'keep_both'], true)) {
    refuse(422, 'conflict is version or keep_both.');
}
$folder = folder_at_level($pdo, (int) $session['folder_id'], 'upload');
try {
    $r = upload_finish($pdo, $session, $conflict);
} catch (UploadRefused $e) {
    if ($e->reason === 'name_taken') {
        log_activity($pdo, 'upload.conflict', 'upload_session', (int) $session['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['existing_document_id' => $e->extra['existing_document_id'] ?? null]]);
    } else {
        log_activity($pdo, 'upload.refused', 'upload_session', (int) $session['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'after' => ['code' => $e->reason]]);
    }
    upload_refused($e);
}
$event = $r['new_document'] ? 'document.upload' : 'document.version';
log_activity($pdo, $event, 'document', $r['document_id'], ['document_id' => $r['document_id'], 'folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'],
    'after' => $r['new_document'] ? ['name' => $r['name'], 'byte_size' => (int) $session['byte_size'], 'mime_kind' => $r['kind'], 'version_no' => 1, 'deduplicated' => $r['deduplicated'], 'inline_ok' => $r['inline_ok']]
                                  : ['name' => $r['name'], 'version_no' => $r['version_no'], 'byte_size' => (int) $session['byte_size'], 'deduplicated' => $r['deduplicated']]]);
log_activity($pdo, 'upload.finish', 'upload_session', (int) $session['id'], ['folder_id' => (int) $folder['id'], 'library_id' => (int) $folder['library_id'], 'document_id' => $r['document_id'], 'after' => ['version_id' => $r['version_id']]]);
if (!$r['new_document']) {
    $owner = (int) one_value($pdo, 'SELECT owner_member_id FROM documents WHERE id = :id', ['id' => $r['document_id']]);
    if ($owner !== $me) {
        $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, :a, :dd)')->execute(['m' => $owner, 'k' => 'version_added', 't' => 'A new version of ' . $r['name'], 'b' => 'Version ' . $r['version_no'] . ' was added by ' . (current_member()['display_name'] ?? 'someone') . '.',
            'rt' => 'document', 'rid' => $r['document_id'], 'd' => $r['document_id'], 'f' => (int) $folder['id'], 'a' => $me, 'dd' => 'version_added:' . $r['version_id']]);
    }
}
$did = $r['new_document'] ? 'Uploaded ' . $r['name'] : 'Added version ' . $r['version_no'] . ' of ' . $r['name'];
dc_done($did, $r['document_id'], dc_land('/documents/' . $r['document_id'], $r['new_document'] ? 'uploaded' : 'version'), 'documentChanged',
    ['version_id' => $r['version_id'], 'version_no' => $r['version_no'], 'deduplicated' => $r['deduplicated'], 'name' => $r['name'], 'new_document' => $r['new_document'], 'mime_kind' => $r['kind'], 'inline_ok' => $r['inline_ok']]);
