<?php
/** Slice 4 proof — the search (spec "Proof" 6, 7, 10): words in text, name, tag, description; a phrase; -word; the filters each; <mark>; the wall for four actors; paging and the total; took_ms logged; did you mean; try the graph; the typed modifiers (ten cases). */
require __DIR__ . '/lib.php';
$s = seed_texts();
// this proof's counts are exact: what the earlier proofs left in the scratch store (renames, tags, extra documents) is put back
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$keep = [$s['board_doc'], $s['minutes'], $s['handbook'], $s['priya_doc'], $s['design'], $s['guest_doc']];
foreach (q('SELECT id FROM documents WHERE NOT (id = ANY (:k))', ['k' => '{' . implode(',', $keep) . '}']) as $x) { pdo()->exec("SELECT dc_document_trash({$x['id']}, 27)"); pdo()->exec("SELECT dc_document_purge({$x['id']})"); }
pdo()->exec("UPDATE documents SET name = 'acme-msa.pdf', description = NULL WHERE id = {$s['board_doc']}");
pdo()->exec("DELETE FROM document_tags"); pdo()->exec("UPDATE tags SET usage_count = 0");
$acme = $s['board_doc']; $minutes = $s['minutes']; $handbook = $s['handbook']; $priya = $s['priya_doc']; $design = $s['design']; $guestDoc = $s['guest_doc'];
post_j(27, '/tags/apply.php', ['document' => $acme, 'tags' => ['contract']]);
post_j(27, '/documents/save.php', ['document' => $minutes, 'description' => 'The January board minutes, zebra edition']);
pdo()->exec("INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES ({$s['contracts']}, 'member', 28, 'view', 27) ON CONFLICT DO NOTHING");

echo "1. Where a word is found\n";
$f = search_as(27, 'Illinois');
ok($f['code'] === 200 && $f['names'] === ['acme-msa.pdf'] && $f['total'] === 1, 'a word in the text');
$f = search_as(27, 'handbook');
ok($f['names'] === ['handbook.pdf'], 'a word in the name');
$f = search_as(27, 'contract');
ok(in_array('acme-msa.pdf', $f['names'], true), 'a word that is a tag');
$f = search_as(27, 'zebra');
ok($f['names'] === ['minutes-jan.docx'], 'a word in the description');
$f = search_as(27, 'termination');
ok($f['total'] >= 4 && $f['names'][0] !== '' , 'a word in many: the total counts them');
$f = search_as(27, 'acme');
ok($f['code'] === 200 && in_array('acme-msa.pdf', $f['names'], true) && ($f['json']['data']['search']['rows'][0]['name'] ?? '') === 'acme-msa.pdf', 'ranked: the name match first');
$f = search_as(27, '"ninety days"');
ok($f['names'] === ['acme-msa.pdf'], 'a phrase');
$f = search_as(1, 'termination -acme');
ok(!in_array('acme-msa.pdf', $f['names'], true) && !in_array('minutes-jan.docx', $f['names'], true) && !in_array('handbook.pdf', $f['names'], true) && in_array('design.pdf', $f['names'], true), '-word excludes (every document naming Acme, the handbook too)');
$row = get_json(27, '/search?q=ninety')['json']['data']['search']['rows'][0] ?? [];
ok(str_contains((string) ($row['excerpt'] ?? ''), '<mark>ninety</mark>') && !str_contains((string) ($row['excerpt'] ?? ''), '<script'), 'the excerpt holds <mark> and nothing else unescaped');

echo "2. The filters\n";
$f = search_as(27, 'termination', ['library' => $s['shared_lib']]);
ok($f['names'] === ['handbook.pdf'], 'library');
$f = search_as(27, 'termination', ['folder' => $s['contracts']]);
ok($f['names'] === ['acme-msa.pdf'], 'folder subtree');
$f = search_as(1, 'termination', ['owner' => 30]);
ok($f['names'] === ['design.pdf'], 'owner');
$f = search_as(26, 'termination', ['mine' => 1]);
ok($f['names'] === ['my-notes.md'], 'mine');
$f = search_as(27, '', ['tag' => 'contract']);
ok($f['names'] === ['acme-msa.pdf'], 'tag (an empty query lists)');
$f = search_as(27, 'termination', ['kind' => 'office']);
ok($f['names'] === ['minutes-jan.docx'], 'kind');
$f = search_as(26, 'termination', ['max_bytes' => 500]);
ok($f['names'] === ['my-notes.md'], 'size (under 500 bytes: only the notes)');
$f = search_as(27, 'termination', ['after' => date('Y-m-d', strtotime('+1 day'))]);
ok($f['total'] === 0, 'after tomorrow: nothing');
$f = search_as(27, 'termination', ['before' => date('Y-m-d', strtotime('+1 day'))]);
ok($f['total'] >= 4, 'before tomorrow: everything');
$f = search_as(26, 'termination', ['shared_with_me' => 1]);
ok(!in_array('my-notes.md', $f['names'], true) && in_array('acme-msa.pdf', $f['names'], true), 'shared with me excludes my own');

echo "3. The wall\n";
$f = search_as(28, 'termination');
ok($f['names'] === ['acme-msa.pdf', 'handbook.pdf'], 'Bea (a viewer of Contracts by row): the contract and the handbook — never the minutes or Priya\'s notes');
$f = search_as(26, 'termination');
sort($f['names']);
ok($f['names'] === ['acme-msa.pdf', 'handbook.pdf', 'minutes-jan.docx', 'my-notes.md'], 'Priya (the Board): her notes, the group\'s two, the handbook — not Engineering\'s, not the guest\'s');
$f = search_as(1, 'termination');
ok(count($f['names']) === 6, 'the admin: everything (six)');
$rr = req('GET', '/search?q=x', ['jar' => actor(29)]);
ok($rr['code'] === 403, 'the guest holds no search.use: the search page is 403 (the roles\' rule; what is shared with them is on Shared with me)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok(array_column(q("SELECT name FROM dc_search('termination', '{}', 25, 0)"), 'name') === ['for-ann.pdf'], '…the function itself walls a guest to the one document shared with her (the records tool, when granted)');

echo "4. Paging, the total, the log\n";
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
for ($i = 1; $i <= 30; $i++) { seed_doc($s['board_root'], "many-$i.txt", 27, "Many termination notes number $i", 'text/plain', 40); }
$f = search_as(27, 'many');
ok($f['total'] === 30 && count($f['json']['data']['search']['rows']) === 25 && ($f['json']['data']['search']['pages'] ?? 0) === 2, '25 a page, the total 30, two pages');
$f2 = get_json(27, '/search?q=many&page=2')['json']['data']['search'] ?? [];
ok(count($f2['rows'] ?? []) === 5 && ($f2['page'] ?? 0) === 2, 'the second page holds the five');
$r = page(actor(27), '/search?q=many');
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-page-2"') && str_contains($r['body'], 'id="search-total"') && str_contains($r['body'], '30 results'), 'the page shows the pages and the total');
$long = str_repeat('longquery ', 20);
search_as(27, $long);
$log = q("SELECT * FROM search_log WHERE member_id = 27 ORDER BY id DESC LIMIT 1")[0];
ok(mb_strlen($log['query']) === 120 && $log['kind'] === 'keyword' && (int) $log['took_ms'] >= 0 && $log['source'] === 'web', 'the search log: the query cut at 120, took_ms, source web');
$ev = last_event('search.keyword');
ok($ev !== null && mb_strlen(json_decode($ev['after'], true)['query']) === 120 && isset(json_decode($ev['after'], true)['results']), 'logged search.keyword with the cut query and the count');
ok((int) one("SELECT count(*) FROM activity_log WHERE after::text LIKE '%Governing law Illinois%'") === 0, 'a document\'s text is never in a log row');

echo "5. Did you mean, try the graph\n";
$r = page(actor(27), '/search?q=handbok');
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-names"') && str_contains($r['body'], 'id="search-name-' . $handbook . '"'), 'a misspelt name: did you mean the handbook');
ok(str_contains($r['body'], 'id="search-try-graph"') && str_contains($r['body'], '/graph/?q=handbok'), 'under three results: Try the graph with the query');
$r = page(actor(27), '/search?q=termination');
ok(!str_contains($r['body'], 'id="search-try-graph"'), 'with many results: no graph link');
$r = page(actor(27), '/search');
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-box"') && str_contains($r['body'], 'autofocus') && str_contains($r['body'], 'id="search-intro"'), 'the empty search page: the box with autofocus');
$r = page(actor(27), '/search?q=Illinois&kind=pdf&mine=1');
ok(str_contains($r['body'], 'id="search-chip-kind"') && str_contains($r['body'], 'id="search-chip-mine"') && str_contains($r['body'], 'id="search-chip-remove-kind"'), 'the filters as removable chips');

echo "6. The typed modifiers (ten cases)\n";
$cases = [
    ['tag:contract acme', ['acme', ['tag' => 'contract']]],
    ['kind:pdf termination', ['termination', ['kind' => 'pdf']]],
    ['owner:me notes', ['notes', ['mine' => true]]],
    ['owner:30 design', ['design', ['owner' => 30]]],
    ['in:' . $s['contracts'] . ' acme', ['acme', ['folder' => $s['contracts']]]],
    ['in:Contracts acme', ['acme', ['folder' => $s['contracts']]]],
    ['after:2026-01-01 before:2027-01-01 x', ['x', ['after' => '2026-01-01', 'before' => '2027-01-01']]],
    ['size>1k size<2m x', ['x', ['min_bytes' => 1024, 'max_bytes' => 2097152]]],
    ['mine shared "a phrase" -not', ['"a phrase" -not', ['mine' => true, 'shared_with_me' => true]]],
    ['tag:nothing-such foo:bar x', ['foo:bar x', ['tag' => '-']]],
];
foreach ($cases as [$typed, [$q, $filters]]) {
    $p = parse_as(27, $typed);
    ksort($filters); $got = $p['filters']; ksort($got);
    ok($p['q'] === $q && $got === $filters, 'parsed: ' . $typed . ' → ' . json_encode($p));
}
finish();
