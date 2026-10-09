<?php
declare(strict_types=1);
/** Action `version_purge` (document, version — never the current): the admin (retention.manage / trash.purge); pauses for an agent (deletion). Releases the bytes. Log version.purge. */
require_once dirname(__DIR__, 3) . '/app/features/files/handler.php';
dc_handler_begin();
require_any_right('retention.manage|trash.purge');
$pdo = db();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'view');
$id = (int) $doc['id'];
$vn = request_integer('version');
$st = $pdo->prepare('SELECT id, version_no, purged_at, byte_size FROM document_versions WHERE document_id = :d AND (version_no = :n OR id = :n)');
$st->execute(['d' => $id, 'n' => $vn ?? 0]);
$v = $st->fetch();
if ($v === false) {
    refuse(404, 'No such version.');
}
if ((int) $v['id'] === (int) $doc['current_version_id']) {
    refuse(422, 'The current version is never purged.');
}
if ($v['purged_at'] !== null) {
    refuse(422, 'That version was already purged.');
}
dc_guard($pdo, static function () use ($pdo, $doc, $id, $v): void {
    $pdo->beginTransaction();
    try {
        version_purge($pdo, (int) $v['id']);
        files_log($pdo, 'version.purge', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'version_no' => (int) $v['version_no'], 'byte_size' => (int) $v['byte_size']]]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
});
dc_done('Purged version ' . (int) $v['version_no'] . ' of ' . $doc['name'], (int) $v['id'], dc_land(return_path('/documents/' . $id . '/versions'), 'purged'), 'documentChanged', ['document_id' => $id]);
