<?php
declare(strict_types=1);
/** Action `graph_test_extraction` (document): one model call on its first 2,000 characters through the real namespace, forgotten at once; graph.manage, people only. Logged graph.test. */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
dc_handler_begin();
require_human();
require_right('graph.manage');
$pdo = db();
$doc = document_from_request($pdo, 'view', 'document');
if (!graph_engine_up()) {
    emit_action_status(false, ['error' => graph_unavailable_sentence(), 'code' => 'graph_unavailable']);
    if (wants_json()) {
        json_response(['ok' => false, 'error' => ['code' => 'graph_unavailable', 'message' => graph_unavailable_sentence()]], 503);
    }
    refuse(503, graph_unavailable_sentence());
}
$id = (int) $doc['id'];
$r = graph_test_extraction($pdo, $id);
log_activity($pdo, 'graph.test', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['status' => $r['status'], 'edges' => $r['edges_count'], 'error_code' => $r['status'] === 'extracted' ? null : $r['status']]]);
$did = $r['status'] === 'extracted' ? 'The model extracted ' . $r['edges_count'] . ' statement' . ($r['edges_count'] === 1 ? '' : 's') . ' from ' . $doc['name'] . ' (nothing kept)'
     : ($r['status'] === 'no_edges' ? 'The model found nothing to extract in ' . $doc['name'] : 'The test failed: ' . ($r['error'] ?? $r['status']));
dc_done($did, $id, dc_land('/graph/admin?' . http_build_query(['test_edges' => $r['edges_count'], 'test_status' => $r['status'], 'test_error' => (string) ($r['error'] ?? '')]), 'tested', 'graph-model'), 'graphChanged',
    ['edges_count' => $r['edges_count'], 'status' => $r['status']]);
