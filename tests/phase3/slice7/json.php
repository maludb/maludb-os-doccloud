<?php
/** Slice 7 proofs 7 and 9 — the screens and the three actions in JSON mode (and under a run token), the browser's HTML flow (a key shown once), the admin's keys screen, and docs/api.md's curl examples run against the proof server. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$board = $s['board_root']; $contracts = $s['contracts'];
$p = pdo();

echo "1. Every screen answers JSON\n";
foreach ([[26, '/settings/tokens/'], [26, '/settings/keys/'], [26, '/settings/keys/new'], [1, '/admin/keys'], [1, '/admin/keys?status=live'], [1, '/admin/keys?status=revoked'], [1, '/admin/keys?member=26'], [1, '/admin/connections'], [1, '/admin/connections?days=7']] as [$m, $path]) {
    $r = get_json($m, $path);
    ok($r['code'] === 200 && isset($r['json']['data']) && !str_contains($r['body'], '<html'), "JSON: $path (member $m)");
}
$j = get_json(26, '/settings/tokens/')['json']['data'];
ok(isset($j['keys'], $j['tokens']) && is_array($j['keys']), 'the tokens screen carries the keys part beside the mcp tokens');
$j = get_json(26, '/settings/keys/new')['json']['data'];
ok($j['defaults'] === ['rate_per_minute' => 60, 'rate_per_day' => 5000] && $j['admin'] === false, 'the mint form: the settings\' defaults; an admin flag');
ok(get_json(1, '/settings/keys/new')['json']['data']['admin'] === true, '...true for the admin');
ok(req('GET', '/settings/keys/', ['headers' => ['Accept: application/json']])['code'] === 401 && req('GET', '/admin/keys', ['headers' => ['Accept: application/json']])['code'] === 401, 'no session: 401');
ok(get_json(27, '/admin/keys')['code'] === 403 && get_json(26, '/admin/keys')['code'] === 403 && get_json(27, '/admin/connections')['code'] === 403, 'the admin\'s screens: 403 for a curator and a member');
ok(get_json(29, '/settings/keys/new')['code'] === 403 && get_json(29, '/settings/keys/')['code'] === 200 && get_json(29, '/settings/keys/')['json']['data']['may']['mint'] === false, 'a guest sees the keys screen empty, may not mint (the form: 403)');
$ag = req('GET', '/settings/keys/', ['headers' => as_agent(run_token(40, 9100))]);
ok($ag['code'] === 403, 'an agent under a run token: the keys screen is 403 (people only)');

echo "2. Every action under a person's action token\n";
$ok = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('record_id', $r['json']) && isset($r['json']['location'], $r['json']['did']);
$r = post_json(26, '/settings/tokens/key-mint.php', ['label' => 'JSON key', 'rate_per_minute' => 30, 'expires_in_days' => 10]);
ok($ok($r) && str_starts_with($r['json']['token'], 'dck_') && $r['json']['rate_per_minute'] === 30 && str_contains($r['json']['location'], '/settings/keys/') && str_ends_with($r['json']['location'], '#key-row-' . $r['json']['record_id']), 'key_mint: ok, did, record_id = the key, location on its row, the token once');
$kid = (int) $r['json']['record_id']; $kraw = $r['json']['token'];
$r = post_json(26, '/settings/tokens/key-rotate.php', ['api_key' => $kid]);
ok($ok($r) && (int) $r['json']['record_id'] !== $kid && $r['json']['old_key_id'] === $kid && $r['json']['overlap_hours'] === 24 && str_starts_with($r['json']['token'], 'dck_'), 'key_rotate: record_id = the NEW key, old_key_id, overlap_hours, the new token once');
$nid = (int) $r['json']['record_id'];
ok((int) key_row($nid)['rate_per_minute'] === 30 && key_row($nid)['expires_at'] === null, 'the rotated key keeps the limits (30) — and not the old one\'s expiry (the old one now expires in 24 hours)');
$r = post_json(26, '/settings/tokens/key-revoke.php', ['api_key' => $nid]);
ok($ok($r) && (int) $r['json']['record_id'] === $nid && str_contains($r['json']['location'], '/settings/keys/'), 'key_revoke: record_id = the key, location back on the keys screen');
$ka = mint_key(26, 'Admin will revoke');
$r = post_json(1, '/settings/tokens/key-revoke.php', ['api_key' => $ka['record_id']]);
ok($ok($r) && str_contains($r['json']['location'], '/admin/keys') && str_contains($r['json']['did'], 'SMOKE Priya'), 'the admin revoking another\'s key: location /admin/keys, the person named');
$r = post_json(26, '/settings/tokens/key-mint.php', []);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['label']) && ($r['json']['ok'] ?? null) !== true, 'a refused mint: 422 with the field');
$r = req('POST', '/settings/tokens/key-mint.php', ['headers' => ['Accept: application/json'], 'form' => ['label' => 'no csrf']]);
ok($r['code'] === 401, 'a POST with neither session nor token: 401');
$jar = actor(26);
$r = req('POST', '/settings/tokens/key-mint.php', ['jar' => $jar, 'form' => ['label' => 'no csrf token'], 'headers' => ['Accept: application/json']]);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE label = 'no csrf token'") === 0, 'a session POST without the CSRF token: 403, nothing minted');
ok(req('GET', '/settings/tokens/key-mint.php', ['jar' => $jar])['code'] === 405, 'GET on an action: 405');

echo "3. A browser: the key is shown once\n";
$before = (int) one("SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26 AND scope = 'api'");
$f = page(actor(26), '/settings/keys/new')['body'];
ok(str_contains($f, 'id="key-form-form"') && str_contains($f, 'id="key-form-field-label"') && str_contains($f, 'name="rate_per_minute"') && str_contains($f, 'name="expires_in_days"') && str_contains($f, 'acts as you'), 'the mint form: label, the two limits, the expiry, "acts as you"');
$r = post(26, '/settings/tokens/key-mint.php', ['label' => 'Browser key']);
ok(in_array($r['code'], [200, 302], true) && str_contains($r['location'] . $r['headers'], '/settings/keys/'), 'the form posts and lands on /settings/keys/');
$b = page(actor(26), '/settings/keys/')['body'];
preg_match('#id="key-once-value">(dck_[a-f0-9]{48})<#', $b, $m); $shown = $m[1] ?? '';
ok($shown !== '' && str_contains($b, 'id="key-once"') && str_contains($b, 'Copy') && str_contains($b, 'it is shown once'), 'the next page shows the key once, with Copy');
ok(api('GET', '/api/v1/me', $shown)['code'] === 200, 'and it is the key');
$b2 = page(actor(26), '/settings/keys/')['body'];
ok(!str_contains($b2, $shown) && !str_contains($b2, 'id="key-once"') && str_contains($b2, 'Browser key'), 'the page after that does not have it — only the label');
ok(!str_contains(page(actor(26), '/settings/tokens/')['body'], $shown), 'nor the tokens page');
$t = page(actor(26), '/settings/tokens/')['body'];
ok(str_contains($t, 'id="tokens-keys"') && str_contains($t, 'id="keys-table"') && str_contains($t, 'id="key-mint-btn"'), 'the tokens page carries the keys part: the table and Mint a key');
$bid = (int) one("SELECT id FROM mcp_access_tokens WHERE label = 'Browser key'");
ok(str_contains($b2, 'id="key-row-' . $bid . '-rotate-btn"') && str_contains($b2, 'id="key-row-' . $bid . '-revoke-btn"') && str_contains($b2, 'hx-confirm="Rotate the key Browser key?') && str_contains($b2, 'hx-confirm="Revoke the key Browser key?'), 'each live row: Rotate and Revoke, both confirmed');
post(26, '/settings/tokens/key-rotate.php', ['api_key' => $bid]);
$b3 = page(actor(26), '/settings/keys/')['body'];
ok(preg_match('#id="key-once-value">(dck_[a-f0-9]{48})<#', $b3, $m3) === 1 && $m3[1] !== $shown && str_contains($b3, 'replacement'), 'rotating shows the replacement once');
ok(str_contains($b3, 'id="key-row-' . $bid . '-state">rotating'), '...and the old key as rotating');
post(26, '/settings/tokens/key-revoke.php', ['api_key' => $bid]);
$b4 = page(actor(26), '/settings/keys/?notice=revoked')['body'];
ok(str_contains($b4, 'id="key-row-' . $bid . '-state">revoked') && !str_contains($b4, 'id="key-row-' . $bid . '-revoke-btn"') && str_contains($b4, 'The key was revoked.'), 'revoking: the chip says revoked, no buttons left, the notice');
ok(api('GET', '/api/v1/me', $shown)['code'] === 401, 'the revoked key answers 401');
$r = post(26, '/settings/tokens/key-revoke.php', ['api_key' => $bid]);
ok($r['code'] === 422, 'revoking twice from the browser: 422 in words');
$r = post(28, '/settings/tokens/key-rotate.php', ['api_key' => $nid]);
ok($r['code'] === 404, 'another person\'s key from the browser: 404');
ok((int) one("SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26 AND scope = 'api'") >= $before + 2, 'the keys exist as rows (no key was lost)');

echo "4. The admin sees every key\n";
mint_key(27, 'Marco long-lived'); mint_key(30, 'Dana scanner');
$a = get_json(1, '/admin/keys')['json']['data'];
$labels = array_column($a['keys'], 'label');
ok(in_array('Marco long-lived', $labels, true) && in_array('Dana scanner', $labels, true) && in_array('JSON key', $labels, true), 'every person\'s keys');
ok(!str_contains(json_encode($a), 'dck_') && !str_contains(json_encode($a), 'token_hash'), 'never a value, never a hash');
$row = array_values(array_filter($a['keys'], fn ($x) => $x['label'] === 'Dana scanner'))[0];
ok($row['member_id'] === 30 && $row['member_name'] === 'SMOKE Dana' && $row['state'] === 'live' && $row['rate_per_day'] === 5000 && isset($row['calls_today']) && array_key_exists('last_used_at', $row), 'each: person, state, limits, calls today, last use');
$only = get_json(1, '/admin/keys?member=30')['json']['data']['keys'];
ok(count($only) >= 1 && array_diff(array_column($only, 'member_id'), [30]) === [] && in_array('Dana scanner', array_column($only, 'label'), true), 'the member filter: only Dana\'s keys');
$rev = get_json(1, '/admin/keys?status=revoked')['json']['data']['keys'];
ok(count($rev) >= 2 && array_diff(array_column($rev, 'state'), ['revoked']) === [], 'the status filter (revoked)');
$h = page(actor(1), '/admin/keys')['body'];
ok(str_contains($h, 'id="admin-keys-table"') && str_contains($h, 'id="admin-keys-filter-live"') && str_contains($h, 'Dana scanner') && str_contains($h, 'id="admin-keys-connections"'), 'the page: the table, the filters, the way to Connections');
$did = (int) one("SELECT id FROM mcp_access_tokens WHERE label = 'Dana scanner'");
ok(str_contains($h, 'id="admin-key-row-' . $did . '-revoke-btn"') && str_contains($h, 'name="return_to" value="/admin/keys"'), 'a Revoke on every live row, returning to this screen');
$r = post(1, '/settings/tokens/key-revoke.php', ['api_key' => $did, 'return_to' => '/admin/keys']);
ok(in_array($r['code'], [200, 302], true) && str_contains($r['location'] . $r['headers'], '/admin/keys') && key_row($did)['revoked_at'] !== null, 'revoking from the admin screen lands back on it');
ok(str_contains(page(actor(1), '/admin/keys?status=revoked')['body'], 'Dana scanner'), '...where it now shows as revoked');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND after IS NULL AND screen IN ('admin-keys', 'admin-connections', 'settings-tokens')") >= 3, 'the screens log their views');

echo "5. docs/api.md runs\n";
$tok = mint_key(27, 'Docs example'); $km = $tok['token'];
$doc = real_doc(27, $contracts, 'docs-example-' . time() . '.pdf', 'docs', 1500);
$stamp = bin2hex(random_bytes(3));
$file = tmpfile_of(1800, 'doc-ex', 'docs-upload-' . $stamp . '.bin');
$md = file_get_contents(dirname(__DIR__, 3) . '/docs/api.md');
preg_match_all('/```sh\n(.*?)```/s', $md, $blocks);
$lines = []; foreach ($blocks[1] as $b) { foreach (explode("\n", $b) as $l) { if (str_starts_with($l, 'curl ')) { $lines[] = $l; } } }
ok(count($lines) >= 13, 'docs/api.md has ' . count($lines) . ' runnable curl examples');
$env = ['BASE' => BASE, 'KEY' => $km, 'FOLDER' => (string) $contracts, 'DOC' => (string) $doc, 'FILE' => $file, 'STAMP' => $stamp, 'PATH' => getenv('PATH')];
$fail = [];
foreach ($lines as $l) {
    $proc = proc_open(['bash', '-c', $l], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) { $fail[] = substr($l, 0, 90) . ' → exit ' . $code . ' ' . trim($err); }
}
ok($fail === [], 'every curl example exits 0 against the proof server' . ($fail ? ': ' . implode(' | ', $fail) : ''));
ok((string) one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $doc]) === '1' && (int) one("SELECT count(*) FROM documents WHERE name = :n", ['n' => 'docs-upload-' . $stamp . '.bin']) === 1 && (int) one("SELECT count(*) FROM folders WHERE name = :n", ['n' => 'Scans ' . $stamp]) === 1, 'and they did what they say: the folder made, the file uploaded, the last one trashed the document');
$ex = $md; preg_match_all('#^\| `?(GET|POST|PUT|PATCH|DELETE) /([a-z/{}_]+)#m', $md, $routes);
ok(count($routes[0]) >= 15, 'the route table lists ' . count($routes[0]) . ' routes');
finish();
