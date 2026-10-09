<?php
declare(strict_types=1);
/**
 * The upload screen (`upload`, /folders/{id}/upload and /f/{id}/upload; ?document= for a new version of one document): the drop zone, the per-file
 * progress list (assets/js/dc-upload.js: parts, resume, the conflict choice inline), "Done" back to the folder; with JavaScript off a plain
 * multiple-file form posting document_upload one file at a time.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$folder = folder_at_level($pdo, request_integer('id') ?? request_integer('folder'), 'upload');
require_right('documents.upload');
$id = (int) $folder['id'];
$documentId = request_integer('document');
$doc = $documentId === null ? null : document_at_level($pdo, $documentId, 'edit');
$settings = ['part_bytes' => (int) one_value($pdo, "SELECT dc_setting_int('upload_part_bytes')"), 'max_document_bytes' => (int) one_value($pdo, "SELECT dc_setting_int('max_document_bytes')")];
log_screen_view($pdo, 'upload');
if (wants_json()) {
    respond_screen(['folder' => present_folder(find_folder($pdo, $id) + ['my_level' => $folder['my_level']]), 'document' => $doc === null ? null : present_document(find_document($pdo, (int) $doc['id']) + ['my_level' => $doc['my_level']]),
        'settings' => $settings, 'sessions' => array_map('present_upload_session', my_upload_sessions($pdo, $id)), 'routes' => ['start' => '/upload/start', 'part' => '/upload/part', 'finish' => '/upload/finish', 'one' => '/documents/upload.php']]);
}
render_screen('Upload to ' . $folder['name'], view('files/upload.php', ['folder' => $folder, 'doc' => $doc, 'settings' => $settings, 'crumbs' => folder_crumbs($pdo, $id), 'sessions' => my_upload_sessions($pdo, $id), 'tz' => member_timezone(), 'standalone' => true]),
    ['activeNav' => 'files', 'screen' => 'upload', 'entity' => 'folder', 'recordId' => $id]);
