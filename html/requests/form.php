<?php
declare(strict_types=1);
/** `request-add` (/requests/new?folder=): a label, a message to the uploader, an expiry, an optional password. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
require_right('requests.make');
$pdo = db();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
$id = (int) $folder['id'];
$defaultDays = (int) one_value($pdo, "SELECT dc_setting_int('link_default_days')");
$allowNever = (bool) one_value($pdo, "SELECT dc_setting_bool('link_no_expiry_allowed')");
if (wants_json()) {
    respond_screen(['folder' => $id, 'name' => $folder['name'], 'default_days' => $defaultDays, 'allow_never' => $allowNever]);
}
render_screen('Request files into ' . $folder['name'], view('links/form.php', ['kind' => 'folder', 'id' => $id, 'name' => $folder['name'], 'link' => null, 'allowNever' => $allowNever, 'defaultDays' => $defaultDays, 'request' => true]),
    ['activeNav' => 'files', 'screen' => 'request-add', 'entity' => 'folder', 'recordId' => $id]);
