<?php
/** Slice 5 proof — JSON mode and the agents (spec "Proof" 11, 12, 13): every screen answers JSON; every action under a person's action token; an agent's run token runs the find and is refused the paused actions by right; the registry's categories. */
require __DIR__ . '/lib.php';
$s = seed_graph();
$acme = $s['board_doc']; $contracts = $s['contracts'];
if (engine()) { graph_ready(); run_graph(); }
echo "1. Every screen answers JSON\n";
$sid = engine() ? (engine_subject_id('tag:acme-corp') ?? 1) : 1;
foreach ([[27, '/graph/'], [27, '/graph/?q=acme-corp'], [27, '/graph/explore'], [27, '/graph/subjects/' . $sid], [27, '/graph/path'], [27, '/graph/path?from=' . $sid . '&to=' . $sid], [1, '/graph/admin'], [1, '/graph/admin?folder=' . $contracts], [1, '/graph/admin?library=' . (int) one('SELECT library_id FROM folders WHERE id = :id', ['id' => $contracts])], [1, '/graph/queue-status']] as [$m, $path]) {
    $r = get_json($m, $path);
    ok($r['code'] === 200 && isset($r['json']['data']) && !str_contains($r['body'], '<html'), "JSON: $path");
}
$r = get_json(29, '/graph/');
ok($r['code'] === 403, 'a guest holds no graph.use: 403');
$r = req('GET', '/graph/', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'no session: 401');

echo "2. Every action under a person's action token\n";
$ok = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['record_id'], $r['json']['location'], $r['json']['did']);
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'index']);
ok($ok($r) && str_ends_with((string) $r['json']['location'], '/f/' . $contracts . '?notice=policy') && (int) $r['json']['record_id'] === $contracts, 'graph_policy_set: ok, record_id = the folder, location lands on it');
$r = post_json(1, '/graph/budget.php', ['graph_monthly_text_mb' => 120]);
ok($ok($r) && ($r['json']['graph_monthly_text_mb'] ?? 0) === 120, 'graph_budget_set');
post_json(1, '/graph/budget.php', ['graph_monthly_text_mb' => 100]);
$job = (int) one("SELECT id FROM graph_jobs WHERE status IN ('queued','done','skipped','failed') ORDER BY id DESC LIMIT 1");
pdo()->exec("UPDATE graph_jobs SET status = 'failed', attempts = 3 WHERE id = $job");
$r = post_json(1, '/graph/retry.php', ['graph_job' => $job]);
ok($ok($r) && (int) $r['json']['record_id'] === $job, 'graph_job_retry');
$r = post_json(1, '/graph/skip.php', ['graph_job' => $job]);
ok($ok($r), 'graph_job_skip (people only, the admin is a person)');
$r = post_json(1, '/graph/rebuild.php', ['folder' => $contracts, 'confirm' => 'yes']);
ok($ok($r) && isset($r['json']['queued']), 'graph_rebuild_all');
if (engine()) {
    $r = post_json(1, '/graph/test.php', ['document' => $acme]);
    ok($ok($r) && isset($r['json']['edges_count']), 'graph_test_extraction');
    $r = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1', 'embedding_model' => 'maludb-local-dev']);
    ok($ok($r) && !isset($r['json']['token']), 'graph_model_set (the credential kept)');
}

echo "3. An agent's run token\n";
if (engine()) { run_graph(); run_graph(); }   // section 2's rebuild and retry queued the store again: let the worker settle it first
$r = req('GET', '/graph/?q=acme-corp', ['headers' => as_agent(run_token(40, 5101))]);
$j = json_decode($r['body'], true) ?: [];
$names = array_column($j['data']['find']['rows'] ?? [], 'name'); sort($names);
ok($r['code'] === 200 && isset($j['data']['find']) && (!engine() || $names === ['acme-msa.pdf', 'minutes-jan.docx']), 'Seamus (a Board member agent) runs the find under a run token and gets his own wall' . (engine() ? ': ' . implode(', ', $names) : ' (no engine: the sentence)'));
$r = post_agent(40, 5102, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'none']);
ok($r['code'] === 403, 'an agent without graph.manage is refused graph_policy_set (403; the registry pauses it for one that holds it)');
$r = post_agent(40, 5103, '/graph/budget.php', ['graph_monthly_text_mb' => 5]);
ok($r['code'] === 403, '...and graph_budget_set');
$r = post_agent(40, 5104, '/graph/skip.php', ['graph_job' => $job]);
ok(in_array($r['code'], [403, 422], true), 'graph_job_skip is people only: an agent is refused');
$r = post_agent(40, 5105, '/graph/test.php', ['document' => $acme]);
ok(in_array($r['code'], [403, 503], true), 'graph_test_extraction is people only');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$cats = [];
foreach ($reg['actions'] as $x) { if (in_array($x['action'], ['graph_policy_set', 'graph_budget_set', 'graph_model_set', 'graph_rebuild_all', 'graph_job_retry', 'graph_job_skip', 'graph_test_extraction'], true)) { $cats[$x['action']] = ($x['approval'] ?? $x['agent_approval'] ?? '') . ':' . ($x['agent'] ? 'agent' : 'people'); } }
ksort($cats);
ok($cats === ['graph_budget_set' => 'other:agent', 'graph_job_retry' => ':agent', 'graph_job_skip' => ':people', 'graph_model_set' => 'other:agent', 'graph_policy_set' => 'other:agent', 'graph_rebuild_all' => 'other:agent', 'graph_test_extraction' => ':people'], 'the registry: four paused (other), two people only, retry free');
$r = post_agent(41, 5106, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'index']);
ok(in_array($r['code'], [401, 403], true), 'an agent the kernel has not admitted: refused');
ok((int) one("SELECT count(*) FROM activity_log WHERE source = 'agent' AND actor_member_id = 40 AND action = 'search.graph'") >= 1, 'the agent\'s find is logged search.graph with source agent');
finish();
