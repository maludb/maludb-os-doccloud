<?php
declare(strict_types=1);

/**
 * The DOCUMENT GRAPH proof — drives ONLY app/doccloud/graph.php against the real MaluDB API on a scratch memory database,
 * with a fake model provider (fake_provider.py: a canned candidate_edges extraction; embeddings are the API's own fallback
 * when no embedder is configured — sha256 hash vectors, deterministic, NOT semantic; so similarity ranks are not judged here).
 * Run by tests/phase0/engine.sh, which sets DC_PROOF_API_URL, DC_PROOF_FAKE_URL, DC_PROOF_TOKEN, DC_PROOF_DB, DC_PROOF_ROLE.
 * Synthetic text only. Prints PASS/FAIL/NOTE lines; the NOTEs are the facts FINDINGS.md records.
 */

require __DIR__ . '/../../../app/doccloud/graph.php';

$API = getenv('DC_PROOF_API_URL') ?: exit("DC_PROOF_API_URL missing\n");
$FAKE = getenv('DC_PROOF_FAKE_URL') ?: exit("DC_PROOF_FAKE_URL missing\n");
$TOKEN = getenv('DC_PROOF_TOKEN') ?: exit("DC_PROOF_TOKEN missing\n");
$PROVIDER_SECRET = 'fake-provider-credential-' . bin2hex(random_bytes(6));

$pass = 0;
$fail = 0;
$ALL = [];

function check(string $name, bool $ok, mixed $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS $name\n";
    } else {
        $fail++;
        echo "FAIL $name  -- " . mb_substr(is_string($detail) ? $detail : (string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 700) . "\n";
    }
}
function note(string $text): void
{
    echo "NOTE $text\n";
}
/** Keeps every result for the final token scan. */
function r(array $result): array
{
    global $ALL;
    $ALL[] = $result;
    return $result;
}
function use_graph(?string $url, ?string $token, ?string $timeout = null): void
{
    putenv('MALUDB_DC_API_URL' . ($url === null ? '' : '=' . $url));
    putenv('MALUDB_DC_API_TOKEN' . ($token === null ? '' : '=' . $token));
    putenv('MALUDB_DC_API_TIMEOUT' . ($timeout === null ? '' : '=' . $timeout));
}
function fake(string $path, string $method = 'GET'): array
{
    global $FAKE;
    $ch = curl_init($FAKE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $raw, true) ?: [];
}
/** A raw call to the API (the proof's own eyes, to record what a route returns — graph.php is what is judged). */
function raw(string $method, string $path, ?array $body = null): array
{
    global $API, $TOKEN;
    $ch = curl_init($API . $path);
    $h = ['Authorization: Bearer ' . $TOKEN, 'Accept: application/json'];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['http' => $http, 'data' => json_decode((string) $raw, true) ?: []];
}
function mids(array $candidates): array
{
    $m = array_values(array_unique(array_map(static fn($c) => $c['maludb_document_id'], $candidates)));
    sort($m);
    return $m;
}

// =====================================================================================================================
echo "-- no configuration: graph_unavailable from every function, nothing thrown\n";
use_graph(null, null);
check('no URL/token: dc_graph_available() is false', dc_graph_available() === false);
$doc = ['id' => 11, 'name' => 'Acme master services agreement.pdf'];
foreach ([
    'index' => r(dc_graph_index_document($doc, 'Some text.', ['contracts'], ['document_id' => 11])),
    'full' => r(dc_graph_full_document($doc, 'Some text.', ['contracts'], ['document_id' => 11])),
    'forget' => r(dc_graph_forget('5')),
    'document' => r(dc_graph_document('5')),
    'documents' => r(dc_graph_documents()),
    'resolve' => r(dc_graph_resolve_subjects('acme contract')),
    'candidates' => r(dc_graph_candidates(['acme'], 'acme contract')),
    'neighbors' => r(dc_graph_neighbors('subject', 1)),
    'path' => r(dc_graph_path(['kind' => 'subject', 'id' => 1], ['kind' => 'subject', 'id' => 2])),
    'god_nodes' => r(dc_graph_god_nodes()),
    'surprises' => r(dc_graph_surprises()),
    'stats' => r(dc_graph_stats()),
    'config_get' => r(dc_graph_config_get()),
    'config_set' => r(dc_graph_config_set(['provider_name' => 'p', 'provider_kind' => 'local_http', 'model_alias' => 'a', 'model_identifier' => 'm', 'base_url' => 'http://x', 'embedding_model' => 'e'])),
    'subject' => r(dc_graph_subject(1)),
] as $fn => $res) {
    check("no URL/token: $fn answers graph_unavailable", ($res['status'] ?? '') === 'graph_unavailable', $res);
}
use_graph($API, null);
check('a URL and no token: graph_unavailable', r(dc_graph_stats())['status'] === 'graph_unavailable');
use_graph(null, $TOKEN);
check('a token and no URL: graph_unavailable', r(dc_graph_stats())['status'] === 'graph_unavailable');
check('dc_graph_error_message gives user-safe words', str_contains(dc_graph_error_message('graph_unavailable'), 'Keyword search still works') && dc_graph_error_message('nonsense') !== '');

echo "-- argument validation (no call is made)\n";
use_graph($API, $TOKEN);
$before = fake('/calls');
foreach ([
    ['index: no name', dc_graph_index_document(['id' => 1, 'name' => ''], 'x', ['a'])],
    ['index: no subjects', dc_graph_index_document($doc, 'x', [])],
    ['full: no text', dc_graph_full_document($doc, '   ', ['a'])],
    ['forget: a bad id', dc_graph_forget('abc')],
    ['resolve: an empty query', dc_graph_resolve_subjects('   ')],
    ['candidates: no subjects', dc_graph_candidates([], 'q')],
    ['neighbors: a bad kind', dc_graph_neighbors('Sub ject', 1)],
    ['path: a bad node', dc_graph_path(['kind' => 'subject'], ['kind' => 'subject', 'id' => 2])],
    ['config_set: template without {{chunk}}', dc_graph_config_set(['provider_name' => 'p', 'provider_kind' => 'local_http', 'model_alias' => 'a', 'model_identifier' => 'm', 'base_url' => 'http://x', 'embedding_model' => 'e', 'prompt_template' => 'no placeholder'])],
    ['config_set: missing base_url', dc_graph_config_set(['provider_name' => 'p', 'provider_kind' => 'local_http', 'model_alias' => 'a', 'model_identifier' => 'm', 'embedding_model' => 'e'])],
] as [$label, $res]) {
    r($res);
    check("$label -> invalid_argument", $res['status'] === 'invalid_argument', $res);
}
check('validation made no model call', fake('/calls') == $before, fake('/calls'));

// =====================================================================================================================
echo "-- availability and the namespace configuration\n";
check('configured: dc_graph_available() is true', dc_graph_available() === true);
$c = r(dc_graph_config_get());
check('config_get before any set answers ok with a null or default config', $c['status'] === 'ok', $c);
$tpl = "DOCCLOUD document graph — extraction\nSUBJECT TYPE: other\n"
    . "Return ONLY JSON of the form {\"candidate_edges\":[{\"subject_text\":\"\",\"subject_type\":\"\",\"verb_text\":\"\",\"predicate\":[],\"source_span\":\"\",\"confidence\":0.0}]}.\n\nText:\n{{chunk}}";
$set = r(dc_graph_config_set(['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model',
    'base_url' => $FAKE . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'doccloud_provider', 'token' => $PROVIDER_SECRET, 'prompt_template' => $tpl]));
check('the dc namespace is configured with the fake provider and the extraction prompt', $set['status'] === 'ok' && ($set['config']['namespace'] ?? '') === 'dc' && str_contains($set['config']['prompt_template'] ?? '', 'SUBJECT TYPE: other'), $set);
check('the configuration names the secret, never the credential', ($set['config']['secret_ref'] ?? '') === 'doccloud_provider' && !str_contains((string) json_encode($set), $PROVIDER_SECRET), $set);
$c = r(dc_graph_config_get());
check('config_get answers the same', $c['status'] === 'ok' && ($c['config']['namespace'] ?? '') === 'dc' && ($c['config']['embedding_model'] ?? '') === 'maludb-local-dev', $c);

// =====================================================================================================================
echo "-- the index policy (remember: no model call)\n";
fake('/reset', 'POST');
$textA = "Acme Corporation master services agreement. Acme Corporation provides consulting services to the Business. The agreement runs for three years and renews yearly. Termination needs ninety days of notice.";
$textB = "Acme Corporation invoice 2026-0912 for consulting services in September. The amount due is twelve thousand dollars. Payment terms are thirty days.";
$textC = "Office lease with Zorblat Properties for the Downtown office. The lease runs for five years. Rent is reviewed yearly.";
$docA = ['id' => 101, 'name' => 'Acme master services agreement.pdf'];
$docB = ['id' => 102, 'name' => 'Acme invoice 2026-0912.pdf'];
$docC = ['id' => 103, 'name' => 'Office lease Zorblat.docx'];
$subjA = ['contracts', 'acme', 'q3-2026', 'Finance › Contracts', 'Priya', 'pdf', '2026', 'doc:101'];
$subjB = ['invoices', 'acme', 'q3-2026', 'Finance › Invoices', 'Priya', 'pdf', '2026', 'doc:102'];
$subjC = ['contracts', 'zorblat', 'leases', 'Finance › Contracts', 'Marco', 'docx', 'doc:103', 'downtown', 'extra-ninth-tag'];
$iA = r(dc_graph_index_document($docA, $textA, $subjA, ['document_id' => 101, 'library_id' => 3, 'version_id' => 1001, 'folder_id' => 12]));
check('document A indexed under eight subjects: indexed, a MaluDB document id, statements > 0', $iA['status'] === 'indexed' && ctype_digit((string) ($iA['maludb_document_id'] ?? '0')) && $iA['statements'] > 0 && $iA['subjects_dropped'] === [], $iA);
$iB = r(dc_graph_index_document($docB, $textB, $subjB, ['document_id' => 102, 'library_id' => 3, 'version_id' => 1002, 'folder_id' => 13]));
check('document B indexed', $iB['status'] === 'indexed' && ctype_digit((string) ($iB['maludb_document_id'] ?? '0')), $iB);
$iC = r(dc_graph_index_document($docC, $textC, $subjC, ['document_id' => 103, 'library_id' => 3, 'version_id' => 1003, 'folder_id' => 12]));
check('document C with nine subjects: indexed under eight, the ninth named in subjects_dropped (M12)', $iC['status'] === 'indexed' && $iC['subjects_dropped'] === ['extra-ninth-tag'] && count($iC['subjects']) === 8, $iC);
note("remember answers: chunk_count=" . ($iA['chunk_count'] ?? '?') . ", statements=" . ($iA['statements'] ?? '?') . " for a " . mb_strlen($textA) . "-char text under 8 subjects (one edge per subject × chunk)");
check('the index policy made no model call', fake('/calls')['extract'] === 0, fake('/calls'));
$MAP = [($iA['maludb_document_id'] ?? '0') => 101, ($iB['maludb_document_id'] ?? '0') => 102, ($iC['maludb_document_id'] ?? '0') => 103];
$rawA = raw('GET', '/v1/documents/' . ($iA['maludb_document_id'] ?? '0'));
$dA = $rawA['data']['document'] ?? [];
check('GET /v1/documents/{id} answers the document with source_type document (the controlled vocabulary; dc was refused)', $rawA['http'] === 200 && ($dA['source_type'] ?? '') === 'document', $rawA);
note('GET /v1/documents/{id} fields: ' . implode(', ', array_keys($dA)) . ' — metadata ' . (array_key_exists('metadata', $dA) ? 'PRESENT' : 'ABSENT') . '; document_type of a remembered document = ' . var_export($dA['document_type'] ?? null, true));
$g = r(dc_graph_document(($iA['maludb_document_id'] ?? '0')));
check('dc_graph_document answers it too', $g['status'] === 'ok' && ($g['document']['title'] ?? '') === $docA['name'], $g);
$big = r(dc_graph_index_document(['id' => 104, 'name' => 'Big.txt'], str_repeat('Word ', 30000), ['doc:104'], ['document_id' => 104]));
check('a long text is cut at DC_GRAPH_TEXT_CHARS (20,000) before remember', $big['status'] === 'indexed' && $big['text_chars'] === 20000, $big);
r(dc_graph_forget(($big['maludb_document_id'] ?? '0')));

// =====================================================================================================================
echo "-- the full policy (documents: the model extracts)\n";
$textD = "Acme Corporation signed a termination clause with the Business. Zorblat Properties owns the Downtown office. Priya Nair manages the contract.";
$docD = ['id' => 105, 'name' => 'Contract summary.docx'];
$fD = r(dc_graph_full_document($docD, $textD, ['contracts', 'doc:105'], ['document_id' => 105, 'library_id' => 3, 'version_id' => 1005, 'folder_id' => 12]));
check('document D extracted: a MaluDB document id, chunk_count 1, edges from the model', $fD['status'] === 'extracted' && ctype_digit((string) ($fD['maludb_document_id'] ?? '0')) && $fD['chunk_count'] === 1 && $fD['edges_count'] >= 3, $fD);
check('...with one model call (one chunk)', fake('/calls')['extract'] === 1, fake('/calls'));
$last = fake('/last');
check('...through the dc namespace\'s own prompt', str_contains($last['extract']['user'] ?? '', 'SUBJECT TYPE: other') && str_contains($last['extract']['user'] ?? '', 'Acme Corporation signed'), $last);
$MAP[($fD['maludb_document_id'] ?? '0')] = 105;
$rawD = raw('GET', '/v1/documents/' . ($fD['maludb_document_id'] ?? '0'));
note('a documents-route document: document_type=' . var_export($rawD['data']['document']['document_type'] ?? null, true) . ', source_type=' . var_export($rawD['data']['document']['source_type'] ?? null, true) . ', tags=' . json_encode($rawD['data']['document']['tags'] ?? null));
note('usage on the documents route: ' . (($fD['usage'] ?? null) === null ? 'ABSENT (M2)' : json_encode($fD['usage'])));
note('subject types are a CONTROLLED vocabulary (malu\$svpor_subject_type: project, person, ai_agent, equipment, software, network, event, process, workflow, time_period, other, stakeholder, concept): an extraction naming another type fails 422 AFTER the model was paid — the prompt must name registered types');
$fE = r(dc_graph_full_document(['id' => 106, 'name' => 'nothing.txt'], 'lowercase words that name nothing at all here.', ['doc:106'], ['document_id' => 106]));
check('a text the model finds nothing in answers no_edges (not an error, no document kept)', $fE['status'] === 'no_edges' && $fE['maludb_document_id'] === null, $fE);
$fF = r(dc_graph_full_document(['id' => 107, 'name' => 'bad.txt'], 'BADJSON Acme Corporation signed it.', ['doc:107'], ['document_id' => 107]));
check('a provider answering nonsense is model_failed (or no_edges), never a throw', in_array($fF['status'], ['model_failed', 'no_edges', 'upstream_error'], true), $fF);
note("the API's own code for provider nonsense: " . json_encode(['status' => $fF['status'], 'http' => $fF['http'], 'engine_code' => $fF['engine_code'] ?? null]));

// =====================================================================================================================
echo "-- listing the graph's documents\n";
$list = r(dc_graph_documents(50));
$listIds = array_map(static fn($d) => (string) ($d['id'] ?? ''), $list['documents']);
sort($listIds);
check('GET /v1/documents?source_type=eq.document lists the four (A, B, C, D) — the memory database is DocCloud\'s alone', $list['status'] === 'ok' && count(array_intersect($listIds, array_keys($MAP))) === 4, [$listIds, array_keys($MAP)]);
note('GET /v1/documents list row fields: ' . implode(', ', array_keys($list['documents'][0] ?? [])));

// =====================================================================================================================
echo "-- finding: subjects, candidates, the map back to DocCloud's documents\n";
$rs = r(dc_graph_resolve_subjects('acme contract', 8));
$names = array_map(static fn($s) => mb_strtolower($s['name']), $rs['subjects']);
check('resolve_subjects("acme contract") names acme and contracts', $rs['status'] === 'ok' && in_array('acme', $names, true) && in_array('contracts', $names, true), $rs);
$viaTerms = array_filter($rs['subjects'], static fn($s) => $s['via'] === 'terms');
check('...the term-matched subjects carry a subject_id (from graph/query seeds)', count($viaTerms) >= 1 && array_sum(array_map(static fn($s) => (int) ($s['subject_id'] !== null), $viaTerms)) === count($viaTerms), $rs);
note('graph/query seeds for "acme contract": ' . json_encode(array_map(static fn($s) => [$s['name'], $s['via'], $s['score']], $rs['subjects'])));
$rawQ = raw('GET', '/v1/graph/query?q=acme%20contract&namespace=dc');
note('graph/query with namespace=dc (a NAME prefix): seeds=' . count($rawQ['data']['seeds'] ?? []) . ' — ' . (count($rawQ['data']['seeds'] ?? []) === 0 ? 'the memory namespace is NOT a filter there (confirmed)' : 'it matched something'));
$rawR = raw('POST', '/v1/memory/recall', ['query' => 'acme contract', 'namespaces' => ['dc'], 'limit' => 5]);
note('recall with no subject proposes: ' . json_encode($rawR['data']['subjects_proposed'] ?? null) . '; result fields: ' . implode(', ', array_keys($rawR['data']['results'][0] ?? [])));

$cand = r(dc_graph_candidates($rs['subjects'], 'acme contract', 50));
check('candidates gathered for the resolved subjects', $cand['status'] === 'ok' && count($cand['candidates']) >= 2, $cand);
$got = mids($cand['candidates']);
$wantAB = [($iA['maludb_document_id'] ?? '0'), ($iB['maludb_document_id'] ?? '0')];
sort($wantAB);
check('...A and B (acme) and C (contracts) are among them, each a MaluDB document id', count(array_intersect($got, [($iA['maludb_document_id'] ?? '0'), ($iB['maludb_document_id'] ?? '0'), ($iC['maludb_document_id'] ?? '0')])) === 3, $got);
check('...every candidate maps back to a DocCloud document through the maludb_document_id map (graph_documents)', count(array_diff($got, array_keys($MAP))) === 0, array_diff($got, array_keys($MAP)));
check('...document_id is NULL on every candidate (no search route returns metadata — the wall maps and judges in SQL)', array_sum(array_map(static fn($c) => (int) ($c['document_id'] !== null), $cand['candidates'])) === 0);
$viaSearch = array_filter($cand['candidates'], static fn($c) => $c['via'] === 'search');
$viaNb = array_filter($cand['candidates'], static fn($c) => $c['via'] === 'neighbors');
check('...the passages come from memory/search (with chunk text and a similarity) and the edges from graph/neighbors', count($viaSearch) >= 1 && count($viaNb) >= 1 && ($viaSearch[array_key_first($viaSearch)]['chunk_text'] ?? '') !== '', [count($viaSearch), count($viaNb)]);
$rawS = raw('POST', '/v1/memory/search', ['query' => 'acme contract', 'subject' => 'acme', 'namespace' => 'dc', 'limit' => 10]);
note('memory/search result fields: ' . implode(', ', array_keys($rawS['data']['results'][0] ?? [])) . ' — metadata ' . (array_key_exists('metadata', $rawS['data']['results'][0] ?? []) ? 'PRESENT' : 'ABSENT') . '; document_id ' . (array_key_exists('document_id', $rawS['data']['results'][0] ?? []) ? 'PRESENT' : 'ABSENT'));
$rawS2 = raw('POST', '/v1/memory/search', ['query' => 'acme contract', 'namespace' => 'dc', 'limit' => 10]);
note('memory/search without a subject or verb: HTTP ' . $rawS2['http'] . ' ' . json_encode($rawS2['data']['error']['code'] ?? $rawS2['data']['code'] ?? $rawS2['data']['detail'] ?? null));
$rawDocIds = raw('POST', '/v1/memory/search', ['query' => 'acme contract', 'subject' => 'acme', 'namespace' => 'dc', 'limit' => 10, 'document_ids' => [(int) ($iA['maludb_document_id'] ?? '0')]]);
$n2 = count($rawDocIds['data']['results'] ?? []);
$n1 = count($rawS['data']['results'] ?? []);
note("memory/search with a document_ids filter (M10): " . ($rawDocIds['http'] === 200 && $n2 < $n1 ? 'HONOURED' : "IGNORED (http {$rawDocIds['http']}, {$n2} of {$n1} rows) — the wall over-fetches until M10"));

$byDoc = r(dc_graph_candidates([['name' => 'doc:102']], 'invoice', 10));
check('the document\'s own subject (doc:102) finds exactly B', $byDoc['status'] === 'ok' && mids($byDoc['candidates']) === [($iB['maludb_document_id'] ?? '0')], $byDoc);
$none = r(dc_graph_candidates(['no-such-subject-anywhere'], 'anything', 10));
check('an unknown subject answers ok with no candidates (never an error)', $none['status'] === 'ok' && $none['candidates'] === [], $none);

// =====================================================================================================================
echo "-- forgetting\n";
$fg = r(dc_graph_forget(($iB['maludb_document_id'] ?? '0')));
check('forget B answers forgotten', $fg['status'] === 'forgotten', $fg);
$cand2 = r(dc_graph_candidates([['name' => 'acme']], 'acme contract', 50));
check('B is gone from the candidates; A remains', !in_array(($iB['maludb_document_id'] ?? '0'), mids($cand2['candidates']), true) && in_array(($iA['maludb_document_id'] ?? '0'), mids($cand2['candidates']), true), mids($cand2['candidates']));
check('dc_graph_document(B) is not_found', r(dc_graph_document(($iB['maludb_document_id'] ?? '0')))['status'] === 'not_found');
check('forgetting B again answers missing', r(dc_graph_forget(($iB['maludb_document_id'] ?? '0')))['status'] === 'missing');
$sub = dc_graph_subject_id('acme');
check('the subject acme still exists after its document is forgotten (subjects are tenant-wide vocabulary)', $sub !== null, $sub);

// =====================================================================================================================
echo "-- the explorer\n";
$acme = dc_graph_subject_id('acme');
$contracts = dc_graph_subject_id('contracts');
check('subject ids resolve by exact name', $acme !== null && $contracts !== null, [$acme, $contracts]);
$nb = r(dc_graph_neighbors('subject', (int) $acme));
$docNb = array_filter($nb['neighbors'] ?? [], static fn($n) => ($n['neighbor_kind'] ?? '') === 'document');
check('neighbors of acme: its remaining document(s), kind document', $nb['status'] === 'ok' && count($docNb) >= 1 && !in_array((string) ($iB['maludb_document_id'] ?? '0'), array_map(static fn($n) => (string) $n['neighbor_id'], $docNb), true), $nb);
note('neighbors row fields: ' . implode(', ', array_keys($nb['neighbors'][0] ?? [])) . '; rows: ' . json_encode(array_map(static fn($n) => [$n['neighbor_kind'] ?? null, $n['neighbor_id'] ?? null, $n['rel'] ?? null], $nb['neighbors'] ?? [])));
$rawN = raw('GET', '/v1/graph/neighbors?kind=document&id=' . ($iA['maludb_document_id'] ?? '0') . '&direction=both');
note('neighbors of document A: ' . json_encode(array_map(static fn($n) => [$n['neighbor_kind'] ?? null, $n['neighbor_id'] ?? null, $n['rel'] ?? null], $rawN['data']['neighbors'] ?? [])));
$p = r(dc_graph_path(['kind' => 'subject', 'id' => (int) $acme], ['kind' => 'subject', 'id' => (int) $contracts], 6));
check('a path between acme and contracts answers ok (through document A)', $p['status'] === 'ok', $p);
note('graph/path answer keys: ' . implode(', ', array_keys($p)));
$gn = r(dc_graph_god_nodes(5));
check('god_nodes answers ok with nodes', $gn['status'] === 'ok' && (isset($gn['nodes']) || isset($gn['god_nodes']) || isset($gn['results'])), $gn);
note('god-nodes answer keys: ' . implode(', ', array_keys($gn)));
$st = r(dc_graph_stats());
check('stats answers ok', $st['status'] === 'ok', $st);
note('stats answer keys: ' . implode(', ', array_keys($st)));
$sp = r(dc_graph_surprises(5));
check('surprises answers ok (possibly empty: communities are never computed, M6)', $sp['status'] === 'ok', $sp);
$sj = r(dc_graph_subject((int) $acme));
note('GET /v1/subjects/{id} answers ' . implode(', ', array_keys($sj['subject'] ?? [])) . ' — its documents [{id, title, rel}] directly');
check('dc_graph_subject answers the subject (label, type, verbs, related_subjects, documents)', $sj['status'] === 'ok' && mb_strtolower((string) ($sj['subject']['label'] ?? $sj['subject']['canonical_name'] ?? '')) === 'acme' && isset($sj['subject']['documents']), $sj);

// =====================================================================================================================
echo "-- the engine failing: timeouts and refusals, never a throw\n";
use_graph($FAKE . '/hang', $TOKEN, '2');
$t0 = microtime(true);
$to = r(dc_graph_stats());
check('an engine that hangs answers graph_timeout within the configured timeout', $to['status'] === 'graph_timeout' && (microtime(true) - $t0) < 6, [$to, microtime(true) - $t0]);
use_graph($API, 'malu_not_a_real_token_000000');
$au = r(dc_graph_stats());
check('a refused token answers graph_auth', $au['status'] === 'graph_auth', $au);
use_graph('http://127.0.0.1:1', $TOKEN, '2');
$un = r(dc_graph_stats());
check('an unreachable engine answers graph_unavailable', $un['status'] === 'graph_unavailable', $un);
use_graph($API, $TOKEN);

// =====================================================================================================================
echo "-- hygiene\n";
$blob = json_encode($ALL);
check('no returned structure carries the API token', !str_contains($blob, $TOKEN), 'token found');
check('no returned structure carries the provider credential', !str_contains($blob, $PROVIDER_SECRET), 'credential found');
foreach ([$iA, $iC, $fD] as $d) {
    r(dc_graph_forget($d['maludb_document_id'] ?? '0'));
}

echo "== $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
