<?php
declare(strict_types=1);
/**
 * GET: the move screen (`document-move`, /documents/{id}/move): the record picker over folders the caller may add to. POST: action `document_move`
 * (document, target_folder; log document.move with before/after folder_id; edit on the document, add on the target; a lock by another refuses;
 * the unique index refuses a taken name in the target). A multi-select posts `document[]`.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $pdo = db();
    $doc = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'edit');
    log_screen_view($pdo, 'document-move');
    $d = find_document($pdo, (int) $doc['id']);
    if (wants_json()) {
        respond_screen(['document' => present_document($d + ['my_level' => $doc['my_level']]), 'path' => folder_path_text($pdo, (int) $doc['folder_id']), 'picker' => ['source' => 'folder', 'params' => []]]);
    }
    render_screen('Move ' . $doc['name'], view('files/document-move.php', ['d' => $d, 'pathText' => folder_path_text($pdo, (int) $doc['folder_id']), 'errors' => [], 'ids' => [(int) $doc['id']], 'copy' => false]),
        ['activeNav' => 'files', 'screen' => 'document-move', 'entity' => 'document', 'recordId' => (int) $doc['id']]);
    exit;
}
dc_handler_begin();
require_right('documents.edit');
$pdo = db();
$me = (int) current_member_id();
$ids = request_list('document') ?? (request_integer('id') !== null ? [(string) request_integer('id')] : []);
$ids = array_values(array_unique(array_map('intval', array_filter($ids, 'ctype_digit'))));
if ($ids === []) {
    refuse(422, 'Say which document.');
}
$target = folder_at_level($pdo, request_integer('target_folder') ?? request_integer('target'), 'upload', 'target folder');
$moved = [];
foreach ($ids as $did) {
    $doc = document_at_level($pdo, $did, 'edit');
    if (($holder = lock_held_by_another($pdo, $did, $me)) !== null) {
        refuse(423, $doc['name'] . ' is locked by ' . $holder . '.');
    }
    if ((int) $doc['folder_id'] === (int) $target['id']) {
        continue;
    }
    dc_guard($pdo, static function () use ($pdo, $doc, $did, $target): void {
        $pdo->beginTransaction();
        try {
            document_move($pdo, $did, (int) $target['id']);
            files_log($pdo, 'document.move', 'document', $did, ['document_id' => $did, 'folder_id' => (int) $target['id'], 'library_id' => (int) $target['library_id'], 'before' => ['folder_id' => (int) $doc['folder_id']], 'after' => ['folder_id' => (int) $target['id'], 'name' => $doc['name']]]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505') {
                throw new DomainException('A document named ' . $doc['name'] . ' is already in ' . $target['name'] . '.');
            }
            throw $e;
        }
    });
    $moved[] = $did;
}
$did = count($ids) === 1 ? 'Moved the document to ' . $target['name'] : 'Moved ' . count($moved) . ' documents to ' . $target['name'];
dc_done($did, count($ids) === 1 ? $ids[0] : (int) $target['id'], dc_land(return_path(count($ids) === 1 ? '/documents/' . $ids[0] : '/f/' . (int) $target['id']), count($ids) === 1 ? 'moved' : 'moved_many'), 'documentChanged');
