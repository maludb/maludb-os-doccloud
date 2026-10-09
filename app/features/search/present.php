<?php
declare(strict_types=1);

/** The one JSON branch of the search screens (whitelists; the excerpt as the page shows it, <mark> and all, everything else escaped). */
function present_search_row(array $r): array
{
    return ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'folder_id' => (int) $r['folder_id'], 'folder_path' => $r['folder_path'] ?? null, 'library_id' => (int) $r['library_id'],
            'owner_member_id' => (int) $r['owner_member_id'], 'mime_kind' => $r['mime_kind'], 'byte_size' => (int) $r['byte_size'], 'tags' => $r['tags'], 'excerpt' => search_excerpt_html((string) ($r['excerpt'] ?? '')),
            'occurred_at' => json_ts($r['occurred_at']), 'rank' => (float) $r['rank'], 'url' => '/documents/' . (int) $r['document_id']];
}

/** ts_headline's <mark> kept, everything else escaped. */
function search_excerpt_html(string $excerpt): string
{
    return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], e($excerpt));
}

function present_search(array $s): array
{
    return ['q' => $s['q'], 'typed' => $s['typed'], 'filters' => (object) $s['filters'], 'total' => $s['total'], 'capped' => $s['capped'], 'page' => $s['page'], 'pages' => $s['pages'], 'took_ms' => $s['took_ms'],
            'rows' => array_map('present_search_row', $s['rows']), 'names' => array_map(static fn (array $n): array => ['document_id' => (int) $n['document_id'], 'name' => $n['name'], 'folder_id' => (int) $n['folder_id'], 'similarity' => (float) $n['similarity'], 'url' => '/documents/' . (int) $n['document_id']], $s['names'])];
}

function present_saved_search(array $s): array
{
    return ['saved_search_id' => (int) $s['saved_search_id'], 'owner_id' => (int) $s['owner_id'], 'owner_name' => $s['owner_name'] ?? null, 'name' => $s['name'], 'query' => $s['query'], 'filters' => (object) $s['filters'],
            'kind' => $s['kind'], 'is_shared' => (bool) $s['is_shared'], 'mine' => !empty($s['mine']), 'created_at' => json_ts($s['created_at']), 'updated_at' => json_ts($s['updated_at']),
            'url' => ($s['kind'] === 'graph' ? '/graph/?' : '/search?') . http_build_query(['q' => $s['query']] + array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $s['filters']))];
}
