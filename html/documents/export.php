<?php
declare(strict_types=1);
/** Action `export_documents` (documents[] — a comma list or document[]): a zip of these the worker builds (what the maker may see); export.own and view on each. Log document.export; lands on Exports. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('export.own');
$pdo = db();
$me = (int) current_member_id();
$ids = request_list('documents') ?? request_list('document') ?? [];
$ids = array_values(array_unique(array_map('intval', array_filter($ids, 'ctype_digit'))));
if ($ids === []) {
    refuse(422, 'Say which documents.');
}
foreach ($ids as $did) {
    document_at_level($pdo, $did, 'view');
}
$exportId = dc_guard($pdo, static function () use ($pdo, $ids, $me): int {
    $id = export_make($pdo, 'documents', null, $ids, $me);
    files_log($pdo, 'document.export', 'export', $id, ['after' => ['export_id' => $id, 'documents' => count($ids)]]);
    return $id;
});
dc_done('The zip of ' . count($ids) . ' document' . (count($ids) === 1 ? '' : 's') . ' is being made', $exportId, dc_land('/exports', 'queued'), 'exportsChanged', ['export_id' => $exportId]);
