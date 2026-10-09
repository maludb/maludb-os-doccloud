<?php
/** Slice 5 proof — the explorer (spec "Proof" 11): subjects by degree filtered to the caller's documents, a subject page with its documents through the wall, a path, surprises empty with the M6 sentence; the subject picker. */
require __DIR__ . '/lib.php';
$s = seed_graph();
if (!engine()) {
    echo "1. Without the engine\n";
    $r = get_json(27, '/graph/explore');
    ok($r['code'] === 200 && ($r['json']['data']['available'] ?? true) === false && ($r['json']['data']['subjects'] ?? ['x']) === [], 'explore answers unavailable with no subjects');
    $html = page(actor(27), '/graph/explore')['body'];
    ok(str_contains($html, 'id="graph-unavailable"') && str_contains($html, 'id="graph-subjects-status"'), 'the page says so');
    $r = get_json(27, '/graph/subjects/1');
    ok($r['code'] === 200 && ($r['json']['data']['subject']['status'] ?? '') === 'graph_unavailable', 'a subject page: graph_unavailable');
    $r = get_json(27, '/graph/path?from=1&to=2');
    ok($r['code'] === 200 && ($r['json']['data']['status'] ?? '') === 'graph_unavailable', 'a path: graph_unavailable');
    $r = page(actor(27), '/pick/?source=subject&q=acme');
    ok($r['code'] === 200 && !str_contains($r['body'], 'picker-row'), 'the subject picker answers no rows');
    finish();
}
graph_ready();
run_graph();
echo "1. Subjects by degree\n";
$r = get_json(27, '/graph/explore');
$names = array_column($r['json']['data']['subjects'] ?? [], 'name');
ok($r['code'] === 200 && ($r['json']['data']['status'] ?? '') === 'ok' && in_array('tag:acme-corp', $names, true), 'Marco sees tag:acme-corp among the subjects by degree: ' . implode(', ', array_slice($names, 0, 6)));
$tag = null; foreach ($r['json']['data']['subjects'] as $x) { if ($x['name'] === 'tag:acme-corp') { $tag = $x; } }
ok($tag !== null && (int) $tag['documents_visible'] === 3, 'tag:acme-corp counts the three documents Marco may see (of four)');
$r28 = get_json(28, '/graph/explore');
$n28 = array_column($r28['json']['data']['subjects'] ?? [], 'name');
ok(!in_array('tag:acme-corp', $n28, true) && !in_array('folder:Contracts', $n28, true), 'Bea sees neither tag:acme-corp nor folder:Contracts (no document of theirs is hers)');
ok(in_array('library:SMOKE Policies', $n28, true) || in_array('doc:' . $s['handbook'], $n28, true) || $n28 === [], 'Bea\'s subjects are the handbook\'s, if any: ' . implode(', ', $n28));
$html = page(actor(27), '/graph/explore')['body'];
ok(str_contains($html, 'id="graph-subjects"') && str_contains($html, 'id="graph-surprises"') && str_contains($html, 'M6'), 'the page: the subjects list and the surprises card with the M6 sentence');

echo "2. A subject page and its documents through the wall\n";
$sid = engine_subject_id('tag:acme-corp');
ok($sid !== null && $sid > 0, 'the subject tag:acme-corp has an engine id');
$r = get_json(27, '/graph/subjects/' . $sid);
$sp = $r['json']['data']['subject'] ?? [];
$docs = array_column($sp['documents'] ?? [], 'name'); sort($docs);
ok($r['code'] === 200 && ($sp['status'] ?? '') === 'ok' && $sp['name'] === 'tag:acme-corp' && $docs === ['acme-msa.pdf', 'for-ann.pdf', 'minutes-jan.docx'] && (int) $sp['documents_hidden'] === 1, 'Marco: its documents are his three, one hidden');
$r = get_json(29, '/graph/subjects/' . $sid);
ok($r['code'] === 403, 'the guest holds no graph.use: the subject page is 403 (the roles\' rule, as for /graph and /search)');
engine_php();
$subjDocs = dc_graph_subject($sid)['subject']['documents'] ?? [];
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
$c29 = json_encode(array_map(static fn (array $d): array => ['maludb_document_id' => (string) $d['id'], 'similarity' => 1.0, 'subject' => 'tag:acme-corp'], $subjDocs));
ok(array_column(q('SELECT name FROM dc_graph_filter(CAST(:c AS jsonb), 50) ORDER BY name', ['c' => $c29]), 'name') === ['for-ann.pdf'], '…the wall over the subject\'s documents gives the guest for-ann only');
$html = page(actor(27), '/graph/subjects/' . $sid)['body'];
ok(str_contains($html, 'id="subject-name"') && str_contains($html, 'id="graph-svg"') && str_contains($html, 'id="graph-svg-data"') && str_contains($html, 'id="subject-find-btn"') && str_contains($html, 'id="subject-path-btn"'), 'the page: the name, the SVG data, find and path buttons');
ok(count($sp['neighbour_subjects'] ?? []) <= 40, 'the neighbourhood is capped at 40');
$r = get_json(27, '/graph/subjects/99999999');
ok($r['code'] === 404 || ($r['json']['data']['subject']['status'] ?? '') === 'not_found', 'an unknown subject: not found');

echo "3. A path and the picker\n";
$sid2 = engine_subject_id('folder:Contracts');
$r = get_json(27, '/graph/path?from=' . $sid . '&to=' . $sid2);
$steps = $r['json']['data']['steps'] ?? [];
ok($r['code'] === 200 && ($r['json']['data']['status'] ?? '') === 'ok' && ($r['json']['data']['found'] ?? false) === true && count($steps) >= 2, 'a path between tag:acme-corp and folder:Contracts: ' . count($steps) . ' steps');
$docSteps = array_filter($steps, static fn (array $x): bool => $x['kind'] === 'document');
ok($docSteps === [] || array_reduce($docSteps, static fn ($c, $x) => $c && (($x['visible'] ?? false) ? ($x['name'] !== 'a document you cannot see') : ($x['name'] === 'a document you cannot see')), true), 'document nodes on the path are judged (visible by name, else "a document you cannot see")');
$html = page(actor(27), '/graph/path?from=' . $sid . '&to=' . $sid2)['body'];
ok(str_contains($html, 'id="graph-path-steps"') && str_contains($html, 'id="graph-path-from"') && str_contains($html, 'id="graph-path-to"'), 'the page: the chain and the two pickers');
$r = page(actor(27), '/pick/?source=subject&q=acme');
ok($r['code'] === 200 && str_contains($r['body'], 'tag:acme-corp'), 'the subject picker finds tag:acme-corp by "acme"');
$r = page(actor(29), '/pick/?source=subject&q=acme');
ok($r['code'] === 403 && !str_contains($r['body'], 'tag:acme-corp'), 'the guest may not use the subject picker (graph.use is not hers): 403 in words, no subject named');
finish();
