<?php
declare(strict_types=1);
/**
 * POST /api/v1/search {q, filters: {library, folder, owner, tag, kind, before, after, mine, shared_with_me, min_bytes, max_bytes}, page} — the keyword search as the key's person (slice 7): dc_search with the
 * batch wall inside. {rows[], total, took_ms, page, pages, capped}. Needs search.use. Logged search.keyword (the query cut at 120) with source api.
 */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/handler.php';
api_route(['POST'], false);
$raw = json_decode((string) file_get_contents('php://input'), true);
$in = is_array($raw) ? $raw : [];
if (!has_right('search.use')) {
    api_error('forbidden', 'You may not search.', 403);
}
$pdo = db();
$typed = trim((string) ($in['q'] ?? ''));
if (mb_strlen($typed) > 200) {
    api_error('invalid', 'A query is up to 200 characters.', 422, ['field' => 'q']);
}
$explicit = array_intersect_key(is_array($in['filters'] ?? null) ? array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $in['filters']) : [], array_flip(['library', 'folder', 'owner', 'tag', 'kind', 'before', 'after', 'mine', 'shared_with_me', 'min_bytes', 'max_bytes']));
if ($typed === '' && $explicit === []) {
    api_error('invalid', 'Send q or a filter.', 422, ['field' => 'q']);
}
$result = search($pdo, $typed, max(1, (int) ($in['page'] ?? 1)), $explicit);
search_log_write($pdo, 'keyword', $typed !== '' ? $typed : json_encode($result['filters']), $result['filters'], $result['total'], $result['took_ms'], 'api');
log_activity($pdo, 'search.keyword', 'search', null, ['after' => ['query' => mb_substr($typed, 0, 120), 'filters' => $result['filters'], 'results' => $result['total'], 'took_ms' => $result['took_ms'], 'page' => $result['page'], 'key_label' => api_key_label()]]);
api_json(present_search($result));
