<?php
declare(strict_types=1);
/** `link-add` (/links/new?folder=|document=) and a link's edit (/links/{id}/edit): label, expires in, download allowed, a password, a note. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$editId = request_integer('id');
$link = null;
if ($editId !== null) {
    $l = link_from_request($pdo, 'id');
    $link = $l['row'];
    $kind = $l['kind'];
    $thing = $l['thing'];
    if ($link['kind'] !== 'view') {
        refuse(404, 'Not found.');
    }
} else {
    require_right('links.make');
    $t = thing_from_request($pdo, 'manage');
    $kind = $t['kind'];
    $thing = $t['row'];
}
$id = (int) $thing['id'];
$allowNever = (bool) one_value($pdo, "SELECT dc_setting_bool('link_no_expiry_allowed')");
$defaultDays = (int) one_value($pdo, "SELECT dc_setting_int('link_default_days')");
if (wants_json()) {
    respond_screen(['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'link' => $link === null ? null : present_link(find_link($pdo, (int) $link['id']) ?? []), 'allow_never' => $allowNever, 'default_days' => $defaultDays]);
}
render_screen($link === null ? 'A link to ' . $thing['name'] : 'Edit the link ' . $link['label'], view('links/form.php', ['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'link' => $link, 'allowNever' => $allowNever, 'defaultDays' => $defaultDays, 'request' => false]),
    ['activeNav' => 'files', 'screen' => 'link-add', 'entity' => $kind, 'recordId' => $id]);
