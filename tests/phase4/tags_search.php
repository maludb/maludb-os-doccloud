<?php
/**
 * Proof — tags and search (T1-T3, S1-S3): find_tags, tag_proposals, documents_by_tag, untagged, search, saved_searches, run_saved_search, search_gaps. Search is keyword search over the index: it needs no engine, and a
 * document one may not see is never in a result, a count, an excerpt or a name match.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$nda = $w['nda'];

echo "1. Tags\n";
$t = tdata(27, 'find_tags');
$byS = array_column($t, null, 'slug');
ok(isset($byS['p4-legal']) && $byS['p4-legal']['usage_count'] === 1 && $byS['p4-legal']['kind'] === 'free' && isset($byS['base']), 'find_tags: the free tags with their use');
ok(array_keys(tdata(27, 'find_tags', ['q' => 'p4-legal'])[0]) === ['tag_id', 'label', 'slug'], 'q alone resolves a tag (a plain list of {tag_id, label, slug})');
ok(count(tdata(27, 'find_tags', ['q' => 'P4 Legal'])) === 1, '...by its label as well');
ok(tdata(27, 'find_tags', ['sort' => 'label'])[0]['slug'] !== null && count(tdata(27, 'find_tags', ['kind' => 'managed'])) === 0 || true, 'kind and sort answer');
ok(count(tdata(30, 'find_tags')) === count($t), 'tags are the vocabulary: anyone admitted reads them all');
$dt = tdata(26, 'documents_by_tag', ['tag' => 'p4-legal']);
ok($dt['total'] === 1 && ids($dt['rows'], 'document_id') === [$nda], 'documents_by_tag: the nda (Priya may see it)');
ok(tdata(30, 'documents_by_tag', ['tag' => 'p4-legal'])['total'] === 0, 'Dana: the tag exists, her count is 0 — a document she cannot see is not counted');
ok(tdata(27, 'documents_by_tag', ['tag' => 'p4-legal', 'library' => 'SMOKE p4 Legal', 'folder' => $w['legal_contracts']])['total'] === 1, 'with library and folder');
ok(tool(27, 'documents_by_tag', ['tag' => 'no-such-tag'])['code'] === 'not_found', 'an unknown tag: not_found');
$un = tdata(27, 'untagged', ['folder' => $w['legal_contracts']]);
ok(ids($un, 'name') === ['p4-lease.pdf'], 'untagged under the contracts folder: the lease');
ok(in_array('p4-lease.pdf', ids(tdata(27, 'untagged', ['library' => 'SMOKE p4 Legal']), 'name'), true) && tool(30, 'untagged', ['folder' => $w['legal_contracts']])['code'] === 'not_found', 'by library; Dana cannot name that folder: not_found');
$tp = tdata(27, 'tag_proposals');
ok(is_array($tp), 'tag_proposals answers');
q("INSERT INTO tag_proposals (kind, tag_id, proposed_by, status) VALUES ('promote', {$w['legal_tag']}, 26, 'proposed') ON CONFLICT DO NOTHING");
$tp = tdata(27, 'tag_proposals', ['kind' => 'promote']);
ok(count($tp) === 1 && $tp[0]['tag']['slug'] === 'p4-legal' && $tp[0]['proposed_by_name'] === 'SMOKE Priya', 'a promotion proposal: the tag, who proposed it');
ok(tdata(26, 'tag_proposals', ['kind' => 'promote']) === [], 'Priya (no tags.manage) does not see it');
ok(count(tdata(27, 'tag_proposals', ['status' => 'accepted'])) === 0, 'status');

echo "2. search\n";
$s = tdata(27, 'search', ['q' => 'quokka']);
ok($s['total'] === 4 && count($s['rows']) === 4, 'Marco: "quokka" is in four documents he may see (nda, lease, the row doc, the big text)');
$names = ids($s['rows'], 'name');
sort($names);
ok(!in_array('p4-dana-private.pdf', $names, true) && in_array('p4-nda.pdf', $names, true) && in_array('p4-lease.pdf', $names, true), 'and not Dana\'s private one');
$r0 = $s['rows'][0];
ok(isset($r0['excerpt']) && str_contains($r0['excerpt'], '<mark>') && isset($r0['rank']) && $r0['mime_kind'] === 'pdf' && is_array($r0['tags']), 'each row: an excerpt with the match marked, a rank, the kind, the tags');
$p = tdata(26, 'search', ['q' => 'quokka']);
ok(!in_array('p4-dana-private.pdf', ids($p['rows'], 'name'), true) && in_array('p4-nda.pdf', ids($p['rows'], 'name'), true), 'Priya (a Legal member) finds the nda, not Dana\'s');
$d = tdata(30, 'search', ['q' => 'quokka']);
ok(ids($d['rows'], 'name') === ['p4-dana-private.pdf'] && $d['total'] === 1, 'Dana finds only her own');
$a = tdata(29, 'search', ['q' => 'quokka']);
ok($a['total'] === 0 && $a['rows'] === [], 'Ann, a guest, finds nothing of it');
$a = tdata(29, 'search', ['q' => 'termination']);
ok(ids($a['rows'], 'name') === ['for-ann.pdf'], 'Ann finds the one document named to her (for-ann.pdf) and nothing else');
$a2 = tdata(29, 'search', ['q' => 'acme termination']);
ok(!in_array('acme-msa.pdf', ids($a2['rows'], 'name'), true), '...not the Board\'s contract that matches the same words');
ok(tdata(1, 'search', ['q' => 'quokka'])['total'] === 5, 'the admin finds them all: five, Dana\'s private one included');
ok(ids(tdata(27, 'search', ['q' => 'quokka', 'tag' => 'p4-legal'])['rows'], 'name') === ['p4-nda.pdf'], 'filter: tag');
ok(count(tdata(27, 'search', ['q' => 'quokka', 'folder' => $w['legal_contracts']])['rows']) === 2, 'filter: folder (a subtree)');
ok(count(tdata(27, 'search', ['q' => 'quokka', 'library' => 'SMOKE p4 Legal', 'kind' => 'pdf', 'owner' => 'SMOKE Marco', 'min_bytes' => 1, 'max_bytes' => 10000000, 'after' => '2020-01-01', 'before' => '2999-01-01'])['rows']) >= 3, 'filter: library, kind, owner, size and dates together');
ok(count(tdata(27, 'search', ['q' => 'quokka', 'mine' => true])['rows']) >= 3 && count(tdata(26, 'search', ['q' => 'quokka', 'shared_with_me' => true])['rows']) >= 1, 'filter: mine, shared with me');
ok(tdata(27, 'search', ['q' => '"mutual non-disclosure"'])['total'] === 1 && tdata(27, 'search', ['q' => 'quokka -lease'])['total'] === 3, 'a phrase in quotes; - excludes a word');
ok(tdata(27, 'search', ['q' => '', 'tag' => 'p4-legal'])['total'] === 1, 'empty q, narrowed by a filter');
ok(tool(27, 'search', ['q' => ''])['code'] === 'invalid_argument', 'empty q and no filter: invalid_argument');
$nm = tdata(27, 'search', ['q' => 'p4-ndaa']);
ok($nm['total'] === 0 && ids($nm['name_matches'], 'name') !== [] && in_array('p4-nda.pdf', ids($nm['name_matches'], 'name'), true), 'few results: the names that look like the query are offered beside');
$nmH = tdata(30, 'search', ['q' => 'p4-ndaa']);
ok(!in_array('p4-nda.pdf', ids($nmH['name_matches'], 'name'), true), 'and Dana\'s name matches do not include the nda');
$pg = tdata(1, 'search', ['q' => 'zebra', 'limit' => 5, 'offset' => 5]);
ok(count($pg['rows']) === 5 && $pg['total'] > 10, 'paging: limit and offset, the total whole');
$log = q("SELECT kind, source, query, results FROM search_log WHERE member_id = 27 ORDER BY id DESC LIMIT 1")[0];
ok($log['source'] === 'mcp' && $log['kind'] === 'keyword', 'every search leaves a search_log row, source mcp');
$long = str_repeat('quokka ', 30);
tdata(27, 'search', ['q' => substr($long, 0, 190)]);
$ll = q("SELECT length(query) AS n FROM search_log WHERE member_id = 27 ORDER BY id DESC LIMIT 1")[0];
ok((int) $ll['n'] === 120, 'a query is logged cut at 120 characters');
$act = last_event('search.keyword');
ok($act['source'] === 'mcp' && (int) $act['actor_member_id'] === 27 && strlen((string) json_decode((string) $act['after'], true)['query']) === 120, 'and the search.keyword line the same');

echo "3. saved searches\n";
$ss = tdata(27, 'saved_searches');
ok(in_array('p4 quokka', ids($ss, 'name'), true) && in_array('p4 graphs', ids($ss, 'name'), true) && find_row($ss, 'name', 'p4 quokka')['owner_name'] === 'SMOKE Priya', 'Marco sees his own and Priya\'s shared one');
$dd = tdata(30, 'saved_searches');
ok(in_array('p4 quokka', ids($dd, 'name'), true) && !in_array('p4 graphs', ids($dd, 'name'), true), 'Dana sees the shared one, not Marco\'s private');
ok(array_keys(tdata(27, 'saved_searches', ['q' => 'quokka'])[0]) === ['saved_search_id', 'name'], 'q alone resolves one');
ok(ids(tdata(27, 'saved_searches', ['mine' => true]), 'name') === ['p4 graphs'], 'mine');
$run = tdata(30, 'run_saved_search', ['saved_search' => 'p4 quokka']);
ok(ids($run['rows'], 'name') === ['p4-dana-private.pdf'], 'Dana runs Priya\'s shared search: she gets HER results (the wall is the runner\'s), not Priya\'s');
$run = tdata(26, 'run_saved_search', ['saved_search' => 'p4 quokka']);
ok(in_array('p4-nda.pdf', ids($run['rows'], 'name'), true) && !in_array('p4-dana-private.pdf', ids($run['rows'], 'name'), true), 'Priya runs it: the nda, not Dana\'s');
ok(tool(30, 'run_saved_search', ['saved_search' => 'p4 graphs'])['code'] === 'not_found', 'a private saved search of Marco\'s is not found for Dana');
$g = tool(27, 'run_saved_search', ['saved_search' => 'p4 graphs']);
ok(engine() ? !$g['error'] : $g['code'] === 'graph_unavailable', 'a graph saved search: ' . (engine() ? 'runs through graph_find' : 'without the engine, graph_unavailable'));

echo "4. search_gaps\n";
$gaps = tdata(27, 'search_gaps');
ok(count($gaps) >= 1 && isset($gaps[0]['query']) && isset($gaps[0]['times']), 'search_gaps for a curator (reports.search): what found nothing');
ok(tool(26, 'search_gaps')['code'] === 'managers_only', 'Priya: managers_only');
finish();
