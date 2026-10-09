<?php
declare(strict_types=1);
/**
 * POST (or PUT) /upload/part?session=&n= — one part's bytes as the raw body (application/octet-stream) or a multipart `part` field
 * (uploads-and-versions.md §2.2). The session's owner only; a repeat overwrites (resume). Answers {part_no, parts_done, parts_total}. Not logged.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT'], true)) {
    http_response_code(405);
    header('Allow: POST, PUT');
    exit;
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$GLOBALS['__raw_body_request'] = true;
require_login();
if (!is_action_authed()) {
    $tok = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['csrf_token'] ?? ''));
    if ($tok === '' || !hash_equals(csrf_token(), $tok)) {
        refuse(403, 'The form expired. Reload and try again.');
    }
}
json_mode_begin();
$pdo = db();
$sessionId = request_integer('session') ?? request_integer('session_id');
$partNo = request_integer('n') ?? request_integer('part_no');
$session = $sessionId === null ? null : upload_session($pdo, $sessionId);
if ($session === null || (int) $session['member_id'] !== (int) current_member_id()) {
    refuse(404, 'Not found.');
}
$tmp = request_bytes_to_file('part');
if ($tmp === null) {
    refuse(422, 'Send the part\'s bytes as the body.');
}
try {
    $r = upload_part($pdo, $session, (int) $partNo, $tmp);
} catch (UploadRefused $e) {
    @unlink($tmp);
    upload_refused($e);
}
emit_action_status(true, ['did' => 'Part ' . $r['part_no'] . ' received', 'record_id' => (int) $session['id']] + $r);
json_response(['ok' => true] + $r);
