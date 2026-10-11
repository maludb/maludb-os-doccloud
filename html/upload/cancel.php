<?php
declare(strict_types=1);
/**
 * POST /upload/cancel — session: the uploader gives up (docs/build-specs/large-uploads.md "Cancel"; a route of `document_upload`, as start, part
 * and finish are). The session's owner only (404 otherwise); an open session only — 409 when it is being stored (assembling) or is over. The
 * row is expired with the error "cancelled" under its row lock (a part being written finishes first; one waiting is then refused), and what
 * had arrived is removed. Logged upload.cancel. Answers {session_id, parts_done, byte_size}.
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
try {
    $c = upload_cancel($pdo, $session);
} catch (UploadRefused $e) {
    upload_refused($e);
}
log_activity($pdo, 'upload.cancel', 'upload_session', (int) $session['id'], ['folder_id' => (int) $session['folder_id'], 'document_id' => $session['document_id'] === null ? null : (int) $session['document_id'],
    'after' => ['filename' => mb_substr((string) $session['filename'], 0, 120), 'byte_size' => (int) $session['byte_size'], 'parts_done' => $c['parts_done'], 'parts_total' => (int) $session['parts_total']]]);
emit_action_status(true, ['did' => 'Cancelled the upload of ' . $session['filename'], 'record_id' => (int) $session['id']] + $c);
json_response(['ok' => true] + $c);
