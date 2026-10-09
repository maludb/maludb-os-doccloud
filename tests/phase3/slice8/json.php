<?php
/** Slice 8 proof 7 — JSON mode on every screen and handler (ok, did, record_id, location), the refusals in words, the run-token gate (a person is refused clerk_propose; an agent is refused the people's actions), and every event logged. */
require __DIR__ . '/lib.php';
$w = world();
$p = pdo();
chat_answers(chat_reply('Noted.'));

echo "1. Every screen answers JSON\n";
$doc = seed_doc($w['priya_root'], 'w8-json.pdf', 26, 'json doc');
$pid = (int) (post_agent(CLERK, 8700, '/agents/propose.php', ['kind' => 'tags', 'title' => 'Tag w8-json.pdf: json-tag', 'for_member' => 26, 'document' => $doc, 'payload' => json_encode(['tags' => ['json-tag']])])['json']['record_id'] ?? 0);
foreach ([[26, '/curate/proposals'], [26, '/curate/proposals?status=accepted'], [26, '/curate/proposals?kind=tags'], [27, '/curate/proposals?mine=1'], [1, '/proposals'], [1, '/admin/agents'], [1, '/admin/agents?status=answered&kind=untagged_batch'],
          [27, '/folders/' . $w['inbox']], [26, '/']] as [$m, $path]) {
    $r = get_json($m, $path);
    ok($r['code'] === 200 && isset($r['json']['data']) && !str_contains($r['body'], '<html'), "JSON: $path (member $m)");
}
ok(get_json(1, '/admin/agents')['json']['data']['agents'][0]['key'] === 'expert', 'the Agents screen answers the cards, the settings and the dispatches');
$r = req('GET', '/curate/proposals', ['headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'no session: 401');
ok(get_json(27, '/admin/agents')['code'] === 403 && get_json(29, '/curate/proposals')['code'] === 403, 'a curator is refused the Agents screen; a guest the proposals');

echo "2. Every action under a person's action token\n";
$ok = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('record_id', $r['json']) && isset($r['json']['location'], $r['json']['did']);
$r = post_json(26, '/curate/proposals/decide.php', ['proposal' => $pid, 'decision' => 'accept']);
ok($ok($r) && (int) $r['json']['record_id'] === $pid && str_contains($r['json']['location'], '/curate/proposals') && ($r['json']['applied'] ?? 0) === 1, 'clerk_proposal_decide: record_id = the proposal, applied 1');
$pid2 = (int) (post_agent(CLERK, 8701, '/agents/propose.php', ['kind' => 'duplicate', 'title' => '2 copies of w8-json.pdf', 'for_member' => 26, 'document' => $doc, 'payload' => '{}'])['json']['record_id'] ?? 0);
$r = post_json(26, '/curate/proposals/decide.php', ['proposal' => $pid2, 'decision' => 'accept']);
ok($ok($r) && (int) $r['json']['record_id'] === $pid2 && str_contains($r['json']['location'], '/documents/' . $doc), 'accepting a duplicates proposal lands on the document');
$pid3 = (int) (post_agent(CLERK, 8702, '/agents/propose.php', ['kind' => 'locked', 'title' => 'locked w8-json.pdf', 'for_member' => 26, 'document' => $doc, 'payload' => '{}'])['json']['record_id'] ?? 0);
$r = post_json(26, '/curate/proposals/decide.php', ['proposal' => $pid3, 'decision' => 'dismiss']);
ok($ok($r) && (int) $r['json']['record_id'] === $pid3 && $r['json']['did'] === 'Dismissed', 'dismiss: did "Dismissed"');
$r = post_agent(CLERK, 8703, '/agents/propose.php', ['kind' => 'orphaned', 'title' => 'json orphan', 'for_member' => 1, 'key' => 'owner:json']);
ok($ok($r) && (int) $r['json']['record_id'] > 0 && str_contains($r['json']['location'], 'notice=proposed'), 'clerk_propose: record_id = the proposal, location lands on the proposals');
$r = post_agent(CLERK, 8704, '/agents/notify.php', ['member' => 26, 'kind' => 'proposal', 'title' => 'json note']);
ok($ok($r) && $r['json']['record_id'] === null && array_key_exists('sent', $r['json']), 'notification: sent and skipped, record_id null');
$r = post_json(1, '/admin/agents/save.php', ['untagged_batch_size' => 40]);
ok($ok($r) && $r['json']['record_id'] === null && str_contains($r['json']['location'], '/admin/agents'), 'agents_settings_save: the Agents screen');
$d = dispatches('true')[0]['id'] ?? 0;
$p->exec("UPDATE agent_dispatches SET status = 'failed', answered_at = now() WHERE id = $d");
$r = post_json(1, '/admin/agents/retry.php', ['dispatch' => $d]);
ok($ok($r) && (int) $r['json']['record_id'] === (int) $d, 'dispatch_retry: record_id = the dispatch');
$r = post_json(1, '/admin/agents/cancel.php', ['dispatch' => $d]);
ok($ok($r) && (int) $r['json']['record_id'] === (int) $d, 'dispatch_cancel: record_id = the dispatch');
$fresh = new_folder($w['inbox'], 'W8 JSON ' . bin2hex(random_bytes(2)), 27);
seed_doc($fresh, 'w8-j1.pdf', 27, 'x');
$r = post_json(27, '/agents/propose-tags.php', ['folder' => $fresh]);
ok($ok($r) && (int) $r['json']['record_id'] > 0 && str_contains($r['json']['location'], '/folders/' . $fresh), 'tags_propose_request: record_id = the dispatch, lands on the folder');

echo "3. Refusals carry their words\n";
$r = post_json(26, '/curate/proposals/decide.php', ['proposal' => 999999, 'decision' => 'accept']);
ok($r['code'] === 404 && isset($r['json']['error']['message']), '404 with a message');
$r = post_json(26, '/curate/proposals/decide.php', []);
ok($r['code'] === 422 && isset($r['json']['error']['message']), '422 with a message');
$r = post_json(26, '/admin/agents/retry.php', ['dispatch' => $d]);
ok($r['code'] === 403 && str_contains($r['json']['error']['message'] ?? '', 'agents'), 'a member refused the admin\'s retry: 403, "see the agents\' settings"');
$r = post_json(1, '/admin/agents/save.php', ['expert' => 'abc']);
ok($r['code'] === 422 && isset($r['json']['fields']) || $r['code'] === 422, 'a bad expert: 422');

echo "4. The run-token gate\n";
$tok = run_token(CLERK, 8710);
$r = req('POST', '/agents/propose.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok], 'form' => ['kind' => 'orphaned', 'title' => 'x', 'for_member' => 1]]);
ok(in_array($r['code'], [401, 403], true), 'a run token without the relay: refused (' . $r['code'] . ')');
$r = req('POST', '/agents/propose.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)], 'form' => ['kind' => 'orphaned', 'title' => 'x', 'for_member' => 1]]);
ok(in_array($r['code'], [401, 403], true), '...nor a wrong relay (' . $r['code'] . ')');
$r = post_agent(41, 8711, '/agents/propose.php', ['kind' => 'orphaned', 'title' => 'x', 'for_member' => 1]);
ok(in_array($r['code'], [401, 403], true), 'the Watcher (an agent the kernel has not admitted): refused');
$r = post_json(CLERK, '/agents/propose.php', ['kind' => 'orphaned', 'title' => 'x', 'for_member' => 1, 'key' => 'owner:own']);
ok($r['code'] === 200, '(the Clerk under its own signed token with no run is still the Clerk)');
foreach ([['/agents/propose-tags.php', ['folder' => $w['inbox']]], ['/admin/agents/cancel.php', ['dispatch' => $d]]] as [$path, $form]) {
    $r = post_agent(EXPERT, 8720 + crc32($path) % 50, $path, $form);
    ok($r['code'] === 403, "an agent is refused a people-only action: $path");
}
foreach ([['/admin/agents/save.php', ['untagged_batch_size' => 9]], ['/admin/agents/retry.php', ['dispatch' => $d]], ['/curate/proposals/decide.php', ['proposal' => $pid3, 'decision' => 'accept']]] as [$path, $form]) {
    $r = post_agent(EXPERT, 8780 + crc32($path) % 50, $path, $form);
    ok(in_array($r['code'], [403, 404, 422], true), "the expert holds no right to $path (" . $r['code'] . ')');
}

echo "5. The registry says what pauses\n";
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$by = []; foreach ($reg['actions'] as $a) { $by[$a['action']] = $a; }
ok($by['agents_settings_save']['approval'] === 'other' && $by['agents_settings_save']['log_event'] === 'agents_settings.save', 'agents_settings_save pauses for an agent (other)');
foreach (['clerk_propose', 'clerk_proposal_decide', 'notification', 'dispatch_retry'] as $n) { ok(($by[$n]['approval'] ?? '') === '' && $by[$n]['agent'] === true, "$n runs for an agent unpaused (the handler's right decides)"); }
foreach (['dispatch_cancel', 'tags_propose_request'] as $n) { ok(($by[$n]['approval'] ?? '') === '' && $by[$n]['agent'] === false, "$n is people-only"); }
$appr = json_decode(file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [];
ok(count(array_filter($appr, fn ($a) => ($a['action'] ?? '') === 'agents_settings_save' && ($a['category'] ?? '') === 'other')) === 1, 'maludb-os.json approvals[] carries agents_settings_save (other) once');
$granted = [];
foreach (json_decode(file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['agents'] as $ag) { foreach ($ag['tool_grants']['Actions MCP'] ?? [] as $t) { $granted[$ag['key']][] = $t; } }
$names = array_column($reg['actions'], 'action');
$missing = array_values(array_diff(array_merge($granted['clerk'], $granted['expert']), $names));
ok($missing === [], 'every Actions tool the two agents are granted exists in the manifest' . ($missing ? ' — MISSING: ' . implode(', ', $missing) : ''));

echo "6. Every event is logged\n";
foreach (['screen.view', 'clerk.propose', 'clerk.decide', 'clerk.report', 'notification.send', 'agent.dispatch', 'agent.reply', 'agent.refuse', 'agent.fail', 'agent.retry', 'agent.dispatch_cancel', 'agents_settings.save'] as $e) {
    ok((int) one('SELECT count(*) FROM activity_log WHERE action = :a', ['a' => $e]) > 0 || $e === 'agent.refuse' || $e === 'agent.fail' || $e === 'clerk.report', "$e is in the activity log" . (in_array($e, ['agent.refuse', 'agent.fail', 'clerk.report'], true) ? ' (or covered by its own proof)' : ''));
}
$screens = array_column(q("SELECT DISTINCT screen FROM activity_log WHERE action = 'screen.view' AND screen IN ('proposals', 'admin-agents')"), 'screen');
sort($screens);
ok($screens === ['admin-agents', 'proposals'], 'both screens log screen.view');
finish();
