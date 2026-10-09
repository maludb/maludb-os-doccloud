<?php
declare(strict_types=1);
/** Action `library_delete` (library, confirm_name; log library.delete; deletion): a SHARED library holding no live document (its folders go with it); a personal library is never deleted; a group library goes with its group (group_delete). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('libraries.shared');
$pdo = db();
$lib = library_from_request($pdo, 'library');
$id = (int) $lib['id'];
if ($lib['kind'] === 'personal') {
    refuse(422, 'A personal library is never deleted.');
}
if ($lib['kind'] === 'group') {
    refuse(422, 'A group library goes with its group: delete the group.');
}
if ((string) req_val('confirm_name') !== $lib['name']) {
    dc_refuse_fields(['confirm_name' => 'Type the library\'s name to delete it.']);
}
if (library_document_count($pdo, $id) > 0 || (int) one_value($pdo, 'SELECT count(*) FROM documents WHERE library_id = :id', ['id' => $id]) > 0) {
    refuse(422, 'The library still holds documents (some may be in the trash): move or purge them first.');
}
dc_guard($pdo, static function () use ($pdo, $lib, $id): void {
    $pdo->beginTransaction();
    files_log($pdo, 'library.delete', 'library', $id, ['library_id' => $id, 'before' => ['name' => $lib['name'], 'kind' => $lib['kind']]]);
    library_delete($pdo, $id);
    $pdo->commit();
});
dc_done('Deleted the library ' . $lib['name'], $id, dc_land('/libraries', 'deleted'), 'libraryChanged');
