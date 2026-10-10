<?php
/**
 * Slice 9 proof 3 — the Admin area: the index and its health line; the settings and limits forms (every field with its bounds, refused in words on the field, logged, the rights, slice 6's retention form still working);
 * every link (the states, the suspicious flagged, the filters, a revoke); every library (personal ones marked, `library.admin_view` once per session, only on opening); the disk; the connections from the kernel and the fallback;
 * the landings slice 8's proposals use.
 */
require __DIR__ . '/lib.php';
$w = world9();
$p = pdo();

echo "1. The index and the health line\n";
$r = get_json(1, '/admin/');
$j = $r['json']['data'] ?? [];
ok($r['code'] === 200 && isset($j['counts'], $j['health']), 'the admin\'s /admin/: 200 with counts and health');
$x = get_json(27, '/admin/');
ok($x['code'] === 403 && str_contains($x['body'], 'change the settings') && get_json(26, '/admin/')['code'] === 403 && get_json(29, '/admin/')['code'] === 403, 'a curator, a Member and a guest: 403 "You may not change the settings."');
ok(req('GET', '/admin/', ['headers' => ['Accept: application/json']])['code'] === 401, 'no session, JSON: 401');
$h = page(actor(1), '/admin/')['body'];
$cards = ['settings', 'limits', 'retention', 'quotas', 'graph', 'links', 'libraries', 'keys', 'agents', 'connections', 'worker', 'storage'];
ok(missing_ids($h, array_merge(['admin-index', 'admin-health', 'health-storage', 'health-graph', 'health-worker', 'health-directory'], array_map(fn ($c) => 'admin-card-' . $c, $cards))) === [], 'the page: a card for each of the twelve areas and the health line');
preg_match_all('~id="admin-link-([a-z]+)"[^>]*>|<a href="([^"]+)"[^>]*id="admin-link-([a-z]+)"~', $h, $m);
$bad = [];
foreach ($cards as $c) {
    preg_match('~<a href="([^"]+)"[^>]*id="admin-link-' . $c . '"~', $h, $mm);
    $u = html_entity_decode($mm[1] ?? '');
    $code = $u === '' ? 0 : page(actor(1), preg_replace('~#.*~', '', $u))['code'];
    if ($code !== 200) { $bad[] = "$c $u → $code"; }
}
ok($bad === [], 'every card\'s link opens' . ($bad ? ': ' . implode('; ', $bad) : ''));
$hl = $j['health'];
ok($hl['storage'] === 'ok' && in_array($hl['graph'], ['ok', 'unavailable', 'unconfigured'], true) && $hl['directory']['last_run_at'] !== null, 'health: the store ok, the graph ' . $hl['graph'] . ', the directory synced');
worker('housekeeping');
$hl = get_json(1, '/admin/')['json']['data']['health'];
ok($hl['worker']['state'] === 'ok' && $hl['worker']['last_at'] !== null && abs(time() - strtotime($hl['worker']['last_at'])) < 120, 'health: the worker\'s pass a moment ago is "ok"');
$p->exec("UPDATE worker_passes SET finished_at = now() - interval '30 minutes', started_at = now() - interval '30 minutes' WHERE id = (SELECT max(id) FROM worker_passes)");
ok(get_json(1, '/admin/')['json']['data']['health']['worker']['state'] === 'stale', '...a pass half an hour old is "stale"');
$p->exec("UPDATE worker_passes SET finished_at = now(), started_at = now() WHERE id = (SELECT max(id) FROM worker_passes)");
$c = $j['counts'];
as_member(1);
ok($c['links_suspicious'] === 1 && $c['links_live'] === (int) one('SELECT count(*) FROM dc_find_links(NULL, NULL, NULL, true)') && $c['libraries'] === (int) one('SELECT count(*) FROM mcp_libraries WHERE archived_at IS NULL') && $c['quotas_near'] >= 1, 'the counts: one suspicious link, the live links and libraries as the database counts them, the Board nearly full');
$since = last_activity_id(); page(actor(1), '/admin/');
ok(count(activity('screen.view', $since)) === 1 && activity('screen.view', $since)[0]['screen'] === 'admin', 'screen.view logged for admin');

echo "2. Settings and limits: the forms\n";
$r = get_json(1, '/admin/settings');
$f = $r['json']['data']['fields'] ?? [];
ok($r['code'] === 200 && count($f) === 17 && $f['link_default_days']['min'] === 1 && $f['link_default_days']['max'] === 3650 && $f['graph_default_policy']['options'] === ['none', 'index', 'full'] && isset($r['json']['data']['windows']['trash_days']), 'the JSON: seventeen fields with their bounds, and slice 6\'s windows');
ok($f['max_document_bytes']['value'] === null && $f['max_document_bytes']['type'] === 'ceiling' && $f['link_default_days']['value'] === 30 && $f['timezone']['value'] !== null, '...and their values');
$h = page(actor(1), '/admin/settings')['body'];
$miss = missing_ids($h, ['settings-form', 'settings-save', 'limits-form', 'limits-save', 'retention-form', 'retention-save', 'settings-business_name', 'settings-timezone', 'settings-link_default_days', 'settings-link_no_expiry_allowed', 'settings-graph_default_policy',
    'settings-max_document_bytes', 'settings-max_text_bytes', 'settings-upload_part_bytes', 'settings-upload_session_hours', 'settings-key_rate_per_minute', 'settings-link_opens_per_minute', 'retention-trash_days']);
ok($miss === [], 'the page: the settings form, the limits form and the windows, every field with its input' . ($miss ? ' — missing ' . implode(', ', $miss) : ''));
ok(str_contains($h, 'name="max_document_mb"') && preg_match('/id="settings-max_document_bytes" min="0" value="" placeholder="No limit"/', $h) === 1 && str_contains($h, 'min="1" max="3650"'), '...the file ceiling in megabytes, empty with "No limit" (db/024), each field with its min and max');
ok(get_json(27, '/admin/settings')['code'] === 403 && get_json(26, '/admin/settings')['code'] === 403 && get_json(29, '/admin/settings')['code'] === 403, 'a curator, a Member and a guest: 403');
ok(page(actor(1), '/admin/retention')['code'] === 200, '/admin/retention is still the same page');

echo "3. settings_save: the settings\n";
$since = last_activity_id();
$x = post_json(1, '/admin/settings/save.php', ['business_name' => 'Acme Docs', 'link_default_days' => 45, 'link_no_expiry_allowed' => 'yes', 'free_tag_proposal_threshold' => 5, 'stale_guest_share_days' => 60, 'graph_default_policy' => 'none', 'graph_text_chars' => 30000, 'timezone' => 'America/Chicago']);
ok($x['code'] === 200 && ($x['json']['ok'] ?? false) && count($x['json']['changed'] ?? []) === 8, 'eight settings saved in one action: ' . count($x['json']['changed'] ?? []) . ' changed');
ok(setting('business_name') === 'Acme Docs' && (int) setting('link_default_days') === 45 && setting('link_no_expiry_allowed') === true && (int) setting('free_tag_proposal_threshold') === 5 && (int) setting('stale_guest_share_days') === 60 && setting('graph_default_policy') === 'none' && (int) setting('graph_text_chars') === 30000 && setting('timezone') === 'America/Chicago', 'the database holds them all');
$a = activity('settings.save', $since);
$aft = json_decode((string) ($a[0]['after'] ?? '{}'), true);
$bef = json_decode((string) ($a[0]['before'] ?? '{}'), true);
ok(count($a) === 1 && $aft['link_default_days'] === 45 && $bef['link_default_days'] === 30 && $aft['business_name'] === 'Acme Docs' && count(activity('limits.save', $since)) === 0, 'logged once as settings.save with before and after; no limits.save');
$d = get_json(1, '/admin/settings')['json']['data']['fields'];
ok($d['link_default_days']['value'] === 45 && $d['business_name']['value'] === 'Acme Docs' && page(actor(1), '/admin/settings')['body'] !== '' && str_contains(page(actor(1), '/admin/settings')['body'], 'value="Acme Docs"'), 'the page shows the saved values');
$x = post_json(1, '/admin/settings/save.php', ['link_default_days' => 45]);
ok($x['code'] === 200 && $x['json']['changed'] === [] && str_contains($x['json']['did'], 'already'), 'saving the same value again changes nothing, and says so');
$x = post_json(1, '/admin/settings/save.php', ['business_name' => '']);
ok($x['code'] === 200 && setting('business_name') === null, 'an emptied business name is nothing');
post_json(1, '/admin/settings/save.php', ['business_name' => 'Acme Docs', 'link_default_days' => 30, 'link_no_expiry_allowed' => 'no', 'free_tag_proposal_threshold' => 3, 'stale_guest_share_days' => 90, 'graph_default_policy' => 'index', 'graph_text_chars' => 20000, 'timezone' => 'UTC']);
ok((int) setting('link_default_days') === 30 && setting('link_no_expiry_allowed') === false && setting('graph_default_policy') === 'index', '(restored)');

echo "4. settings_save: refused in words, on the field\n";
foreach ([['link_default_days', 0, 'whole number from 1 to 3,650'], ['link_default_days', 'many', 'whole number'], ['link_default_days', 3651, '3,650'], ['free_tag_proposal_threshold', 101, '1 to 100'], ['stale_guest_share_days', 0, '1 to 3,650'],
          ['graph_default_policy', 'maybe', 'none, index, full'], ['graph_text_chars', 999, '1000 to 200,000'], ['timezone', 'Mars/Phobos', 'America/Chicago'], ['business_name', str_repeat('x', 121), 'at most 120']] as [$k, $v, $words]) {
    $x = post_json(1, '/admin/settings/save.php', [$k => $v]);
    ok($x['code'] === 422 && isset($x['json']['error']['fields'][$k]) && str_contains($x['json']['error']['fields'][$k], $words), "$k refused on the field: " . ($x['json']['error']['fields'][$k] ?? '?'));
}
$x = post_json(1, '/admin/settings/save.php', ['link_no_expiry_allowed' => 'maybe']);
ok($x['code'] === 422, 'a yes/no that is neither: 422');
$x = post_json(1, '/admin/settings/save.php', ['link_default_days' => 0, 'graph_text_chars' => 40000]);
ok($x['code'] === 422 && (int) setting('graph_text_chars') === 20000, 'one bad field refuses the whole save: nothing changed');
$x = post_json(1, '/admin/settings/save.php', []);
ok($x['code'] === 422, 'nothing named: 422');
$x = post_json(1, '/admin/settings/save.php', ['stale_guest_share_days' => 0, 'link_default_days' => 0]);
ok($x['code'] === 422 && count($x['json']['error']['fields']) === 2, 'two bad fields: both named');

echo "5. settings_save: the limits\n";
$since = last_activity_id();
$x = post_json(1, '/admin/settings/save.php', ['max_document_mb' => 100, 'max_text_mb' => 0.5, 'upload_part_mb' => 16, 'upload_session_hours' => 48, 'key_rate_per_minute' => 120, 'key_rate_per_day' => 9000, 'key_rotation_overlap_hours' => 0, 'link_opens_per_minute' => 30, 'request_uploads_per_hour' => 10]);
ok($x['code'] === 200 && count($x['json']['changed']) === 9, 'nine limits in one action (megabytes for the sizes)');
ok((int) setting('max_document_bytes') === 104857600 && (int) setting('max_text_bytes') === 524288 && (int) setting('upload_part_bytes') === 16777216 && (int) setting('upload_session_hours') === 48 && (int) setting('key_rate_per_minute') === 120
    && (int) setting('key_rate_per_day') === 9000 && (int) setting('key_rotation_overlap_hours') === 0 && (int) setting('link_opens_per_minute') === 30 && (int) setting('request_uploads_per_hour') === 10, 'the database holds bytes: 100 MB = 104,857,600');
ok(count(activity('limits.save', $since)) === 1 && count(activity('settings.save', $since)) === 0, 'logged once as limits.save (and not settings.save)');
$x = post_json(1, '/admin/settings/save.php', ['max_document_bytes' => 52428800]);
ok($x['code'] === 200 && (int) setting('max_document_bytes') === 52428800, 'the byte form of the same field is accepted too');
foreach ([['max_document_mb', 0.5, '1.0 MB'], ['max_document_mb', -5, 'empty for no limit'], ['max_text_mb', 0.01, '64'], ['upload_part_mb', 100, '64'], ['upload_part_mb', 'big', 'number'], ['upload_session_hours', 169, '1 to 168'], ['key_rate_per_minute', 0, '1 to 100,000'],
          ['key_rate_per_day', 10000001, '10,000,000'], ['key_rotation_overlap_hours', 169, '0 to 168'], ['link_opens_per_minute', 0, '1 to 100,000'], ['request_uploads_per_hour', 'x', 'whole number']] as [$k, $v, $words]) {
    $x = post_json(1, '/admin/settings/save.php', [$k => $v]);
    ok($x['code'] === 422 && isset($x['json']['error']['fields'][$k]) && str_contains($x['json']['error']['fields'][$k], $words), "$k = " . var_export($v, true) . ' refused: ' . ($x['json']['error']['fields'][$k] ?? '?'));
}
$x = post_json(1, '/admin/settings/save.php', ['max_document_mb' => 2000000]);
ok($x['code'] === 200 && (int) setting('max_document_bytes') === 2000000 * 1048576, 'the largest file has no top (db/024): 2,000,000 MB is a ceiling an admin may set');
$x = post_json(1, '/admin/settings/save.php', ['max_document_mb' => '']);
ok($x['code'] === 200 && setting('max_document_bytes') === null, 'emptied, the largest file has no ceiling again (NULL)');
$since = last_activity_id();
post_json(1, '/admin/settings/save.php', ['business_name' => 'Acme Docs', 'upload_session_hours' => 24]);
ok(count(activity('settings.save', $since)) === 1 && count(activity('limits.save', $since)) === 1, 'a request with a setting and a limit logs both events');
post_json(1, '/admin/settings/save.php', ['max_document_mb' => '', 'max_text_mb' => 5, 'upload_part_mb' => 8, 'upload_session_hours' => 24, 'key_rate_per_minute' => 60, 'key_rate_per_day' => 5000, 'key_rotation_overlap_hours' => 24, 'link_opens_per_minute' => 60, 'request_uploads_per_hour' => 20]);
ok(setting('max_document_bytes') === null && (int) setting('upload_part_bytes') === 8388608, '(restored)');

echo "6. settings_save: the walls; slice 6's windows\n";
$x = post_json(27, '/admin/settings/save.php', ['business_name' => 'Marco']);
ok($x['code'] === 403 && str_contains($x['body'], 'change the settings') && setting('business_name') === 'Acme Docs', 'a curator: 403 "You may not change the settings."; nothing changed');
$x = post_json(27, '/admin/settings/save.php', ['key_rate_per_minute' => 5]);
ok($x['code'] === 403 && str_contains($x['body'], 'set quotas'), 'a curator on a limit: 403 "You may not set quotas."');
ok(post_json(26, '/admin/settings/save.php', ['business_name' => 'x'])['code'] === 403 && post_json(29, '/admin/settings/save.php', ['business_name' => 'x'])['code'] === 403, 'a Member and a guest: 403');
ok(req('POST', '/admin/settings/save.php', ['headers' => ['Accept: application/json'], 'form' => ['business_name' => 'x']])['code'] === 401, 'no session: 401');
ok(post_agent(EXPERT, 9101, '/admin/settings/save.php', ['business_name' => 'Robot'])['code'] === 403 && setting('business_name') === 'Acme Docs', 'the expert agent holds no settings.manage: 403');
$x = post(1, '/admin/settings/save.php', ['business_name' => 'Acme Docs', 'return_to' => '/admin/settings']);
ok(in_array($x['code'], [302, 303], true) && str_contains($x['location'], '/admin/settings') && str_contains($x['location'], 'notice=settings'), 'a browser POST lands back on the page with a notice');
$x = post_json(1, '/admin/settings/retention.php', ['trash_days' => 31]);
ok($x['code'] === 200 && (int) setting('trash_days') === 31, 'slice 6\'s retention_set still works (trash_days 31)');
ok(post_json(1, '/admin/settings/retention.php', ['lock_hours' => 721])['code'] === 422 && post_json(27, '/admin/settings/retention.php', ['trash_days' => 5])['code'] === 403, '...with its bounds and its wall');
post_json(1, '/admin/settings/retention.php', ['trash_days' => 30]);
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$by = []; foreach ($reg['actions'] as $a) { $by[$a['action']] = $a; }
ok($by['settings_save']['approval'] === 'other' && $by['retention_set']['approval'] === 'other' && $by['settings_save']['agent'] === true && $by['settings_save']['log_event'] === 'settings.save', 'the registry: settings_save and retention_set pause for an agent (other)');

echo "7. Every link\n";
$raw = make_link(26, ['document' => $w['priya_doc'], 'label' => 'w9 secret holder', 'expires_in_days' => 30]);
$r = get_json(1, '/admin/links');
$rows = $r['json']['data']['links'] ?? [];
$by = []; foreach ($rows as $l) { $by[$l['link_id']] = $l; }
ok($r['code'] === 200 && isset($by[$w['exp_link']], $by[$w['wide_link']], $by[$w['priya_link']], $by[$w['request']], $by[$raw['link_id']]), 'the admin sees every link and request, whoever made it (' . count($rows) . ')');
ok($by[$w['wide_link']]['suspicious'] === true && $by[$w['wide_link']]['addresses'] === 12 && $by[$w['exp_link']]['suspicious'] === false, 'the link opened from twelve addresses is flagged suspicious; the others are not');
ok($by[$w['exp_link']]['state'] === 'expiring' && $by[$w['priya_link']]['state'] === 'expiring' && $by[$raw['link_id']]['state'] === 'live' && $by[$w['request']]['kind'] === 'request', 'states: expiring, expiring, live; the request is a request');
ok($by[$w['exp_link']]['made_by_name'] === 'SMOKE Marco' && $by[$w['exp_link']]['thing'] === 'acme-msa.pdf', 'who made it and what it opens');
$sus = array_column(get_json(1, '/admin/links?status=suspicious')['json']['data']['links'], 'link_id');
ok($sus === [$w['wide_link']], 'status=suspicious: only that one');
$exp = array_column(get_json(1, '/admin/links?status=expiring')['json']['data']['links'], 'link_id');
ok(in_array($w['exp_link'], $exp, true) && in_array($w['priya_link'], $exp, true) && !in_array($w['wide_link'], $exp, true), 'status=expiring: the two ending in three days');
$req = get_json(1, '/admin/links?kind=request')['json']['data']['links'];
ok(count($req) >= 2 && count(array_filter($req, fn ($l) => $l['kind'] !== 'request')) === 0, 'kind=request: file requests only');
ok(get_json(1, '/admin/links?live=1')['json']['data']['status'] === 'live', '?live=1 is status=live');
$h = page(actor(1), '/admin/links')['body'];
ok(missing_ids($h, ['admin-links-table', 'admin-link-row-' . $w['wide_link'], 'admin-link-row-' . $w['wide_link'] . '-suspicious', 'admin-link-revoke-' . $w['exp_link'], 'admin-links-filters', 'admin-links-filter-suspicious']) === [], 'the page: a row per link, the suspicious badge, Revoke on a live one');
ok(!str_contains($h, $raw['raw']) && !str_contains($h, substr($raw['raw'], 0, 20)), 'the link\'s address (its token) is on no page: it is stored hashed');
ok(get_json(27, '/admin/links')['code'] === 403 && get_json(26, '/admin/links')['code'] === 403 && get_json(29, '/admin/links')['code'] === 403, 'a curator, a Member and a guest: 403');
$since = last_activity_id();
$x = post_json(1, '/links/revoke.php', ['link' => $w['exp_link'], 'return_to' => '/admin/links']);
ok($x['code'] === 200 && $x['json']['record_id'] === $w['exp_link'], 'the admin revokes Marco\'s link through slice 3\'s action');
$a = activity('link.revoke', $since);
ok(count($a) === 1 && (int) $a[0]['actor_member_id'] === 1 && (int) $a[0]['entity_id'] === $w['exp_link'], '...logged link.revoke by the admin');
$st = array_column(get_json(1, '/admin/links?status=revoked')['json']['data']['links'], 'state', 'link_id');
ok(($st[$w['exp_link']] ?? '') === 'revoked' && !in_array($w['exp_link'], array_column(get_json(1, '/admin/links?status=live')['json']['data']['links'], 'link_id'), true), 'it shows as revoked and is no longer live');
$h = page(actor(1), '/admin/links?status=revoked')['body'];
ok(!str_contains($h, 'id="admin-link-revoke-' . $w['exp_link'] . '"'), '...with no Revoke button');
ok(post_json(1, '/links/revoke.php', ['link' => $w['exp_link']])['code'] === 422 && post_json(1, '/links/revoke.php', ['link' => 99999999])['code'] === 404, 'revoking it again: 422; a link that does not exist: 404');
$rp = post(1, '/links/revoke.php', ['link' => $w['wide_link'], 'return_to' => '/admin/links?status=suspicious']);
ok(in_array($rp['code'], [302, 303], true) && str_contains($rp['location'], '/admin/links') && str_contains($rp['location'], 'notice=revoked'), 'from the page it lands back on the list with a notice');
ok(get_json(1, '/admin/links?status=suspicious')['json']['data']['links'] === [], 'a revoked link is no longer suspicious (dc_links_suspicious lists live ones)');

echo "8. Every library\n";
$libCount = (int) one('SELECT count(*) FROM libraries WHERE archived_at IS NULL');
$r = get_json(1, '/admin/libraries');
$rows = $r['json']['data']['libraries'] ?? [];
$kinds = array_count_values(array_column($rows, 'kind'));
ok($r['code'] === 200 && count($rows) === $libCount && ($kinds['personal'] ?? 0) >= 4 && ($kinds['group'] ?? 0) >= 1 && ($kinds['shared'] ?? 0) >= 1, 'every library: ' . count($rows) . ' (personal ' . ($kinds['personal'] ?? 0) . ', group ' . ($kinds['group'] ?? 0) . ', shared ' . ($kinds['shared'] ?? 0) . ')');
$priyaLib = (int) one("SELECT id FROM libraries WHERE kind = 'personal' AND owner_member_id = 26");
$pl = array_values(array_filter($rows, fn ($l) => $l['library_id'] === $priyaLib))[0];
ok($pl['personal'] === true && $pl['owner_name'] === 'SMOKE Priya' && $pl['i_belong'] === false && $pl['documents'] >= 1, 'Priya\'s personal library is marked: hers, not the admin\'s, with its documents counted');
$boardRow = array_values(array_filter($rows, fn ($l) => $l['library_id'] === $w['lib']))[0];
ok($boardRow['group_name'] === 'SMOKE Board' && $boardRow['quota_bytes'] === 1000000 && $boardRow['used_bytes'] === (int) one('SELECT used_bytes FROM libraries WHERE id = :i', ['i' => $w['lib']]) && $boardRow['pct'] >= 92.0, 'a group library names its group, used ' . $boardRow['used_bytes'] . ' of 1,000,000 (' . $boardRow['pct'] . '%)');
ok(array_unique(array_column(get_json(1, '/admin/libraries?kind=personal')['json']['data']['libraries'], 'kind')) === ['personal'], 'kind=personal narrows');
$p->exec("UPDATE libraries SET archived_at = now() WHERE kind = 'shared'");
$arch = get_json(1, '/admin/libraries?archived=1')['json']['data']['libraries'];
$p->exec("UPDATE libraries SET archived_at = NULL WHERE kind = 'shared'");
ok(count($arch) >= 1 && count(array_filter($arch, fn ($l) => !$l['archived'])) === 0, 'archived=1 lists the archived ones');
$h = page(actor(1), '/admin/libraries')['body'];
ok(missing_ids($h, ['admin-libraries-table', 'admin-library-row-' . $priyaLib, 'admin-library-kind-' . $priyaLib, 'admin-library-link-' . $priyaLib, 'admin-libraries-filter-personal']) === [] && str_contains($h, 'admin view'), 'the page: a row per library, the kind, a link, the "admin view" mark');
ok(get_json(27, '/admin/libraries')['code'] === 403 && get_json(26, '/admin/libraries')['code'] === 403, 'a curator and a Member: 403');
[$ja, ] = sign_on(1);
$since = last_activity_id();
page($ja, '/admin/libraries'); page($ja, '/admin/libraries');
ok(count(activity('library.admin_view', $since)) === 0, 'looking at the list logs no admin view (only opening a library does)');
$link = '/libraries/' . $priyaLib;
page($ja, $link); page($ja, $link); page($ja, $link . '?back=%2Fadmin%2Flibraries');
$a = activity('library.admin_view', $since);
ok(count($a) === 1 && (int) $a[0]['library_id'] === $priyaLib && (int) $a[0]['actor_member_id'] === 1, 'opening Priya\'s personal library three times in one session logs library.admin_view once');
$other = (int) one("SELECT id FROM libraries WHERE kind = 'personal' AND owner_member_id = 27");
page($ja, '/libraries/' . $other);
ok(count(activity('library.admin_view', $since)) === 2, '...another library, another row');
[$jb, ] = sign_on(1);
page($jb, $link);
ok(count(activity('library.admin_view', $since)) === 3, '...and a new session logs it again');
$own = (int) one("SELECT id FROM libraries WHERE kind = 'personal' AND owner_member_id = 1");
page($ja, '/libraries/' . $own);
ok(count(activity('library.admin_view', $since)) === 3, 'the admin opening their own library logs nothing');
preg_match('~href="(/libraries/' . $priyaLib . '[^"]*)"~', $h, $lm);
ok(isset($lm[1]) && str_contains($lm[1], 'back='), 'the list\'s link carries ?back= (click-around)');

echo "9. The disk\n";
$r = get_json(1, '/admin/storage');
$j = $r['json']['data'] ?? [];
ok($r['code'] === 200 && $j['state'] === 'ok' && $j['free_bytes'] > 0 && $j['total_bytes'] >= $j['free_bytes'], 'the disk: the store ok, free ' . round($j['free_bytes'] / 1073741824, 1) . ' GB of ' . round($j['total_bytes'] / 1073741824, 1) . ' GB');
$sql = q("SELECT count(*) FILTER (WHERE ref_count > 0 AND deleted_at IS NULL) lv, COALESCE(sum(byte_size) FILTER (WHERE ref_count > 0 AND deleted_at IS NULL), 0) lb, count(*) FILTER (WHERE ref_count = 0 AND deleted_at IS NULL) wt, count(*) FILTER (WHERE deleted_at IS NOT NULL) fr FROM blobs")[0];
ok($j['blobs']['live'] === (int) $sql['lv'] && $j['blobs']['live_bytes'] === (int) $sql['lb'] && $j['blobs']['waiting'] === (int) $sql['wt'] && $j['blobs']['freed'] === (int) $sql['fr'], 'blobs: ' . $sql['lv'] . ' in use (' . $sql['lb'] . ' bytes), ' . $sql['wt'] . ' waiting, ' . $sql['fr'] . ' freed — the database\'s own');
$used = (int) one('SELECT sum(used_bytes) FROM libraries');
ok($j['libraries_used_bytes'] === $used && $j['deduplicated_bytes'] === max(0, $used - $j['blobs']['live_bytes']), 'the libraries add up ' . $used . '; de-duplication saved ' . $j['deduplicated_bytes'] . ' (used minus the blobs)');
$p->exec("INSERT INTO blobs (sha256, byte_size, storage_path, ref_count) VALUES ('" . str_repeat('a', 64) . "', 4321, 'blobs/aa/aa/x', 0)");
$j2 = get_json(1, '/admin/storage')['json']['data'];
ok($j2['blobs']['waiting'] === $j['blobs']['waiting'] + 1 && $j2['blobs']['waiting_bytes'] === $j['blobs']['waiting_bytes'] + 4321, 'a blob nobody references is "waiting to be freed" (+4,321 bytes)');
$p->exec("DELETE FROM blobs WHERE sha256 = '" . str_repeat('a', 64) . "'");
$h = page(actor(1), '/admin/storage')['body'];
ok(missing_ids($h, ['storage-state-badge', 'storage-directory', 'storage-disk', 'blobs-live', 'blobs-waiting', 'blobs-freed', 'blobs-dedup']) === [], 'the page: the state, the directory, the disk bar, the four blob figures');
ok(get_json(27, '/admin/storage')['code'] === 403 && get_json(26, '/admin/storage')['code'] === 403, 'a curator and a Member: 403');
$since = last_activity_id(); get_json(1, '/admin/storage');
ok(count(activity('screen.view', $since)) === 1 && activity('screen.view', $since)[0]['screen'] === 'admin-storage', 'screen.view logged for admin-storage');

echo "10. Connections: the kernel's list, and the sentence\n";
kernel_connections_set(null);
$r = get_json(1, '/admin/connections');
$j = $r['json']['data'] ?? [];
ok($r['code'] === 200 && $j['approved_in_kernel'] === false && $j['approved'] === null && count($j['declared_shares']) === 4, 'a kernel without the route: not listed; DocCloud\'s four reads named from the manifest');
$h = page(actor(1), '/admin/connections')['body'];
ok(str_contains($h, 'id="connections-kernel-fallback"') && str_contains($h, 'bin/app_connection.php') && str_contains($h, 'folders_index'), '...the page says they are approved in the kernel (bin/app_connection.php) and names the reads');
kernel_connections_set([['consumer' => 'knowledge', 'provider' => 'doccloud', 'tool' => 'document_text', 'status' => 'approved'], ['consumer' => 'projects', 'provider' => 'doccloud', 'tool' => 'folders_index', 'status' => 'pending']]);
$j = get_json(1, '/admin/connections')['json']['data'];
ok($j['approved_in_kernel'] === true && count($j['approved']) === 2 && $j['approved'][0]['consumer'] === 'knowledge' && $j['approved'][0]['status'] === 'approved', 'the fake kernel\'s route answers: two connections, knowledge approved');
$h = page(actor(1), '/admin/connections')['body'];
ok(str_contains($h, 'id="connections-kernel-list"') && str_contains($h, 'id="connections-kernel-0"') && !str_contains($h, 'connections-kernel-fallback'), '...listed on the page, no fallback sentence');
kernel_connections_set([]);
ok(str_contains(page(actor(1), '/admin/connections')['body'], 'id="connections-kernel-none"'), 'an empty list says no application is approved yet');
kernel_connections_set(null);
ok(get_json(27, '/admin/connections')['code'] === 403, 'a curator: 403');

echo "11. The landings slice 8's proposals use\n";
foreach (['/curate/ownership?member=31' => 'curate-ownership', '/admin/quotas?library=' . $w['lib'] => 'admin-quotas', '/admin/graph' => 'graph-admin', '/admin/retention' => 'admin-settings', '/admin/keys' => 'admin-keys', '/admin/agents' => 'admin-agents', '/admin/worker' => 'admin-worker'] as $u => $screen) {
    $since = last_activity_id();
    $r = get_json(1, $u);
    $rows = array_filter(activity('screen.view', $since), fn ($a) => $a['screen'] === $screen || (json_decode((string) $a['after'], true)['screen'] ?? '') === $screen || ($a['entity_type'] ?? '') !== '');
    ok($r['code'] === 200 && isset($r['json']['data']), "$u resolves: 200 with data");
}
ok(array_key_exists('available', get_json(1, '/admin/graph')['json']['data']) && get_json(1, '/admin/graph')['json']['data'] === get_json(1, '/graph/admin')['json']['data'], '/admin/graph is the graph admin (/graph/admin) itself');
$prop = post_agent(CLERK, 9201, '/agents/propose.php', ['kind' => 'orphaned', 'title' => 'Lee left; 3 documents are still his', 'for_member' => 27, 'key' => 'owner:w9', 'payload' => json_encode(['owner_member_id' => 31])]);
ok($prop['code'] === 200 && ($prop['json']['ok'] ?? false), 'the Clerk\'s "orphaned" proposal for a curator is made');
$dec = post_json(27, '/curate/proposals/decide.php', ['proposal' => $prop['json']['record_id'], 'decision' => 'accept']);
ok($dec['code'] === 200 && str_contains((string) ($dec['json']['location'] ?? ''), '/curate/ownership') && str_contains((string) ($dec['json']['location'] ?? ''), 'member=31'), 'accepting it lands on /curate/ownership?member=31: ' . ($dec['json']['location'] ?? '?'));
finish();
