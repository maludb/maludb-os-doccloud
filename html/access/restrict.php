<?php
declare(strict_types=1);
/**
 * Action `folder_restrict` (folder, keep[] as kind:id:level or kind:ref:level): stop inheriting — the folder keeps only the rows named (the inherited
 * principals are dropped); the caller is kept at manage when the set is empty (dc_folder_restrict). Log access.restrict {kept: n}.
 */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('documents.share');
$pdo = db();
$me = (int) current_member_id();
$folder = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), 'manage');
$id = (int) $folder['id'];
if ($folder['parent_id'] === null) {
    refuse(422, 'A library\'s root is not restricted: change the library\'s level instead.');
}
$keep = [];
$errors = [];
foreach (request_list('keep') ?? [] as $item) {
    $parts = explode(':', $item);
    if (count($parts) !== 3) {
        $errors['keep'] = 'A kept row is kind:id:level.';
        break;
    }
    [$k, $who, $lvl] = $parts;
    if (!isset(PRINCIPAL_KINDS[$k]) || !in_array($lvl, ACCESS_LEVELS_FOLDER, true)) {
        $errors['keep'] = 'A kept row names a principal kind and a level (view, upload, edit, manage).';
        break;
    }
    if ($k === 'application') {
        $keep[] = ['principal_kind' => 'application', 'principal_id' => null, 'principal_ref' => $who, 'level' => $lvl];
    } elseif ($k === 'everyone') {
        $keep[] = ['principal_kind' => 'everyone', 'principal_id' => null, 'principal_ref' => null, 'level' => $lvl];
    } elseif (ctype_digit($who)) {
        $keep[] = ['principal_kind' => $k, 'principal_id' => (int) $who, 'principal_ref' => null, 'level' => $lvl];
    } else {
        $errors['keep'] = 'A kept row names a principal by id.';
        break;
    }
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$n = dc_guard($pdo, static fn (): int => folder_restrict($pdo, $id, $keep, $me));
files_log($pdo, 'access.restrict', 'folder', $id, ['folder_id' => $id, 'library_id' => (int) $folder['library_id'], 'after' => ['kept' => $n]]);
dc_done('Restricted ' . $folder['name'] . ': ' . $n . ' row' . ($n === 1 ? '' : 's') . ' kept', $id, dc_land(return_path(share_url('folder', $id)), 'restricted'), 'accessChanged');
