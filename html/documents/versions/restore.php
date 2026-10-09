<?php
declare(strict_types=1);
/** Action `version_restore` (document, version — the version NUMBER or id): a new version from the old blob (dc_version_restore); versions.restore and edit on the document; a lock by another refuses. Log version.restore. */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('versions.restore');
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'edit');
$id = (int) $doc['id'];
if (($holder = lock_held_by_another($pdo, $id, $me)) !== null) {
    refuse(423, 'Locked by ' . $holder . '.');
}
$vn = request_integer('version');
$st = $pdo->prepare('SELECT id, version_no, purged_at FROM document_versions WHERE document_id = :d AND (version_no = :n OR id = :n)');
$st->execute(['d' => $id, 'n' => $vn ?? 0]);
$v = $st->fetch();
if ($v === false) {
    refuse(404, 'No such version.');
}
if ($v['purged_at'] !== null) {
    refuse(422, 'That version was purged; its bytes are gone.');
}
$newId = dc_guard($pdo, static function () use ($pdo, $doc, $id, $v, $me): int {
    $pdo->beginTransaction();
    try {
        $newId = version_restore($pdo, (int) $v['id'], $me);
        $newNo = (int) one_value($pdo, 'SELECT version_no FROM document_versions WHERE id = :v', ['v' => $newId]);
        files_log($pdo, 'version.restore', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'from_version_no' => (int) $v['version_no'], 'new_version_no' => $newNo]]);
        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
});
dc_done('Restored version ' . (int) $v['version_no'] . ' of ' . $doc['name'] . ' as a new version', $newId, dc_land(return_path('/documents/' . $id . '/versions'), 'restored'), 'documentChanged', ['document_id' => $id]);
