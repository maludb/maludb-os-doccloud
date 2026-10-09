<?php
declare(strict_types=1);
/** My files (screen `my-files`, /files): the person's own library — 302 to the `folder` screen at its root (/f/{root}); a guest goes to Shared with me. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
if (is_guest() || !has_right('library.personal')) {
    if (is_guest()) {
        redirect('/shared/');
    }
    require_right('library.personal');
}
$pdo = db();
$root = one_value($pdo, "SELECT root_folder_id FROM libraries WHERE kind = 'personal' AND owner_member_id = app_current_member_id()");
if ($root === null) {
    $lib = one_value($pdo, 'SELECT dc_library_ensure_personal(:m)', ['m' => (int) current_member_id()]);
    $root = $lib === null ? null : one_value($pdo, 'SELECT root_folder_id FROM libraries WHERE id = :l', ['l' => (int) $lib]);
}
if ($root === null) {
    refuse(403, 'You may not keep a library of your own.');
}
redirect('/f/' . (int) $root . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
