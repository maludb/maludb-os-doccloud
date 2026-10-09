<?php
declare(strict_types=1);
/**
 * Actions `library_create` (no `library`: name, description, everyone_level, graph_policy — a SHARED library; log library.create) and
 * `library_update` (library + name, description, archived yes/no — the archive and the restore are the `archived` field, the manifest's one key per
 * form; log library.update, library.archive, library.restore). The everyone level of an existing library is library_everyone_set (it pauses for an
 * agent when raised). A field left out stays as it was. libraries.shared, on a shared library.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('libraries.shared');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('library') ?? request_integer('id');
$errors = [];
$name = req_val('name');
if ($name !== null && ($name === '' || mb_strlen($name) > 120)) {
    $errors['name'] = $name === '' ? 'Give the library a name.' : 'A name is up to 120 characters.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 2000)) : null;
if ($id === null) {
    if ($name === null) {
        $errors['name'] = 'Give the library a name.';
    }
    $everyone = req_has('everyone_level') && (string) req_val('everyone_level') !== '' ? (string) req_val('everyone_level') : 'view';
    if (!isset(EVERYONE_LEVELS[$everyone])) {
        $errors['everyone_level'] = 'The everyone level is none, view, upload or edit.';
    }
    $policy = req_has('graph_policy') && (string) req_val('graph_policy') !== '' ? (string) req_val('graph_policy') : 'index';
    if (!in_array($policy, ['none', 'index', 'full'], true)) {
        $errors['graph_policy'] = 'The graph policy is none, index or full.';
    }
    if ($errors !== []) {
        dc_refuse_fields($errors);
    }
    $newId = dc_guard($pdo, static function () use ($pdo, $name, $description, $everyone, $policy, $me): int {
        $pdo->beginTransaction();
        $newId = library_create($pdo, ['name' => $name, 'description' => $description, 'everyone_level' => $everyone, 'graph_policy' => $policy], $me);
        files_log($pdo, 'library.create', 'library', $newId, ['library_id' => $newId, 'after' => ['name' => $name, 'kind' => 'shared', 'everyone_level' => $everyone, 'graph_policy' => $policy]]);
        $pdo->commit();
        return $newId;
    });
    dc_done('Made the shared library ' . $name, $newId, dc_land('/libraries/' . $newId, 'created'), 'libraryChanged');
}
$lib = library_from_request($pdo, 'library');
if ($lib['kind'] !== 'shared' && !is_dc_admin()) {
    refuse(403, 'Only a shared library is changed here: a personal library is its owner\'s, a group\'s follows its group.');
}
if ($lib['kind'] === 'group' && $name !== null && $name !== $lib['name']) {
    $errors['name'] = 'A group library is named after its group: rename the group.';
}
if (req_has('everyone_level')) {
    $errors['everyone_level'] = 'Who gets what by default is its own action (library_everyone_set).';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$archivedWanted = req_has('archived') ? dc_yes('archived') : null;
$did = [];
dc_guard($pdo, static function () use ($pdo, $lib, $name, $description, $archivedWanted, &$did): void {
    $id = (int) $lib['id'];
    $pdo->beginTransaction();
    $newName = $name ?? $lib['name'];
    $newDesc = req_has('description') ? $description : $lib['description'];
    if ($newName !== $lib['name'] || $newDesc !== $lib['description']) {
        library_update($pdo, $id, ['name' => $newName, 'description' => $newDesc]);
        files_log($pdo, 'library.update', 'library', $id, ['library_id' => $id, 'before' => ['name' => $lib['name']], 'after' => ['name' => $newName]]);
        $did[] = 'saved';
    }
    if ($archivedWanted === true && $lib['archived_at'] === null) {
        library_archive($pdo, $id);
        files_log($pdo, 'library.archive', 'library', $id, ['library_id' => $id, 'after' => ['name' => $newName]]);
        $did[] = 'archived';
    } elseif ($archivedWanted === false && $lib['archived_at'] !== null) {
        library_restore($pdo, $id);
        files_log($pdo, 'library.restore', 'library', $id, ['library_id' => $id, 'after' => ['name' => $newName]]);
        $did[] = 'restored';
    }
    $pdo->commit();
});
$id = (int) $lib['id'];
$notice = in_array('archived', $did, true) ? 'archived' : (in_array('restored', $did, true) ? 'restored' : 'updated');
dc_done($did === [] ? 'Nothing to change' : ucfirst(implode(' and ', $did)) . ' the library ' . ($name ?? $lib['name']), $id, dc_land(return_path('/libraries/' . $id), $notice), 'libraryChanged');
