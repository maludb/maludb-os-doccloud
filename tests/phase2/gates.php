<?php
/**
 * Proof: rights per role on the shell's screens and the twelve placeholders (200 vs 403), the placeholders' behaviour (a page naming its
 * slice, 501 for JSON and for a POST, `stub` in the registry), the menu and the tab bar per role, no-session answers, the home's JSON
 * (first_run, the libraries per role, a guest none), the person-own screens as JSON, the command bar through the kernel's chat endpoint,
 * the action-token / run-token gate, the mirror (the personal library on sign-on and on a sync, none for a guest; a department group
 * following the feed; a revoked grant shutting the door) — sso-shell.md "Proof" 2 and 3.
 * Run after the browser proof through tests/phase2/run.sh (the store it seeds is idempotent).
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// route => the roles that get 200; the roles are admin (the owner 1), curator (Marco 27), member (Priya 26), member-by-capability (Bea 28), guest (Ann 29).
$all = 'admin curator member bea guest';
$members = 'admin curator member bea';
$matrix = [
    '/' => $all, '/shared/' => $all, '/activity' => $all, '/notifications' => $all, '/settings/' => $all, '/settings/tokens/' => $all,
    '/groups/' => $members, '/libraries/' => $members, '/recent' => $members, '/favourites' => $members, '/tags/' => $members, '/search/' => $members,
    '/graph/' => $members, '/trash/' => $members,
    '/curate/' => 'admin curator', '/admin/' => 'admin',
];
[$jAdmin, ] = sign_on(1);
[$jCurator, ] = sign_on(27);
[$jMember, ] = sign_on(26);
[$jBea, ] = sign_on(28);
[$jGuest, ] = sign_on(29);
$who = ['admin' => $jAdmin, 'curator' => $jCurator, 'member' => $jMember, 'bea' => $jBea, 'guest' => $jGuest];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 5 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($jCurator, '/admin/');
ok(str_contains($r['body'], 'You may not change the settings.') && str_contains($r['body'], 'id="message-text"'), 'a refusal says what the person may not do, in words ("You may not change the settings.")');
$r = page($jGuest, '/files/');
ok($r['code'] === 302 && str_ends_with($r['location'], '/shared/'), 'a guest on My files is sent to Shared with me (no personal library; slice 1)');
$r = page($jMember, '/files/');
ok($r['code'] === 302 && preg_match('#/f/\d+$#', $r['location']) === 1, 'a member on My files is sent to their personal root (slice 1)');
ok(badge_of($jAdmin) === 'Super-admin' && badge_of($jCurator) === 'Curator' && badge_of($jMember) === 'Member' && badge_of($jBea) === 'Member' && badge_of($jGuest) === 'Guest', 'the header badge reads the highest role held: Super-admin, Curator, Member, Member (by capability), Guest');

echo "2. The placeholders — a page naming its slice, 501 for JSON and for a POST\n";
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs === 6, "six controllers call render_nav_stub (slice 1 built My files, Groups, Libraries, Recent, Favourites; slice 3 Shared with me): $stubs");
$ph = ['tags' => ['/tags/', 'slice 4', 'Tags'], 'search' => ['/search/', 'slice 4', 'Search'],
       'graph' => ['/graph/', 'slice 5', 'Graph'], 'trash' => ['/trash/', 'slice 6', 'Trash'], 'curate' => ['/curate/', 'slice 9', 'Curate'], 'admin' => ['/admin/', 'slice 9', 'Admin']];
$bad = [];
foreach ($ph as $id => [$url, $slice, $title]) {
    $b = page($jAdmin, $url)['body'];
    if (!(str_contains($b, 'id="' . $id . '-coming"') && str_contains($b, $title . ' is not built yet') && str_contains($b, $slice . ' builds this screen') && str_contains($b, '<title>' . $title . ' · DocCloud</title>'))) { $bad[] = $url; }
}
ok($bad === [], 'every placeholder: the shell, the header and one card saying which slice builds it' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$r = page($jAdmin, '/search/', $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'a JSON caller on a placeholder: 501 not_built');
$r = req('POST', '/search/', ['jar' => $jAdmin, 'form' => ['csrf_token' => page_csrf($jAdmin)]]);
ok($r['code'] === 501 && str_contains($r['body'], 'This is built by slice 4.'), 'a POST to a placeholder: 501, in words');
$r = page($jAdmin, '/graph/', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="graph-coming"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Graph/mi', $r['headers']) === 1 && preg_match('/^X-Screen: graph\r?$/mi', $r['headers']) === 1, 'over HTMX a placeholder is the partial with HX-Title and X-Screen, so the tab highlight and the command bar follow');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'graph'");
ok($v >= 2, "opening a placeholder logs screen.view for its screen ($v for graph)");
$built = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$reg = [];
foreach (($built['screens'] ?? []) as $s) { $reg[$s['screen'] ?? ''] = $s; }
$stubIds = array_values(array_filter(array_keys($reg), static fn ($k) => !empty($reg[$k]['stub'])));
$builtIds = array_values(array_filter(array_keys($reg), static fn ($k) => !empty($reg[$k]['built'])));
ok(count($stubIds) === 6 && ($reg['home']['built'] ?? null) === true && ($reg['settings-tokens']['built'] ?? null) === true && ($reg['admin']['stub'] ?? null) === true && ($reg['folder']['built'] ?? null) === true, 'the registry marks six screens as stubs and the shell\'s five plus slices 1–3\'s as built, so the voice surface never offers an unbuilt screen (' . count($stubIds) . ' stubs, ' . count($builtIds) . ' built)');

echo "3. The menu and the tab bar per role, and no session\n";
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? array_values(array_filter($m[1], fn ($i) => !str_starts_with($i, 'library-'))) : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
$seed = seed_store();                                                       // the browser proof seeded it already; idempotent
$full = ['home', 'files', 'shared', 'groups', 'libraries', 'recent', 'favourites', 'tags', 'search', 'graph', 'trash', 'my-settings', 'notifications', 'tokens', 'activity'];
ok($menu($jMember) === $full && $groups($jMember) === ['Your libraries', 'Me'], 'a Member sees the eleven items, Your libraries, then Me — no Curate, no Admin: ' . implode(', ', $menu($jMember)));
ok($menu($jGuest) === ['home', 'shared', 'my-settings', 'notifications', 'tokens', 'activity'] && $groups($jGuest) === ['Me'], 'a guest sees Home, Shared with me and Me only — no My files, Groups, Tags, Graph, Trash, and no Your libraries');
ok(in_array('curate', $menu($jCurator), true) && !in_array('admin', $menu($jCurator), true) && $groups($jCurator) === ['Your libraries', 'Me', 'Curate'], 'a curator sees Curate, not Admin');
ok(in_array('admin', $menu($jAdmin), true) && in_array('curate', $menu($jAdmin), true) && $groups($jAdmin) === ['Your libraries', 'Me', 'Curate', 'Admin'], 'the admin sees Curate and Admin');
$tabs = fn (string $j): array => (preg_match_all('/id="tab-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok($tabs($jMember) === ['home', 'files', 'search', 'my-settings'], 'the phone\'s tab bar is Home · Files · Search · Me for a member: ' . implode(', ', $tabs($jMember)));
ok($tabs($jGuest) === ['home', 'shared', 'my-settings'] && str_contains(page($jGuest, '/')['body'], 'id="tab-shared" class="app-tab'), 'a guest\'s Files tab reads Shared with me (and no Search: a guest holds no search.use)');
$r = page(jar(), '/files/');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a screen goes to the launcher with ?app=doccloud');
$r = req('GET', '/files/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/graph/');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session on a placeholder: the launcher too (the right is checked after the login)');

echo "4. The home — JSON, and the lists\n";
[$jDana, ] = sign_on(30);                                                   // Dana: a Member whose personal library holds nothing and who is in no group with documents
$d = json_decode(page($jDana, '/', $json)['body'], true)['data'] ?? [];
ok(($d['first_run'] ?? null) === true && $d['may']['upload'] === true && $d['may']['admin'] === false && $d['personal_root'] > 0, 'a new Member\'s home JSON: first_run true, may.upload, personal_root (Dana)');
$h = page($jDana, '/')['body'];
ok(str_contains($h, 'id="home-upload-first-btn"') && str_contains($h, 'Upload your first file') && str_contains($h, 'href="/f/' . (int) $d['personal_root'] . '?upload=1"'), 'the one button, Upload your first file, points at the root of My files');
$d = json_decode(page($jMember, '/', $json)['body'], true)['data'] ?? [];
$kinds = array_column($d['libraries'] ?? [], 'kind', 'library_id');
ok(($d['first_run'] ?? null) === false && count($d['libraries']) === 2 && in_array('personal', $kinds, true) && in_array('group', $kinds, true) && $d['guest'] === false, 'Priya (a document of her own, a group): first_run false, two libraries — hers and the Board');
ok(count($d['recent']) >= 1 && (int) $d['recent'][0]['document_id'] === $seed['priya_doc'], 'her recent documents name her notes');
$h = page($jMember, '/')['body'];
ok(str_contains($h, 'id="home-libraries-list"') && str_contains($h, '<label>Your libraries</label>') && substr_count($h, 'id="nav-library-') === 2 && !str_contains($h, 'id="home-first-run"'), 'her home lists the libraries as cards and the sidebar Your libraries (two); the first-run card is gone');
ok(preg_match('#href="/f/' . $seed['board_root'] . '\?back=%2F"#', $h) === 1, 'a library card\'s name is a link to its root folder carrying ?back= (click-around)');
$d = json_decode(page($jCurator, '/', $json)['body'], true)['data'] ?? [];
ok(count($d['libraries']) === 2 && $d['may']['curate'] === true, 'Marco: his library and the Board; may.curate');
$d = json_decode(page($jGuest, '/', $json)['body'], true)['data'] ?? [];
ok($d['guest'] === true && $d['libraries'] === [] && (int) $d['shared_count'] === 1 && ($d['first_run'] ?? null) === false, 'a guest: no library, one thing shared (Marco\'s document), never first_run');
$h = page($jGuest, '/')['body'];
ok(str_contains($h, 'id="home-shared-count"') && str_contains($h, 'id="home-shared-btn"') && !str_contains($h, 'home-upload-first-btn') && !str_contains($h, '<label>Your libraries</label>'), 'a guest\'s home: told one thing is shared, a button to Shared with me, no upload button, no libraries');
$d = json_decode(page($jAdmin, '/', $json)['body'], true)['data'] ?? [];
ok(count($d['libraries']) === (int) one("SELECT count(*) FROM libraries WHERE archived_at IS NULL") && $d['may']['admin'] === true, 'the admin sees every library (' . count($d['libraries']) . ')');

echo "5. The person's own screens answer JSON\n";
$d = json_decode(page($jMember, '/settings/', $json)['body'], true)['data'] ?? [];
ok(isset($d['kinds']['share_received']) && isset($d['kinds']['monday_note']) && count($d['kinds']) === 13 && isset($d['notify']['email_enabled']) && isset($d['timezone']), '/settings/: how I am told (notify), the thirteen notification kinds the schema knows, the time zone');
$r = page($jMember, '/settings/tokens/', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['tokens'] ?? null), '/settings/tokens/: the tokens list');
$r = page($jMember, '/notifications', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['notifications'] ?? null), '/notifications: the list');
$r = page($jMember, '/activity', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['rows'] ?? null), '/activity: the rows');
$t = page_csrf($jMember);
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['email_enabled' => 'no', 'kinds' => ['share_received', 'nonsense']]]);
ok($r['code'] === 422, 'prefs_save refuses an unknown kind: 422');
$r = req('POST', '/settings/prefs.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['email_enabled' => 'no', 'kinds' => ['share_received', 'comment']]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['did'] ?? '') === 'Saved how you are told' && one('SELECT kinds::text FROM notification_prefs WHERE member_id = 26') === '{share_received,comment}', 'prefs_save writes the choices');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['label' => 'proof', 'scope' => 'api']]);
ok($r['code'] === 422, 'token_mint refuses the api scope here (a key is slice 7\'s key_mint)');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['label' => 'proof', 'scope' => 'mcp']]);
$mb = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && str_starts_with((string) ($mb['token'] ?? ''), 'mcp_') && ($mb['record_id'] ?? 0) > 0, 'token_mint answers the token once (JSON) with its record_id');
$tid = (int) ($mb['record_id'] ?? 0);
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['token' => $tid]]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]), 'token_revoke revokes it');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$nid = one("SELECT dc_notify(26, 'share_received', 'SMOKE: Marco shared Contracts with you', NULL, 'folder', {$seed['contracts']}, NULL, {$seed['contracts']}, 27, 'p2:share:' || random()::text)");
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['notification' => $nid]]);
ok($r['code'] === 200 && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $nid]), 'notification_read marks one read');
one("SELECT dc_notify(26, 'comment', 'SMOKE: a comment', NULL, 'document', {$seed['priya_doc']}, {$seed['priya_doc']}, NULL, 27, 'p2:c:' || random()::text)");
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jMember, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => []]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0, 'notifications_read_all (no id) marks every unread one read');

echo "6. The command bar through the kernel's chat endpoint\n";
$since = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'Where is the Acme contract filed?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the expert\'s reply is rendered above the bar');
$a = activity('assistant.ask', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && ($la['length'] ?? 0) === 33 && ($la['run_id'] ?? 0) >= 1 && !str_contains((string) end($a)['after'], 'Acme'), 'assistant.ask logs the length and the run id, never the words');
$chatLines = file(need('FAKE_KERNEL_STATE') . '.chat'); $chat = json_decode((string) end($chatLines), true);
ok($chat['agent'] === 'expert' && (int) $chat['acting'] === 26, 'the kernel saw the call for the expert as the acting person');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'form' => ['utterance' => 'hi']]);
ok($r['code'] === 403, 'no CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'id="assistant-reply-error"'), 'the kernel\'s 404 (no expert) is shown as an error in words');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'The expert is busy with another turn.'), 'a refusal is shown in the kernel\'s own words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I drafted the link; making it waits for approval.', 'actions' => [['tool' => 'link_make', 'status' => 'awaiting_approval']], 'approval_request_id' => 12]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'make a link for the auditor', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-approval"') && str_contains($r['body'], 'request #12') && str_contains($r['body'], 'link_make'), 'an action awaiting approval is shown as such, with the request number');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your tokens.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my tokens', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location to /settings/tokens/)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'id="assistant-reply-error"'), 'over 2,000 characters: 422, in words');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => '', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'nothing said: 422');

echo "7. The action-token and run-token gate\n";
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
ok($r['code'] === 200 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'a person\'s action token opens a read for one request, with no cookie set');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]]);
ok($r['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . (function (string $t): string { return substr($t, 0, -1) . ($t[-1] === '0' ? '1' : '0'); })(person_token(26))]]);
ok($r['code'] === 401, 'a tampered one: 401');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['email_enabled' => 'yes', 'kinds' => ['share_received']]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['did'] ?? '') === 'Saved how you are told' && (json_decode($r['body'], true)['ok'] ?? false) === true, 'a write under an action token answers JSON (prefs_save: ok, did): the action token stands in for CSRF');
ok(one('SELECT email_enabled::text FROM notification_prefs WHERE member_id = 26') === 'true', '...and it was written');
$tok = run_token(40, 77);
$r = req('GET', '/activity', ['headers' => as_agent($tok)]);
ok($r['code'] === 200, 'an admitted agent (Seamus, 40) under a run token reads its activity: 200');
$since = last_activity_id();
req('GET', '/activity', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['agent_run_id'] === 77 && end($v)['source'] === 'agent', 'its screen view is logged with the run id and source agent');
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 200, 'an agent may open the home (it is a member here; the home is its libraries)');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'but not mint a person\'s token: 403 (people only)');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'nor use the command bar: 403');
$r = req('GET', '/activity', ['headers' => as_agent(run_token(40, 77, -5))]);
ok($r['code'] === 401, 'an expired run token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok($r['code'] === 401, 'a run token without its relay signature: 401');
$since = last_activity_id();
$tok2 = run_token(900, 78);
$r = req('GET', '/activity', ['headers' => as_agent($tok2)]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 900') === 0, 'an id with no mirror row is refused and never created');
$ref = activity('member.refused', $since);
ok($ref !== [] && (int) end($ref)['entity_id'] === 900, 'and logged member.refused with the id');

echo "8. The mirror — the personal library, the department groups, a revoked grant\n";
ok((int) one("SELECT count(*) FROM libraries WHERE kind = 'personal'") === (int) one("SELECT count(*) FROM members WHERE member_kind = 'human' AND NOT is_external AND status = 'active' AND capability IS NOT NULL"), 'the sync made one personal library per admitted non-external human (' . one("SELECT count(*) FROM libraries WHERE kind = 'personal'") . ')');
ok((int) one("SELECT count(*) FROM libraries WHERE kind = 'personal' AND owner_member_id = 29") === 0, '...and none for the guest');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'library.create' AND after->>'kind' = 'personal'") >= 5, 'each was logged library.create (kind personal)');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
pdo()->exec("INSERT INTO groups (key, name, kind, department_id, created_by) VALUES ('p2-eng', 'SMOKE Engineering', 'department', 8, 27) ON CONFLICT (key) DO NOTHING");
$eng = (int) one("SELECT id FROM groups WHERE key = 'p2-eng'");
ok(one("SELECT array_agg(member_id ORDER BY member_id)::text FROM group_members WHERE group_id = $eng AND left_at IS NULL") === '{30,31}', 'a department group made now holds the department\'s members (Dana, Lee)');
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => '2026-01-01T00:00:00.000000Z', 'next' => '2026-01-02T00:00:00.000000Z', 'full' => false, 'members' => [], 'departments' => [],
    'memberships' => [['member_id' => 26, 'department_id' => 8, 'is_admin' => false, 'is_primary' => false, 'joined_at' => '2026-10-01T00:00:00Z', 'left_at' => null], ['member_id' => 31, 'department_id' => 8, 'is_admin' => false, 'is_primary' => true, 'joined_at' => '2026-01-01T00:00:00Z', 'left_at' => '2026-10-09T00:00:00Z']],
    'deleted_departments' => [], 'scopes' => [], 'access' => []]; return $s; });
exec('php bin/directory_sync.php 2>&1', $syncOut, $rc);
ok($rc === 0 && one("SELECT array_agg(member_id ORDER BY member_id)::text FROM group_members WHERE group_id = $eng AND left_at IS NULL") === '{26,30}', 'after one sync the department group follows the feed: Priya joined, Lee dated out (' . implode(' ', $syncOut) . ')');
ok(req('GET', '/', ['jar' => $jBea])['code'] === 200, 'Bea, signed on earlier, is let in while her grant stands');
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => '2026-01-02T00:00:00.000000Z', 'next' => '2026-01-03T00:00:00.000000Z', 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [],
    'access' => [['member_id' => 28, 'capability' => null, 'roles' => [], 'scopes' => []]]]; return $s; });
exec('php bin/directory_sync.php 2>&1', $syncOut2, $rc2);
ok($rc2 === 0 && req('GET', '/', ['jar' => $jBea])['code'] === 302, 'a grant the kernel revoked shuts the door within one sync');
ok(one("SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1") === 'directory' && one('SELECT capability IS NULL FROM members WHERE id = 28'), '...the session ended by the directory and the mirror holds no grant');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();
