<?php
declare(strict_types=1);
/**
 * Actions `group_create` (no `group`: name, key?, description, kind custom|department, department, member_level; log group.create — groups.manage) and
 * `group_update` (group + name, description, member_level — the level is a field of the update; log group.update — groups.manage or an owner).
 * A field left out stays as it was. The database makes the group's library, seeds a department group's members and the maker as a custom group's
 * owner, and refuses a second live group for a department (23505 → the department field).
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('group') ?? request_integer('id');
$errors = [];
$name = req_val('name');
if ($name !== null && ($name === '' || mb_strlen($name) > 120)) {
    $errors['name'] = $name === '' ? 'Give the group a name.' : 'A name is up to 120 characters.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 2000)) : null;
$level = req_val('member_level');
if ($level !== null && $level !== '' && !isset(MEMBER_LEVELS[$level])) {
    $errors['member_level'] = 'The member level is view or edit.';
}
if ($id === null) {
    require_right('groups.manage');
    if ($name === null) {
        $errors['name'] = 'Give the group a name.';
    }
    $kind = (string) (req_val('kind') ?? 'custom');
    if ($kind === '') { $kind = 'custom'; }
    if (!in_array($kind, ['custom', 'department'], true)) {
        $errors['kind'] = 'A group is custom, or follows a department.';
    }
    $dept = null;
    if ($kind === 'department') {
        $dept = dc_ref($pdo, 'department', null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'department', $errors, false);
        if ($dept === null && !isset($errors['department'])) {
            $errors['department'] = 'Say which department the group follows.';
        }
    }
    $key = (string) (req_val('key') ?? '');
    if ($key !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $key)) {
        $errors['key'] = 'A key is lower-case letters, digits and dashes.';
    }
    if ($errors !== []) {
        dc_refuse_fields($errors);
    }
    $key = $key !== '' ? $key : group_key_from($pdo, (string) $name);
    $f = ['key' => $key, 'name' => $name, 'description' => $description, 'kind' => $kind, 'department_id' => $dept, 'member_level' => $level !== null && $level !== '' ? $level : 'edit'];
    $newId = dc_guard($pdo, static function () use ($pdo, $f, $me): int {
        $pdo->beginTransaction();
        try {
            $newId = group_create($pdo, $f, $me);
            $lib = (int) one_value($pdo, 'SELECT id FROM libraries WHERE group_id = :g', ['g' => $newId]);
            files_log($pdo, 'group.create', 'group', $newId, ['library_id' => $lib, 'after' => ['name' => $f['name'], 'key' => $f['key'], 'kind' => $f['kind'], 'department_id' => $f['department_id'], 'member_level' => $f['member_level']]]);
            $pdo->commit();
            return $newId;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505') {
                throw new DomainException(str_contains($e->getMessage(), 'groups_department_live') ? 'That department already has a live group.' : 'A group with that key already exists.');
            }
            throw $e;
        }
    });
    dc_done('Made the group ' . $name, $newId, dc_land('/groups/' . $newId, 'created'), 'groupChanged');
}
$g = group_from_request($pdo, 'group');
require_group_owner($g);
if ($g['archived_at'] !== null) {
    refuse(422, 'The group is archived: restore it before changing it.');
}
if (req_has('kind') && (string) req_val('kind') !== '' && (string) req_val('kind') !== $g['kind']) {
    $errors['kind'] = 'A group\'s kind is set when it is made.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$f = ['name' => $name ?? $g['name'], 'description' => req_has('description') ? $description : $g['description'], 'member_level' => $level !== null && $level !== '' ? $level : $g['member_level']];
$before = ['name' => $g['name'], 'description' => $g['description'], 'member_level' => $g['member_level']];
$diff = dc_diff($before, $f);
if ($diff['after'] !== []) {
    dc_guard($pdo, static function () use ($pdo, $g, $f, $diff): void {
        $pdo->beginTransaction();
        try {
            group_update($pdo, (int) $g['group_id'], $f);
            $after = $diff['after'];
            if (isset($after['description'])) { $after['description'] = '(changed)'; }
            $b = $diff['before'];
            if (isset($b['description'])) { $b['description'] = '(changed)'; }
            files_log($pdo, 'group.update', 'group', (int) $g['group_id'], ['library_id' => $g['library_id'] !== null ? (int) $g['library_id'] : null, 'before' => $b, 'after' => $after]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505') {
                throw new DomainException('A group with that name already exists.');
            }
            throw $e;
        }
    });
}
dc_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the group ' . $f['name'], (int) $g['group_id'], dc_land(return_path('/groups/' . (int) $g['group_id']), 'updated'), 'groupChanged');
