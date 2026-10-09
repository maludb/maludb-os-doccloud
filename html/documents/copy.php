<?php
declare(strict_types=1);
/**
 * GET: the copy screen (the move screen with "Copy to"). POST: action `document_copy` (document, target_folder, name?): a new document in the target
 * sharing the current version's blob, owned by the copier, the tags copied; view on the document, add on the target. Log document.copy; lands on the copy.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $pdo = db();
    $doc = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
    log_screen_view($pdo, 'document-move');
    $d = find_document($pdo, (int) $doc['id']);
    if (wants_json()) {
        respond_screen(['document' => present_document($d + ['my_level' => $doc['my_level']]), 'path' => folder_path_text($pdo, (int) $doc['folder_id']), 'picker' => ['source' => 'folder', 'params' => []]]);
    }
    render_screen('Copy ' . $doc['name'], view('files/document-move.php', ['d' => $d, 'pathText' => folder_path_text($pdo, (int) $doc['folder_id']), 'errors' => [], 'ids' => [(int) $doc['id']], 'copy' => true]),
        ['activeNav' => 'files', 'screen' => 'document-move', 'entity' => 'document', 'recordId' => (int) $doc['id']]);
    exit;
}
dc_handler_begin();
require_right('documents.upload');
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'view');
$id = (int) $doc['id'];
$target = folder_at_level($pdo, request_integer('target_folder') ?? request_integer('target'), 'upload', 'target folder');
$name = req_val('name');
$newId = dc_guard($pdo, static function () use ($pdo, $doc, $id, $target, $name, $me): int {
    $pdo->beginTransaction();
    try {
        $newId = document_copy($pdo, $id, (int) $target['id'], $me, $name);
        $copy = find_document($pdo, $newId);
        files_log($pdo, 'document.copy', 'document', $newId, ['document_id' => $newId, 'folder_id' => (int) $target['id'], 'library_id' => (int) $target['library_id'], 'after' => ['name' => $copy['name'] ?? $doc['name'], 'from_document_id' => $id, 'byte_size' => (int) $doc['byte_size']]]);
        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
});
dc_done('Copied ' . $doc['name'] . ' to ' . $target['name'], $newId, dc_land('/documents/' . $newId, 'copied'), 'documentChanged');
