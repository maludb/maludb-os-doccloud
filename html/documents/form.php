<?php
declare(strict_types=1);
/** The document's edit form (screen `document-edit`, /documents/{id}/edit): name, description; links to Move and Copy. Posts to save.php. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$doc = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'edit');
$id = (int) $doc['id'];
$d = find_document($pdo, $id);
$values = ['name' => $doc['name'], 'description' => (string) ($doc['description'] ?? '')];
log_screen_view($pdo, 'document-edit');
if (wants_json()) {
    respond_screen(['document' => present_document($d + ['my_level' => $doc['my_level']]), 'values' => $values]);
}
render_screen('Edit ' . $doc['name'], view('files/document-edit.php', ['d' => $d, 'values' => $values, 'errors' => [], 'crumbs' => folder_crumbs($pdo, (int) $doc['folder_id'])]),
    ['activeNav' => 'files', 'screen' => 'document-edit', 'entity' => 'document', 'recordId' => $id]);
