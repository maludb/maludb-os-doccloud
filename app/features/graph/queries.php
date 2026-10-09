<?php
declare(strict_types=1);

/**
 * The reads of the document graph (slice 5): the engine's state and the counts by policy, the policies per library, a folder's effective
 * policy and its estimate, the queue, the usage, the model (never the credential), the explorer's subjects by degree, a subject's page, a
 * path, a document's subjects, and the excerpt from DocCloud's OWN text. Every document node the engine answers is mapped by its engine id
 * through graph_documents and judged by dc_graph_filter() / dc_visible_among() — the wall in SQL — before it is shown.
 */

/** {available, by_policy: {index: n, full: n}, queued, running, failed, skipped, budget} */
function graph_state(PDO $pdo): array
{
    $by = ['index' => 0, 'full' => 0];
    foreach ($pdo->query('SELECT policy, count(*) AS n FROM graph_documents WHERE NOT maludb_document_id LIKE \'none:%\' GROUP BY policy')->fetchAll() as $r) {
        $by[$r['policy']] = (int) $r['n'];
    }
    $jobs = ['queued' => 0, 'running' => 0, 'failed' => 0, 'skipped' => 0, 'done' => 0];
    foreach ($pdo->query('SELECT status, count(*) AS n FROM graph_jobs GROUP BY status')->fetchAll() as $r) {
        $jobs[$r['status']] = (int) $r['n'];
    }
    return ['available' => graph_engine_up(), 'by_policy' => $by, 'budget' => graph_budget($pdo)] + $jobs;
}

/** dc_graph_budget_state() for the month. */
function graph_budget(PDO $pdo, ?string $month = null): array
{
    $st = $month === null ? $pdo->query('SELECT * FROM dc_graph_budget_state()') : $pdo->prepare('SELECT * FROM dc_graph_budget_state(CAST(:m AS date))');
    if ($month !== null) {
        $st->execute(['m' => $month]);
    }
    $b = $st->fetch() ?: ['month' => date('Y-m-01'), 'full_chars' => 0, 'budget_chars' => 0, 'pct' => 0, 'full_documents' => 0, 'index_documents' => 0];
    $b['full_chars'] = (int) $b['full_chars'];
    $b['budget_chars'] = (int) $b['budget_chars'];
    $b['pct'] = (float) ($b['pct'] ?? 0);
    $b['left_chars'] = max(0, $b['budget_chars'] - $b['full_chars']);
    return $b;
}

/** The policy per library the caller reaches, with the documents in the graph by policy and the queued ones (the admin's table). */
function library_policies(PDO $pdo): array
{
    $sql = "SELECT l.library_id, l.kind, l.name, l.graph_policy, l.root_folder_id, l.my_level,
                   (SELECT count(*) FROM documents d WHERE d.library_id = l.library_id AND d.trashed_at IS NULL) AS documents,
                   (SELECT count(*) FROM graph_documents g JOIN documents d ON d.id = g.document_id WHERE d.library_id = l.library_id AND g.policy = 'index' AND g.maludb_document_id NOT LIKE 'none:%') AS indexed,
                   (SELECT count(*) FROM graph_documents g JOIN documents d ON d.id = g.document_id WHERE d.library_id = l.library_id AND g.policy = 'full' AND g.maludb_document_id NOT LIKE 'none:%') AS extracted,
                   (SELECT count(*) FROM graph_jobs j JOIN documents d ON d.id = j.document_id WHERE d.library_id = l.library_id AND j.status IN ('queued', 'running')) AS queued
              FROM mcp_libraries l WHERE l.archived_at IS NULL ORDER BY CASE l.kind WHEN 'personal' THEN 0 WHEN 'group' THEN 1 ELSE 2 END, l.name";
    return $pdo->query($sql)->fetchAll();
}

/** dc_effective_graph_policy(folder). */
function effective_policy(PDO $pdo, int $folderId): string
{
    return (string) one_value($pdo, 'SELECT dc_effective_graph_policy(:id)', ['id' => $folderId]);
}

/** dc_graph_estimate(folder) + the engine's state: {documents, chars, calls, budget_left_chars, over_budget, engine_available}. */
function graph_estimate(PDO $pdo, int $folderId): array
{
    $st = $pdo->prepare('SELECT * FROM dc_graph_estimate(:id)');
    $st->execute(['id' => $folderId]);
    $e = $st->fetch() ?: ['documents' => 0, 'chars' => 0, 'calls' => 0, 'budget_left_chars' => 0, 'over_budget' => false];
    return ['documents' => (int) $e['documents'], 'chars' => (int) $e['chars'], 'calls' => (int) $e['calls'], 'budget_left_chars' => (int) $e['budget_left_chars'],
            'over_budget' => (bool) $e['over_budget'], 'engine_available' => graph_engine_up()];
}

/** The queue (mcp_graph_jobs — graph.manage), filters: status, kind, document; newest first, paged. */
function graph_jobs(PDO $pdo, array $filters = [], int $page = 1, int $per = 50): array
{
    $where = ['true'];
    $args = [];
    foreach (['status', 'kind'] as $k) {
        if (!empty($filters[$k])) {
            $where[] = "j.$k = :$k";
            $args[$k] = $filters[$k];
        }
    }
    if (!empty($filters['document'])) {
        $where[] = 'j.document_id = :document';
        $args['document'] = (int) $filters['document'];
    }
    $sql = 'SELECT j.*, d.name AS document_name, count(*) OVER () AS total FROM mcp_graph_jobs j LEFT JOIN documents d ON d.id = j.document_id WHERE ' . implode(' AND ', $where)
         . ' ORDER BY CASE j.status WHEN \'running\' THEN 0 WHEN \'queued\' THEN 1 WHEN \'failed\' THEN 2 ELSE 3 END, j.created_at DESC LIMIT :lim OFFSET :off';
    $st = $pdo->prepare($sql);
    foreach ($args as $k => $v) {
        $st->bindValue(':' . $k, $v);
    }
    $st->bindValue(':lim', max(1, min($per, 200)), PDO::PARAM_INT);
    $st->bindValue(':off', max(0, ($page - 1) * $per), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total'], 'page' => $page];
}

/** Usage by month and kind (mcp_graph_usage — costs.read), the newest months first. */
function graph_usage(PDO $pdo, int $months = 6): array
{
    $st = $pdo->prepare('SELECT month, kind, calls, chars, documents FROM mcp_graph_usage WHERE month >= date_trunc(\'month\', now())::date - make_interval(months => :m) ORDER BY month DESC, kind');
    $st->execute(['m' => max(1, min($months, 24))]);
    return $st->fetchAll();
}

/** The model as MaluDB keeps it: provider, alias, embedding model, the prompt and secret_ref — never the credential. */
function graph_model(PDO $pdo): array
{
    $settings = $pdo->query('SELECT model_alias, embedding_model, graph_default_policy, graph_monthly_text_mb, graph_text_chars FROM dc_settings WHERE id = 1')->fetch() ?: [];
    if (!graph_engine_up()) {
        return ['status' => 'graph_unavailable', 'config' => null, 'settings' => $settings, 'prompt' => dc_graph_prompt_template()];
    }
    $r = dc_graph_config_get();
    $cfg = is_array($r['config'] ?? null) ? $r['config'] : [];
    unset($cfg['token'], $cfg['secret'], $cfg['api_key']);
    return ['status' => $r['status'], 'config' => $cfg, 'settings' => $settings, 'prompt' => (string) ($cfg['prompt_template'] ?? dc_graph_prompt_template()), 'secret_ref' => $cfg['secret_ref'] ?? null];
}

/**
 * The engine's document nodes mapped to DocCloud's documents the caller may see: [{maludb_document_id, document_id?, similarity?, subject?}] →
 * dc_graph_filter rows {document_id, name, folder_id, library_id, owner_member_id, mime_kind, score, subjects, hits} + folder_path. One statement.
 */
function graph_judge(PDO $pdo, array $candidates, int $limit = 50, array $filters = []): array
{
    if ($candidates === []) {
        return [];
    }
    $clean = [];
    foreach ($candidates as $c) {
        $row = ['maludb_document_id' => (string) ($c['maludb_document_id'] ?? ''), 'similarity' => isset($c['similarity']) ? (float) $c['similarity'] : 0.5];
        if (isset($c['document_id']) && $c['document_id'] !== null) {
            $row['document_id'] = (int) $c['document_id'];
        }
        if (!empty($c['subject'])) {
            $row['subject'] = (string) $c['subject'];
        }
        $clean[] = $row;
    }
    $st = $pdo->prepare('SELECT * FROM dc_graph_filter(CAST(:c AS jsonb), :lim, CAST(:f AS jsonb))');
    $st->execute(['c' => json_encode($clean), 'lim' => max(1, min($limit, 200)), 'f' => json_encode((object) $filters)]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['subjects'] = is_array($r['subjects']) ? $r['subjects'] : pg_text_array((string) ($r['subjects'] ?? '{}'));
        $r['score'] = (float) $r['score'];
        $r['hits'] = (int) $r['hits'];
        $r['folder_path'] = folder_path_text($pdo, (int) $r['folder_id']);
    }
    unset($r);
    return $rows;
}

/** The best passage from DocCloud's OWN text around the query's words (ts_headline); the engine's chunk text never reaches a caller. */
function graph_excerpt(PDO $pdo, int $documentId, string $q, array $subjects = []): string
{
    $words = trim($q);
    if ($words === '') {
        $words = implode(' ', array_map(static fn (string $s): string => preg_replace('/^[a-z]+:/', '', $s) ?? $s, array_slice($subjects, 0, 3)));
    }
    $st = $pdo->prepare("SELECT ts_headline('simple', left(t.text, 200000), websearch_to_tsquery('simple', unaccent(:q)), 'MaxFragments=1, MaxWords=30, MinWords=10, StartSel=<mark>, StopSel=</mark>')
                           FROM document_text t JOIN documents d ON d.current_version_id = t.version_id WHERE d.id = :id AND t.char_count > 0");
    $st->execute(['q' => $words === '' ? 'document' : $words, 'id' => $documentId]);
    $h = $st->fetchColumn();
    if ($h === false || $h === null || trim((string) $h) === '') {
        $d = find_document($pdo, $documentId);
        return (string) ($d['description'] ?? '');
    }
    return (string) $h;
}

/** A document's subjects as the graph holds them (mcp_graph_documents — the wall): {policy, indexed_at, subjects[]} or null when not in the graph. */
function document_subjects_of(PDO $pdo, int $documentId): ?array
{
    $st = $pdo->prepare('SELECT policy, indexed_at, subjects FROM mcp_graph_documents WHERE document_id = :id');
    $st->execute(['id' => $documentId]);
    $g = $st->fetch();
    if ($g === false) {
        return null;
    }
    $subjects = is_array($g['subjects']) ? $g['subjects'] : pg_text_array((string) $g['subjects']);
    if ($subjects === []) {
        return null;                                   // a skipped job's marker row (none:<job>, no subjects): the engine holds nothing of it
    }
    return ['policy' => $g['policy'], 'indexed_at' => $g['indexed_at'], 'subjects' => $subjects];
}

/** The explorer's subjects by degree, each with the count of ITS documents the caller may see (zero → hidden); cached 60 s per caller. */
function god_nodes(PDO $pdo, ?int $libraryId = null, int $limit = 25): array
{
    if (!graph_engine_up()) {
        return ['status' => 'graph_unavailable', 'subjects' => []];
    }
    $key = 'god:' . (int) current_member_id() . ':' . ($libraryId ?? 0);
    $cached = $_SESSION['graph_cache'][$key] ?? null;
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
        return $cached['value'];
    }
    // GET /v1/graph/god-nodes ranks every node (documents too: {object_kind, object_id, label, degree_out, degree_in, degree_total}); documents
    // take most of the top, so the ranking is read deep and the subjects picked out of it.
    $r = dc_graph_god_nodes(min(500, max(200, $limit * 8)));
    if ($r['status'] !== 'ok') {
        return ['status' => $r['status'], 'subjects' => []];
    }
    $out = [];
    foreach ($r['god_nodes'] ?? $r['nodes'] ?? [] as $n) {
        if (($n['object_kind'] ?? $n['kind'] ?? $n['node_kind'] ?? 'subject') !== 'subject') {
            continue;
        }
        $sid = (int) ($n['object_id'] ?? $n['id'] ?? $n['node_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $docs = subject_documents($pdo, $sid, $libraryId === null ? [] : ['library' => $libraryId], 200);
        if ($docs['visible'] === 0) {
            continue;
        }
        $out[] = ['subject_id' => $sid, 'name' => (string) ($n['label'] ?? $n['canonical_name'] ?? $n['name'] ?? ('#' . $sid)), 'type' => $n['type'] ?? null,
                  'degree' => (int) ($n['degree_total'] ?? $n['degree'] ?? $n['score'] ?? 0), 'documents_visible' => $docs['visible']];
        if (count($out) >= $limit) {
            break;
        }
    }
    $value = ['status' => 'ok', 'subjects' => $out];
    $_SESSION['graph_cache'][$key] = ['at' => time(), 'value' => $value];
    return $value;
}

/** A subject's documents through the wall (GET /v1/subjects/{id} → its documents → dc_graph_filter): {subject, rows, visible, total}. */
function subject_documents(PDO $pdo, int $subjectId, array $filters = [], int $limit = 50): array
{
    $r = dc_graph_subject($subjectId);
    if ($r['status'] !== 'ok') {
        return ['status' => $r['status'], 'subject' => null, 'rows' => [], 'visible' => 0, 'total' => 0];
    }
    $s = $r['subject'] ?? [];
    $cands = [];
    foreach ($s['documents'] ?? [] as $d) {
        $cands[] = ['maludb_document_id' => (string) ($d['id'] ?? ''), 'similarity' => 1.0, 'subject' => (string) ($s['label'] ?? $s['canonical_name'] ?? '')];
    }
    $rows = graph_judge($pdo, $cands, $limit, $filters);
    return ['status' => 'ok', 'subject' => $s, 'rows' => $rows, 'visible' => count($rows), 'total' => count($cands)];
}

/** The subject page: the subject, its documents (the wall), its neighbours as a list (document nodes judged; subjects as they are). */
function subject_page(PDO $pdo, int $subjectId): array
{
    if (!graph_engine_up()) {
        return ['status' => 'graph_unavailable'];
    }
    $sd = subject_documents($pdo, $subjectId);
    if ($sd['status'] !== 'ok') {
        return ['status' => $sd['status']];
    }
    $s = $sd['subject'];
    $n = dc_graph_neighbors('subject', $subjectId, 'both');
    $subjects = [];
    $docNodes = [];
    foreach ($n['neighbors'] ?? [] as $nb) {
        $kind = (string) ($nb['neighbor_kind'] ?? '');
        if ($kind === 'subject') {
            $subjects[] = ['subject_id' => (int) $nb['neighbor_id'], 'name' => (string) ($nb['label'] ?? ('#' . $nb['neighbor_id'])), 'rel' => (string) ($nb['rel'] ?? '')];
        } elseif ($kind === 'document') {
            $docNodes[(string) $nb['neighbor_id']] = (string) ($nb['rel'] ?? '');
        }
    }
    $hidden = max(0, count($docNodes) - count($sd['rows']));
    return ['status' => 'ok', 'subject_id' => $subjectId, 'name' => (string) ($s['label'] ?? $s['canonical_name'] ?? ('#' . $subjectId)), 'type' => $s['type'] ?? null,
            'description' => $s['description'] ?? null, 'verbs' => $s['verbs'] ?? [], 'related' => $s['related_subjects'] ?? [], 'documents' => $sd['rows'], 'documents_hidden' => $hidden,
            'neighbour_subjects' => array_slice($subjects, 0, 40)];
}

/** A path between two subjects: the chain of nodes; document nodes judged (a hidden one shown as such). */
function subject_path(PDO $pdo, int $a, int $b, int $depth = 6): array
{
    if (!graph_engine_up()) {
        return ['status' => 'graph_unavailable', 'steps' => [], 'found' => false];
    }
    $r = dc_graph_path(['kind' => 'subject', 'id' => $a], ['kind' => 'subject', 'id' => $b], $depth);
    if ($r['status'] !== 'ok') {
        return ['status' => $r['status'], 'steps' => [], 'found' => false];
    }
    // GET /v1/graph/path answers {paths: [{depth, path: ["subject:3", "document:12", "subject:21"]}, …]}, shortest first (tests/phase0/engine
    // FINDINGS): each path is a list of "kind:id" strings. The first path whose documents the caller may all see is shown; else the shortest,
    // its hidden documents named as such.
    $paths = [];
    foreach ((array) ($r['paths'] ?? []) as $p) {
        $list = is_array($p) ? ($p['path'] ?? $p['nodes'] ?? $p) : [];
        $nodes = [];
        foreach ((array) $list as $node) {
            if (is_string($node) && preg_match('/^([a-z_]+):(\d+)$/', $node, $m)) {
                $nodes[] = ['kind' => $m[1], 'id' => (int) $m[2]];
            } elseif (is_array($node)) {
                $nodes[] = ['kind' => (string) ($node['kind'] ?? $node['node_kind'] ?? 'subject'), 'id' => (int) ($node['id'] ?? $node['node_id'] ?? 0)];
            }
        }
        if ($nodes !== []) {
            $paths[] = $nodes;
        }
        if (count($paths) >= 20) {
            break;
        }
    }
    if ($paths === []) {
        return ['status' => 'ok', 'steps' => [], 'found' => false];
    }
    $allDocs = [];
    foreach ($paths as $nodes) {
        foreach ($nodes as $n) {
            if ($n['kind'] === 'document') {
                $allDocs[(string) $n['id']] = true;
            }
        }
    }
    $seen = [];
    if ($allDocs !== []) {
        foreach (graph_judge($pdo, array_map(static fn (string $m): array => ['maludb_document_id' => $m, 'similarity' => 1.0], array_keys($allDocs)), 200) as $row) {
            $seen[(int) $row['document_id']] = true;
        }
        $mapSt = $pdo->prepare('SELECT maludb_document_id, document_id FROM graph_documents WHERE maludb_document_id = ANY (CAST(:ids AS text[]))');
        $mapSt->execute(['ids' => '{' . implode(',', array_map(static fn (string $s): string => '"' . $s . '"', array_keys($allDocs))) . '}']);
        $visibleEngine = [];
        foreach ($mapSt->fetchAll() as $m) {
            if (isset($seen[(int) $m['document_id']])) {
                $visibleEngine[(string) $m['maludb_document_id']] = true;
            }
        }
    } else {
        $visibleEngine = [];
    }
    $chosen = $paths[0];
    foreach ($paths as $nodes) {
        $ok = true;
        foreach ($nodes as $n) {
            if ($n['kind'] === 'document' && !isset($visibleEngine[(string) $n['id']])) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $chosen = $nodes;
            break;
        }
    }
    $steps = [];
    $docIds = [];
    foreach ($chosen as $n) {
        $steps[] = ['kind' => $n['kind'], 'id' => $n['id'], 'name' => $n['kind'] === 'subject' ? (subject_label($n['id']) ?? ('#' . $n['id'])) : ('#' . $n['id']), 'rel' => ''];
        if ($n['kind'] === 'document') {
            $docIds[] = (string) $n['id'];
        }
    }
    $visible = [];
    if ($docIds !== []) {
        foreach (graph_judge($pdo, array_map(static fn (string $m): array => ['maludb_document_id' => $m, 'similarity' => 1.0], $docIds), 200) as $row) {
            $visible[(int) $row['document_id']] = $row;
        }
        $mapSt = $pdo->prepare('SELECT maludb_document_id, document_id FROM graph_documents WHERE maludb_document_id = ANY (CAST(:ids AS text[]))');
        $mapSt->execute(['ids' => '{' . implode(',', array_map(static fn (string $s): string => '"' . str_replace('"', '', $s) . '"', $docIds)) . '}']);
        $map = [];
        foreach ($mapSt->fetchAll() as $m) {
            $map[(string) $m['maludb_document_id']] = (int) $m['document_id'];
        }
        foreach ($steps as &$s) {
            if ($s['kind'] === 'document') {
                $did = $map[(string) $s['id']] ?? null;
                if ($did !== null && isset($visible[$did])) {
                    $s['document_id'] = $did;
                    $s['name'] = $visible[$did]['name'];
                    $s['visible'] = true;
                } else {
                    $s['name'] = 'a document you cannot see';
                    $s['visible'] = false;
                }
            }
        }
        unset($s);
    }
    return ['status' => 'ok', 'found' => $steps !== [], 'steps' => $steps];
}

/** The subject picker's search: the engine's subjects by a substring (GET /v1/subjects?q=), cached 60 s per query. */
function subjects_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 20): array
{
    if (!graph_engine_up() || trim($q) === '') {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $per, 'pages' => 1];
    }
    $key = 'pick:' . mb_strtolower(trim($q));
    $cached = $_SESSION['graph_cache'][$key] ?? null;
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
        $rows = $cached['value'];
    } else {
        $r = dc_graph_resolve_subjects(mb_substr(trim($q), 0, 80), 8);
        $rows = [];
        foreach ($r['subjects'] ?? [] as $s) {
            $sid = $s['subject_id'] ?? dc_graph_subject_id((string) $s['name']);
            if ($sid !== null) {
                $rows[] = ['id' => (int) $sid, 'label' => (string) $s['name'], 'detail' => (string) ($s['via'] ?? '')];
            }
        }
        $_SESSION['graph_cache'][$key] = ['at' => time(), 'value' => $rows];
    }
    return ['rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'page' => $page, 'page_size' => $per, 'pages' => max(1, (int) ceil(count($rows) / $per))];
}

/** A subject's name by id (the picker's label). */
function subject_label(int $subjectId): ?string
{
    if (!graph_engine_up()) {
        return null;
    }
    $r = dc_graph_subject($subjectId);
    return $r['status'] === 'ok' ? (string) ($r['subject']['label'] ?? $r['subject']['canonical_name'] ?? null) : null;
}

/** Reconciliation on demand (the admin): the engine's documents against graph_documents — orphans the engine holds that DocCloud does not know, rows the engine lost. */
function graph_reconcile(PDO $pdo, int $limit = 200): array
{
    $r = dc_graph_documents($limit, 0);
    if ($r['status'] !== 'ok') {
        return ['status' => $r['status'], 'orphans' => [], 'lost' => [], 'engine' => 0, 'known' => 0];
    }
    $engine = [];
    foreach ($r['documents'] ?? [] as $d) {
        $engine[(string) ($d['id'] ?? '')] = (string) ($d['title'] ?? '');
    }
    $known = [];
    foreach ($pdo->query("SELECT maludb_document_id, document_id FROM graph_documents WHERE maludb_document_id NOT LIKE 'none:%'")->fetchAll() as $g) {
        $known[(string) $g['maludb_document_id']] = (int) $g['document_id'];
    }
    $orphans = [];
    foreach ($engine as $mid => $title) {
        if (!isset($known[$mid])) {
            $orphans[] = ['maludb_document_id' => $mid, 'title' => $title];
        }
    }
    $lost = [];
    if (count($engine) < $limit) {                      // only when the engine's list was complete can a missing one be called lost
        foreach ($known as $mid => $docId) {
            if (!isset($engine[$mid])) {
                $lost[] = ['maludb_document_id' => $mid, 'document_id' => $docId];
            }
        }
        if ($lost !== []) {
            $ids = array_map(static fn (array $l): int => $l['document_id'], $lost);
            $pdo->prepare('UPDATE graph_documents SET version_id = NULL WHERE document_id = ANY (CAST(:ids AS bigint[]))')->execute(['ids' => '{' . implode(',', $ids) . '}']);   // the catch-up re-sends them
        }
    }
    log_activity($pdo, 'graph.reconcile', 'graph_job', 0, ['after' => ['engine' => count($engine), 'known' => count($known), 'orphans' => count($orphans), 'lost' => count($lost)]]);
    return ['status' => 'ok', 'orphans' => $orphans, 'lost' => $lost, 'engine' => count($engine), 'known' => count($known)];
}
