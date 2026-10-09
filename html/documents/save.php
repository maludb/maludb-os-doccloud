<?php
declare(strict_types=1);
/**
 * Actions `document_rename` (document, name — log document.rename) and `document_describe` (document, description — log document.describe); one
 * form posts both, the handler reads which fields came. A lock by another refuses in words; the unique index refuses a taken name (422 on the field).
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('documents.edit');
$pdo = db();
$me = (int) current_member_id();
$doc = document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'edit');
$id = (int) $doc['id'];
if (($holder = lock_held_by_another($pdo, $id, $me)) !== null) {
    refuse(423, 'Locked by ' . $holder . '.');
}
$errors = [];
$name = req_val('name');
if ($name !== null) {
    if (preg_match('#[/\\\\]#', $name) || mb_strlen($name) > 255 || in_array($name, ['', '.', '..'], true)) {
        $errors['name'] = 'Give the document a name (no slash, up to 255 characters).';
    } else {
        $name = dc_safe_filename($name);
    }
}
$description = req_val('description');
if ($description !== null && mb_strlen($description) > 4000) {
    $errors['description'] = 'A description is up to 4,000 characters.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$renamed = $name !== null && $name !== $doc['name'];
$described = $description !== null && $description !== (string) ($doc['description'] ?? '');
dc_guard($pdo, static function () use ($pdo, $doc, $id, $name, $description, $renamed, $described): void {
    $pdo->beginTransaction();
    try {
        if ($renamed) {
            document_rename($pdo, $id, $name);
            files_log($pdo, 'document.rename', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'before' => ['name' => $doc['name']], 'after' => ['name' => $name]]);
        }
        if ($described) {
            document_describe($pdo, $id, $description);
            files_log($pdo, 'document.describe', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'description_length' => mb_strlen($description)]]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((string) $e->getCode() === '23505') {
            dc_refuse_fields(['name' => 'A document of that name is already here.']);
        }
        throw $e;
    }
});
$did = $renamed ? 'Renamed the document to ' . $name : ($described ? 'Saved the description' : 'Nothing to change');
dc_done($did, $id, dc_land(return_path('/documents/' . $id), $renamed ? 'renamed' : 'described'), 'documentChanged');
