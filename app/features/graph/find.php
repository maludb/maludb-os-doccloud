<?php
declare(strict_types=1);

/**
 * dc_graph_find() — the one function behind /graph (find), the records tool graph_find, POST /api/v1/graph/find and the expert when the
 * asker asks for the graph (design §6.3; docs/build-specs/document-graph.md §3). Six steps: the gate · the subjects (the query's terms and
 * recall, plus the filters' own) · the candidates over-fetched ×3 · THE WALL (dc_graph_filter, SQL, with the filters) · the excerpt from
 * DocCloud's OWN text · the log (search_log kind graph, search.graph without the words). The engine's chunk text never leaves this file.
 */
const GRAPH_FIND_PAGE = 25;

function graph_find(PDO $pdo, string $q, array $filters = [], int $limit = GRAPH_FIND_PAGE, string $source = 'web'): array
{
    $t0 = microtime(true);
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    $limit = max(1, min($limit, 50));
    $empty = ['query' => $q, 'filters' => $filters, 'subjects' => [], 'rows' => [], 'total' => 0, 'candidates' => 0, 'took_ms' => 0];
    if (!app_is_active()) {
        return ['status' => 'refused'] + $empty;
    }
    if ($q === '' || mb_strlen($q) > 400) {
        return ['status' => 'no_query'] + $empty;
    }
    if (!graph_engine_up()) {
        graph_find_log($pdo, $q, $filters, 0, 0, $source, 'graph_unavailable');
        return ['status' => 'graph_unavailable'] + $empty;
    }
    // 2. the subjects: the filters' own first (the exact strings dc_graph_subjects() makes), then the query's
    $subjects = [];
    foreach (graph_filter_subjects($pdo, $filters) as $name) {
        $subjects[mb_strtolower($name)] = ['name' => $name, 'subject_id' => null, 'score' => 1.0, 'via' => 'filter'];
    }
    $r = dc_graph_resolve_subjects($q, 8);
    if (in_array($r['status'], ['graph_unavailable', 'graph_timeout', 'graph_auth', 'graph_forbidden'], true)) {
        graph_find_log($pdo, $q, $filters, 0, 0, $source, $r['status']);
        return ['status' => $r['status'] === 'graph_timeout' ? 'graph_timeout' : 'graph_unavailable'] + $empty;
    }
    foreach ($r['subjects'] ?? [] as $s) {
        $k = mb_strtolower((string) $s['name']);
        if (!isset($subjects[$k])) {
            $subjects[$k] = ['name' => (string) $s['name'], 'subject_id' => $s['subject_id'] ?? null, 'score' => (float) ($s['score'] ?? 0), 'via' => (string) ($s['via'] ?? 'terms')];
        }
    }
    $subjects = array_slice(array_values($subjects), 0, DC_GRAPH_MAX_SUBJECTS);
    $via = array_map(static fn (array $s): array => ['name' => $s['name'], 'via' => $s['via']], $subjects);
    if ($subjects === []) {
        graph_find_log($pdo, $q, $filters, 0, 0, $source, 'no_subjects');
        return ['status' => 'no_subjects', 'took_ms' => graph_ms($t0)] + ['query' => $q, 'filters' => $filters, 'subjects' => [], 'rows' => [], 'total' => 0, 'candidates' => 0];
    }
    // 3. the candidates, over-fetched against the wall (M10)
    $c = dc_graph_candidates($subjects, $q, min(200, $limit * 3 + 50));
    if ($c['status'] !== 'ok') {
        graph_find_log($pdo, $q, $filters, 0, 0, $source, $c['status']);
        return ['status' => in_array($c['status'], ['graph_unavailable', 'graph_timeout'], true) ? $c['status'] : 'graph_error', 'detail' => $c['status'], 'subjects' => $via, 'took_ms' => graph_ms($t0)] + ['query' => $q, 'filters' => $filters, 'rows' => [], 'total' => 0, 'candidates' => 0];
    }
    $cands = array_map(static fn (array $x): array => ['maludb_document_id' => $x['maludb_document_id'], 'similarity' => $x['similarity'] ?? 0.5, 'subject' => $x['subject']], $c['candidates'] ?? []);
    // 4. the wall — one SQL statement, the filters inside it
    $rows = graph_judge($pdo, $cands, $limit, $filters);
    // 5. the excerpts from DocCloud's own text
    $names = array_column($subjects, 'name');
    foreach ($rows as &$row) {
        $row['excerpt'] = graph_excerpt($pdo, (int) $row['document_id'], $q, $row['subjects'] ?: $names);
        $row['tags'] = pg_text_array((string) one_value($pdo, 'SELECT dc_document_tag_slugs(:id)', ['id' => (int) $row['document_id']]));
        $row['owner_name'] = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => (int) $row['owner_member_id']]);
    }
    unset($row);
    $ms = graph_ms($t0);
    // 6. the log, the usage
    graph_find_log($pdo, $q, $filters, count($rows), $ms, $source, 'ok', count($via));
    $pdo->prepare("INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, 'find', 1, 0, 0)
                   ON CONFLICT (month, kind) DO UPDATE SET calls = graph_usage.calls + 1")->execute();
    return ['status' => 'ok', 'query' => $q, 'filters' => $filters, 'subjects' => $via, 'rows' => $rows, 'total' => count($rows), 'candidates' => count($cands), 'took_ms' => $ms];
}

/** The filters' own subject strings: tag:<slug>, folder:<name>, library:<name>, owner:<display name> — what dc_graph_subjects() wrote. */
function graph_filter_subjects(PDO $pdo, array $filters): array
{
    $out = [];
    if (!empty($filters['tag']) && $filters['tag'] !== '-') {
        $out[] = 'tag:' . $filters['tag'];
    }
    if (!empty($filters['folder'])) {
        $n = one_value($pdo, 'SELECT name FROM folders WHERE id = :id AND parent_id IS NOT NULL', ['id' => (int) $filters['folder']]);
        if ($n) {
            $out[] = 'folder:' . $n;
        }
    }
    if (!empty($filters['library'])) {
        $n = one_value($pdo, 'SELECT name FROM libraries WHERE id = :id', ['id' => (int) $filters['library']]);
        if ($n) {
            $out[] = 'library:' . $n;
        }
    }
    $owner = !empty($filters['mine']) ? (int) current_member_id() : (int) ($filters['owner'] ?? 0);
    if ($owner > 0) {
        $n = one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $owner]);
        if ($n) {
            $out[] = 'owner:' . $n;
        }
    }
    return $out;
}

function graph_ms(float $t0): int
{
    return (int) round((microtime(true) - $t0) * 1000);
}

/** search_log kind graph (the query cut at 120) and search.graph (never the words). */
function graph_find_log(PDO $pdo, string $q, array $filters, int $results, int $ms, string $source, string $status, int $subjects = 0): void
{
    $eval = !empty($GLOBALS['__graph_eval']);                      // an evaluation run (the bridge says so) leaves no search_log row, only the search.graph line marked eval
    try {
        if (!$eval) {
            search_log_write($pdo, 'graph', $q, $filters, $results, $ms, $source);
        }
    } catch (Throwable $e) {
        error_log('graph find log: ' . $e->getMessage());
    }
    log_activity($pdo, 'search.graph', 'search', null, ['screen' => 'graph', 'after' => ['length' => mb_strlen($q), 'results' => $results, 'subjects' => $subjects, 'status' => $status, 'took_ms' => $ms, 'filters' => array_keys($filters)] + ($eval ? ['eval' => true] : [])]);
}

function app_is_active(): bool
{
    return current_member_id() !== null;
}

/**
 * Related documents: the document's subjects (graph_documents, the wall on the document first) → each subject's documents (GET /v1/subjects/{id}, the
 * cheaper read) → dc_graph_filter → ranked by shared subjects, the document itself removed; cached 60 s per caller and document, never stored.
 * {status: ok|not_in_graph|graph_unavailable, subjects, rows}
 */
function graph_related(PDO $pdo, int $documentId, int $limit = 8): array
{
    $g = document_subjects_of($pdo, $documentId);
    if ($g === null) {
        return ['status' => 'not_in_graph', 'subjects' => [], 'rows' => []];
    }
    if (!graph_engine_up()) {
        return ['status' => 'graph_unavailable', 'subjects' => $g['subjects'], 'rows' => []];
    }
    $key = 'related:' . (int) current_member_id() . ':' . $documentId;
    $cached = $_SESSION['graph_cache'][$key] ?? null;
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
        return $cached['value'];
    }
    $cands = [];
    $used = [];
    foreach (array_slice($g['subjects'], 0, DC_GRAPH_MAX_SUBJECTS) as $name) {
        if (str_starts_with($name, 'doc:')) {
            continue;
        }
        $sid = dc_graph_subject_id($name);
        if ($sid === null) {
            continue;
        }
        $used[] = $name;
        $r = dc_graph_subject($sid);
        if ($r['status'] !== 'ok') {
            continue;
        }
        $docs = $r['subject']['documents'] ?? [];
        $rank = 0;
        foreach ($docs as $d) {
            $rank++;
            $cands[] = ['maludb_document_id' => (string) ($d['id'] ?? ''), 'similarity' => 1 / $rank, 'subject' => $name];
        }
    }
    $rows = [];
    foreach (graph_judge($pdo, $cands, $limit + 1) as $row) {
        if ((int) $row['document_id'] === $documentId) {
            continue;
        }
        $rows[] = $row;
        if (count($rows) >= $limit) {
            break;
        }
    }
    $value = ['status' => 'ok', 'subjects' => $used, 'rows' => $rows];
    $_SESSION['graph_cache'][$key] = ['at' => time(), 'value' => $value];
    return $value;
}
