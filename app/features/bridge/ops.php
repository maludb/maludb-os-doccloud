<?php
declare(strict_types=1);

/**
 * THE BRIDGE's operations (Phase 4, docs/doccloud-mcp-tool-surface.md "The bridge"): how the Python read servers reach what only PHP may touch — the engine (app/doccloud/graph.php, the one file that holds its token), the
 * signed download URL, the search log (the read roles cannot write) and the trail of an admin's look at a foreign library. The door is html/internal/bridge.php (internal port only, signed with ACTIONS_RELAY_KEY); this file is
 * what it calls. A `member` caller is a verified, admitted mirror row and is judged by the SAME walls as the screens (dc_can_see_document, the graph's dc_graph_filter); an `application` caller is judged by dc_application_* and
 * is honoured only for the four share ops. Every refusal is a ShareRefused (code, sentence, HTTP status); a document one may not see is the same 404 as one that does not exist.
 */
require_once dirname(__DIR__) . '/graph/handler.php';
require_once dirname(__DIR__) . '/shares/queries.php';

const BRIDGE_OPS = ['graph_find', 'graph_related', 'document_subjects', 'graph_subjects', 'subject', 'subject_neighbors', 'graph_path', 'graph_estimate_check', 'admin_view', 'log_search',
                    'share_folders', 'share_documents', 'share_text', 'share_link', 'document_link'];
const BRIDGE_SHARE_OPS = ['share_folders', 'share_documents', 'share_text', 'share_link', 'document_link'];

/** Sign the caller in for THIS request: an active, admitted mirror row (else 403 unknown_member). The door (mcp | agent) is the activity source; a run's id and request id stamp every row. */
function bridge_become(array $caller, array $in): int
{
    $id = filter_var($caller['member_id'] ?? null, FILTER_VALIDATE_INT);
    $m = $id === false || $id < 1 ? null : find_member_by_id(db(), (int) $id);
    if ($m === null || $m['status'] !== 'active' || $m['capability'] === null) {
        throw new ShareRefused('unknown_member', 'That member is not admitted here.', 403);
    }
    $_SESSION['member_id'] = (int) $m['id'];
    db_apply_context(db());
    $GLOBALS['__public_door'] = ($in['door'] ?? 'mcp') === 'agent' ? 'agent' : 'mcp';
    if (is_int($in['run_id'] ?? null) && $in['run_id'] > 0) {
        $GLOBALS['__agent_run_id'] = (int) $in['run_id'];
    }
    $rid = (string) ($in['request_id'] ?? '');
    if ($rid !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $rid)) {
        $_SERVER['HTTP_X_REQUEST_ID'] = $rid;
    }
    $GLOBALS['__graph_eval'] = !empty($in['is_eval']);
    return (int) $m['id'];
}

/** A document the caller may see, or the same 404 as for one that does not exist. */
function bridge_document(PDO $pdo, mixed $v): int
{
    $id = filter_var($v, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1 || !(bool) one_value($pdo, 'SELECT dc_can_see_document(:id)', ['id' => (int) $id])) {
        throw new ShareRefused('not_found', 'No document you can see matches that.', 404);
    }
    return (int) $id;
}

/** A subject by id or by the name the graph holds it under. */
function bridge_subject_id(mixed $v): int
{
    if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
        return (int) $v;
    }
    $id = is_string($v) && trim($v) !== '' ? dc_graph_subject_id(trim($v)) : null;
    if ($id === null) {
        throw new ShareRefused('not_found', 'No subject in the graph matches that.', 404);
    }
    return $id;
}

/** A graph row as the tools answer it: the wall's fields, the path, the excerpt as plain text with <mark> kept. */
function bridge_row(array $r): array
{
    return ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'folder_id' => (int) $r['folder_id'], 'folder_path' => $r['folder_path'] ?? null, 'library_id' => (int) $r['library_id'],
            'owner_member_id' => (int) $r['owner_member_id'], 'owner_name' => $r['owner_name'] ?? null, 'mime_kind' => $r['mime_kind'], 'score' => round((float) $r['score'], 3), 'subjects' => array_values($r['subjects'] ?? []),
            'hits' => (int) ($r['hits'] ?? 0), 'tags' => array_values($r['tags'] ?? []), 'excerpt' => isset($r['excerpt']) ? (string) $r['excerpt'] : null];
}

/** One call: the op, the caller (already verified by the door), the whole envelope → the answer document. Throws ShareRefused. */
function bridge_dispatch(PDO $pdo, string $op, array $in): array
{
    if (!in_array($op, BRIDGE_OPS, true)) {
        throw new ShareRefused('unknown_op', 'That is not an operation of the bridge.', 404);
    }
    $caller = is_array($in['caller'] ?? null) ? $in['caller'] : [];
    $args = is_array($in['args'] ?? null) ? $in['args'] : [];
    if (in_array($op, BRIDGE_SHARE_OPS, true)) {
        if (($caller['kind'] ?? '') !== 'application') {
            throw new ShareRefused('forbidden', 'The shares answer only to a requesting application.', 403);
        }
        return shares_dispatch($pdo, $op === 'document_link' ? 'share_link' : $op, $caller, $args);
    }
    if (($caller['kind'] ?? '') !== 'member') {
        throw new ShareRefused('forbidden', 'That operation is a member\'s.', 403);
    }
    bridge_become($caller, $in);
    switch ($op) {
        case 'graph_find': {
            $q = trim((string) ($args['query'] ?? ''));
            $filters = search_filters_from_query($pdo, is_array($args['filters'] ?? null) ? $args['filters'] : [], []);
            $r = graph_find($pdo, $q, $filters, (int) ($args['limit'] ?? GRAPH_FIND_PAGE), 'mcp');
            return ['status' => $r['status'], 'detail' => $r['detail'] ?? null, 'query' => $r['query'], 'subjects' => array_values($r['subjects'] ?? []), 'rows' => array_map('bridge_row', $r['rows'] ?? []),
                    'candidates' => (int) ($r['candidates'] ?? 0), 'took_ms' => (int) ($r['took_ms'] ?? 0)];
        }
        case 'graph_related': {
            $doc = bridge_document($pdo, $args['document'] ?? null);
            $r = graph_related($pdo, $doc, max(1, min(50, (int) ($args['limit'] ?? 10))));
            return ['status' => $r['status'], 'document_id' => $doc, 'subjects' => array_values($r['subjects'] ?? []), 'rows' => array_map('bridge_row', $r['rows'] ?? [])];
        }
        case 'document_subjects': {
            $doc = bridge_document($pdo, $args['document'] ?? null);
            $g = document_subjects_of($pdo, $doc);
            return $g === null ? ['status' => 'not_in_graph', 'document_id' => $doc, 'subjects' => []]
                : ['status' => 'ok', 'document_id' => $doc, 'policy' => $g['policy'], 'indexed_at' => json_ts($g['indexed_at']), 'subjects' => array_values($g['subjects'])];
        }
        case 'graph_subjects': {
            $lib = isset($args['library']) ? (int) $args['library'] : null;
            if ($lib !== null && (int) one_value($pdo, 'SELECT count(*) FROM mcp_libraries WHERE library_id = :id', ['id' => $lib]) === 0) {
                throw new ShareRefused('not_found', 'No library you can see matches that.', 404);
            }
            $r = god_nodes($pdo, $lib, max(1, min(50, (int) ($args['limit'] ?? 25))));
            return ['status' => $r['status'], 'subjects' => $r['subjects']];
        }
        case 'subject': {
            if (!graph_engine_up()) {
                return ['status' => 'graph_unavailable'];
            }
            $p = subject_page($pdo, bridge_subject_id($args['subject'] ?? null));
            if ($p['status'] !== 'ok') {
                return ['status' => $p['status']];
            }
            return ['status' => 'ok', 'subject_id' => $p['subject_id'], 'name' => $p['name'], 'type' => $p['type'], 'documents' => array_map('bridge_row', $p['documents']),
                    'neighbours' => array_map(static fn (array $n): array => ['kind' => 'subject', 'id' => $n['subject_id'], 'name' => $n['name'], 'rel' => $n['rel']], $p['neighbour_subjects'])];
        }
        case 'subject_neighbors': {
            if (!graph_engine_up()) {
                return ['status' => 'graph_unavailable'];
            }
            $sid = bridge_subject_id($args['subject'] ?? null);
            $kind = in_array($args['kind'] ?? null, ['subject', 'document'], true) ? $args['kind'] : null;
            $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
            $n = dc_graph_neighbors('subject', $sid, 'both');
            if ($n['status'] !== 'ok') {
                return ['status' => $n['status'] === 'graph_timeout' ? 'graph_timeout' : 'graph_error', 'detail' => $n['status']];
            }
            $out = [];
            $docCands = [];
            $rels = [];
            foreach ($n['neighbors'] ?? [] as $nb) {
                $k = (string) ($nb['neighbor_kind'] ?? '');
                if ($k === 'subject' && $kind !== 'document') {
                    $out[] = ['kind' => 'subject', 'id' => (int) $nb['neighbor_id'], 'name' => (string) ($nb['label'] ?? ('#' . $nb['neighbor_id'])), 'rel' => (string) ($nb['rel'] ?? '')];
                } elseif ($k === 'document' && $kind !== 'subject') {
                    $docCands[] = ['maludb_document_id' => (string) $nb['neighbor_id'], 'similarity' => 1.0];
                    $rels[(string) $nb['neighbor_id']] = (string) ($nb['rel'] ?? '');
                }
            }
            if ($docCands !== []) {                                       // THE WALL: only documents the caller may see are listed, and they are the only ones counted
                $map = $pdo->prepare('SELECT maludb_document_id, document_id FROM graph_documents WHERE maludb_document_id = ANY (CAST(:ids AS text[]))');
                $map->execute(['ids' => '{' . implode(',', array_map(static fn (array $c): string => '"' . str_replace('"', '', $c['maludb_document_id']) . '"', $docCands)) . '}']);
                $byDoc = [];
                foreach ($map->fetchAll() as $m) {
                    $byDoc[(int) $m['document_id']] = (string) $m['maludb_document_id'];
                }
                foreach (graph_judge($pdo, $docCands, 200) as $row) {
                    $out[] = ['kind' => 'document', 'id' => (int) $row['document_id'], 'name' => $row['name'], 'rel' => $rels[$byDoc[(int) $row['document_id']] ?? ''] ?? ''];
                }
            }
            return ['status' => 'ok', 'subject' => ['subject_id' => $sid, 'name' => subject_label($sid)], 'neighbours' => array_slice($out, 0, $limit)];
        }
        case 'graph_path': {
            $a = bridge_subject_id($args['from'] ?? null);
            $b = bridge_subject_id($args['to'] ?? null);
            $p = subject_path($pdo, $a, $b, max(1, min(6, (int) ($args['max_depth'] ?? 4))));
            if (($p['status'] ?? 'ok') !== 'ok') {
                return ['status' => $p['status']];
            }
            foreach ($p['steps'] as $s) {
                if ($s['kind'] === 'document' && empty($s['visible'])) {
                    return ['status' => 'ok', 'found' => false, 'steps' => []];      // a path through a document the caller may not see is no path for them
                }
            }
            return ['status' => 'ok', 'found' => $p['found'], 'steps' => $p['steps']];
        }
        case 'graph_estimate_check':
            return ['engine_available' => graph_engine_up()];
        case 'admin_view': {
            if (!is_dc_admin()) {
                throw new ShareRefused('forbidden', 'Opening another person\'s library is the admin\'s.', 403);
            }
            $lib = (int) ($args['library_id'] ?? 0);
            if ((int) one_value($pdo, 'SELECT count(*) FROM libraries WHERE id = :id', ['id' => $lib]) === 0) {
                throw new ShareRefused('not_found', 'No such library.', 404);
            }
            $recent = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'library.admin_view' AND library_id = :l AND actor_member_id = app_current_member_id() AND occurred_at > now() - interval '1 hour'", ['l' => $lib]);
            if ($recent === 0) {
                log_activity($pdo, 'library.admin_view', 'library', $lib, ['library_id' => $lib, 'after' => ['door' => 'mcp']]);
            }
            return ['ok' => true, 'logged' => $recent === 0];
        }
        case 'log_search': {
            $kind = in_array($args['kind'] ?? '', ['keyword', 'graph', 'name'], true) ? (string) $args['kind'] : 'keyword';
            $query = mb_substr((string) ($args['query'] ?? ''), 0, 120);
            $filters = is_array($args['filters'] ?? null) ? $args['filters'] : [];
            $results = max(0, (int) ($args['results'] ?? 0));
            $ms = max(0, (int) ($args['took_ms'] ?? 0));
            $eval = !empty($GLOBALS['__graph_eval']);
            if (!$eval) {
                search_log_write($pdo, $kind, $query, $filters, $results, $ms, 'mcp');
            }
            log_activity($pdo, 'search.' . $kind, 'search', null, ['after' => ['query' => $query, 'filters' => $filters, 'results' => $results, 'took_ms' => $ms, 'kind' => $kind] + ($eval ? ['eval' => true] : [])]);
            return ['ok' => true, 'logged' => !$eval];
        }
    }
    throw new ShareRefused('unknown_op', 'That is not an operation of the bridge.', 404);
}
