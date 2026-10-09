<?php
/** Slice 5 proof — the policy (spec "Proof" 1, 2, 8, 10): the default index, a folder set full (the estimate shown), inherit, none forgetting, a curator's reach, the walls on the policy screen and action. */
require __DIR__ . '/lib.php';
$s = seed_graph();
$acme = $s['board_doc']; $contracts = $s['contracts']; $boardLib = (int) one('SELECT library_id FROM folders WHERE id = :id', ['id' => $contracts]);
echo "1. The default policy and the queue the triggers wrote\n";
ok(one('SELECT dc_effective_graph_policy(:id)', ['id' => $contracts]) === 'index', 'the default policy is index (the library\'s)');
$queued = (int) one("SELECT count(*) FROM graph_jobs WHERE kind = 'index' AND status = 'queued'");
ok($queued >= 6, "text extracted queues an index job per document ($queued queued)");
ok((int) one("SELECT count(*) FROM graph_jobs WHERE document_id = :d AND kind = 'index' AND status = 'queued'", ['d' => $acme]) === 1, 'acme-msa.pdf has one queued index job');
$subj = q('SELECT dc_graph_subjects(:d) AS s', ['d' => $acme])[0]['s'];
ok(str_starts_with((string) $subj, '{doc:' . $acme . ',') && str_contains((string) $subj, 'tag:acme-corp') && str_contains((string) $subj, 'folder:Contracts') && str_contains((string) $subj, 'kind:pdf'), "the document's known subjects: doc:<id> first, its tags, folder, library, owner, kind, year: $subj");

echo "2. The policy screen and the estimate\n";
$r = get_json(1, '/graph/admin?folder=' . $contracts);
$t = $r['json']['data']['target'] ?? [];
ok($r['code'] === 200 && ($t['kind'] ?? '') === 'folder' && ($t['effective'] ?? '') === 'index' && isset($t['estimate']['documents'], $t['estimate']['chars'], $t['estimate']['calls'], $t['estimate']['sentence']), 'the admin opens the folder\'s policy with the estimate (documents, characters, calls, the sentence)');
ok(($t['estimate']['documents'] ?? 0) >= 1 && ($t['estimate']['over_budget'] ?? true) === false, 'the estimate counts the folder\'s documents with text and is under the budget');
$html = page(actor(1), '/graph/admin?folder=' . $contracts)['body'];
ok(str_contains($html, 'id="graph-policy-form"') && str_contains($html, 'id="graph-estimate"') && str_contains($html, 'id="graph-policy-full"') && str_contains($html, 'id="graph-policy-inherit"'), 'the page: the form with none/index/full/inherit and the estimate');
$r = get_json(27, '/graph/admin?folder=' . $contracts);
ok($r['code'] === 200 && ($r['json']['data']['target']['kind'] ?? '') === 'folder' && ($r['json']['data']['may']['manage'] ?? true) === false, 'Marco (a group owner, no graph.manage) opens the policy of his library\'s folder');
$htmlM = page(actor(27), '/graph/admin?folder=' . $contracts)['body'];
ok(str_contains($htmlM, 'id="graph-policy-full" value="full" disabled') || preg_match('/id="graph-policy-full"[^>]*disabled/', $htmlM) === 1, '...with full disabled (the graph admin\'s)');
$r = get_json(28, '/graph/admin?folder=' . $contracts);
ok($r['code'] === 404, 'Bea (outside the Board) cannot open it: 404');
$r = get_json(27, '/graph/admin');
ok($r['code'] === 403, 'the graph admin screen itself needs graph.manage (Marco: 403)');

echo "3. Setting the policy\n";
$r = post_json(27, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'full']);
ok($r['code'] === 403, 'a curator is refused full in words (403)');
$r = post_json(27, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'none']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && ($r['json']['graph_policy'] ?? '') === 'none', 'Marco sets none on Contracts (no model spend) — allowed for a library manager');
ok(one('SELECT dc_effective_graph_policy(:id)', ['id' => $contracts]) === 'none' && one('SELECT dc_effective_graph_policy(:id)', ['id' => $s['board_root']]) === 'index', 'the folder\'s policy overrides for its subtree only');
ok(job_status($acme, 'index') === 'skipped' || (int) one("SELECT count(*) FROM graph_jobs WHERE document_id = :d AND status = 'queued'", ['d' => $acme]) === 0, 'none: the queued index job no longer waits (nothing to forget yet: the engine holds nothing)');
$ev = last_event('graph.policy_set');
$after = json_decode((string) ($ev['after'] ?? '{}'), true) ?: [];
ok($ev !== null && (int) $ev['actor_member_id'] === 27 && (int) $ev['folder_id'] === $contracts && ($after['graph_policy'] ?? '') === 'none', 'graph.policy_set logged with the folder and the policy');
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'full']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && ($r['json']['queued'] ?? 0) >= 1, 'the admin sets full: the folder\'s documents requeued as full');
ok(job_status($acme, 'full') === 'queued', 'acme-msa.pdf now waits as a full job');
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'inherit']);
ok($r['code'] === 200 && one('SELECT graph_policy FROM folders WHERE id = :id', ['id' => $contracts]) === null && one('SELECT dc_effective_graph_policy(:id)', ['id' => $contracts]) === 'index', 'inherit clears the folder\'s own policy (the library\'s index applies)');
ok(job_status($acme, 'index') === 'queued' && job_status($acme, 'full') === 'skipped', '...and the full job is skipped for an index one');
$r = post_json(1, '/graph/policy.php', ['library' => $boardLib, 'graph_policy' => 'full']);
ok($r['code'] === 200 && one('SELECT graph_policy FROM libraries WHERE id = :id', ['id' => $boardLib]) === 'full', 'a library\'s policy set to full');
$r = post_json(1, '/graph/policy.php', ['library' => $boardLib, 'graph_policy' => 'index']);
ok($r['code'] === 200, '...and back to index');
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'sideways']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['graph_policy']), 'an unknown policy is refused naming the field');
$r = post_json(1, '/graph/policy.php', ['graph_policy' => 'index']);
ok($r['code'] === 422, 'no library or folder: 422');
$r = post_json(1, '/graph/policy.php', ['library' => $boardLib, 'graph_policy' => 'inherit']);
ok($r['code'] === 422, 'a library cannot inherit');

echo "4. Over the budget: full needs the word\n";
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 1");
pdo()->exec("INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, 'full', 1, 1000000, 1) ON CONFLICT (month, kind) DO UPDATE SET chars = 1000000");   // 48,576 chars left of the month's 1 MB
$big = seed_doc($contracts, 'big-report.txt', 27, str_repeat('Acme budget report line ', 5000), 'text/plain', 120000);   // 120,000 chars, ONE sentence (the fake provider makes one edge per sentence)
$r = get_json(1, '/graph/admin?folder=' . $contracts);
ok(($r['json']['data']['target']['estimate']['over_budget'] ?? false) === true && str_contains($r['json']['data']['target']['estimate']['sentence'] ?? '', 'over budget'), 'the estimate says over budget');
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'full']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['confirm']), 'full over the budget is refused until "full" is typed');
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'full', 'confirm' => 'full']);
ok($r['code'] === 200, '...and accepted with it');
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 100");
pdo()->exec("DELETE FROM graph_usage WHERE kind = 'full'");
post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'inherit']);
// the big report is this proof's alone: purged, so the queue and find proofs (same scratch database) start from their own seed
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
q('SELECT dc_document_trash(:d, 27)', ['d' => $big]); q('SELECT dc_document_purge(:d)', ['d' => $big]);
q("DELETE FROM graph_jobs WHERE document_id IS NULL AND kind = 'forget' AND status = 'queued' AND maludb_document_id IS NULL");

echo "5. The folder and library pages carry the policy\n";
$html = page(actor(27), '/f/' . $contracts)['body'];
ok(str_contains($html, 'id="folder-graph-policy"') && str_contains($html, 'id="folder-graph-policy-link"'), 'the folder page shows the effective policy chip and Policy… for a manager');
$html = page(actor(26), '/f/' . $contracts)['body'];
ok(str_contains($html, 'id="folder-graph-policy"') && !str_contains($html, 'id="folder-graph-policy-link"'), '...the chip without the link for an editor');
$html = page(actor(27), '/libraries/' . $boardLib)['body'];
ok(preg_match('#id="library-policy"#', $html) === 1 && str_contains($html, '/graph/admin?library=' . $boardLib), 'the library page\'s policy badge links to the policy form');
finish();
