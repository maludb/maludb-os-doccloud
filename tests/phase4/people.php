<?php
/**
 * Proof — agents, proposals, keys, people, notes and what is mine: clerk_proposals, agent_dispatches, find_keys, clerk_report, find_members, find_departments, find_notes, my_favourites, recent_documents,
 * my_notifications, my_tokens, my_uploads, get_settings, records_search. Never a key's value or hash, never a token.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$nda = $w['nda'];

echo "1. The Clerk's proposals, dispatches, the report\n";
q("INSERT INTO clerk_proposals (kind, document_id, for_member_id, title, payload, proposed_by) VALUES ('untagged', {$w['lease']}, 27, 'p4 Tag the lease', '{\"suggested\":[\"lease\"]}', 42), ('duplicate', {$w['dups'][0]}, 26, 'p4 Same file twice', '{}', 42)");
$cp = tdata(27, 'clerk_proposals');
$mine = find_row($cp, 'title', 'p4 Tag the lease');
ok($mine !== null && isset($mine['proposal_id']) && $mine['status'] === 'proposed' && $mine['payload']['suggested'] === ['lease'] && find_row($cp, 'title', 'p4 Same file twice') !== null, 'Marco (groups.manage) sees the proposal that is for him (proposal_id, status, payload) and the one for Priya');
ok(ids(tdata(26, 'clerk_proposals'), 'title') === ['p4 Same file twice'], 'Priya sees hers only');
ok(tdata(30, 'clerk_proposals') === [], 'Dana sees none');
ok(array_keys(tdata(27, 'clerk_proposals', ['q' => 'lease'])[0]) === ['proposal_id', 'title', 'kind'], 'q alone resolves one by title');
ok(count(tdata(1, 'clerk_proposals', ['kind' => 'duplicate', 'for' => 'SMOKE Priya'])) === 1 && count(tdata(1, 'clerk_proposals', ['status' => 'accepted'])) === 0, 'kind, for and status filters');
q("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, reply_excerpt, detail, folder_id) VALUES ('folder', {$w['inbox']}, 40, 'untagged_batch', 'chat', 27, 'failed', '" . str_repeat('r', 400) . "', 'the expert was busy', {$w['inbox']})");
$ad = tdata(1, 'agent_dispatches');
ok(count($ad) >= 1 && $ad[0]['agent_name'] === 'SMOKE Seamus' && strlen($ad[0]['reply_excerpt']) === 300 && $ad[0]['kind'] === 'untagged_batch', 'agent_dispatches (agents.settings): the agent named, the reply cut at 300');
ok(count(tdata(27, 'agent_dispatches')) >= 1 && count(tdata(30, 'agent_dispatches')) === 0, 'Marco, who caused it, sees it; Dana sees none');
ok(count(tdata(1, 'agent_dispatches', ['status' => 'answered'])) === 0 && count(tdata(1, 'agent_dispatches', ['agent' => 'SMOKE Seamus', 'kind' => 'untagged_batch'])) >= 1, 'status, agent and kind filters');
facts(950, 42, ['Records MCP' => ['clerk_report' => []]]);
$cr = mcp_tool(REC, rtoken(42, 950), 'clerk_report', []);
ok(!$cr['error'] && $cr['data']['week_days'] === 7, 'clerk_report as the Clerk');
$cd = $cr['data'];
ok($cd['untagged']['count'] >= 1 && $cd['duplicates']['count'] >= 1 && $cd['stale_guest_shares']['count'] >= 1 && $cd['links_expiring']['count'] >= 1 && $cd['links_suspicious']['count'] >= 1 && $cd['requests_open']['count'] >= 1 && $cd['trash_due']['count'] >= 1 && $cd['locked_long']['count'] >= 1 && $cd['extraction_failures']['count'] >= 1 && $cd['quota_near']['count'] >= 1 && $cd['orphaned']['count'] >= 1, 'every part of the Monday report: untagged, duplicates, old guest shares, links expiring and suspicious, requests, trash due, long locks, no text, quota, orphaned');
ok($cd['budget']['count'] === 1 && isset($cd['untagged']['folders']) && !str_contains($cr['text'], WORLD_SECRET), 'the budget flagged; untagged by folder; and no document\'s words anywhere in it');
ok(tool(26, 'clerk_report')['code'] === 'managers_only' && tool(27, 'clerk_report')['code'] === 'managers_only', 'a member and a curator: managers_only (the Clerk\'s alone)');
ok(!tool(1, 'clerk_report')['error'], 'the admin may read it (the function\'s own rule)');
$cw = tdata(1, 'clerk_report', ['week' => 1]);
ok($cw['week_days'] === 1, 'week');

echo "2. Keys, tokens, uploads\n";
ktoken_api(26);
$fk = tdata(26, 'find_keys');
ok(count($fk) >= 1 && isset($fk[0]['key_id']) && $fk[0]['is_live'] === true && !has_key($fk, 'token_hash') && !has_key($fk, 'token'), 'find_keys: Priya\'s key, live — never a value or a hash');
ok(array_keys(tdata(26, 'find_keys', ['q' => 'key'])[0]) === ['key_id', 'label'], 'q alone resolves a key by label');
ok(tdata(30, 'find_keys') === [] && tool(26, 'find_keys', ['member' => 'SMOKE Marco'])['code'] === 'managers_only', 'Dana has none; Priya may not list Marco\'s');
ok(count(tdata(1, 'find_keys', ['member' => 'SMOKE Priya'])) >= 1 && count(tdata(1, 'find_keys', ['live' => false])) >= count(tdata(1, 'find_keys')), 'the admin lists anyone\'s; live false includes the dead');
$mt = tdata(26, 'my_tokens');
ok(count($mt) >= 2 && array_unique(array_column($mt, 'scope')) !== [] && !has_key($mt, 'token_hash') && !has_key($mt, 'token'), 'my_tokens: her mcp token and key, no value');
ok(array_unique(array_column(tdata(26, 'my_tokens', ['scope' => 'api']), 'scope')) === ['api'], 'scope filter');
ok(count(array_filter(tdata(26, 'my_tokens'), fn ($t) => (int) $t['token_id'] === 0)) === 0 && count(array_filter(tdata(1, 'my_tokens'), fn ($t) => $t['label'] === 'SMOKE p4' && false)) === 0, 'and only her own, even for the admin');
$up = tdata(26, 'my_uploads');
ok(count($up) === 1 && $up[0]['filename'] === 'p4-big.bin' && $up[0]['parts_done'] === 1 && $up[0]['parts_total'] === 3, 'my_uploads: the session in flight, 1 of 3 parts');
ok(tdata(27, 'my_uploads') === [] && tdata(26, 'my_uploads', ['status' => 'done']) === [], 'Marco has none; status filter');

echo "3. People, departments, notes, mine\n";
$fm = tdata(26, 'find_members', ['q' => 'Marco']);
ok(array_keys($fm[0]) === ['member_id', 'display_name', 'member_kind'], 'q alone resolves a person');
$all = tdata(26, 'find_members', ['limit' => 100]);
ok(count($all) >= 8 && find_row($all, 'display_name', 'SMOKE Seamus')['is_agent'] === true && find_row($all, 'display_name', 'SMOKE Priya')['email'] !== null && find_row($all, 'display_name', 'SMOKE Marco')['email'] === null, 'find_members: everyone admitted; an email only for oneself');
ok(count(array_filter(tdata(26, 'find_members', ['kind' => 'agent', 'limit' => 100]), fn ($m) => !$m['is_agent'])) === 0 && ids(tdata(26, 'find_members', ['guests' => true]), 'display_name') === ['SMOKE Ann'], 'kind agent; guests');
ok(find_row(tdata(26, 'find_members', ['group' => 'p4-legal']), 'display_name', 'SMOKE Marco') !== null && find_row(tdata(26, 'find_members', ['group' => 'p4-legal']), 'display_name', 'SMOKE Dana') === null, 'by group');
$ga = tdata(29, 'find_members', ['limit' => 100]);
ok(ids($ga, 'display_name') !== [] && !in_array('SMOKE Dana', ids($ga, 'display_name'), true) && !in_array('SMOKE Priya', ids($ga, 'display_name'), true), 'Ann, a guest, sees herself and those who shared with her — not Dana, not Priya');
$dp = tdata(26, 'find_departments');
ok(is_array($dp) && count(tdata(26, 'find_departments', ['q' => 'Engineering'])) >= 1 && array_keys(tdata(26, 'find_departments', ['q' => 'Engineering'])[0]) === ['department_id', 'name'], 'find_departments: q alone resolves one');
ok(count(tdata(29, 'find_departments')) === 0, 'a guest sees no departments');
ok(count(tdata(26, 'find_members', ['department' => 'SMOKE Engineering', 'limit' => 100])) >= 1, 'members by department');
$fn = tdata(27, 'find_notes', ['document' => $nda]);
ok(count($fn) === 1 && $fn[0]['member_name'] === 'SMOKE Marco' && $fn[0]['body'] === 'p4 note on the nda', 'find_notes: the comment on the nda');
ok(tool(30, 'find_notes', ['document' => $nda])['code'] === 'not_found', 'Dana: not_found');
$fv = tdata(26, 'my_favourites');
ok(ids($fv, 'name') === ['p4-nda.pdf'] && $fv[0]['kind'] === 'document' && tdata(30, 'my_favourites') === [], 'my_favourites: the nda, for Priya only');
$rd = tdata(26, 'recent_documents');
ok(is_array($rd), 'recent_documents answers');
q("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'share_received', 'p4 something was shared', 'x'), (26, 'comment', 'p4 read one', 'x')");
q("UPDATE notifications SET read_at = now() WHERE title = 'p4 read one'");
$mn = tdata(26, 'my_notifications');
ok(array_key_exists('prefs', $mn) && $mn['notifications'][0]['title'] === 'p4 something was shared' && $mn['notifications'][0]['read_at'] === null, 'my_notifications: unread first; the preferences');
ok(count(tdata(26, 'my_notifications', ['unread_only' => true])['notifications']) === 1 && tdata(30, 'my_notifications')['notifications'] === [], 'unread_only; nobody sees another\'s');
$gs = tdata(26, 'get_settings');
ok($gs['trash_days'] === 30 && isset($gs['graph_monthly_text_mb']) && array_key_exists('business_name', $gs), 'get_settings: the windows and the budget');

echo "4. records_search, the long tail\n";
$rs = tool(26, 'records_search', ['sql' => 'SELECT name FROM mcp_documents ORDER BY name']);
ok(!$rs['error'] && in_array('p4-nda.pdf', array_column($rs['data'], 'name'), true) && !in_array('p4-dana-private.pdf', array_column($rs['data'], 'name'), true), 'records_search over the views answers as the asker: the nda yes, Dana\'s private no');
foreach (['SELECT 1; SELECT 2' => 'one statement', 'DELETE FROM documents' => 'a read', "SELECT set_config('app.member_id','1',true)" => 'set_config', 'SELECT storage_path FROM blobs' => 'storage_path', "SELECT mcp_admit_agent(41)" => 'mcp_admit_agent', "SELECT * FROM dc_link_resolve('x')" => 'dc_link_', "SELECT password_hash FROM members" => 'password_hash'] as $sql => $why) {
    $r = tool(1, 'records_search', ['sql' => $sql]);
    ok($r['data']['error'] ?? null !== null && is_string($r['data']['error'] ?? null), "records_search refuses: $why");
}
ok(count(tdata(26, 'records_search', ['sql' => 'SELECT * FROM mcp_documents'])) <= 200, 'at most 200 rows');
$bad = tool(26, 'records_search', ['sql' => 'SELECT * FROM documents']);
ok(is_string($bad['data']['error'] ?? null), 'a table the read role cannot see: "Query failed", not a leak');
finish();
