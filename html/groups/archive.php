<?php
declare(strict_types=1);
/** Action `group_archive` (group; log group.archive): its library closes to members — the admin and each owner of a document still reach them. groups.manage. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('groups.manage');
$pdo = db();
$g = group_from_request($pdo, 'group');
$id = (int) $g['group_id'];
if ($g['archived_at'] !== null) {
    refuse(422, 'The group is already archived.');
}
dc_guard($pdo, static function () use ($pdo, $g, $id): void {
    $pdo->beginTransaction();
    group_archive($pdo, $id);
    files_log($pdo, 'group.archive', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'after' => ['name' => $g['name']]]);
    $pdo->commit();
});
dc_done('Archived the group ' . $g['name'], $id, dc_land('/groups/' . $id, 'archived'), 'groupChanged');
