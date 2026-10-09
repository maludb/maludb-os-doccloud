<?php
declare(strict_types=1);
/** Action `graph_rebuild_all` (library or folder, confirm=yes): every document under it forgotten and re-sent under its policy; graph.manage; pauses for an agent (other). Logged graph.rebuild. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_right('graph.manage');
$pdo = db();
if (!dc_yes('confirm')) {
    dc_refuse_fields(['confirm' => 'Say confirm=yes: a rebuild re-sends every document and a full library may cost the month\'s budget.']);
}
if (request_integer('folder') !== null) {
    $f = folder_at_level($pdo, request_integer('folder'), 'manage');
    $kind = 'folder'; $id = (int) $f['id']; $name = $f['name']; $libraryId = (int) $f['library_id']; $estimateFolder = $id;
} elseif (request_integer('library') !== null) {
    $l = library_from_request($pdo, 'library');
    $kind = 'library'; $id = (int) $l['id']; $name = $l['name']; $libraryId = $id; $estimateFolder = (int) $l['root_folder_id'];
} else {
    dc_refuse_fields(['library' => 'Say which library or folder.']);
}
$estimate = graph_estimate($pdo, $estimateFolder);
$n = dc_guard($pdo, static function () use ($pdo, $kind, $id, $name, $libraryId, $estimate): int {
    $pdo->beginTransaction();
    $n = graph_rebuild_all($pdo, $kind, $id);
    log_activity($pdo, 'graph.rebuild', $kind, $id, ['library_id' => $libraryId, 'folder_id' => $kind === 'folder' ? $id : null, 'after' => ['name' => $name, 'queued' => $n, 'chars' => $estimate['chars'], 'over_budget' => $estimate['over_budget']]]);
    $pdo->commit();
    return $n;
});
dc_done('Re-sending ' . $n . ' document' . ($n === 1 ? '' : 's') . ' of ' . $name . ' to the graph', $id, dc_land('/graph/admin', 'rebuilt', 'graph-queue'), 'graphChanged', ['queued' => $n]);
