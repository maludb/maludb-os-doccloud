<?php
declare(strict_types=1);
/** Action `document_lock_break` (document): a manager breaks another's lock (dc_document_unlock force); the holder is told (lock_broken). Log document.lock_break. Confirmed. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'manage');
$id = (int) $doc['id'];
if ($doc['locked_by'] === null) {
    refuse(422, 'It is not locked.');
}
$holder = (int) $doc['locked_by'];
dc_guard($pdo, static function () use ($pdo, $doc, $id, $me, $holder): void {
    $pdo->beginTransaction();
    try {
        document_unlock($pdo, $id, $me, true);
        files_log($pdo, 'document.lock_break', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'holder_member_id' => $holder]]);
        if ($holder !== $me) {
            $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, :a, NULL)')->execute(['m' => $holder, 'k' => 'lock_broken', 't' => 'Your lock on ' . $doc['name'] . ' was broken', 'b' => (current_member()['display_name'] ?? 'A manager') . ' broke it.',
                'rt' => 'document', 'rid' => $id, 'd' => $id, 'f' => (int) $doc['folder_id'], 'a' => $me]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
});
dc_done('Broke the lock on ' . $doc['name'], $id, dc_land(return_path('/documents/' . $id), 'lock_broken'), 'documentChanged');
