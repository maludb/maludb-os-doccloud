<?php
declare(strict_types=1);
/** A shared library's form (screens `library-add` /libraries/new and `library-edit` /libraries/{id}/edit): name, description, everyone level; the quota is the admin's (slice 6). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
require_right('libraries.shared');
$pdo = db();
$id = request_integer('id');
$lib = null;
if ($id !== null) {
    $lib = library_from_request($pdo, 'id');
    if ($lib['kind'] !== 'shared') {
        refuse(422, 'Only a shared library is edited here: a personal library is its owner\'s, a group\'s follows its group.');
    }
}
$screen = $id === null ? 'library-add' : 'library-edit';
log_screen_view($pdo, $screen);
$values = $lib !== null ? ['name' => $lib['name'], 'description' => $lib['description'] ?? '', 'everyone_level' => $lib['everyone_level']] : ['name' => '', 'description' => '', 'everyone_level' => 'view'];
if (wants_json()) {
    respond_screen(['library' => $lib === null ? null : present_library(find_library($pdo, (int) $lib['id'])), 'values' => $values, 'everyone_levels' => EVERYONE_LEVELS]);
}
render_screen($id === null ? 'New shared library' : 'Edit ' . $lib['name'], view('libraries/form.php', ['lib' => $lib, 'values' => $values, 'errors' => []]), ['activeNav' => 'libraries', 'screen' => $screen, 'entity' => 'library', 'recordId' => $id ?? '']);
