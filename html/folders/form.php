<?php
declare(strict_types=1);
/** A folder's form (screens `folder-add` /folders/new?parent= and `folder-edit` /folders/{id}/edit): name, colour, auto-tags (stored now; db/009's trigger applies them — slice 4 makes the vocabulary real). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$id = request_integer('id');
$folder = null;
$parent = null;
if ($id !== null) {
    $folder = folder_at_level($pdo, $id, 'edit');
    if ($folder['parent_id'] === null) {
        refuse(422, 'A library\'s root is renamed with the library.');
    }
    $parent = folder_at_level($pdo, (int) $folder['parent_id'], 'view');
} else {
    require_right('documents.upload');
    $parent = folder_at_level($pdo, request_integer('parent'), 'upload', 'folder');
}
$screen = $id === null ? 'folder-add' : 'folder-edit';
log_screen_view($pdo, $screen);
$values = $folder !== null ? ['name' => $folder['name'], 'colour' => $folder['colour'], 'auto_tags' => implode(', ', pg_text_array((string) $folder['auto_tags']))] : ['name' => '', 'colour' => null, 'auto_tags' => ''];
if (wants_json()) {
    respond_screen(['folder' => $folder === null ? null : present_folder(find_folder($pdo, (int) $folder['id']) + ['my_level' => $folder['my_level']]), 'parent' => present_folder(find_folder($pdo, (int) $parent['id']) + ['my_level' => $parent['my_level']]),
        'values' => $values, 'colours' => FOLDER_COLOURS]);
}
render_screen($id === null ? 'New folder' : 'Edit ' . $folder['name'], view('files/form.php', ['folder' => $folder, 'parent' => $parent, 'values' => $values, 'errors' => [], 'crumbs' => folder_crumbs($pdo, (int) $parent['id'])]),
    ['activeNav' => 'files', 'screen' => $screen, 'entity' => 'folder', 'recordId' => $id ?? '']);
