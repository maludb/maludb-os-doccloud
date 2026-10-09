<?php
declare(strict_types=1);
/** Action `document_purge` (document, confirm=yes; log document.purge): a trashed document, final — versions and bytes released, the graph forgets it. trash.purge (the admin); pauses for an agent (deletion). */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
dc_handler_begin();
require_right('trash.purge');
$pdo = db();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'manage');
$id = (int) $doc['id'];
if ($doc['trashed_at'] === null) {
    refuse(422, 'Only a document in the trash is purged.');
}
if (!dc_yes('confirm')) {
    dc_refuse_fields(['confirm' => 'Say yes to purge ' . $doc['name'] . ': it cannot be restored.']);
}
dc_guard($pdo, static function () use ($pdo, $doc, $id): void {
    $pdo->beginTransaction();
    files_log($pdo, 'document.purge', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'byte_size' => (int) $doc['byte_size']]]);
    if (!document_purge($pdo, $id)) {
        throw new DomainException('It is not in the trash.');
    }
    $pdo->commit();
});
dc_done('Purged ' . $doc['name'], $id, dc_land(return_path('/trash/'), 'purged'), 'documentChanged', ['document_id' => $id]);
