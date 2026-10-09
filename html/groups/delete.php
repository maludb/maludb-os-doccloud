<?php
declare(strict_types=1);
/** Action `group_delete` (group — archived, its library holding no document; new_owner is not needed when nothing is left; confirm_name; log group.delete; deletion): groups.manage. Else "Archive it, or move its documents first." */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('groups.manage');
$pdo = db();
$g = group_from_request($pdo, 'group');
$id = (int) $g['group_id'];
if ((string) req_val('confirm_name') !== $g['name']) {
    dc_refuse_fields(['confirm_name' => 'Type the group\'s name to delete it.']);
}
if ($g['archived_at'] === null) {
    refuse(422, 'Archive the group first; delete it once it is archived and empty.');
}
if ($g['library_id'] !== null && (int) one_value($pdo, 'SELECT count(*) FROM documents WHERE library_id = :l', ['l' => (int) $g['library_id']]) > 0) {
    refuse(422, 'The group\'s library still holds documents (some may be in the trash). Archive it, or move its documents first.');
}
dc_guard($pdo, static function () use ($pdo, $g, $id): void {
    $pdo->beginTransaction();
    files_log($pdo, 'group.delete', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'before' => ['name' => $g['name'], 'key' => $g['key'], 'kind' => $g['kind']]]);
    group_delete($pdo, $id);
    $pdo->commit();
});
dc_done('Deleted the group ' . $g['name'], $id, dc_land('/groups', 'deleted'), 'groupChanged');
