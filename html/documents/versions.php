<?php
declare(strict_types=1);
/** The versions screen (`document-versions`, /documents/{id}/versions): every version — who, when, size, source, note, the extraction state, download, restore, purge (admin); the window sentence. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$doc = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
$id = (int) $doc['id'];
$d = find_document($pdo, $id);
$versions = document_versions($pdo, $id);
$me = (int) current_member_id();
$lockedByOther = lock_held_by_another($pdo, $id, $me) !== null;
$may = ['restore' => dc_level_at_least($doc['my_level'], 'edit') && has_right('versions.restore') && !$lockedByOther, 'purge' => has_right('retention.manage') || has_right('trash.purge')];
$window = 'Every version is kept ' . (int) one_value($pdo, "SELECT dc_setting_int('versions_keep_days')") . ' days, then the last ' . (int) one_value($pdo, "SELECT dc_setting_int('versions_keep_count')") . '; the current one always.';
log_screen_view($pdo, 'document-versions');
if (wants_json()) {
    respond_screen(['document' => present_document($d + ['my_level' => $doc['my_level']]), 'versions' => array_map('present_version', $versions), 'may' => $may, 'window' => $window]);
}
render_screen('Versions of ' . $doc['name'], view('files/versions.php', ['d' => $d, 'versions' => $versions, 'may' => $may, 'window' => $window, 'tz' => member_timezone(), 'crumbs' => folder_crumbs($pdo, (int) $doc['folder_id']),
    'notice' => dc_notice($_GET['notice'] ?? null, ['restored' => ['success', 'Restored as a new version.'], 'purged' => ['success', 'The version was purged; its bytes are released.']])]),
    ['activeNav' => 'files', 'screen' => 'document-versions', 'entity' => 'document', 'recordId' => $id]);
