<?php
declare(strict_types=1);
/**
 * Settings (screen `admin-settings`, GET /admin/settings): the application's settings as forms, each field with its bounds — Settings (settings_save: name, time zone, link defaults, the free-tag threshold,
 * the stale-share days, the graph's default policy and text), Limits (settings_save: the file ceilings, the part size, the session hours, the key and link rates; quotas.manage), and slice 6's windows (retention_set:
 * versions, trash, locks; retention.manage). A form shows only when the person holds its right; the page opens for any of the three.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
require_any_right('settings.manage|quotas.manage|retention.manage');
$pdo = db();
$windows = retention_settings($pdo);
$current = settings_current($pdo);
$may = ['settings' => has_right('settings.manage'), 'limits' => has_right('quotas.manage'), 'retention' => has_right('retention.manage')];
log_activity($pdo, 'screen.view', 'settings', null, ['screen' => 'admin-settings']);
if (wants_json()) {
    $fields = [];
    foreach (SETTINGS_FIELDS as $k => [$group, $type, $label, $min, $max, $unit]) {
        $fields[$k] = ['group' => $group, 'type' => $type, 'label' => $label, 'min' => $type === 'enum' ? null : $min, 'max' => $type === 'enum' ? null : $max, 'options' => $type === 'enum' ? $max : null, 'unit' => $unit,
                       'value' => ($may[$group] ?? false) ? ($current[$k] ?? null) : null];
    }
    respond_screen(['windows' => array_map(static fn ($v) => is_bool($v) ? $v : (int) $v, $windows), 'bounds' => RETENTION_BOUNDS, 'fields' => $fields, 'may' => $may]);
}
$notices = ['retention' => ['success', 'The windows were saved; the next pass follows them.'], 'settings' => ['success', 'The settings were saved.'], 'limits' => ['success', 'The limits were saved.']];
render_screen('Settings', view('admin/settings.php', ['windows' => $windows, 'current' => $current, 'may' => $may, 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin', 'screen' => 'admin-settings', 'entity' => 'settings']);
