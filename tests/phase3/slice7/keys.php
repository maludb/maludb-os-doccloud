<?php
/** Slice 7 proofs 1, 2, 4, 7 — keys: minted once (the hash only), who may mint, the limits (429 with Retry-After, the day), a revoked / expired / mcp-scope bearer refused, a key acts as its person, rotation with its overlap, revoking, the prune pass. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$board = $s['board_root'];
$since = last_activity_id();

echo "1. A key is minted once, and only its hash is kept\n";
$k = mint_key(27, 'Mail-room scanner');
ok($k['_code'] === 200 && ($k['ok'] ?? false) === true && str_starts_with((string) ($k['token'] ?? ''), 'dck_') && strlen($k['token']) === 52, 'key_mint: 200 {ok, record_id, token} — dck_ and 48 hex, in the answer once');
$id = (int) $k['record_id']; $raw = $k['token'];
$row = key_row($id);
ok($row['scope'] === 'api' && (int) $row['member_id'] === 27 && $row['token_hash'] === hash('sha256', $raw) && !str_contains(json_encode($row), $raw), 'the table holds scope api, the person and the sha256 — never the key');
ok((int) $row['rate_per_minute'] === 60 && (int) $row['rate_per_day'] === 5000 && $row['expires_at'] === null && $row['revoked_at'] === null, 'the limits default to the settings\' 60 a minute and 5,000 a day; never expires');
$ev = last_event('key.mint');
ok($ev !== null && (int) $ev['entity_id'] === $id && $ev['source'] === 'assistant' && (int) $ev['actor_member_id'] === 27 && json_decode($ev['after'], true)['label'] === 'Mail-room scanner', 'key.mint is logged under the label, as the person (source assistant under the action token)');
ok((int) one('SELECT count(*) FROM activity_log WHERE (after::text LIKE :r OR before::text LIKE :r OR route LIKE :r) AND id > :s', ['r' => '%' . $raw . '%', 's' => $since]) === 0 && (int) one('SELECT count(*) FROM activity_log WHERE after::text LIKE :r', ['r' => '%' . substr($raw, 4, 20) . '%']) === 0, 'the key is nowhere in the log');
$me = api('GET', '/api/v1/me', $raw);
ok($me['code'] === 200 && $me['json']['member']['member_id'] === 27 && $me['json']['label'] === 'Mail-room scanner' && $me['json']['rate_per_minute'] === 60 && $me['json']['rate_per_day'] === 5000, 'GET /api/v1/me as the key: Marco, the label, the limits');
ok($me['json']['calls_today'] === 1, 'calls_today counts this very call');
$bad = mint_key(27, '');
ok($bad['_code'] === 422 && isset($bad['error']) && isset($bad['error']['fields']['label']), 'a key without a label: 422 on the field');
ok(mint_key(27, str_repeat('x', 81))['_code'] === 422, 'a label over 80 characters: 422');
ok(mint_key(27, 'too fast', ['rate_per_minute' => 61])['_code'] === 422, 'a person may not raise the limit past the settings\' (61 a minute): 422');
ok(mint_key(27, 'too many', ['rate_per_day' => 5001])['_code'] === 422 && mint_key(27, 'zero', ['rate_per_minute' => 0])['_code'] === 422, '...nor the day past 5,000, nor set zero');
$low = mint_key(27, 'Lowered', ['rate_per_minute' => 5, 'rate_per_day' => 100]);
ok($low['_code'] === 200 && (int) key_row((int) $low['record_id'])['rate_per_minute'] === 5 && (int) key_row((int) $low['record_id'])['rate_per_day'] === 100, 'lowering the limits is allowed (5 a minute, 100 a day)');
$adm = mint_key(1, 'Admin raised', ['rate_per_minute' => 120]);
ok($adm['_code'] === 200 && (int) key_row((int) $adm['record_id'])['rate_per_minute'] === 120, 'an admin may set any limit the database allows (120 a minute)');
$exp = mint_key(27, 'Expires', ['expires_in_days' => 3]);
$e = key_row((int) $exp['record_id'])['expires_at'];
ok($exp['_code'] === 200 && abs(strtotime((string) $e) - time() - 3 * 86400) < 120, 'expires_in_days 3: the key stops working in 3 days');
ok(mint_key(27, 'bad expiry', ['expires_in_days' => 'soon'])['_code'] === 422 && mint_key(27, 'zero days', ['expires_in_days' => 0])['_code'] === 422, 'expires_in_days must be 1 to 3650: 422');

echo "   who may mint\n";
ok(mint_key(29, 'A guest key')['_code'] === 403, 'a guest may not hold a key: 403');
$r = post_agent(40, 9001, '/settings/tokens/key-mint.php', ['label' => 'agent key']);
ok($r['code'] === 403 && (int) one("SELECT count(*) FROM mcp_access_tokens WHERE member_id = 40") === 0, 'an agent under a run token may not mint: 403, no row (people only)');
$r = req('POST', '/settings/tokens/key-mint.php', ['form' => ['label' => 'x'], 'headers' => ['Accept: application/json']]);
ok($r['code'] === 401, 'no session, no token: 401');
$mcp = post_json(27, '/settings/tokens/mint.php', ['label' => 'for my AI']);
$mcpRaw = $mcp['json']['token'] ?? '';
ok(str_starts_with($mcpRaw, 'mcp_') && api('GET', '/api/v1/me', $mcpRaw)['code'] === 401, 'an mcp-scope token is not a key: 401 at the API');
ok($mcp['code'] === 200 && post_json(27, '/settings/tokens/mint.php', ['label' => 'x', 'scope' => 'api'])['code'] === 422, 'token_mint refuses scope api: keys are minted by key_mint');

echo "2. Refused bearers\n";
$r = api('GET', '/api/v1/me', null);
ok($r['code'] === 401 && ($r['json']['error']['code'] ?? '') === 'unauthorized', 'no Authorization header: 401 {error:{code: unauthorized}}');
ok(api('GET', '/api/v1/me', 'dck_' . str_repeat('0', 48))['code'] === 401 && api('GET', '/api/v1/me', 'not-a-key')['code'] === 401, 'an unknown key: 401');
$r = req('GET', '/api/v1/me', ['headers' => ['Authorization: Basic ' . base64_encode('a:b')]]);
ok($r['code'] === 401, 'another scheme: 401');
$rv = mint_key(27, 'To revoke');
ok(api('GET', '/api/v1/me', $rv['token'])['code'] === 200, 'a live key works');
$r = post_json(27, '/settings/tokens/key-revoke.php', ['api_key' => $rv['record_id']]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === (int) $rv['record_id'] && key_row((int) $rv['record_id'])['revoked_at'] !== null, 'key_revoke: 200, record_id the key, revoked_at set');
ok(api('GET', '/api/v1/me', $rv['token'])['code'] === 401, 'a revoked key: 401');
$ev = last_event('key.revoke');
ok($ev !== null && (int) $ev['entity_id'] === (int) $rv['record_id'] && json_decode($ev['before'], true)['label'] === 'To revoke', 'key.revoke is logged under the label');
ok(post_json(27, '/settings/tokens/key-revoke.php', ['api_key' => $rv['record_id']])['code'] === 422, 'revoking it again: 422');
$re = mint_key(27, 'To expire');
age_key((int) $re['record_id'], 'expires_at', '1 minute');
$cr = post_json(27, '/settings/tokens/key-rotate.php', ['api_key' => $re['record_id']]);
ok(api('GET', '/api/v1/me', $re['token'])['code'] === 401, 'an expired key: 401');
ok($cr['code'] === 422, 'an expired key is not rotated: 422');
pdo()->exec("UPDATE members SET status = 'inactive' WHERE id = 28"); $kb = mint_key(26, 'Priya soon gone');
pdo()->exec("UPDATE members SET status = 'inactive' WHERE id = 26");
ok(api('GET', '/api/v1/me', $kb['token'])['code'] === 401, 'a key whose person is no longer admitted: 401');
pdo()->exec("UPDATE members SET status = 'active' WHERE id IN (26, 28)");
ok(api('GET', '/api/v1/me', $kb['token'])['code'] === 200, '...and works again when they are');

echo "3. A key acts as its person and no more\n";
$kp = mint_key(26, 'Priya\'s'); $km = $raw; $kbea = mint_key(28, 'Bea\'s');
$marcoRoot = root_of(27); $priyaRoot = root_of(26);
ok(api('GET', '/api/v1/folders/' . $priyaRoot, $km)['code'] === 404, 'Marco\'s key: Priya\'s personal library is 404');
ok(api('GET', '/api/v1/folders/' . $priyaRoot, $kp['token'])['code'] === 200, 'Priya\'s key: her own library is 200');
ok(api('GET', '/api/v1/folders/' . $board, $kp['token'])['code'] === 200 && api('GET', '/api/v1/folders/' . $board, $kbea['token'])['code'] === 404, 'the Board\'s folder: a member\'s key 200, Bea\'s (not a member) 404');
ok(api('GET', '/api/v1/documents/' . $s['priya_doc'], $km)['code'] === 404 && api('GET', '/api/v1/documents/' . $s['priya_doc'], $kp['token'])['code'] === 200, 'Priya\'s document: 404 for Marco\'s key, 200 for hers');

echo "4. The limits\n";
if ((int) date('s') > 48) { sleep(62 - (int) date('s')); }
$kr = mint_key(27, 'Rate', ['rate_per_minute' => 3]);
$kid = (int) $kr['record_id'];
$codes = []; for ($i = 0; $i < 3; $i++) { $codes[] = api('GET', '/api/v1/me', $kr['token'])['code']; }
ok($codes === [200, 200, 200], 'three calls in a minute at a limit of 3: all 200');
$r4 = api('GET', '/api/v1/me', $kr['token']);
ok($r4['code'] === 429 && ($r4['json']['error']['code'] ?? '') === 'rate_limited', 'the 4th: 429 rate_limited');
$ra = (int) header_of($r4, 'Retry-After');
ok($ra >= 1 && $ra <= 60, 'with Retry-After in seconds (' . $ra . ')');
api('GET', '/api/v1/me', $kr['token']); api('GET', '/api/v1/folders/' . $board, $kr['token']);
ok((int) one("SELECT refused FROM key_usage WHERE token_id = :t AND bucket_kind = 'minute' ORDER BY bucket_start DESC LIMIT 1", ['t' => $kid]) === 3, 'every refusal is counted (3)');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'key.rate_limited' AND token_id = :t", ['t' => $kid]) === 1, 'key.rate_limited is logged once for the minute, not once per refusal');
ok((int) one("SELECT calls FROM key_usage WHERE token_id = :t AND bucket_kind = 'minute' ORDER BY bucket_start DESC LIMIT 1", ['t' => $kid]) === 3, 'a refused call is not charged: the minute still counts 3');
reset_usage($kid);
ok(api('GET', '/api/v1/me', $kr['token'])['code'] === 200, 'a new minute (the counters cleared): 200 again');
$kd = mint_key(27, 'Day', ['rate_per_day' => 2]); $did = (int) $kd['record_id'];
$c = []; for ($i = 0; $i < 3; $i++) { $x = api('GET', '/api/v1/me', $kd['token']); $c[] = $x['code']; }
ok($c === [200, 200, 429], 'a day limit of 2: the 3rd call is 429');
ok((int) header_of($x, 'Retry-After') >= 1 && ($x['json']['error']['message'] ?? '') !== '' && str_contains($x['json']['error']['message'], 'day'), '...Retry-After set, the words say the day');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'key.rate_limited' AND token_id = :t AND after->>'limit' = 'day'", ['t' => $did]) === 1, 'logged once, limit day');
$j = get_json(27, '/settings/keys/');
$mine = array_values(array_filter($j['json']['data']['keys'] ?? [], fn ($x) => $x['key_id'] === $did))[0] ?? [];
ok($j['code'] === 200 && ($mine['calls_today'] ?? -1) === 2 && ($mine['refused_today'] ?? -1) === 1 && $mine['rate_per_day'] === 2, 'the keys screen: calls today 2 of 2, 1 refused');
$b = page(actor(27), '/settings/keys/')['body'];
ok(str_contains($b, 'id="key-row-' . $did . '-calls">2 of 2'), 'the page says "2 of 2" for the key');

echo "5. Rotation: the old key keeps 24 hours\n";
$ko = mint_key(27, 'Rotating'); $oid = (int) $ko['record_id'];
$rot = post_json(27, '/settings/tokens/key-rotate.php', ['api_key' => $oid]);
ok($rot['code'] === 200 && str_starts_with((string) ($rot['json']['token'] ?? ''), 'dck_') && $rot['json']['token'] !== $ko['token'] && (int) $rot['json']['record_id'] !== $oid && $rot['json']['overlap_hours'] === 24, 'key_rotate: a new key (shown once), record_id the new one, overlap 24');
$nid = (int) $rot['json']['record_id']; $nraw = $rot['json']['token'];
$n = key_row($nid); $o = key_row($oid);
ok((int) $n['rotated_from'] === $oid && $n['label'] === 'Rotating' && (int) $n['rate_per_minute'] === 60 && (int) $n['member_id'] === 27, 'the new key points at the old, keeps its label, limits and person');
ok(abs(strtotime((string) $o['expires_at']) - time() - 24 * 3600) < 120 && $o['revoked_at'] === null, 'the old key now expires in 24 hours');
ok(api('GET', '/api/v1/me', $nraw)['code'] === 200 && api('GET', '/api/v1/me', $ko['token'])['code'] === 200, 'both keys work during the overlap');
$ev = last_event('key.rotate');
ok($ev !== null && (int) $ev['entity_id'] === $oid && json_decode($ev['after'], true)['new_key_id'] === $nid && !str_contains(json_encode($ev), $nraw), 'key.rotate is logged with the new key\'s id, never a value');
$j = get_json(27, '/settings/keys/')['json']['data']['keys'];
$st = []; foreach ($j as $x) { $st[$x['key_id']] = $x; }
ok($st[$oid]['state'] === 'rotating' && $st[$nid]['state'] === 'live' && $st[$nid]['rotated_from'] === $oid && $st[$oid]['successor'] === $nid, 'the screen: the old key rotating, the new live, each pointing at the other');
$b = page(actor(27), '/settings/keys/')['body'];
ok(str_contains($b, 'id="key-row-' . $oid . '-state">rotating') && str_contains($b, 'id="key-row-' . $nid . '-from"') && !str_contains($b, 'id="key-row-' . $oid . '-rotate-btn"'), 'the page: a "rotating" chip, "replaces #…" on the new, no Rotate on the old');
ok(post_json(27, '/settings/tokens/key-rotate.php', ['api_key' => $oid])['code'] === 422, 'a key already being rotated out is not rotated again: 422');
ok(post_json(26, '/settings/tokens/key-rotate.php', ['api_key' => $nid])['code'] === 404, 'another person\'s key is not rotated (not even by id): 404');
ok(post_json(1, '/settings/tokens/key-rotate.php', ['api_key' => $nid])['code'] === 404, '...the admin neither: only the owner rotates');
age_key($oid, 'expires_at', '1 second');
ok(api('GET', '/api/v1/me', $ko['token'])['code'] === 401 && api('GET', '/api/v1/me', $nraw)['code'] === 200, 'after the overlap the old key answers 401; the new works');
settings_set('key_rotation_overlap_hours', 2);
$r2 = post_json(27, '/settings/tokens/key-rotate.php', ['api_key' => $nid]);
ok($r2['json']['overlap_hours'] === 2 && abs(strtotime((string) key_row($nid)['expires_at']) - time() - 2 * 3600) < 120, 'the overlap follows the setting (2 hours)');
settings_set('key_rotation_overlap_hours', 24);

echo "6. Revoking: one's own, or the admin's\n";
$kv = mint_key(26, 'Priya two'); $vid = (int) $kv['record_id'];
ok(post_json(27, '/settings/tokens/key-revoke.php', ['api_key' => $vid])['code'] === 404, 'a curator may not revoke another person\'s key (no links.all): 404');
ok(api('GET', '/api/v1/me', $kv['token'])['code'] === 200, '...it still works');
$r = post_json(1, '/settings/tokens/key-revoke.php', ['api_key' => $vid]);
ok($r['code'] === 200 && api('GET', '/api/v1/me', $kv['token'])['code'] === 401, 'the admin (links.all) revokes it: 200, then 401');
$ev = last_event('key.revoke');
ok((int) $ev['actor_member_id'] === 1 && json_decode($ev['before'], true)['member_id'] === 26, 'the log names the admin as the actor and Priya as the key\'s person');
ok(post_json(27, '/settings/tokens/key-revoke.php', ['api_key' => 999999])['code'] === 404, 'a key that does not exist: 404');
$r = post_agent(40, 9002, '/settings/tokens/key-revoke.php', ['api_key' => $nid]);
ok($r['code'] === 403, 'an agent under a run token may not revoke: 403 (people only)');
$r = post_agent(40, 9003, '/settings/tokens/key-rotate.php', ['api_key' => $nid]);
ok($r['code'] === 403 && api('GET', '/api/v1/me', $nraw)['code'] === 200, '...nor rotate: 403, the key untouched');

echo "7. The key_usage_prune pass\n";
$t = (int) $kd['record_id'];
pdo()->exec("INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES ($t, 'minute', now() - interval '2 days', 5), ($t, 'minute', now() - interval '3 days', 5), ($t, 'day', now() - interval '40 days', 9), ($t, 'day', now() - interval '10 days', 9)");
$q = worker_queues_probe();
ok($q >= 3, 'the worker screen\'s counter sees the old buckets (' . $q . ')');
$r = worker('key_usage_prune');
$p = $r['passes'][0]['steps']['key_usage_prune'] ?? $r['steps']['key_usage_prune'] ?? null;
$left = (int) one("SELECT count(*) FROM key_usage WHERE token_id = :t AND ((bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days'))", ['t' => $t]);
ok($left === 0 && (int) one("SELECT count(*) FROM key_usage WHERE token_id = :t AND bucket_kind = 'day' AND bucket_start < now() - interval '9 days'", ['t' => $t]) === 1, 'the pass pruned minute buckets over a day old and day buckets over 35 days, and kept the 10-day-old day bucket');
ok(!str_contains(json_encode($r), '"stub"'), 'the pass is no longer a stub');
function worker_queues_probe(): int { return (int) one("SELECT count(*) FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days')"); }
finish();
