<?php
declare(strict_types=1);
/**
 * POST /api/v1/links {document|folder, kind: view|request, label, days, allow_download, password?, note?} — a link (the action link_make) or a file request (request_make) under the key's person: links.make / requests.make
 * and manage on the thing, the handler's own words otherwise. 201 {link_id, url, expires_at} — the URL is shown ONCE (the token is hashed; nothing can show it again). Slice 7.
 */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_route(['POST'], true);
$kind = api_field('kind') ?? 'view';
if (!in_array($kind, ['view', 'request'], true)) {
    api_error('invalid', 'kind is view or request.', 422, ['field' => 'kind']);
}
$form = [];
foreach (['label', 'note', 'password', 'allow_download', 'message'] as $f) {
    if (isset($_POST[$f])) {
        $form[$f] = $_POST[$f];
    }
}
if (isset($_POST['days'])) {
    $form['expires_in_days'] = $_POST['days'];
} elseif (isset($_POST['expires_in_days'])) {
    $form['expires_in_days'] = $_POST['expires_in_days'];
}
if (isset($form['allow_download'])) {
    $form['allow_download_sent'] = '1';
}
if ($kind === 'request') {
    if (!isset($_POST['folder'])) {
        api_error('invalid', 'A file request is into a folder: send folder.', 422, ['field' => 'folder']);
    }
    [$status, $out] = api_run_handler('requests/make.php', $form + ['folder' => (string) $_POST['folder']]);
} else {
    $thing = isset($_POST['document']) ? ['document' => (string) $_POST['document']] : (isset($_POST['folder']) ? ['folder' => (string) $_POST['folder']] : null);
    if ($thing === null) {
        api_error('invalid', 'Send document or folder.', 422);
    }
    [$status, $out] = api_run_handler('links/make.php', $form + $thing);
}
if ($status !== 200) {
    api_relay($status, $out);
}
api_json(['link_id' => (int) $out['record_id'], 'url' => $out['url'] ?? null, 'expires_at' => $out['expires_at'] ?? null, 'kind' => $kind], 201);
