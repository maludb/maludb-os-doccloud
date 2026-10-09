<?php
declare(strict_types=1);
/** Action `document_reextract` (document): the current version's text read again by the worker (extracted_status → pending); manage on the document. Log document.reextract. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'manage');
$id = (int) $doc['id'];
dc_guard($pdo, static function () use ($pdo, $doc, $id): void {
    if (!document_reextract($pdo, $id)) {
        throw new DomainException('There is no version to read.');
    }
    files_log($pdo, 'document.reextract', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name']]]);
});
dc_done('The text of ' . $doc['name'] . ' will be read again', $id, dc_land(return_path('/documents/' . $id), 'reextract'), 'documentChanged');
