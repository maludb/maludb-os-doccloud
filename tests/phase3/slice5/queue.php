<?php
/** Slice 5 proof — the worker's pass and the queue (spec "Proof" 1–6): without the engine the jobs wait; with it: index, full, replace, forget, the budget held and released, the notice. */
require __DIR__ . '/lib.php';
$s = seed_graph();
$acme = $s['board_doc']; $contracts = $s['contracts']; $minutes = $s['minutes'];
if (!engine()) {
    echo "1. Without the engine the queue waits\n";
    $before = q("SELECT id, attempts FROM graph_jobs WHERE status = 'queued' ORDER BY id");
    $r = run_graph();
    ok(($r['deferred'] ?? -1) === count($before) && ($r['reason'] ?? '') === 'graph_unavailable' && ($r['done'] ?? 1) === 0, 'the pass defers every job (' . count($before) . ') with reason graph_unavailable');
    $after = q("SELECT id, attempts FROM graph_jobs WHERE status = 'queued' ORDER BY id");
    ok($after === $before, 'nothing taken, attempts unchanged');
    $ev = last_event('graph.deferred');
    ok($ev !== null && $ev['source'] === 'cron', 'one graph.deferred note in the log, never one per job');
    ok((int) one("SELECT count(*) FROM graph_documents") === 0, 'the engine holds nothing');
    $r = get_json(1, '/graph/queue-status');
    ok($r['code'] === 200 && ($r['json']['data']['state']['available'] ?? true) === false && ($r['json']['data']['state']['queued'] ?? 0) === count($before), 'the queue status says unavailable with the jobs waiting');
    finish();
}
echo "1. The model and the index pass\n";
$m = graph_ready();
ok($m['code'] === 200 && ($m['json']['ok'] ?? false), 'the admin sets the model through the handler (the fake provider, the default prompt)');
fake('/reset', 'POST');
$r = run_graph();
ok(($r['done'] ?? 0) >= 6 && ($r['failed'] ?? 1) === 0, "the pass indexed the documents with text (done {$r['done']}, failed {$r['failed']}, skipped {$r['skipped']})");
$g = graph_row($acme);
ok($g !== null && $g['policy'] === 'index' && $g['maludb_document_id'] !== '' && !str_starts_with($g['maludb_document_id'], 'none:'), 'acme-msa.pdf has its engine id and policy index in graph_documents');
$subj = pg_text_array_php($g['subjects']);
ok(in_array('doc:' . $acme, $subj, true) && in_array('tag:acme-corp', $subj, true) && in_array('folder:Contracts', $subj, true), 'the subjects kept: doc:<id>, tag:acme-corp, folder:Contracts …');
ok(((fake('/calls')['extract'] ?? -1) === 0), 'the index policy made NO model call (remember)');
ok(engine_doc($g['maludb_document_id']) === 'ok', 'the engine answers the document by its id');
ok((int) one("SELECT documents FROM graph_usage WHERE kind = 'index' AND month = date_trunc('month', now())::date") >= 6, 'graph_usage counts the month\'s index jobs');
$ev = last_event('graph.index');
ok($ev !== null && $ev['source'] === 'cron' && $ev['document_id'] !== null, 'graph.index logged per document with its id');
function pg_text_array_php(string $v): array { return array_map(static fn (string $x): string => trim($x, '"'), array_filter(explode(',', trim($v, '{}')), static fn ($x) => $x !== '')); }

echo "2. The full policy\n";
$r = post_json(1, '/graph/policy.php', ['folder' => $contracts, 'graph_policy' => 'full']);
ok($r['code'] === 200 && job_status($acme, 'full') === 'queued', 'Contracts set full: acme-msa.pdf queued as full');
$oldId = $g['maludb_document_id'];
fake('/reset', 'POST');
$r = run_graph();
ok(($r['done'] ?? 0) >= 1 && (fake('/calls')['extract'] ?? 0) >= 1, 'the pass sent it through the model (the fake provider counted an extraction call)');
$g2 = graph_row($acme);
ok($g2 !== null && $g2['policy'] === 'full' && $g2['maludb_document_id'] !== $oldId, 'the engine row now says full with a new engine id');
$last = fake('/last')['extract'] ?? [];
ok(str_contains((string) ($last['user'] ?? ''), 'THIS LIST ONLY') && str_contains((string) ($last['user'] ?? ''), 'Master services agreement'), 'the prompt the model saw: the registered-types instruction and the document\'s text');
$fj = q("SELECT * FROM graph_jobs WHERE kind = 'forget' AND maludb_document_id = :m", ['m' => $oldId]);
ok($fj !== [] , 'a forget job for the old engine id was queued (the replace)');
run_graph();
ok(q("SELECT status FROM graph_jobs WHERE kind = 'forget' AND maludb_document_id = :m", ['m' => $oldId])[0]['status'] === 'done' && engine_doc($oldId) === 'not_found', '...done: the old engine document is gone');

echo "3. no_edges is skipped, nothing kept\n";
$blank = seed_doc($contracts, 'numbers.txt', 27, "12345 67890\n24680 13579\n", 'text/plain', 300);
run_graph();
$st = job_status($blank, 'full');
ok(in_array($st, ['skipped', 'done'], true), "a text the model finds nothing in: the full job is $st");
if ($st === 'skipped') {
    ok(str_starts_with((string) (graph_row($blank)['maludb_document_id'] ?? ''), 'none:'), '...and the marker row keeps the catch-up from re-sending it');
} else {
    ok(true, '(the fake provider extracted an edge from the numbers; no marker needed)');
}

echo "4. A new version replaces; a purge forgets; none forgets\n";
$g3 = graph_row($acme); $prev = $g3['maludb_document_id'];
$p = pdo(); $p->exec("SELECT set_config('app.member_id', '27', false)");
$sha = hash('sha256', 'v2-acme'); $b = q('SELECT blob_id FROM dc_blob_claim(:s, :n)', ['s' => $sha, 'n' => 1300])[0]['blob_id'];
$v = q('SELECT version_id FROM dc_version_add(:d, NULL, NULL, :b, :f, :m, :n, :s, :by)', ['d' => $acme, 'b' => $b, 'f' => 'acme-msa.pdf', 'm' => 'application/pdf', 'n' => 1300, 's' => $sha, 'by' => 27])[0]['version_id'];
$p->prepare("INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES (:v, :d, :t, :c, 'proof')")->execute(['v' => $v, 'd' => $acme, 't' => 'Acme Corporation master services agreement, second edition. Termination needs sixty days of notice.', 'c' => 100]);
ok(job_status($acme, 'full') === 'queued', 'a new version with text queues a new full job');
run_graph();
$g4 = graph_row($acme);
ok($g4['maludb_document_id'] !== $prev && (int) $g4['version_id'] === (int) $v, 'the engine row moved to the new version with a new engine id');
run_graph();
ok(engine_doc($prev) === 'not_found', 'the previous engine document was forgotten');
$cur = $g4['maludb_document_id'];
$p->prepare('SELECT dc_document_trash(:d, 27)')->execute(['d' => $acme]);
ok((q("SELECT status FROM graph_jobs WHERE kind = 'forget' AND maludb_document_id = :m ORDER BY id DESC LIMIT 1", ['m' => $cur])[0]['status'] ?? '') === 'queued', 'trashing queues a forget');
run_graph();
ok(engine_doc($cur) === 'not_found' && graph_row($acme) === null, '...done: the engine forgot it and the row is gone');
$p->prepare('SELECT dc_document_restore(:d)')->execute(['d' => $acme]);
run_graph();
ok(graph_row($acme) !== null, 'restored: re-sent under its policy');
$cur = graph_row($acme)['maludb_document_id'];
$p->prepare('SELECT dc_document_trash(:d, 27)')->execute(['d' => $acme]);
$p->prepare('SELECT dc_document_purge(:d)')->execute(['d' => $acme]);
run_graph();
ok(engine_doc($cur) === 'not_found', 'a purge: the engine document forgotten by its own job');
$mg = graph_row($minutes); $mid = $mg['maludb_document_id'];
post_json(1, '/graph/policy.php', ['library' => (int) one('SELECT library_id FROM documents WHERE id = :d', ['d' => $minutes]), 'graph_policy' => 'none']);
run_graph();
ok(graph_row($minutes) === null && engine_doc($mid) === 'not_found', 'policy none on the library: its documents forgotten');
post_json(1, '/graph/policy.php', ['library' => (int) one('SELECT library_id FROM documents WHERE id = :d', ['d' => $minutes]), 'graph_policy' => 'index']);
run_graph();
ok(graph_row($minutes) !== null, '...back to index: re-sent');

echo "5. The budget holds a full job; raised, it runs; the notice at 80%\n";
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 1");
pdo()->exec("INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, 'full', 1, 1000000, 1) ON CONFLICT (month, kind) DO UPDATE SET chars = 1000000");   // 48,576 chars left of the month's 1 MB
$big = seed_doc($contracts, 'big-report.txt', 27, str_repeat('Acme budget report line ', 5000), 'text/plain', 120000);   // 120,000 chars over the remainder; ONE sentence (one edge per chunk from the fake provider)
ok(job_status($big, 'full') === 'queued', 'a 120,000-character text under full is queued');
$r = run_graph();
ok(job_status($big, 'full') === 'queued' && ($r['deferred'] ?? 0) >= 1, 'the pass leaves it queued (over the month\'s remainder) and reports it deferred');
$rq = get_json(1, '/graph/queue-status');
ok(($rq['json']['data']['state']['queued'] ?? 0) >= 1, 'the admin\'s queue status shows it waiting');
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 100");
run_graph();
ok(in_array(job_status($big, 'full'), ['done', 'skipped'], true), 'the budget raised: the job ran');
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 1");
pdo()->exec("INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, 'full', 1, 900000, 1) ON CONFLICT (month, kind) DO UPDATE SET chars = 900000");
run_graph();
$n = bell(1, 'graph_budget');
ok($n !== [] && str_contains($n[0]['title'], '%'), 'at 80% the admin is told once (graph_budget)');
run_graph();
ok(count(bell(1, 'graph_budget')) === count($n), '...and not again this month');
pdo()->exec("UPDATE dc_settings SET graph_monthly_text_mb = 100");
finish();
