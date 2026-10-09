<?php
declare(strict_types=1);
/** A group's form (screens `group-add` /groups/new?department= and `group-edit` /groups/{id}/edit): name, key, description, kind (custom · from a department), member level. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$id = request_integer('id');
$g = null;
if ($id !== null) {
    $g = group_from_request($pdo, 'id');
    require_group_owner($g);
} else {
    require_right('groups.manage');
}
$screen = $id === null ? 'group-add' : 'group-edit';
log_screen_view($pdo, $screen);
$values = $g !== null ? ['name' => $g['name'], 'key' => $g['key'], 'description' => $g['description'] ?? '', 'kind' => $g['kind'], 'department' => $g['department_id'], 'member_level' => $g['member_level']]
                      : ['name' => '', 'key' => '', 'description' => '', 'kind' => request_integer('department') !== null ? 'department' : 'custom', 'department' => request_integer('department'), 'member_level' => 'edit'];
$departments = $g === null ? departments_without_group($pdo) : [];
if (wants_json()) {
    respond_screen(['group' => $g === null ? null : present_group($g), 'values' => $values, 'departments_without_group' => array_map(static fn (array $d): array => ['department_id' => (int) $d['department_id'], 'name' => $d['name']], $departments), 'member_levels' => MEMBER_LEVELS]);
}
render_screen($id === null ? 'New group' : 'Edit ' . $g['name'], view('groups/form.php', ['g' => $g, 'values' => $values, 'errors' => [], 'departments' => $departments]), ['activeNav' => 'groups', 'screen' => $screen, 'entity' => 'group', 'recordId' => $id ?? '']);
