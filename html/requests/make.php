<?php
declare(strict_types=1);
/** Action `request_make` (folder, label, message, expires_in_days, password): a request link on the folder — uploads land there owned by me. Pauses for an agent. Log request.make. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('requests.make');
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
$id = (int) $folder['id'];
$errors = [];
$label = trim((string) (req_val('label') ?? ''));
if ($label === '' || mb_strlen($label) > 120) {
    $errors['label'] = 'Give the request a label (up to 120 characters).';
}
$message = req_val('message') ?? req_val('note');
if ($message !== null && mb_strlen($message) > 1000) {
    $errors['message'] = 'The message is up to 1,000 characters.';
}
$days = link_days_from_request($pdo, $errors);
$password = req_val('password');
if ($password !== null && $password !== '' && mb_strlen($password) < 4) {
    $errors['password'] = 'A password is at least 4 characters.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$r = dc_guard($pdo, static fn (): array => request_make($pdo, $id, ['label' => $label, 'days' => $days, 'password' => $password, 'note' => $message === '' ? null : $message], $me));
$link = find_link($pdo, $r['link_id']);
files_log($pdo, 'request.make', 'link', $r['link_id'], ['folder_id' => $id, 'library_id' => (int) $folder['library_id'], 'after' => ['label' => $label, 'kind' => 'request', 'expires_at' => $link['expires_at'] ?? null, 'has_password' => $password !== null && $password !== '']]);
link_show_once($r['link_id'], $r['raw']);
$url = link_public_url(['kind' => 'request'], $r['raw']);
dc_done('Made the file request ' . $label . ' into ' . $folder['name'], $r['link_id'], dc_land('/folders/' . $id . '/links?made=' . $r['link_id'], 'request_made', 'link-url-once'), 'linksChanged', ['url' => $url, 'expires_at' => json_ts($link['expires_at'] ?? null)]);
