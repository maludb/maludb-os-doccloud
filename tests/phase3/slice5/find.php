<?php
/** Slice 5 proof — dc_graph_find() and related documents (spec "Proof" 5, 6, 7): without the engine the sentence and keyword search still working; with it the subjects, THE WALL for four callers, the excerpt from DocCloud's own text, the log, related documents. */
require __DIR__ . '/lib.php';
$s = seed_graph();
$acme = $s['board_doc']; $minutes = $s['minutes']; $notes = $s['priya_doc']; $ann = $s['guest_doc']; $handbook = $s['handbook'];
if (!engine()) {
    echo "1. Without the engine\n";
    $f = find_as(27, 'acme');
    ok($f['code'] === 200 && $f['status'] === 'graph_unavailable' && $f['names'] === [] && str_contains($f['json']['data']['find']['sentence'] ?? '', 'keyword search still works'), 'a find answers graph_unavailable with the sentence');
    $html = page(actor(27), '/graph/?q=acme')['body'];
    ok(str_contains($html, 'id="graph-unavailable"') && str_contains($html, 'id="graph-keyword-link"') && str_contains($html, '/search?q=acme'), 'the page: the banner and the keyword search link');
    $k = search_as(27, 'termination');
    ok($k['code'] === 200 && in_array('acme-msa.pdf', $k['names'], true), 'keyword search still works');
    ok((int) one("SELECT count(*) FROM search_log WHERE kind = 'graph' AND results = 0") >= 1 && last_event('search.graph') !== null, 'the find is still logged (kind graph, 0 results; search.graph)');
    $r = get_json(27, '/documents/' . $acme);
    ok($r['code'] === 200 && ($r['json']['data']['graph']['status'] ?? '') === 'not_in_graph', 'the document page says not in the graph');
    $r = get_json(27, '/graph/?q=' . str_repeat('x', 500));
    ok($r['code'] === 200 && ($r['json']['data']['find']['status'] ?? '') === 'no_query', 'a 500-character query is no_query');
    finish();
}
graph_ready();
run_graph();
// The queue and policy proofs (run before this one on the same scratch database) leave big-report.txt in Contracts, whose extracted text names
// "Acme": the engine rightly finds it too. The wall checks below compare the four documents this proof seeded and say so.
$seeded = static fn (array $names): array => array_values(array_filter($names, static fn (string $n): bool => $n !== 'big-report.txt'));
echo "1. The subjects and the wall\n";
$f = find_as(27, 'acme-corp');
ok($f['code'] === 200 && $f['status'] === 'ok', 'Marco finds by the tag subject: status ok');
$used = array_column($f['json']['data']['find']['subjects'] ?? [], 'name');
ok(in_array('tag:acme-corp', $used, true), 'the subjects used name tag:acme-corp (from terms or recall): ' . implode(', ', $used));
ok($seeded($f['names']) === ['acme-msa.pdf', 'for-ann.pdf', 'minutes-jan.docx'], 'Marco gets acme, the minutes and for-ann — NOT Priya\'s notes (the wall): ' . implode(', ', $f['names']));
$f26 = find_as(26, 'acme-corp');
ok($seeded($f26['names']) === ['acme-msa.pdf', 'minutes-jan.docx', 'my-notes.md'], 'Priya gets acme, the minutes and her notes — not for-ann: ' . implode(', ', $f26['names']));
$f28 = find_as(28, 'acme-corp');
ok($f28['status'] === 'ok' && $f28['names'] === [], 'Bea (outside the Board) gets nothing although the engine returned four');
$f29 = find_as(29, 'acme-corp');
ok($f29['code'] === 403, 'the guest holds no graph.use: the graph page is 403 (slice 4\'s rule for search; what is shared with her is on Shared with me)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
$all = json_encode(array_map(static fn (array $r): array => ['maludb_document_id' => $r['maludb_document_id'], 'similarity' => 0.9, 'subject' => 'tag:acme-corp'], q('SELECT maludb_document_id FROM graph_documents')));
$annSees = array_column(q('SELECT name FROM dc_graph_filter(CAST(:c AS jsonb), 50) ORDER BY name', ['c' => $all]), 'name');
ok($annSees === ['for-ann.pdf'], '…the wall itself gives the guest Ann only for-ann.pdf of every document the engine holds (the records tool, when granted): ' . implode(', ', $annSees));
$f1 = find_as(1, 'acme-corp');
ok(count($seeded($f1['names'])) === 4, 'the admin gets all four');
ok(($f['json']['data']['find']['candidates'] ?? 0) >= 4, 'the candidates read were over-fetched (' . ($f['json']['data']['find']['candidates'] ?? 0) . ') and cut by the wall');
$row = $f['rows'][array_search('acme-msa.pdf', array_column($f['rows'], 'name'), true)] ?? [];
ok(in_array('tag:acme-corp', $row['subjects'] ?? [], true) && ($row['url'] ?? '') === '/documents/' . $acme && isset($row['score']), 'a row: the matched subject, the url, a score');
ok(str_contains((string) ($row['excerpt'] ?? ''), 'Acme') && !str_contains((string) ($row['excerpt'] ?? ''), 'source_span'), 'the excerpt comes from DocCloud\'s own text (the engine\'s chunk words never shown)');
$f2 = find_as(27, 'termination clause');
ok($f2['status'] === 'ok' && in_array('acme-msa.pdf', $f2['names'], true), 'a query in the full-extracted words (Contracts is full) finds acme through its extracted subjects');
$row2 = $f2['rows'][array_search('acme-msa.pdf', array_column($f2['rows'], 'name'), true)] ?? [];
ok(str_contains((string) ($row2['excerpt'] ?? ''), '<mark>'), 'the excerpt marks the query\'s words');
$f3 = find_as(27, 'zorblat quantum', ['kind' => 'pdf']);
ok(in_array($f3['status'], ['no_subjects', 'ok'], true) && $f3['names'] === [], 'words the graph never met: nothing (no_subjects or an empty ok)');
$f4 = find_as(27, 'acme-corp kind:docx');   // typed: an unknown kind is a chip that matches nothing (slice 4's rule); an unknown ?kind= is dropped
ok($f4['names'] === [], 'a kind filter nobody matches: nothing');
$f5 = find_as(27, 'acme-corp', ['kind' => 'office']);
ok($seeded($f5['names']) === ['minutes-jan.docx'], 'kind:office keeps the minutes only');
$f6 = find_as(27, 'acme-corp', ['folder' => $s['contracts']]);
ok($seeded($f6['names']) === ['acme-msa.pdf'], 'a folder filter keeps the subtree only');
$f7 = find_as(27, 'acme-corp', ['mine' => '1']);
ok(!in_array('minutes-jan.docx', $f7['names'], true) || (int) one('SELECT owner_member_id FROM documents WHERE id = :d', ['d' => $minutes]) === 27, 'mine keeps the caller\'s own');

echo "2. The log\n";
$sl = q("SELECT * FROM search_log WHERE kind = 'graph' ORDER BY id DESC LIMIT 1");
ok($sl !== [] && $sl[0]['query'] === 'acme-corp' && (int) $sl[0]['results'] >= 0, 'search_log has the graph row with the query');
find_as(27, str_repeat('acme ', 40));
$sl = q("SELECT * FROM search_log WHERE kind = 'graph' ORDER BY id DESC LIMIT 1");
ok(strlen($sl[0]['query']) <= 120, 'a long query is cut at 120 in the log');
$ev = last_event('search.graph');
$after = json_decode((string) $ev['after'], true) ?: [];
ok($ev !== null && !isset($after['query']) && isset($after['length'], $after['results']), 'search.graph carries the length and the count, never the words');
ok((int) one("SELECT calls FROM graph_usage WHERE kind = 'find' AND month = date_trunc('month', now())::date") >= 3, 'graph_usage counts the finds');

echo "3. Related documents\n";
$r = get_json(27, '/documents/' . $acme);
$g = $r['json']['data']['graph'] ?? [];
ok(($g['status'] ?? '') === 'ok' && in_array('tag:acme-corp', $g['subjects'] ?? [], true), 'the document page: in the graph with its subjects');
$rel = array_column($g['related'] ?? [], 'name');
ok(in_array('minutes-jan.docx', $rel, true) && !in_array('my-notes.md', $rel, true) && !in_array('acme-msa.pdf', $rel, true), 'related for Marco: the minutes (shared subjects), never Priya\'s notes, never itself: ' . implode(', ', $rel));
$html = page(actor(27), '/documents/' . $acme)['body'];
ok(str_contains($html, 'id="document-graph-policy"') && str_contains($html, 'id="document-related-cards"') && str_contains($html, 'id="document-related-' . $minutes . '"'), 'the page shows the policy chip and the related cards');
$r = get_json(29, '/documents/' . $ann);
ok(($r['json']['data']['graph']['status'] ?? '') === 'ok' && ($r['json']['data']['graph']['related'] ?? ['x']) === [], 'the guest\'s related: none (nothing else is hers)');
$r = get_json(27, '/documents/' . $handbook);
ok(in_array($r['json']['data']['graph']['status'] ?? '', ['ok', 'not_in_graph'], true), 'the handbook answers ok or not_in_graph');
finish();
