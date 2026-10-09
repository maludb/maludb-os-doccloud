<?php
/** Slice 8 proof 1 — db/022 and the agents' settings: the expert and the Clerk named (agents only), dc_is_clerk(), the thresholds, admin-only, an agent's attempt, the Agents screen. */
require __DIR__ . '/lib.php';
$s = seed_texts(); $p = pdo();

echo "1. The columns and the Clerk's identity\n";
ok((bool) one("SELECT count(*) FROM information_schema.columns WHERE table_name = 'dc_settings' AND column_name IN ('clerk_member_id', 'expert_member_id', 'untagged_batch_size', 'duplicate_copies', 'dispatch_retry_minutes', 'clerk_report_enabled')") == 6, 'dc_settings carries the six agent columns (db/016 five, db/022 the expert)');
$d = q('SELECT untagged_batch_size, duplicate_copies, dispatch_retry_minutes, clerk_report_enabled, clerk_member_id, expert_member_id FROM dc_settings WHERE id = 1')[0];
ok((int) $d['untagged_batch_size'] === 40 && (int) $d['duplicate_copies'] === 2 && (int) $d['dispatch_retry_minutes'] === 30 && $d['clerk_report_enabled'] && $d['clerk_member_id'] === null && $d['expert_member_id'] === null, 'the defaults: 40, 2, 30 minutes, on, nobody named');
ok(report_as(CLERK) === [] && report_as(26) === [] && report_as(27) === [], 'before any Clerk is named nobody but the admin reads the report (db/022 fixed the NULL that let everyone)');
ok(report_as(1) !== [], 'the admin reads it');
$p->exec("SELECT set_config('app.member_id', '" . CLERK . "', false)"); ok(one('SELECT dc_is_clerk()') === false, 'dc_is_clerk() is false (not NULL) for an agent not yet named');

echo "2. agents_settings_save\n";
$r = name_agents();
ok($r['code'] === 200 && ($r['json']['ok'] ?? false), 'the admin names the expert (Seamus) and the Clerk');
$e = last_event('agents_settings.save'); $a = json_decode($e['after'] ?? '{}', true); $b = json_decode($e['before'] ?? '{}', true);
ok($e !== null && (int) $e['actor_member_id'] === 1 && ($a['expert_member_id'] ?? null) === EXPERT && ($a['clerk_member_id'] ?? null) === CLERK && array_key_exists('expert_member_id', $b) && $b['expert_member_id'] === null, 'logged agents_settings.save with the before and after');
$r2 = post_json(1, '/admin/agents/save.php', ['expert' => EXPERT, 'clerk' => CLERK]);
ok($r2['code'] === 200 && ($r2['json']['changed'] ?? ['x']) === [], 'saving the same again changes nothing');
$p->exec("SELECT set_config('app.member_id', '" . CLERK . "', false)"); ok(one('SELECT dc_is_clerk()') === true, 'dc_is_clerk() true for the named Clerk');
$p->exec("SELECT set_config('app.member_id', '" . EXPERT . "', false)"); ok(one('SELECT dc_is_clerk()') === false, '...and false for the expert');
$p->exec("SELECT set_config('app.member_id', '26', false)"); ok(one('SELECT dc_is_clerk()') === false, '...and false for a person');
$st = settings_row();
ok((int) $st['expert_member_id'] === EXPERT && (int) $st['clerk_member_id'] === CLERK, 'the settings hold both ids');
$r = post_json(1, '/admin/agents/save.php', ['expert' => 26]);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'agent'), 'a person cannot be the expert: 422 in words');
$r = post_json(1, '/admin/agents/save.php', ['clerk' => 41]);
ok($r['code'] === 422, 'an agent the kernel has not admitted (the Watcher) cannot be the Clerk: 422');
$r = post_json(1, '/admin/agents/save.php', ['clerk' => EXPERT]);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'two agents'), 'the expert and the Clerk are two agents: 422');
$r = post_json(1, '/admin/agents/save.php', ['untagged_batch_size' => 0]);
ok($r['code'] === 422 && isset($r['json']['error']), 'a batch of 0: 422');
$r = post_json(1, '/admin/agents/save.php', ['duplicate_copies' => 1]);
ok($r['code'] === 422, 'one copy is not a duplicate: 422');
$r = post_json(1, '/admin/agents/save.php', ['dispatch_retry_minutes' => 99999]);
ok($r['code'] === 422, 'a retry wait of 99999 minutes: 422');
$r = post_json(1, '/admin/agents/save.php', ['untagged_batch_size' => 5, 'duplicate_copies' => 4, 'dispatch_retry_minutes' => 10, 'clerk_report_enabled' => 'no']);
$st = settings_row();
ok($r['code'] === 200 && (int) $st['untagged_batch_size'] === 5 && (int) $st['duplicate_copies'] === 4 && (int) $st['dispatch_retry_minutes'] === 10 && $st['clerk_report_enabled'] === false, 'the thresholds and the switch save');
ok(report_as(CLERK) === [], 'with the Monday report switched off the Clerk gets an empty report');
post_json(1, '/admin/agents/save.php', ['untagged_batch_size' => 40, 'duplicate_copies' => 2, 'dispatch_retry_minutes' => 30, 'clerk_report_enabled' => 'yes']);
$r = post_json(1, '/admin/agents/save.php', []);
ok($r['code'] === 422, 'nothing to save: 422');
$r = post_json(1, '/admin/agents/save.php', ['expert' => '']);
ok($r['code'] === 200 && one('SELECT expert_member_id FROM dc_settings WHERE id = 1') === null, 'an empty expert means none');
name_agents();

echo "3. Who may\n";
foreach ([[27, 'a curator'], [26, 'a member'], [29, 'a guest']] as [$m, $who]) {
    $r = post_json($m, '/admin/agents/save.php', ['untagged_batch_size' => 7]);
    ok($r['code'] === 403, "$who may not save the agents' settings: 403");
}
$r = post_agent(EXPERT, 8101, '/admin/agents/save.php', ['untagged_batch_size' => 7]);
ok($r['code'] === 403, 'an agent holds no agents.settings: refused');
ok((int) settings_row()['untagged_batch_size'] === 40, 'and nothing changed');
$r = req('POST', '/admin/agents/save.php', ['headers' => ['Accept: application/json'], 'form' => ['untagged_batch_size' => 7]]);
ok($r['code'] === 401, 'no session: 401');

echo "4. The Agents screen\n";
$j = get_json(1, '/admin/agents');
$d = $j['json']['data'] ?? [];
ok($j['code'] === 200 && count($d['agents'] ?? []) === 2 && $d['agents'][0]['key'] === 'expert' && $d['agents'][1]['key'] === 'clerk', 'the admin sees the two agents');
ok($d['agents'][0]['state'] === 'hired' && $d['agents'][0]['member_id'] === EXPERT && $d['agents'][1]['state'] === 'hired' && $d['agents'][1]['member_id'] === CLERK, 'both named and admitted: hired');
ok(($d['agents'][1]['duty']['schedule_cron'] ?? '') === '50 6 * * 1', 'the Clerk\'s duty comes from maludb-os.json: 50 6 * * 1');
ok(($d['agents'][0]['tools'] ?? 0) > 40 && ($d['agents'][0]['skills'] ?? 0) === 7, 'the expert\'s tools and skills are counted from the manifest');
ok($d['settings']['untagged_batch_size'] === 40 && $d['settings']['clerk_member_id'] === CLERK, 'the thresholds are in the answer');
$html = page(actor(1), '/admin/agents')['body'];
foreach (['agents-thresholds', 'agent-card-expert', 'agent-card-clerk', 'dispatches-table', 'agents-save-btn', 'agents-field-expert', 'agents-field-clerk'] as $id) { ok(str_contains($html, 'id="' . $id . '"'), "the page has #$id"); }
$html27 = page(actor(27), '/admin/agents');
ok($html27['code'] === 403, 'a curator is refused the screen: 403');
$p->exec("UPDATE dc_settings SET clerk_member_id = NULL WHERE id = 1");
$d = get_json(1, '/admin/agents')['json']['data'];
ok($d['agents'][1]['state'] === 'not_named', 'a Clerk not named shows "not named"');
name_agents();
echo "5. The picker source\n";
$r = req('GET', '/pick/?source=agent&q=', ['jar' => actor(1)]);
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Seamus') && str_contains($r['body'], 'SMOKE Clerk') && !str_contains($r['body'], 'SMOKE Priya'), 'the agent picker lists agents (Seamus, the Clerk) and no person');
$r = req('GET', '/pick/?source=agent&q=', ['jar' => actor(27)]);
ok($r['code'] === 403, 'the agent picker is the admin\'s: a curator gets 403');
finish();
function settings_row(): array { return q('SELECT * FROM dc_settings WHERE id = 1')[0]; }
