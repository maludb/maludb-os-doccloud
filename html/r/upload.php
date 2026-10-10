<?php
declare(strict_types=1);
/**
 * POST /r/<token>/upload — one file per request (the route `request_upload`, never an action): the rate rule 20 an hour per address, the file's size
 * against 64 MB and the per-file ceiling, then request_upload() (the maker owns it; the request tag; the maker told). JSON {ok, name} for the page's
 * script, a redirect to …?done=1 for a form. The uploader learns nothing of the folder.
 */
if (!isset($link, $linkId, $hash, $pdo, $base)) {
    http_response_code(404);
    exit;
}
verify_csrf();
$file = $_FILES['file'] ?? null;
$uploader = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
$answer = static function (int $status, array $payload) use ($base): never {
    if (str_contains((string) ($GLOBALS['__public_accept'] ?? ''), 'application/json') || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {   // the page's script, or a tool: JSON; a bare form: the redirect
        json_response($payload, $status);
    }
    if ($status === 200) {
        header('Location: ' . $base . '?done=1', true, 302);
        exit;
    }
    public_render('Not uploaded', view('public/unavailable.php', ['title' => 'Not uploaded', 'message' => (string) ($payload['error']['message'] ?? 'That could not be uploaded.')]), $status);
};
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
    $answer(422, ['ok' => false, 'error' => ['code' => 'no_file', 'message' => 'Choose a file to send.']]);
}
$ceiling = min(64 * 1024 * 1024, dc_file_ceiling($pdo) ?? PHP_INT_MAX);
if ((int) $file['size'] > $ceiling) {
    $answer(413, ['ok' => false, 'error' => ['code' => 'too_large', 'message' => 'A file sent this way is at most ' . format_bytes($ceiling) . '.']]);
}
if (!(bool) one_value($pdo, 'SELECT dc_link_rate_ok(:h, :k)', ['h' => $hash, 'k' => 'upload_hour'])) {
    link_open($pdo, $linkId, 'rate_refused', $hash);
    log_activity($pdo, 'link.refused', 'link', $linkId, ['actor_member_id' => null, 'after' => ['reason' => 'rate']]);
    $answer(429, ['ok' => false, 'error' => ['code' => 'rate_limited', 'message' => 'Too many uploads from your address this hour; try again later.']]);
}
try {
    $pdo->exec("SELECT set_config('app.member_id', '" . (int) $link['made_by'] . "', false)");      // the maker owns it; the walls judge as the maker
    $r = request_upload($pdo, $link, ['name' => (string) $file['name'], 'tmp_name' => (string) $file['tmp_name'], 'size' => (int) $file['size']], $uploader, $hash);
} catch (UploadRefused $e) {
    $answer($e->status, ['ok' => false, 'error' => ['code' => $e->reason, 'message' => $e->getMessage()]]);
}
log_activity($pdo, 'request.upload', 'link', $linkId, ['actor_member_id' => null, 'document_id' => (int) $r['document_id'], 'folder_id' => (int) $link['folder_id'], 'after' => ['link_id' => $linkId, 'document_id' => (int) $r['document_id'], 'bytes' => (int) $file['size']]]);
$answer(200, ['ok' => true, 'name' => (string) $file['name']]);
