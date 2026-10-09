<?php
declare(strict_types=1);
/**
 * GET: the move screen (`folder-move`, /folders/{id}/move) — the record picker over folders the caller may add to (source `folder`, param
 * `library` optional), the current path shown. POST: action `folder_move` (folder, target_folder; log folder.move with before/after parent_id;
 * edit on the folder, add on the target; the database refuses under-itself, across libraries, a root, too deep → 422 on the target field).
 * A multi-select move posts `folder[]` (several) — each moved under the same target, logged one by one.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $pdo = db();
    $folder = folder_at_level($pdo, request_integer('id') ?? request_integer('folder'), 'edit');
    if ($folder['parent_id'] === null) {
        refuse(422, 'A library\'s root never moves.');
    }
    log_screen_view($pdo, 'folder-move');
    if (wants_json()) {
        respond_screen(['folder' => present_folder(find_folder($pdo, (int) $folder['id']) + ['my_level' => $folder['my_level']]), 'path' => folder_path_text($pdo, (int) $folder['id']), 'picker' => ['source' => 'folder', 'params' => ['library' => (int) $folder['library_id']]]]);
    }
    render_screen('Move ' . $folder['name'], view('files/move.php', ['folder' => $folder, 'pathText' => folder_path_text($pdo, (int) $folder['id']), 'errors' => [], 'ids' => [(int) $folder['id']]]),
        ['activeNav' => 'files', 'screen' => 'folder-move', 'entity' => 'folder', 'recordId' => (int) $folder['id']]);
    exit;                                                              // the GET screen shares this file with its action
}
dc_handler_begin();
require_right('documents.edit');
$pdo = db();
$ids = request_list('folder') ?? (request_integer('id') !== null ? [(string) request_integer('id')] : []);
$ids = array_values(array_unique(array_map('intval', array_filter($ids, 'ctype_digit'))));
if ($ids === []) {
    refuse(422, 'Say which folder.');
}
$target = folder_at_level($pdo, request_integer('target_folder') ?? request_integer('target'), 'upload', 'target folder');
$moved = [];
foreach ($ids as $fid) {
    $folder = folder_at_level($pdo, $fid, 'edit');
    if ($folder['parent_id'] === null) {
        dc_refuse_fields(['folder' => 'A library\'s root never moves.']);
    }
    if ((int) $folder['parent_id'] === (int) $target['id']) {
        continue;
    }
    dc_guard($pdo, static function () use ($pdo, $folder, $fid, $target): void {
        $pdo->beginTransaction();
        try {
            folder_move($pdo, $fid, (int) $target['id']);
            files_log($pdo, 'folder.move', 'folder', $fid, ['folder_id' => $fid, 'library_id' => (int) $folder['library_id'], 'before' => ['parent_id' => (int) $folder['parent_id']], 'after' => ['parent_id' => (int) $target['id'], 'name' => $folder['name']]]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505') {
                throw new DomainException('A folder named ' . $folder['name'] . ' is already in ' . $target['name'] . '.');
            }
            throw $e;
        }
    });
    $moved[] = $fid;
}
$did = count($ids) === 1 ? 'Moved the folder to ' . $target['name'] : 'Moved ' . count($moved) . ' folders to ' . $target['name'];
dc_done($did, count($ids) === 1 ? $ids[0] : (int) $target['id'], dc_land(return_path('/f/' . (count($ids) === 1 ? $ids[0] : (int) $target['id'])), count($ids) === 1 ? 'moved' : 'moved_many'), 'folderChanged');
