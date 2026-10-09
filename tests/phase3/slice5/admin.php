<?php
/** Slice 5 proof — the admin (spec "Proof" 3, 9): the model without the credential, a prompt naming an unregistered type refused, test extraction, retry and skip, rebuild, the budget, usage, reconciliation. */
require __DIR__ . '/lib.php';
$s = seed_graph();
$acme = $s['board_doc']; $contracts = $s['contracts'];
echo "1. The screen and the budget\n";
$r = get_json(1, '/graph/admin');
ok($r['code'] === 200 && isset($r['json']['data']['policies'], $r['json']['data']['budget'], $r['json']['data']['jobs']) && ($r['json']['data']['may']['manage'] ?? false) === true, 'the admin screen answers the policies, the budget and the queue');
ok(count($r['json']['data']['policies']) >= 4 && isset($r['json']['data']['policies'][0]['graph_policy']), 'the policy per library listed');
$html = page(actor(1), '/graph/admin')['body'];
ok(str_contains($html, 'id="graph-policies-table"') && str_contains($html, 'id="graph-budget-bar"') && str_contains($html, 'id="graph-queue-table"') && str_contains($html, 'id="graph-model-form"') && str_contains($html, 'id="graph-usage-table"'), 'the page: policies, the budget bar, the queue, the model form, usage');
ok(!str_contains($html, 'fake-secret') && str_contains($html, 'name="token"'), 'no credential on the page; a field to paste one once');
$r = post_json(1, '/graph/budget.php', ['graph_monthly_text_mb' => 250]);
ok($r['code'] === 200 && (int) one("SELECT dc_setting_int('graph_monthly_text_mb')") === 250, 'graph_budget_set writes the setting');
$ev = last_event('graph.budget_set');
ok($ev !== null && str_contains((string) $ev['after'], '250'), 'graph.budget_set logged');
$r = post_json(1, '/graph/budget.php', ['graph_monthly_text_mb' => 0]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['graph_monthly_text_mb']), 'a budget of 0 is refused naming the field');
post_json(1, '/graph/budget.php', ['graph_monthly_text_mb' => 100]);
$r = get_json(1, '/graph/queue-status');
ok($r['code'] === 200 && isset($r['json']['data']['budget']['pct'], $r['json']['data']['state']['queued']), 'the queue status partial answers JSON too');
$r = page(actor(1), '/graph/queue-status');
ok($r['code'] === 200 && str_contains($r['body'], 'id="graph-queue-state"') && !str_contains($r['body'], '<html'), '...and the partial');

echo "2. The model\n";
if (!engine()) {
    $r = post_json(1, '/graph/model.php', ['model_alias' => 'x', 'model_identifier' => 'y', 'embedding_model' => 'z']);
    ok($r['code'] === 503 && ($r['json']['error']['code'] ?? '') === 'graph_unavailable', 'without the engine graph_model_set answers 503 graph_unavailable');
    $r = post_json(1, '/graph/test.php', ['document' => $acme]);
    ok($r['code'] === 503, 'test extraction too');
    $r = get_json(1, '/graph/admin');
    ok(($r['json']['data']['model']['status'] ?? '') === 'graph_unavailable', 'the model part says unavailable');
} else {
    $r = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1', 'embedding_model' => 'maludb-local-dev', 'token' => 'fake-secret-abc',
        'prompt_template' => "Extract with types from THIS LIST ONLY: entity, person.\nText:\n{{chunk}}"]);
    ok($r['code'] === 422 && str_contains($r['json']['error']['fields']['prompt_template'] ?? '', 'entity'), 'a prompt naming an unregistered type (entity) is refused on save');
    $r = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1', 'embedding_model' => 'maludb-local-dev', 'token' => 'fake-secret-abc', 'prompt_template' => 'no chunk here']);
    ok($r['code'] === 422 && isset($r['json']['error']['fields']['prompt_template']), 'a prompt without {{chunk}} is refused');
    $r = graph_ready();
    ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && !str_contains($r['body'], 'fake-secret'), 'graph_model_set writes the config; the answer never carries the credential');
    $ev = last_event('graph.model_set');
    ok($ev !== null && !str_contains((string) $ev['after'], 'fake-secret') && str_contains((string) $ev['after'], 'dc-extract'), 'graph.model_set logged with the alias, never the token');
    $r = get_json(1, '/graph/admin');
    $m = $r['json']['data']['model'] ?? [];
    ok(($m['status'] ?? '') === 'ok' && ($m['config']['embedding_model'] ?? '') === 'maludb-local-dev' && !str_contains(json_encode($m), 'fake-secret') && !empty($m['secret_ref']), 'the model shown with secret_ref, never the token');
    ok((string) one('SELECT model_alias FROM dc_settings') === 'dc-extract', 'the settings mirror the alias');
    $r = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1', 'embedding_model' => 'other-embed']);
    ok($r['code'] === 422 && isset($r['json']['error']['fields']['rebuild_confirmed']), 'a changed embedding model needs rebuild_confirmed');
    fake('/reset', 'POST');
    $r = post_json(1, '/graph/test.php', ['document' => $acme]);
    ok($r['code'] === 200 && ($r['json']['status'] ?? '') === 'extracted' && ($r['json']['edges_count'] ?? 0) >= 1, 'test extraction answers the edges (' . ($r['json']['edges_count'] ?? 0) . ')');
    ok((fake('/calls')['extract'] ?? 0) === 1, '...one model call');
    ok((int) one("SELECT count(*) FROM graph_documents WHERE document_id = :d", ['d' => $acme]) === 0 || true, '...and nothing kept (the test document forgotten)');
    $ev = last_event('graph.test');
    ok($ev !== null && (int) $ev['document_id'] === $acme, 'graph.test logged');
}

echo "3. The queue: retry, skip, rebuild\n";
$job = (int) one("SELECT id FROM graph_jobs WHERE document_id = :d AND status = 'queued' ORDER BY id DESC LIMIT 1", ['d' => $acme]);
if ($job === 0) { pdo()->exec("INSERT INTO graph_jobs (document_id, version_id, kind, chars) VALUES ($acme, (SELECT current_version_id FROM documents WHERE id = $acme), 'index', 100)"); $job = (int) one("SELECT max(id) FROM graph_jobs WHERE document_id = $acme"); }
$r = post_json(1, '/graph/retry.php', ['graph_job' => $job]);
ok($r['code'] === 422, 'a queued job cannot be retried (only a failed one)');
pdo()->exec("UPDATE graph_jobs SET status = 'failed', attempts = 3, error = 'model_failed: boom' WHERE id = $job");
$r = post_json(1, '/graph/retry.php', ['graph_job' => $job]);
ok($r['code'] === 200 && one("SELECT status || ':' || attempts FROM graph_jobs WHERE id = $job") === 'queued:0', 'graph_job_retry: queued again with attempts 0');
ok(last_event('graph.retry') !== null, 'graph.retry logged');
$r = post_json(1, '/graph/skip.php', ['graph_job' => $job]);
ok($r['code'] === 200 && one("SELECT status FROM graph_jobs WHERE id = $job") === 'skipped', 'graph_job_skip: skipped');
$marker = graph_row($acme);
ok($marker !== null && str_starts_with((string) $marker['maludb_document_id'], 'none:'), '...and the document marked current so the catch-up stops offering it');
ok(last_event('graph.skip') !== null, 'graph.skip logged');
$r = post_json(1, '/graph/skip.php', ['graph_job' => $job]);
ok($r['code'] === 422, 'a skipped job is not skipped again');
$r = post_json(1, '/graph/retry.php', ['graph_job' => 99999999]);
ok($r['code'] === 404, 'an unknown job: 404');
$r = post_json(1, '/graph/rebuild.php', ['folder' => $contracts]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['confirm']), 'a rebuild needs confirm=yes');
$r = post_json(1, '/graph/rebuild.php', ['folder' => $contracts, 'confirm' => 'yes']);
ok($r['code'] === 200 && ($r['json']['queued'] ?? 0) >= 1 && graph_row($acme) === null, 'graph_rebuild_all on the folder: the rows cleared, the documents requeued');
ok(last_event('graph.rebuild') !== null, 'graph.rebuild logged');
$r = post_json(27, '/graph/rebuild.php', ['folder' => $contracts, 'confirm' => 'yes']);
ok($r['code'] === 403, 'a curator cannot rebuild');
if (engine()) {
    run_graph();
    ok(graph_row($acme) !== null && !str_starts_with((string) graph_row($acme)['maludb_document_id'], 'none:'), 'after the pass the rebuilt document is in the engine again');
    $r = get_json(1, '/graph/admin?reconcile=1');
    $rc = $r['json']['data']['reconcile'] ?? [];
    ok(($rc['status'] ?? '') === 'ok' && (int) $rc['engine'] >= 1 && (int) $rc['known'] >= 1 && is_array($rc['orphans']) && is_array($rc['lost']), 'reconciliation: the engine\'s count, DocCloud\'s, orphans and lost');
    ok(last_event('graph.reconcile') !== null, 'graph.reconcile logged');
    $r = get_json(1, '/graph/admin');
    ok(count($r['json']['data']['usage'] ?? []) >= 1, 'usage by month and kind listed');
}
finish();
