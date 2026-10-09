<?php
declare(strict_types=1);
/**
 * POST /api/v1/graph/find {q, filters: {library, folder, tag, kind, owner, mine}, limit} — the document graph's find as the key's person (slice 7): dc_graph_find, every result judged by THE WALL.
 * {status, rows[], subjects[], total, candidates, took_ms}; 503 graph_unavailable when the engine is not there (graph routes only — keyword search needs no engine). Needs graph.use.
 */
require_once dirname(__DIR__, 4) . '/app/api/prelude.php';
require_once dirname(__DIR__, 4) . '/app/api/routes.php';
require_once dirname(__DIR__, 4) . '/app/features/graph/handler.php';
api_route(['POST'], false);
$raw = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($raw) ? $raw : [];
if (!has_right('graph.use')) {
    api_error('forbidden', 'You may not use the document graph.', 403);
}
$pdo = db();
$q = trim((string) ($in['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 400) {
    api_error('invalid', 'Send q: what the documents should be about (up to 400 characters).', 422, ['field' => 'q']);
}
$filters = array_intersect_key(is_array($in['filters'] ?? null) ? array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $in['filters']) : [], array_flip(['library', 'folder', 'tag', 'kind', 'owner', 'mine']));
$find = graph_find($pdo, $q, search_filters_from_query($pdo, $filters, []), max(1, min(50, (int) ($in['limit'] ?? GRAPH_FIND_PAGE))), 'api');
if (in_array($find['status'], ['graph_unavailable', 'graph_timeout'], true)) {
    api_error($find['status'], graph_status_sentence($find['status']), 503);
}
api_json(present_graph_find($find));
