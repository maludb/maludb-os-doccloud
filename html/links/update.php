<?php
declare(strict_types=1);
/** Action `link_update` (link, label, expires_in_days, allow_download, password — blank keeps it and the word none removes it —, note): in place, no new token. Log link.update. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('links.make');
$pdo = db();
$l = link_from_request($pdo, 'link');
$old = $l['row'];
if ($old['revoked_at'] !== null || $old['rotated_at'] !== null) {
    refuse(422, 'A revoked or rotated link is not changed.');
}
$errors = [];
$f = [];
if (req_has('label')) {
    $label = trim((string) req_val('label'));
    if ($label === '' || mb_strlen($label) > 120) {
        $errors['label'] = 'A label is 1 to 120 characters.';
    }
    $f['label'] = $label;
}
if (req_has('note')) {
    $note = (string) req_val('note');
    if (mb_strlen($note) > 1000) {
        $errors['note'] = 'The note is up to 1,000 characters.';
    }
    $f['note'] = $note === '' ? null : $note;
}
if (req_has('allow_download_sent')) {
    $f['allow_download'] = req_has('allow_download') && dc_yes('allow_download');
} elseif (req_has('allow_download')) {
    $f['allow_download'] = dc_yes('allow_download', (bool) $old['allow_download']);
}
if (req_has('password')) {
    $p = (string) req_val('password');
    if ($p === '') {
        $f['password'] = null;                     // blank keeps it
    } elseif (strtolower($p) === 'none') {
        $f['password'] = '';                       // the word none removes it
    } elseif (mb_strlen($p) < 4) {
        $errors['password'] = 'A password is at least 4 characters.';
    } else {
        $f['password'] = $p;
    }
}
$f['days'] = link_days_from_request($pdo, $errors, null);
if (req_has('expires_in_days') && $f['days'] === null && trim((string) req_val('expires_in_days')) !== '') {
    // "default" asked → the setting's days from now
    $f['days'] = (int) one_value($pdo, "SELECT dc_setting_int('link_default_days')");
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
dc_guard($pdo, static function () use ($pdo, $old, $f): void { link_update($pdo, (int) $old['id'], $f); });
$new = find_link($pdo, (int) $old['id']);
files_log($pdo, 'link.update', 'link', (int) $old['id'], ['folder_id' => $l['folder_id'], 'document_id' => $l['document_id'], 'library_id' => $l['library_id'],
    'before' => ['label' => $old['label'], 'expires_at' => $old['expires_at'], 'allow_download' => (bool) $old['allow_download'], 'has_password' => $old['password_hash'] !== null],
    'after' => ['label' => $new['label'], 'expires_at' => $new['expires_at'], 'allow_download' => (bool) $new['allow_download'], 'has_password' => (bool) $new['has_password']]]);
dc_done('Saved the link ' . $new['label'], (int) $old['id'], dc_land(return_path(links_url($l['kind'], $l['thing_id'])), 'updated'), 'linksChanged', ['expires_at' => json_ts($new['expires_at'])]);
