<?php
declare(strict_types=1);
/**
 * Actions `folder_create` (parent, name — no `folder`; log folder.create), `folder_rename` (folder + name; log folder.rename) and `folder_update`
 * (folder + colour and/or auto_tags; log folder.update — the colour is a field of the update, the manifest's one key per form). One form posts all
 * three: the handler reads which fields came. A field left out stays as it was. The database refuses a duplicate name among live siblings,
 * a slash in a name and a root's rename (dc_guard → 422 on the field).
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('folder') ?? request_integer('id');
$errors = [];
$name = req_val('name');
if ($name !== null) {
    if ($name === '' || mb_strlen($name) > 255 || preg_match('#[/\\\\]#', $name) || in_array($name, ['.', '..'], true)) {
        $errors['name'] = $name === '' ? 'Give the folder a name.' : 'A folder\'s name is up to 255 characters, with no slash.';
    }
}
$colour = null;
if (req_has('colour')) {
    $c = strtolower((string) req_val('colour'));
    if ($c !== '' && !isset(FOLDER_COLOURS[$c])) {
        $errors['colour'] = 'Pick one of the eight colours, or none.';
    }
    $colour = $c === '' ? null : $c;
}
$autoTags = null;
if (req_has('auto_tags')) {
    $autoTags = [];
    foreach (request_list('auto_tags') ?? [] as $t) {
        $slug = (string) one_value($pdo, 'SELECT dc_slugify(:t)', ['t' => $t]);
        if ($slug === '') {
            $errors['auto_tags'] = 'A tag is letters, digits and dashes.';
            break;
        }
        $autoTags[] = $slug;
    }
    $autoTags = array_values(array_unique($autoTags));
}
if ($id === null) {
    // folder_create
    require_right('documents.upload');
    $parent = folder_at_level($pdo, request_integer('parent'), 'upload', 'folder');
    if ($name === null) {
        $errors['name'] = 'Give the folder a name.';
    }
    if ($errors !== []) {
        dc_refuse_fields($errors);
    }
    $newId = dc_guard($pdo, static function () use ($pdo, $parent, $name, $colour, $autoTags, $me): int {
        $pdo->beginTransaction();
        try {
            $newId = folder_create($pdo, (int) $parent['id'], ['name' => $name, 'colour' => $colour, 'auto_tags' => $autoTags ?? []], $me);
            files_log($pdo, 'folder.create', 'folder', $newId, ['folder_id' => $newId, 'library_id' => (int) $parent['library_id'], 'after' => ['name' => $name, 'parent_id' => (int) $parent['id'], 'colour' => $colour]]);
            $pdo->commit();
            return $newId;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505') {
                dc_refuse_fields(['name' => 'A folder of that name is already here.']);      // the sentence on the field (JSON: fields.name)
            }
            throw $e;
        }
    });
    dc_done('Made the folder ' . $name, $newId, dc_land('/f/' . $newId, 'created'), 'folderChanged');
}
// folder_rename / folder_update
$folder = folder_at_level($pdo, $id, 'edit');
if ($folder['parent_id'] === null && $name !== null && $name !== $folder['name']) {
    $errors['name'] = 'A library\'s root is renamed with the library.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$renamed = $name !== null && $name !== $folder['name'];
$changed = [];
dc_guard($pdo, static function () use ($pdo, $folder, $id, $name, $renamed, $colour, $autoTags, &$changed): void {
    $pdo->beginTransaction();
    try {
        if ($renamed) {
            folder_rename($pdo, $id, $name);
            files_log($pdo, 'folder.rename', 'folder', $id, ['folder_id' => $id, 'library_id' => (int) $folder['library_id'], 'before' => ['name' => $folder['name']], 'after' => ['name' => $name]]);
            $changed[] = 'name';
        }
        if (req_has('colour') && $colour !== $folder['colour']) {
            folder_colour_set($pdo, $id, $colour);
            $changed[] = 'colour';
        }
        $before = pg_text_array((string) $folder['auto_tags']);
        if ($autoTags !== null && $autoTags !== $before) {
            folder_auto_tags_set($pdo, $id, $autoTags);
            $changed[] = 'auto_tags';
        }
        if (array_intersect($changed, ['colour', 'auto_tags']) !== []) {
            files_log($pdo, 'folder.update', 'folder', $id, ['folder_id' => $id, 'library_id' => (int) $folder['library_id'],
                'before' => array_filter(['colour' => in_array('colour', $changed, true) ? $folder['colour'] : null, 'auto_tags' => in_array('auto_tags', $changed, true) ? $before : null], static fn ($v) => $v !== null),
                'after' => array_filter(['colour' => in_array('colour', $changed, true) ? $colour : null, 'auto_tags' => in_array('auto_tags', $changed, true) ? $autoTags : null], static fn ($v) => $v !== null)]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((string) $e->getCode() === '23505') {
            dc_refuse_fields(['name' => 'A folder of that name is already here.']);
        }
        throw $e;
    }
});
$did = $renamed ? 'Renamed the folder to ' . $name : ($changed === [] ? 'Nothing to change' : 'Saved the folder');
dc_done($did, $id, dc_land(return_path('/f/' . $id), $renamed ? 'renamed' : 'updated'), 'folderChanged');
