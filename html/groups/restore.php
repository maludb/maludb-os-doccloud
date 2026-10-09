<?php
declare(strict_types=1);
/** Action `group_restore` (group; log group.restore): groups.manage. A department group's members are re-synced on restore. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('groups.manage');
$pdo = db();
$g = group_from_request($pdo, 'group');
$id = (int) $g['group_id'];
if ($g['archived_at'] === null) {
    refuse(422, 'The group is not archived.');
}
dc_guard($pdo, static function () use ($pdo, $g, $id): void {
    $pdo->beginTransaction();
    try {
        group_restore($pdo, $id);
        if ($g['kind'] === 'department') {
            $pdo->prepare('SELECT dc_group_sync_department(:g)')->execute(['g' => $id]);
        }
        files_log($pdo, 'group.restore', 'group', $id, ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'after' => ['name' => $g['name']]]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((string) $e->getCode() === '23505') {
            throw new DomainException('That department has another live group now.');
        }
        throw $e;
    }
});
dc_done('Restored the group ' . $g['name'], $id, dc_land('/groups/' . $id, 'restored'), 'groupChanged');
