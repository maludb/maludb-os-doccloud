<?php
declare(strict_types=1);
/** Action `document_restore` (document; log document.restore): back from the trash to its folder — the library root when that folder is gone, renamed "(2)" when the name is taken. trash.own and manage on the document. */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
dc_handler_begin();
require_right('trash.own');
$pdo = db();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'manage');
$id = (int) $doc['id'];
if ($doc['trashed_at'] === null) {
    refuse(422, 'It is not in the trash.');
}
$r = dc_guard($pdo, static function () use ($pdo, $doc, $id): array {
    $pdo->beginTransaction();
    $r = document_restore($pdo, $id);
    if (!$r['restored']) {
        throw new DomainException('It is not in the trash.');
    }
    files_log($pdo, 'document.restore', 'document', $id, ['document_id' => $id, 'folder_id' => $r['folder_id'], 'library_id' => (int) $doc['library_id'],
        'after' => ['name' => $r['name'], 'was_named' => $doc['name'], 'to_root' => $r['to_root'], 'renamed' => $r['renamed']]]);
    $pdo->commit();
    return $r;
});
$did = 'Restored ' . $r['name'] . ($r['to_root'] ? ' to the library\'s top folder (its folder is gone)' : '') . ($r['renamed'] ? ' as ' . $r['name'] . ' (the name was taken)' : '');
dc_done($did, $id, dc_land(return_path('/documents/' . $id), $r['to_root'] ? 'restored_root' : 'untrashed'), 'documentChanged', ['document_id' => $id, 'folder_id' => $r['folder_id'], 'name' => $r['name'], 'to_root' => $r['to_root'], 'renamed' => $r['renamed']]);
