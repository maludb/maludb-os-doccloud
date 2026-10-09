<?php
declare(strict_types=1);

/**
 * The reads of search (slice 4): dc_search() (the index-first search with the batch wall inside), dc_search_names() (a misspelt name), the
 * saved searches (mcp_saved_searches), the search report's data (dc_search_gaps). The page logs every search through search_log_write().
 */
const SEARCH_PAGE = 25;
const SEARCH_CANDIDATE_CAP = 2000;   // dc_search cuts its candidates here (db/016): a total at the cap is "about N"

/** The search: the typed words parsed, the explicit filters merged, dc_search, the names strip, the ms. */
function search(PDO $pdo, string $typed, int $page = 1, array $explicit = []): array
{
    $parsed = search_parse($pdo, $typed);
    $q = $parsed['q'];
    $filters = search_filters_from_query($pdo, $explicit, $parsed['filters']);
    $page = max(1, $page);
    $t0 = microtime(true);
    $st = $pdo->prepare('SELECT * FROM dc_search(:q, CAST(:f AS jsonb), :lim, :off)');
    $st->execute(['q' => $q, 'f' => json_encode((object) $filters), 'lim' => SEARCH_PAGE, 'off' => ($page - 1) * SEARCH_PAGE]);
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    foreach ($rows as &$r) {
        $r['tags'] = is_array($r['tags']) ? $r['tags'] : pg_text_array((string) ($r['tags'] ?? '{}'));
        $r['folder_path'] = (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => (int) $r['folder_id']]);
    }
    unset($r);
    $names = [];
    if ($q !== '' && $total < 5) {
        $ns = $pdo->prepare('SELECT * FROM dc_search_names(:q, 5)');
        $ns->execute(['q' => $q]);
        $seen = array_map(static fn (array $x): int => (int) $x['document_id'], $rows);
        foreach ($ns->fetchAll() as $n) {
            if (!in_array((int) $n['document_id'], $seen, true)) {
                $names[] = $n;
            }
        }
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    return ['q' => $q, 'typed' => $typed, 'filters' => $filters, 'rows' => $rows, 'total' => $total, 'capped' => $total >= SEARCH_CANDIDATE_CAP, 'page' => $page,
            'pages' => max(1, (int) ceil($total / SEARCH_PAGE)), 'took_ms' => $ms, 'names' => $names];
}

/** My saved searches and the shared ones (mcp_saved_searches). */
function my_saved_searches(PDO $pdo): array
{
    $st = $pdo->prepare('SELECT s.*, m.display_name AS owner_name FROM mcp_saved_searches s LEFT JOIN members m ON m.id = s.owner_id ORDER BY (s.owner_id = app_current_member_id()) DESC, s.updated_at DESC');
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['filters'] = is_array($r['filters']) ? $r['filters'] : (json_decode((string) $r['filters'], true) ?: []);
        $r['mine'] = (int) $r['owner_id'] === (int) current_member_id();
    }
    return $rows;
}

function find_saved_search(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT s.*, m.display_name AS owner_name FROM mcp_saved_searches s LEFT JOIN members m ON m.id = s.owner_id WHERE s.saved_search_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['filters'] = is_array($r['filters']) ? $r['filters'] : (json_decode((string) $r['filters'], true) ?: []);
    $r['mine'] = (int) $r['owner_id'] === (int) current_member_id();
    return $r;
}

/** What found nothing, clustered (dc_search_gaps — reports.search). */
function search_gaps(PDO $pdo, string $since = '30 days', int $limit = 50): array
{
    $st = $pdo->prepare('SELECT * FROM dc_search_gaps(now() - CAST(:s AS interval), :lim)');
    $st->execute(['s' => $since, 'lim' => $limit]);
    return $st->fetchAll();
}
