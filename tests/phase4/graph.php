<?php
/**
 * Proof — the document graph through MCP (G1-G5): graph_find, graph_related, document_subjects, graph_subjects, get_subject, subject_neighbors, graph_path, graph_queue, graph_estimate, graph_usage. Without the engine
 * (the default run) every engine-backed tool answers graph_unavailable in words, the tools that read DocCloud's own bookkeeping still answer, and keyword search is unaffected. DC_ENGINE=1 runs the same tools against the
 * real MaluDB API over a scratch memory database (the recipe of slice 5): the wall is proven again with rows.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$u1 = $w['u1'];
$engine = getenv('DC_ENGINE') === '1';

echo "1. The bookkeeping tools (no engine needed)\n";
$ds = tdata(26, 'document_subjects', ['document' => $u1]);
ok($ds['policy'] === 'full' && $ds['document_id'] === $u1 && find_row($ds['subjects'], 'name', 'Supplier Terms')['via'] === 'extracted' && find_row($ds['subjects'], 'name', 'folder:Priya')['via'] === 'known', 'document_subjects: Priya\'s invoice — the known subjects (folder:, owner: …) and the extracted ones, told apart');
ok(!in_array('doc:' . $u1, array_column($ds['subjects'], 'name'), true), 'the internal doc: marker is not a subject');
ok(tool(30, 'document_subjects', ['document' => $u1])['code'] === 'not_found', 'Dana cannot see it: not_found');
ok(tool(26, 'document_subjects', ['document' => $w['priya_doc']])['code'] === 'not_in_graph', 'a document the graph does not hold: not_in_graph');
$q = tdata(1, 'graph_queue');
ok(isset($q['budget']['pct']) && $q['budget']['pct'] >= 80 && count($q['jobs']) >= 2 && isset($q['jobs'][0]['name']), 'graph_queue (graph.manage): the budget at 85% and the jobs, named');
ok(count(tdata(1, 'graph_queue', ['status' => 'failed'])['jobs']) >= 1 && array_unique(array_column(tdata(1, 'graph_queue', ['status' => 'failed'])['jobs'], 'status')) === ['failed'], 'status filter');
ok(count(tdata(1, 'graph_queue', ['document' => $w['u2']])['jobs']) === 2, 'document filter');
ok(tool(27, 'graph_queue')['code'] === 'managers_only' && tool(26, 'graph_queue')['code'] === 'managers_only', 'a curator and a member: managers_only');
$e = tdata(1, 'graph_estimate', ['folder' => $w['legal_contracts']]);
ok($e['documents'] >= 1 && $e['chars'] > 0 && is_bool($e['over_budget']) && $e['engine_available'] === $engine && $e['budget_left_chars'] !== null, 'graph_estimate (admin): documents, characters, calls, the budget, the engine\'s state');
$em = tdata(27, 'graph_estimate', ['folder' => $w['legal_contracts']]);
ok($em['documents'] >= 1 && $em['budget_left_chars'] === null && $em['over_budget'] === null, 'for Marco the budget figures are not shown');
ok(tool(30, 'graph_estimate', ['folder' => $w['legal_contracts']])['code'] === 'not_found', 'Dana: not_found');
$gu = tdata(1, 'graph_usage');
ok(count($gu) >= 1 && isset($gu[0]['calls']) && !has_key($gu, 'dollars') && !has_key($gu, 'cost'), 'graph_usage: calls, characters, documents — never dollars');
ok(tool(27, 'graph_usage')['code'] === 'managers_only', 'costs.read only');
ok(count(tdata(1, 'graph_usage', ['months' => 1])) <= count($gu), 'months');

if (!$engine) {
    echo "2. Without the engine: graph_unavailable, in words, never a crash\n";
    foreach ([['graph_find', ['query' => 'supplier terms']], ['graph_related', ['document' => $u1]], ['graph_subjects', []], ['get_subject', ['subject' => 3]], ['subject_neighbors', ['subject' => 3]], ['graph_path', ['from' => 3, 'to' => 4]]] as [$tn, $a]) {
        $r = tool(26, $tn, $a);
        ok($r['code'] === 'graph_unavailable' && str_contains($r['data']['error']['message'], 'keyword search') && $r['data']['error']['retry'] === false, "$tn: graph_unavailable, and it points to keyword search");
    }
    ok(tool(26, 'graph_related', ['document' => $w['priya_doc']])['code'] === 'not_in_graph', 'graph_related on a document that is not in the graph: not_in_graph');
    ok(tool(30, 'graph_related', ['document' => $u1])['code'] === 'not_found', '...on one Dana cannot see: not_found');
    ok(tool(26, 'graph_find', ['query' => 'x'])['error'] === true, 'a query under two characters is refused by the schema');
    ok(tool(26, 'get_subject', ['subject' => 'No Such Subject'])['code'] === 'graph_unavailable' || tool(26, 'get_subject', ['subject' => 'No Such Subject'])['code'] === 'not_found', 'a subject by name with no engine: graph_unavailable (or not_found)');
    ok(tool(26, 'graph_find', ['query' => 'supplier terms', 'library' => 'Nope'])['code'] === 'not_found', 'a library the asker cannot name: not_found before the engine is asked');
    $s = tdata(26, 'search', ['q' => 'invoice']);
    ok($s['total'] >= 1, 'keyword search still works: "invoice"');
    $sl = last_event('search.graph');
    ok($sl !== null && $sl['source'] === 'mcp' && !str_contains((string) $sl['after'], 'supplier terms'), 'the graph search is logged search.graph, source mcp, without the words');
    $sg = q("SELECT kind, source FROM search_log WHERE member_id = 26 AND kind = 'graph' ORDER BY id DESC LIMIT 1");
    ok($sg !== [] && $sg[0]['source'] === 'mcp', 'and a search_log row of kind graph');
} else {
    echo "2. With the engine: the wall holds\n";
    graph_ready();
    for ($i = 0; $i < 6; $i++) { run_graph(); }           // the worker's graph pass, as slice 5 does: the world's documents go into the real engine under their known subjects
    $inGraph = array_map('intval', array_column(q("SELECT document_id FROM graph_documents WHERE maludb_document_id NOT LIKE 'none:%'"), 'document_id'));
    ok(count($inGraph) >= 15, 'the engine holds ' . count($inGraph) . ' documents (known subjects: tag:, folder:, library:, owner:)');
    $expected = function (int $who, string $tag) use ($inGraph): array {
        $ids = array_map('intval', array_column(q("SELECT dt.document_id FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE t.slug = :s", ['s' => $tag]), 'document_id'));
        $vis = visible_live($who);
        $r = array_values(array_intersect($ids, $inGraph, $vis));
        sort($r);
        return $r;
    };
    foreach ([27 => 'Marco', 26 => 'Priya', 30 => 'Dana', 28 => 'Bea', 29 => 'Ann', 1 => 'the admin'] as $who => $nm) {
        foreach (['p4-legal', 'base'] as $tag) {
            $r = tool($who, 'graph_find', ['query' => $tag, 'limit' => 50]);
            $got = $r['error'] ? [] : array_map('intval', array_column($r['data']['rows'], 'document_id'));
            sort($got);
            $want = $expected($who, $tag);
            // the engine also reaches a document through the folder or library subject the query's words name (the nda's neighbours): so every row is a document $nm may see, and every visible document carrying the tag is among them
            ok(!$r['error'] && array_diff($got, visible_live($who)) === [] && array_diff($want, $got) === [], "$nm: graph_find \"$tag\" returns only documents $nm may see (" . count($got) . "), among them the " . count($want) . " that carry the tag" . ($r['error'] ? ' — ' . $r['text'] : ''));
            if ($got !== []) {
                $row = $r['data']['rows'][0];
                ok(isset($row['score'], $row['subjects'], $row['name']) && !str_contains(json_encode($r['data']), 'source_span'), "$nm: a row carries the score and the subjects, and nothing of the engine's chunks");
            }
        }
    }
    $f = tdata(27, 'graph_find', ['query' => 'p4-legal', 'folder' => $w['legal_contracts']]);
    ok(in_array('p4-nda.pdf', ids($f['rows'], 'name'), true) && array_diff(ids($f['rows'], 'document_id'), visible_live(27)) === [] && in_array('tag:p4-legal', array_column($f['subjects_used'], 'name'), true) && $f['candidates_read'] >= 1, 'graph_find with a folder filter: the nda; the subjects used; the candidates read');
    $dp = tool(30, 'graph_find', ['query' => 'p4-legal']);
    ok(!$dp['error'] && $dp['data']['rows'] === [] && count($dp['data']['subjects_used']) >= 1, 'Dana asks for the nda\'s subject: the engine returns the documents, the wall removes them — an empty list, not an error');
    $rel = tdata(27, 'graph_related', ['document' => $w['nda']]);
    ok(!in_array($w['nda'], array_column($rel['rows'], 'document_id'), true) && array_diff(array_column($rel['rows'], 'document_id'), visible_docs(27)) === [], 'graph_related for the nda: documents that share its subjects, never itself, only visible ones (' . count($rel['rows']) . ')');
    $relD = tool(30, 'graph_related', ['document' => $w['dana_doc']]);
    ok(!$relD['error'] && array_diff(array_column($relD['data']['rows'], 'document_id'), visible_docs(30)) === [], 'graph_related for Dana\'s own document lists none of Marco\'s');
    $gs = tdata(30, 'graph_subjects', ['limit' => 50]);
    ok(is_array($gs) && count(array_filter($gs, fn ($s) => $s['documents_visible'] < 1)) === 0 && !in_array('tag:p4-legal', array_column($gs, 'name'), true), 'graph_subjects for Dana: no subject she cannot reach a document through (tag:p4-legal is not listed)');
    $gm = tdata(27, 'graph_subjects', ['limit' => 50]);
    ok(in_array('tag:p4-legal', array_column($gm, 'name'), true) || count($gm) >= 1, 'and for Marco there are subjects');
    $gl = tdata(27, 'graph_subjects', ['library' => 'SMOKE p4 Legal', 'limit' => 50]);
    ok(is_array($gl), 'graph_subjects for one library');
    $sub = tool(27, 'get_subject', ['subject' => 'tag:p4-legal']);
    ok(!$sub['error'] && $sub['data']['name'] === 'tag:p4-legal' && ids($sub['data']['documents'], 'name') === ['p4-nda.pdf'], 'get_subject by name: its documents (the nda) and neighbours');
    ok(tool(30, 'get_subject', ['subject' => 'tag:p4-legal'])['data']['documents'] === [], 'Dana: the subject, no documents');
    $nb = tool(27, 'subject_neighbors', ['subject' => 'tag:p4-legal']);
    ok(!$nb['error'] && count($nb['data']['neighbours']) >= 1, 'subject_neighbors: the neighbourhood');
    $nbD = tool(30, 'subject_neighbors', ['subject' => 'tag:p4-legal']);
    ok(!$nbD['error'] && count(array_filter($nbD['data']['neighbours'], fn ($n) => $n['kind'] === 'document')) === 0, 'for Dana no document node in it');
    $pa = tool(27, 'graph_path', ['from' => 'tag:p4-legal', 'to' => 'owner:SMOKE Marco']);
    ok(!$pa['error'] && array_key_exists('found', $pa['data']), 'graph_path answers found true or false');
    $pD = tool(30, 'graph_path', ['from' => 'tag:p4-legal', 'to' => 'owner:SMOKE Marco']);
    ok(!$pD['error'] && ($pD['data']['found'] === false || count(array_filter($pD['data']['steps'], fn ($s) => $s['kind'] === 'document')) === 0), 'a path through a document Dana cannot see is no path for her');
    $de = tdata(1, 'graph_estimate', ['folder' => $w['legal_contracts']]);
    ok($de['engine_available'] === true, 'graph_estimate: the engine is up');
    $sg = q("SELECT kind, source FROM search_log WHERE member_id = 27 AND kind = 'graph' ORDER BY id DESC LIMIT 1");
    ok($sg !== [] && $sg[0]['source'] === 'mcp', 'a graph search is a search_log row of kind graph, source mcp');
    $rs = tool(27, 'run_saved_search', ['saved_search' => 'p4 graphs']);
    ok(!$rs['error'] && isset($rs['data']['rows']), 'a saved graph search runs through graph_find');
}
finish();
