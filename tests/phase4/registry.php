<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-doccloud.json + mcp/action_registry.json, composed as the installer writes mcp/registries/doccloud.json,
 * are loaded by the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does (application_actions.register): a tool per built action, approval categories the
 * kernel knows, the `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp) with the
 * caller's token; and a few whole actions are driven through the kernel's tool (the approval hook and the handler stubbed — nothing is written). Then maludb-os.json: the agents' grants name tools and actions that exist.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$S = surface();
$root = dirname(__DIR__, 2);
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-doccloud.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'doccloud' && count($wrapper['resolve']) === 12, 'the wrapper: schema maludb-os.registry/1, app_key doccloud, twelve resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry is current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 97 && count(array_filter($actions, fn ($a) => !empty($a['built']))) === 97, 'the registry holds 97 actions, every one built (95 + the grid-and-media pair)');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
$withCat = count(array_filter($actions, fn ($a) => $a['approval'] !== null));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && $withCat === 23, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); ' . $withCat . ' actions carry one');
$missing = [];
foreach ($actions as $k => $a) { $p = $root . '/html' . parse_url($a['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p))) { $missing[] = $k . ' → ' . $a['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $k; } } }
ok($phantom === [], 'no action carries a phantom parameter');
ok(array_filter($actions, fn ($a) => empty($a['log_event'])) === [], 'every action names the log event the approval hook asks about');

echo "2. Every resolve entity: its tool exists on the records server and answers the plain list\n";
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $S['records'], true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} is on the records server; id {$spec['id_field']}, label {$spec['label_field']}, params " . implode(', ', $spec['params']));
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$idsOnly = ['version', 'access', 'upload_session', 'dispatch', 'notification', 'token', 'note', 'graph_job', 'subject', 'tag_proposal'];
ok(count(array_intersect($idsOnly, array_keys($pm))) === 0, 'the ids-only params (version, access, upload_session, dispatch, notification, token, note, graph_job, subject, tag_proposal) are never resolved by name');
$used = [];
foreach ($actions as $a) { foreach ($a['params'] as $p) { if (isset($pm[$p['name']])) { $used[$p['name']] = true; } } }
ok(count($used) >= 12, 'the resolved params are really used by actions (' . count($used) . ' distinct names)');

q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) SELECT 26, 'SMOKE p4 resolvable key', :h, 'api' WHERE NOT EXISTS (SELECT 1 FROM mcp_access_tokens WHERE label = 'SMOKE p4 resolvable key')", ['h' => hash('sha256', 'dck_resolvable')]);
$resolve = [
    ['document', 'p4-nda', atoken(27)], ['document', (string) $w['nda'], atoken(27)], ['folder', 'p4 Contracts', atoken(27)], ['library', 'SMOKE p4 Legal', atoken(27)], ['group', 'p4-legal', atoken(27)], ['tag', 'p4-legal', atoken(27)],
    ['link', 'w8 expiring link', atoken(27)], ['request', 'p4 legal request', atoken(27)], ['saved_search', 'p4 quokka', atoken(26)], ['proposal', 'Tag the lease', atoken(27)], ['api_key', 'SMOKE p4 resolvable key', atoken(26)],
    ['member', 'SMOKE Dana', atoken(27)], ['department', 'Engineering', atoken(27)],
];
$drive = [
    ['document_trash', ['document' => 'p4-lease.pdf'], atoken(27)],
    ['document_move', ['document' => 'p4-lease.pdf', 'target_folder' => 'p4 Contracts'], atoken(27)],
    ['tag_apply', ['document' => 'p4-nda.pdf', 'tags' => 'p4-legal'], atoken(27)],
    ['group_member_add', ['group' => 'p4-legal', 'member' => 'SMOKE Dana', 'role' => 'member'], atoken(27)],
    ['link_make', ['folder' => 'p4 Contracts', 'label' => 'p4 never made', 'confirmed' => true], atoken(27)],
    ['share_guest', ['folder' => 'p4 Contracts', 'guest' => 'SMOKE Ann', 'level' => 'view', 'confirmed' => true], atoken(27), 'pending_approval'],
    ['folder_create', ['parent' => 'p4 Contracts', 'name' => 'p4 never created'], atoken(27)],
    ['document_describe', ['document' => 'nonesuch document', 'description' => 'x'], atoken(27)],
    ['document_move', ['document' => 'p4-nda.pdf', 'target_folder' => 'Contracts'], atoken(27)],
];
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = escapeshellarg($kpy) . ' ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('DC_DEV_ENV')])) . ' 2>/tmp/dc-registry-check.err';
$out = (string) shell_exec($cmd);
$k = json_decode($out, true);
ok(is_array($k), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($k) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/dc-registry-check.err'), -400)));
if (!is_array($k)) { finish(); }
ok($k['loaded'] === ['doccloud'] && $k['registered'] === 97, 'application_actions.load_registries() accepts it and register() adds 97 tools');
ok(count($k['tools']) === 97 && count(array_diff(array_keys($actions), array_keys($k['tools']))) === 0, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $a) { if ($a['approval'] !== null && !str_contains($k['tools'][$key]['description'], 'category: ' . $a['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . $withCat . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $a) { $txt = json_encode($k['tools'][$key]['schema']); if (!empty($a['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [], 'every destructive action carries the `confirmed` guard (' . count(array_filter($actions, fn ($a) => !empty($a['confirm']))) . ' of them)');
$noParam = [];
foreach ($actions as $key => $a) { $props = array_keys($k['tools'][$key]['schema']['$defs'][array_key_first($k['tools'][$key]['schema']['$defs'] ?? [])]['properties'] ?? []); foreach ($a['params'] as $p) { if (($p['name'] ?? null) && !in_array($p['name'], $props, true)) { $noParam[] = "$key.{$p['name']}"; } } }
ok($noParam === [], 'every manifest parameter is a field of its tool' . ($noParam ? ' — missing: ' . implode(', ', array_slice($noParam, 0, 5)) : ''));
echo "   resolving through the kernel's resolver, as the person who asks:\n";
foreach ($resolve as [$entity, $value]) {
    $r = $k['resolved'][$entity . '|' . $value] ?? null;
    $spec = $wrapper['resolve'][$entity];
    ok($r !== null && !isset($r['error']) && $r['matches'] === 1 && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true),
        "resolver: $entity '" . substr((string) $value, 0, 24) . "' → 1 match" . ($r && isset($r['label']) ? ', ' . $spec['id_field'] . ' ' . substr((string) $r['id'], 0, 8) . ', "' . substr((string) $r['label'], 0, 40) . '"' : ' — ' . json_encode($r)));
}
$id = fn ($e, $v) => (string) $k['resolved'][$e . '|' . $v]['id'];
$d = $k['driven'];
echo "3. Whole actions through the kernel's tool\n";
ok(($d[0]['answer']['status'] ?? '') === 'success' && count($d[0]['posts']) === 1 && $d[0]['posts'][0]['path'] === '/documents/trash.php' && $d[0]['posts'][0]['fields'] === ['document' => (string) $w['lease']], 'document_trash by NAME (no approval): the name resolves to the lease\'s id and the handler is posted the id — the kernel\'s hook is not asked');
ok(($d[1]['answer']['status'] ?? '') === 'success' && $d[1]['posts'][0]['fields'] === ['document' => (string) $w['lease'], 'target_folder' => (string) $w['legal_contracts']], 'document_move: the document and the target folder both resolved by name');
ok(($d[2]['answer']['status'] ?? '') === 'success' && $d[2]['posts'][0]['path'] === '/tags/apply.php' && $d[2]['posts'][0]['fields']['document'] === (string) $w['nda'] && $d[2]['posts'][0]['fields']['tags[]'] === ['p4-legal'], 'tag_apply: the document resolved; the repeated tags (slugs) are passed as given, never resolved');
ok(($d[3]['answer']['status'] ?? '') === 'success' && $d[3]['posts'][0]['fields']['group'] === $id('group', 'p4-legal') && $d[3]['posts'][0]['fields']['member'] === '30', 'group_member_add: the group and the person resolved by name (Dana is 30)');
ok(($d[4]['answer']['status'] ?? '') === 'success' && $d[4]['posts'][0]['fields']['log_event'] === 'link.make' && $d[4]['posts'][1]['fields']['folder'] === (string) $w['legal_contracts'] && $d[4]['posts'][1]['fields']['label'] === 'p4 never made', 'link_make (external_send, confirmed): the hook is asked about link.make, then the folder resolved, the label as given');
ok(($d[5]['answer']['status'] ?? '') === 'pending_approval' && count($d[5]['posts']) === 1 && $d[5]['posts'][0]['fields']['log_event'] === 'access.share_guest' && $d[5]['answer']['resolved']['guest'] === 'SMOKE Ann', 'share_guest paused for approval: the guest resolved by name, nothing posted to DocCloud');
ok(($d[6]['answer']['status'] ?? '') === 'success' && $d[6]['posts'][0]['fields']['parent'] === (string) $w['legal_contracts'], 'folder_create: the parent resolved by name');
ok(($d[7]['answer']['status'] ?? '') === 'error' && str_contains($d[7]['answer']['message'], "No document matching 'nonesuch document'") && $d[7]['posts'] === [], 'an unknown name: "No document matching …" and nothing posted');
ok(($d[8]['answer']['status'] ?? '') === 'error' && str_contains($d[8]['answer']['message'], 'Several match') && $d[8]['posts'] === [], 'an ambiguous name (Contracts — two folders): the kernel lists the candidates and posts nothing');
$wrote = (int) one("SELECT count(*) FROM share_links WHERE label = 'p4 never made'") + (int) one("SELECT count(*) FROM folders WHERE name = 'p4 never created'");
ok($wrote === 0, 'and nothing was written to the application: the handler posts were stubs');

echo "4. maludb-os.json is in step with what the servers offer\n";
$agents = array_column($manifest['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'clerk'], 'two agents hired on install: the expert and the Filing Clerk');
$badTool = [];
foreach ($manifest['agents'] as $a) {
    foreach ($a['tool_grants']['Records MCP'] ?? [] as $t) { if (!in_array($t, $S['records'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Activity MCP'] ?? [] as $t) { if (!in_array($t, $S['activity'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
    foreach ($a['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($actions[$t]) && !in_array($t, ['message_send', 'inbox_read'], true)) { $badTool[] = $a['key'] . ' ' . $t; } }
}
ok($badTool === [], 'every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry (or the kernel\'s own message tools)' . ($badTool ? ' — ' . implode(', ', $badTool) : ''));
ok(count(array_intersect(array_merge($agents['expert']['tool_grants']['Records MCP'], $agents['clerk']['tool_grants']['Records MCP']), $S['kernel_only'])) === 0, 'no agent is granted a share that answers only as an application principal');
echo "  note  the expert is granted records_search (the surface's 'Agents' grants' paragraph says it is not) and the Clerk is not granted clerk_report (its own tool) — the manifest is the owner's, reported not changed\n";
ok(!in_array('graph_find', $agents['expert']['tool_grants']['Records MCP'], true) === false, 'the expert is granted graph_find (it calls it only when the person asks)');
$shares = array_column($manifest['shares'], 'tool');
ok($shares === $S['shares'] && count(array_filter($manifest['shares'], fn ($s) => ($s['timeout_seconds'] ?? 0) === 60)) === 1, 'shares[] names exactly the four tools; document_text declares the 60-second wait');
$appr = array_column($manifest['approvals'], 'category', 'action');
$bad = [];
foreach ($manifest['approvals'] as $a) { if (!isset($actions[$a['action']]) || $actions[$a['action']]['approval'] !== $a['category']) { $bad[] = $a['action']; } }
ok($bad === [] && count($appr) === $withCat, 'approvals[] is the manifest\'s ' . $withCat . ' pauses, each an action of the registry with that category');
ok((int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/sync_approvals.php --check >/dev/null 2>&1; echo $?') === 0, 'and bin/sync_approvals.php --check agrees');
foreach ($manifest['skills'] as $s) { ok(is_file($root . '/' . $s . '/SKILL.md'), "skill $s has its SKILL.md"); }
$known = array_merge($S['records'], $S['activity'], array_keys($actions), ['message_send', 'inbox_read', 'stale_share', 'link_expiring', 'link_suspicious', 'request_open', 'trash_due', 'no_text']);
$unknown = [];
foreach (array_merge(glob($root . '/skills/*/*.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $name) { if (!in_array($name, $known, true) && preg_match('/^(clerk|dispatch|agent|graph|document|folder|share|tag|link|find|get|my|search|record|actor|recent|storage|quota|extraction|stale|trash|locked|access|saved|run|untagged|duplicates|downloads|group|library|app)_/', $name)) { $unknown[] = basename(dirname($f)) . '/' . basename($f) . ': ' . $name; } }
}
ok($unknown === [], 'every tool or action a skill or a job description names exists' . ($unknown ? ' — ' . implode('; ', $unknown) : ''));
finish();
