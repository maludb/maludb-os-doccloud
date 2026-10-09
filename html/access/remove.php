<?php
declare(strict_types=1);
/** Action `share_remove` (access): the row goes; when a folder's last row goes, inheritance resumes (the trigger recomputes the roots). Log access.unshare. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('documents.share');
$pdo = db();
$a = access_from_request($pdo);
$name = (string) one_value($pdo, 'SELECT dc_principal_name(:k, :i, :r)', ['k' => $a['row']['principal_kind'], 'i' => $a['row']['principal_id'], 'r' => $a['row']['principal_ref']]);
dc_guard($pdo, static function () use ($pdo, $a): void {
    share_remove($pdo, $a['kind'], (int) $a['row']['id']);
});
files_log($pdo, 'access.unshare', 'access', (int) $a['row']['id'], ['folder_id' => $a['folder_id'], 'document_id' => $a['document_id'], 'library_id' => $a['library_id'],
    'before' => ['level' => $a['row']['level'], 'principal_kind' => $a['row']['principal_kind'], 'principal_id' => $a['row']['principal_id'], 'principal_ref' => $a['row']['principal_ref'], 'on' => $a['kind'], 'on_id' => $a['thing_id']]]);
dc_done('Removed ' . $name . ' from ' . $a['name'], (int) $a['row']['id'], dc_land(return_path(share_url($a['kind'], $a['thing_id'])), 'removed'), 'accessChanged');
