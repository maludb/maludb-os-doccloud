<?php
declare(strict_types=1);
/** Action `share_level_set` (access, level — none removes the reach but keeps the row): manage on the thing. Log access.level_set. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('documents.share');
$pdo = db();
$me = (int) current_member_id();
$a = access_from_request($pdo);
$errors = [];
$allowed = $a['kind'] === 'folder' ? array_merge(ACCESS_LEVELS_FOLDER, ['none']) : array_merge(ACCESS_LEVELS_DOCUMENT, ['none']);
if ($a['row']['principal_kind'] === 'application') { $allowed = ['view', 'none']; }
if ($a['row']['principal_kind'] === 'guest') { $allowed = ['view', 'edit', 'none']; }
$level = level_from_request($a['kind'], $errors, $allowed);
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$before = (string) $a['row']['level'];
dc_guard($pdo, static function () use ($pdo, $a, $level, $me): void {
    share_level_set($pdo, $a['kind'], (int) $a['row']['id'], $level, $me);
});
files_log($pdo, 'access.level_set', 'access', (int) $a['row']['id'], ['folder_id' => $a['folder_id'], 'document_id' => $a['document_id'], 'library_id' => $a['library_id'],
    'before' => ['level' => $before], 'after' => ['level' => $level, 'principal_kind' => $a['row']['principal_kind'], 'principal_id' => $a['row']['principal_id'], 'on' => $a['kind'], 'on_id' => $a['thing_id']]]);
dc_done('Set ' . dc_principal_name_of($pdo, $a['row']) . ' to ' . $level . ' on ' . $a['name'], (int) $a['row']['id'], dc_land(return_path(share_url($a['kind'], $a['thing_id'])), 'level'), 'accessChanged');

function dc_principal_name_of(PDO $pdo, array $row): string
{
    return (string) one_value($pdo, 'SELECT dc_principal_name(:k, :i, :r)', ['k' => $row['principal_kind'], 'i' => $row['principal_id'], 'r' => $row['principal_ref']]);
}
