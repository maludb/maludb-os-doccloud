<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-doccloud.conf; the routing form of docs/phase1-consistency.md). Meaningful under
 * DC_APP=apache tests/phase2/run.sh, where a real Apache serves the template rendered as the installer renders it; under php -S the same
 * checks run against tests/dev_router.php, which mirrors the rewrites. The receivers at their extension-less names, the canonical URLs and
 * the placeholders, /d/, /f/, /s/, /r/, /api/v1/* (404 until slice 7 but for health), /pick/, the MCP proxies 502 (under Apache), the
 * manifest and the assets with their types, nothing outside html/, storage/ unreachable by path.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php');
foreach (['files', 'shared', 'groups', 'libraries', 'tags', 'search', 'graph', 'trash', 'curate'] as $d) {
    ok(page($j, "/$d/")['code'] === 200 && page($j, "/$d")['code'] === 200, "/$d/ and /$d both reach $d/index.php (the placeholder)");
}
ok(page($j, '/recent')['code'] === 200 && page($j, '/favourites')['code'] === 200, '/recent and /favourites reach recent.php and favourites.php (the placeholders)');
ok(page($j, '/admin/')['code'] === 403 && page($j, '/admin')['code'] === 403, '/admin/ and /admin resolve to admin/index.php (403 for a curator, by the right)');
ok(page($j, '/activity')['code'] === 200 && page($j, '/notifications')['code'] === 200, '/activity and /notifications resolve to their own files');
ok(page($j, '/groups/new')['code'] === 404 && page($j, '/groups/1')['code'] === 404 && page($j, '/groups/1/edit')['code'] === 404, '/groups/new, /groups/1 and /groups/1/edit are rewritten to form.php and view.php, which slice 1 builds: 404 until then');
ok(page($j, '/folders/1')['code'] === 404 && page($j, '/documents/1')['code'] === 404, '/folders/{id} and /documents/{id} are rewritten to their view.php: 404 until slices 1 and 2');
ok(page($j, '/f/1')['code'] === 404 && page($j, '/f/1/share')['code'] === 404, '/f/{id} and /f/{id}/share are rewritten to f/index.php and f/share.php: 404 until slices 1 and 3');
ok(req('GET', '/d/1')['code'] === 404 && req('GET', '/d/1/v/2')['code'] === 404 && req('GET', '/d/1/thumb')['code'] === 404, '/d/{id}, /d/{id}/v/{n} and /d/{id}/thumb are rewritten to d/index.php and d/thumb.php: 404 until slice 2');
ok(req('GET', '/s/' . str_repeat('a', 48))['code'] === 404 && req('GET', '/r/' . str_repeat('b', 48))['code'] === 404, '/s/<token> and /r/<token> are rewritten to s/index.php and r/index.php: 404 until slice 3');
ok(req('GET', '/s/short')['code'] === 404 && req('GET', '/s.php')['code'] === 404 && req('GET', '/d.php')['code'] === 404, 'a malformed token path and the old s.php / d.php names are 404');
ok(req('GET', '/upload/start')['code'] === 404 && req('GET', '/exports/1')['code'] === 404, '/upload/{x} and /exports/{id} are rewritten to their files: 404 until slice 2');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
$hb = json_decode($h['body'], true) ?: [];
ok($h['code'] === 200 && ($hb['application'] ?? '') === 'doccloud', '/api/v1/health answers');
ok(($hb['storage'] ?? '') === 'ok' && ($hb['graph'] ?? '') === 'unavailable', 'health says storage: ok and graph: unavailable (no token in the proof)');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('POST', '/api/v1/upload')['code'] === 404 && req('GET', '/api/v1/documents/1')['code'] === 404 && req('POST', '/api/v1/search')['code'] === 404, 'the token API (/api/v1/upload, /documents/{id}, /search) is rewritten to files slice 7 builds: 404 until then');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
$p = req('GET', '/pick/?source=x');
ok($p['code'] === 302 && $p['location'] === LAUNCHER, '/pick/ without a session goes to the launcher');
if (getenv('DC_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:8707/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'doccloud', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
    $conf = (string) file_get_contents(getenv('DC_DEV_STATE') . '/apache/site.conf');
    ok(str_contains($conf, 'LimitRequestBody 73400320') && str_contains($conf, 'upload_max_filesize 64M') && str_contains($conf, 'post_max_size 72M'), 'the rendered vhost carries LimitRequestBody 70 MB and the PHP upload limits deploy says');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'DocCloud', '/manifest.webmanifest is served as JSON and names DocCloud');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/app/doccloud/graph.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/storage/blobs/aa/bb/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
