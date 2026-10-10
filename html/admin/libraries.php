<?php
declare(strict_types=1);
/**
 * Every library (screen `admin-libraries`, GET /admin/libraries?kind=&archived=): personal, group and shared — kind, name, owner or group, used, quota, documents. The personal ones are marked: the admin's reach is
 * logged, and opening one (its link goes to /libraries/{id}) logs `library.admin_view` once per session through dc_note_admin_view(). library.admin_view. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_login();
require_right('library.admin_view');
$pdo = db();
$kind = request_string('kind');
$kind = in_array($kind, ['personal', 'group', 'shared'], true) ? $kind : '';
$archived = request_bool('archived');
$rows = admin_libraries($pdo, ['kind' => $kind ?: null, 'archived' => $archived]);
log_activity($pdo, 'screen.view', 'library', null, ['screen' => 'admin-libraries']);
if (wants_json()) {
    respond_screen(['libraries' => array_map(static fn (array $l): array => present_library($l) + ['owner_name' => $l['owner_name'], 'group_name' => $l['group_name'], 'pct' => $l['pct'], 'personal' => $l['kind'] === 'personal', 'i_belong' => (bool) $l['mine']], $rows),
        'kind' => $kind ?: null, 'archived' => $archived]);
}
render_screen('Libraries', view('admin/libraries.php', ['rows' => $rows, 'kind' => $kind, 'archived' => $archived]), ['activeNav' => 'admin', 'screen' => 'admin-libraries', 'entity' => 'library']);
