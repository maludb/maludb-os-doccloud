<?php
declare(strict_types=1);
/**
 * THE GATE: GET /d/{id} (the current version) and /d/{id}/v/{n} (uploads-and-versions.md §4). Who may: a session or action-token caller with
 * dc_can_see_document; a link holder ($_SESSION['link'][id], set by slice 3's /s/ door) through dc_link_reaches_document (download only when
 * the link allows); an API key as its person. None → 404, the same nothing. Inline only when the kind is inline-safe (else attachment);
 * ?download=1 forces attachment. Ranges, ETag (the sha256), nosniff, Cache-Control private — store_stream(). Logged document.download or
 * document.preview with the bytes and the door; last_opened_at for a session caller.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
$pdo = db();
$id = request_integer('id');
$versionNo = request_integer('v');
if ($id === null) {
    refuse(404, 'Not found.');
}
$door = 'web';
$linkId = null;
$allowDownload = true;
if (!is_logged_in() && isset($_SERVER['HTTP_AUTHORIZATION']) && !is_action_authed()) {
    require_once dirname(__DIR__, 2) . '/app/api/bootstrap.php';
    api_authenticate();
    $door = 'api';
}
if (!is_logged_in()) {
    // a link holder: the /s/ door put the link in the session (slice 3)
    foreach ($_SESSION['link'] ?? [] as $lid => $info) {
        if ((bool) one_value($pdo, 'SELECT dc_link_reaches_document(:l, :d)', ['l' => (int) $lid, 'd' => $id])) {
            $linkId = (int) $lid;
            $allowDownload = (bool) ($info['allow_download'] ?? true);
            $door = 'link';
            break;
        }
    }
    if ($linkId === null) {
        if (wants_json()) {
            json_error('unauthorized', 'Sign in required.', 401);
        }
        header('Location: ' . launcher_url('app=' . rawurlencode(app_key())), true, 401);      // 401 WITH the way in (PHP would make a bare Location a 302)
        exit;
    }
    $pdo->exec("SELECT set_config('app.member_id', '', false)");
    $st = $pdo->prepare('SELECT * FROM documents WHERE id = :id AND trashed_at IS NULL');
    $st->execute(['id' => $id]);
    $doc = $st->fetch();
    if ($doc === false) {
        refuse(404, 'Not found.');
    }
} else {
    if (is_action_authed() && current_agent_run_id() !== null) {
        $door = 'agent';
    }
    $doc = document_at_level($pdo, $id, 'view');
}
if ($versionNo !== null) {
    $st = $pdo->prepare('SELECT v.*, b.storage_path FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.document_id = :d AND v.version_no = :n AND v.purged_at IS NULL');
    $st->execute(['d' => $id, 'n' => $versionNo]);
} else {
    $st = $pdo->prepare('SELECT v.*, b.storage_path FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.id = :v AND v.purged_at IS NULL');
    $st->execute(['v' => (int) $doc['current_version_id']]);
}
$v = $st->fetch();
if ($v === false) {
    refuse(404, 'Not found.');
}
$download = request_bool('download');
$inlineOk = dc_inline_safe((string) $v['mime_type'], (string) $v['filename'], dc_storage_path((string) $v['storage_path']));
$inline = $inlineOk && !$download;
if ($linkId !== null && $download && !$allowDownload) {
    refuse(403, 'This link shows the document; it does not download it.');
}
if ($linkId !== null && !$inlineOk && !$allowDownload) {
    refuse(403, 'This link shows the document; it does not download it.');
}
$ranged = isset($_SERVER['HTTP_RANGE']);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '"' . $v['sha256'] . '"') {
    $event = $inline ? 'document.preview' : 'document.download';
    log_activity($pdo, $event, 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'actor_member_id' => $linkId !== null ? null : ($_SESSION['member_id'] ?? null),
        'source' => $linkId !== null ? 'portal' : null, 'after' => ['version_no' => (int) $v['version_no'], 'bytes' => (int) $v['byte_size'], 'ranged' => $ranged, 'door' => $door, 'link_id' => $linkId]]);
    if ($linkId !== null) {
        $pdo->prepare('SELECT dc_link_open(:l, :w, :a, :d, :b)')->execute(['l' => $linkId, 'w' => $inline ? 'view' : 'download', 'a' => hash('sha256', client_ip() . '|' . date('Y-m-d') . '|' . (string) env('ACTION_TOKEN_KEY', '')), 'd' => $id, 'b' => (int) $v['byte_size']]);
    } elseif ($door === 'web' && !$ranged) {
        $pdo->prepare('UPDATE documents SET last_opened_at = now() WHERE id = :id')->execute(['id' => $id]);
    }
}
store_stream((string) $v['storage_path'], ['filename' => $v['filename'], 'mime' => $v['mime_type'], 'inline' => $inline, 'sha256' => $v['sha256'], 'byte_size' => (int) $v['byte_size']]);
