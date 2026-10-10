<?php
/**
 * Proof — the gate: the three token shapes and what each is offered (docs/doccloud-mcp-tool-surface.md "Who may call"). A person's own mcp_ token sees every tool; a KEY (scope api) is refused; the tenant's signed
 * action/run tokens name a member the mirror admits; an agent's run token is offered exactly the tools the (fake) kernel's run-facts names for THIS endpoint, fail closed when the kernel is down or does not vouch; the
 * kernel's own token reaches app_roles and the four shares only; an evaluation run reads and leaves no search_log row.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();

echo "1. The surface: every tool the document names, offered to a person, annotated read-only\n";
$all = mcp_tools(REC, ptoken(26));
$acts = mcp_tools(ACT, ptoken(26));
$perPerson = array_values(array_diff($S['records'], $S['kernel_only']));
sort($perPerson);
ok($all !== null && $all === $perPerson, 'a person is offered the records tools of the surface (' . count($perPerson ?? []) . ': every one but the three that answer only as an application principal)');
ok($acts === $S['activity'] && count($acts) === 7, 'and the seven activity tools');
$defs = mcp_tool_defs(REC, ptoken(26));
$ro = array_filter($defs, fn ($t) => ($t['annotations']['readOnlyHint'] ?? false) === true && ($t['annotations']['openWorldHint'] ?? true) === false);
ok(count($ro) === count($defs), 'every records tool carries readOnlyHint true and openWorldHint false');
$noDesc = array_filter($defs, fn ($t) => strlen((string) ($t['description'] ?? '')) < 40);
ok($noDesc === [], 'every tool says what it is for (a description an agent can choose by)');
$byName = array_column($defs, null, 'name');
ok(isset($byName['find_documents']['inputSchema']['properties']['params']) || isset($byName['find_documents']['inputSchema']['$defs']), 'a tool takes one `params` object');

echo "2. Who is refused\n";
ok(mcp_tools(REC, '') === null, 'no bearer: 401');
ok(mcp_tools(REC, 'mcp_' . str_repeat('0', 48)) === null, 'a token nobody minted: 401');
ok(mcp_tools(REC, ktoken_api(26)) === null, 'an API key (scope api) is not a token for the MCP servers: 401');
ok(mcp_tools(REC, atoken(26, -5)) === null, 'an expired action token: 401');
$p = '26.' . (time() + 300);
ok(mcp_tools(REC, $p . '.' . hash_hmac('sha256', $p, 'not-the-key')) === null, 'an action token signed with another key: 401');
ok(mcp_tools(REC, atoken(999)) === null, 'an action token for a member the mirror does not know: 401 (an unknown id is refused, never created)');
ok(mcp_tools(REC, kernel_token('someoneelse')) === null, 'a kernel token bound to another application: 401');
ok(mcp_tools(ACT, ptoken(26) . 'x') === null, 'a token with a letter changed: 401');
ok(mcp_tools(REC, ptoken(31)) === null, 'Lee has left: his token resolves to nobody — 401');

echo "3. A person: their own token, or the signed action token of the command bar\n";
ok(mcp_tools(REC, atoken(26)) === $perPerson, 'Priya\'s action token is offered the same tools');
ok(mcp_tools(REC, ptoken(29)) === $perPerson, 'the guest Ann\'s token is offered them too (rows decide what she sees, not the list)');
$me = tdata(26, 'get_settings');
ok(is_array($me) && isset($me['trash_days']) && !array_key_exists('model_alias', $me) || ($me['model_alias'] ?? null) === null, 'get_settings as a member: the model fields are not shown');
$adm = tdata(1, 'get_settings');
ok(is_array($adm) && array_key_exists('model_alias', $adm), 'and the admin\'s answer carries them');
$roles = tool(26, 'app_roles');
ok($roles['data']['schema'] === 'os.app-roles/1' && $roles['data']['application'] === 'doccloud' && count($roles['data']['roles']) === 4 && count($roles['data']['rights']) === 28, 'app_roles answers os.app-roles/1: 4 roles, 28 rights');
ok(array_column($roles['data']['roles'], 'key') === ['guest', 'user', 'curator', 'admin'] && count(array_filter($roles['data']['roles'], fn ($r) => $r['is_admin'])) === 1, 'Guest, Member (user), Curator, DocCloud admin — one is_admin');

echo "4. An agent: only what the kernel's run-facts names for THIS endpoint\n";
facts(901, 40, ['Records MCP' => ['find_documents' => [], 'search' => [], 'document_text' => []], 'Activity MCP' => ['recent_activity' => []]]);
$t = rtoken(40, 901);
ok(mcp_tools(REC, $t) === ['document_text', 'find_documents', 'search'], 'Seamus (run 901) is offered exactly the three Records tools the kernel granted');
ok(mcp_tools(ACT, $t) === ['recent_activity'], '...and on the Activity server exactly its one');
$ok = mcp_tool(REC, $t, 'find_documents', ['q' => 'acme-msa']);
ok(!$ok['error'] && count($ok['data']['rows'] ?? $ok['data']) >= 1, 'a granted tool answers');
$no = mcp_tool(REC, $t, 'get_document', ['document' => $w['board_doc']]);
ok($no['error'] && str_contains($no['text'], 'not among the tools this agent was granted'), 'a tool he was not granted is refused in words');
$no2 = mcp_tool(REC, $t, 'app_roles');
ok($no2['error'], 'app_roles was not granted either: refused');
$ko = mcp_tool(REC, $t, 'folders_index', [], true);
ok($ko['error'], 'a share is not callable by an agent');
facts(902, 40, ['Records MCP' => ['find_documents' => []]], ['valid' => false]);
ok(mcp_tools(REC, rtoken(40, 902)) === [], 'the kernel says valid:false for run 902: nothing is offered (fail closed)');
ok(mcp_tool(REC, rtoken(40, 902), 'find_documents', ['q' => 'acme'])['error'], '...and nothing can be called');
ok(mcp_tools(REC, rtoken(40, 909)) === [], 'a run the kernel has never heard of (909): nothing is offered');
facts(903, 40, ['Another MCP' => ['find_documents' => []]]);
ok(mcp_tools(REC, rtoken(40, 903)) === [], 'grants on another endpoint are not grants here');
facts(904, 42, ['Records MCP' => ['clerk_report' => [], 'find_libraries' => []]]);
ok(mcp_tools(REC, rtoken(42, 904)) === ['clerk_report', 'find_libraries'], 'the Clerk (run 904) is offered its two');

echo "5. First contact: an agent the mirror has but has not admitted\n";
$before = (string) one('SELECT capability FROM members WHERE id = 41');
ok($before === '', 'the Watcher (41) is not admitted yet');
ok(mcp_tools(REC, rtoken(41, 910)) === null, 'a run token the kernel does not vouch for: 401');
facts(911, 41, []);
ok(mcp_tools(REC, rtoken(41, 911)) === null, 'vouched but with no endpoints of this application: still 401');
ok((string) one('SELECT capability FROM members WHERE id = 41') === '', '...and not admitted');
facts(912, 41, ['Records MCP' => ['find_folders' => []]]);
ok(mcp_tools(REC, rtoken(41, 912)) === ['find_folders'], 'vouched with endpoints here: admitted on first contact and offered its one tool');
ok((string) one('SELECT capability FROM members WHERE id = 41') === 'write', 'the mirror now admits the Watcher (mcp_admit_agent)');
q("UPDATE members SET capability = NULL WHERE id = 41");

echo "6. The kernel down: fail closed\n";
$pid = (int) trim((string) @file_get_contents(need('DC_DEV_STATE') . '/kernel.pid'));
facts(920, 40, ['Records MCP' => ['find_documents' => []]]);
shell_exec('kill ' . $pid . ' 2>/dev/null; sleep 0.5');
ok(mcp_tools(REC, rtoken(40, 920)) === [], 'with the kernel unreachable an agent run (920) is offered nothing');
ok(mcp_tool(REC, rtoken(40, 920), 'find_documents', ['q' => 'acme'])['error'], '...and can call nothing');
ok(mcp_tools(REC, rtoken(41, 921)) === null, 'a first contact cannot be vouched for: 401');
ok(mcp_tools(REC, ptoken(26)) === $perPerson, 'a PERSON is not the kernel\'s to judge: their token still works');
shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && (php -S 127.0.0.1:8702 tests/fake_kernel.php >> ' . escapeshellarg(need('DC_DEV_STATE') . '/kernel.log') . ' 2>&1 & echo $! > ' . escapeshellarg(need('DC_DEV_STATE') . '/kernel.pid') . ')');
for ($i = 0; $i < 30; $i++) { $c = curl_init('http://127.0.0.1:8702/x'); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]); curl_exec($c); if (curl_getinfo($c, CURLINFO_HTTP_CODE) > 0) { break; } usleep(200000); }
ok(mcp_tools(REC, rtoken(40, 920)) === ['find_documents'], 'the kernel back: the same run token is offered its tool again (the failure was not cached)');

echo "7. An evaluation run reads, and leaves no search_log row\n";
facts(930, 40, ['Records MCP' => ['search' => []]], ['trigger' => 'eval']);
facts(931, 40, ['Records MCP' => ['search' => []]]);
$n0 = (int) one('SELECT count(*) FROM search_log WHERE member_id = 40');
$e = mcp_tool(REC, rtoken(40, 930), 'search', ['q' => 'quokka']);
ok(!$e['error'] && ($e['data']['total'] ?? 0) >= 0, 'search under an eval run answers');
ok((int) one('SELECT count(*) FROM search_log WHERE member_id = 40') === $n0, '...and writes no search_log row');
$ev = last_event('search.keyword');
ok($ev !== null && (int) $ev['actor_member_id'] === 40 && str_contains((string) $ev['after'], '"eval"'), '...only the search.keyword line, marked eval, and no words of the query beyond the cut');
$n = mcp_tool(REC, rtoken(40, 931), 'search', ['q' => 'quokka']);
ok(!$n['error'] && (int) one('SELECT count(*) FROM search_log WHERE member_id = 40') === $n0 + 1, 'the same search in a normal run writes one search_log row');
$row = q('SELECT kind, source, query FROM search_log WHERE member_id = 40 ORDER BY id DESC LIMIT 1')[0];
ok($row['kind'] === 'keyword' && $row['source'] === 'mcp' && $row['query'] === 'quokka', '...kind keyword, source mcp, the query');
$ev = last_event('search.keyword');
ok($ev['source'] === 'agent' && (int) $ev['agent_run_id'] === 931 && $ev['request_id'] === 'req-run-931', 'logged as the agent\'s own call: source agent, the run id and the kernel\'s request id');

echo "8. The kernel's own token: app_roles and the four shares, nothing else\n";
$kt = kernel_token();
ok(mcp_tools(REC, $kt) === ['app_roles', 'document_link', 'document_text', 'documents_index', 'folders_index'], 'it is offered app_roles and the four shares');
ok(mcp_tools(ACT, $kt) === [], 'and nothing on the activity server');
$ar = mcp_tool(REC, $kt, 'app_roles', [], true);
ok(!$ar['error'] && $ar['data']['schema'] === 'os.app-roles/1', 'app_roles answers it');
foreach (['find_documents' => ['q' => 'x'], 'get_document' => ['document' => 1], 'search' => ['q' => 'x'], 'records_search' => ['sql' => 'select 1'], 'my_tokens' => []] as $tn => $a) {
    $r = mcp_tool(REC, kernel_token(), $tn, $a);
    ok($r['error'] && str_contains($r['text'], 'reaches'), "the kernel's token cannot call $tn");
}
foreach ($S['kernel_only'] as $tn) {
    $r = mcp_tool(REC, ptoken(1), $tn, [], true);
    ok($r['error'], "$tn: even the admin's own token cannot call it (an application principal's tool)");
}
ok(!in_array('document_text', array_diff($S['records'], $perPerson), true) && in_array('document_text', $all, true), 'document_text is both: a person reads a document with it, the kernel shares it');
finish();
