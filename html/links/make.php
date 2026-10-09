<?php
declare(strict_types=1);
/**
 * Action `link_make` (folder or document, label, expires_in_days, allow_download, password, note): dc_link_mint — the raw token shown ONCE on the links
 * screen (kept in the session for one render), never logged. links.make and manage on the thing; pauses for an agent (external_send). Log link.make.
 */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('links.make');
$pdo = db();
$me = (int) current_member_id();
$thing = thing_from_request($pdo, 'manage');
$errors = [];
$label = trim((string) (req_val('label') ?? ''));
if ($label === '' || mb_strlen($label) > 120) {
    $errors['label'] = 'Give the link a label (up to 120 characters): the log and the Clerk call it that.';
}
$days = link_days_from_request($pdo, $errors);
$note = req_val('note');
if ($note !== null && mb_strlen($note) > 1000) {
    $errors['note'] = 'The note is up to 1,000 characters.';
}
$password = req_val('password');
if ($password !== null && $password !== '' && mb_strlen($password) < 4) {
    $errors['password'] = 'A password is at least 4 characters.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$allowDownload = req_has('allow_download_sent') ? (req_has('allow_download') && dc_yes('allow_download')) : dc_yes('allow_download', true);
$r = dc_guard($pdo, static fn (): array => link_make($pdo, $thing['kind'], $thing['id'], ['label' => $label, 'days' => $days, 'allow_download' => $allowDownload, 'password' => $password, 'note' => $note === '' ? null : $note], $me, 'view'));
$link = find_link($pdo, $r['link_id']);
files_log($pdo, 'link.make', 'link', $r['link_id'], ['folder_id' => $thing['folder_id'], 'document_id' => $thing['document_id'], 'library_id' => $thing['library_id'],
    'after' => ['label' => $label, 'kind' => 'view', 'expires_at' => $link['expires_at'] ?? null, 'allow_download' => $allowDownload, 'has_password' => $password !== null && $password !== '', 'on' => $thing['kind'], 'on_id' => $thing['id']]]);
link_show_once($r['link_id'], $r['raw']);
$url = link_public_url(['kind' => 'view'], $r['raw']);
dc_done('Made the link ' . $label . ' to ' . $thing['name'], $r['link_id'], dc_land(links_url($thing['kind'], $thing['id']) . '?made=' . $r['link_id'], 'made', 'link-url-once'), 'linksChanged', ['url' => $url, 'expires_at' => json_ts($link['expires_at'] ?? null)]);
