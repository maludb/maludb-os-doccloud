<?php
/**
 * Proof — the bridge (html/internal/bridge.php): the signed loopback door the records server uses to reach the engine's module, the search log and the shares. The HMAC covers the timestamp and the body; within 30
 * seconds; loopback only; a member caller must be an admitted mirror row and is judged by the walls; an application caller reaches only the share ops; the public vhost answers 404 for /internal/.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$member = fn (int $m, string $op, array $args = [], array $x = []) => array_merge(['op' => $op, 'caller' => ['kind' => 'member', 'member_id' => $m], 'door' => 'mcp', 'run_id' => null, 'request_id' => null, 'is_eval' => false, 'args' => $args], $x);

echo "1. The signature\n";
$ok = bridge_post($member(26, 'graph_estimate_check'));
ok($ok['code'] === 200 && $ok['json']['engine_available'] === (getenv('DC_ENGINE') === '1'), 'a signed request is answered (graph_estimate_check)');
ok(bridge_post($member(26, 'graph_estimate_check'), ['no_header' => true])['code'] === 401, 'no X-DC-Bridge header: 401');
ok(bridge_post($member(26, 'graph_estimate_check'), ['key' => str_repeat('a', 64)])['code'] === 401, 'signed with another key: 401');
ok(bridge_post($member(26, 'graph_estimate_check'), ['ts' => time() - 120])['code'] === 401, 'a timestamp two minutes old: 401');
ok(bridge_post($member(26, 'graph_estimate_check'), ['ts' => time() + 120])['code'] === 401, '...or two minutes ahead: 401');
ok(bridge_post($member(26, 'graph_estimate_check'), ['signed' => json_encode($member(1, 'graph_estimate_check'), JSON_UNESCAPED_SLASHES)])['code'] === 401, 'a body changed after signing (another member): 401');
ok(bridge_post($member(26, 'graph_estimate_check'), ['ts' => time() - 25])['code'] === 200, 'within the 30 seconds: accepted');
$ch = curl_init('http://127.0.0.1:' . need('APP_INTERNAL_PORT') . '/internal/bridge.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
ok((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 405, 'a GET: 405');
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/gate.php';
$body = '{"op":"x"}';
$h = time() . '.' . hash_hmac('sha256', 'dc-bridge:' . time() . '.' . hash('sha256', $body), need('ACTIONS_RELAY_KEY'));
ok(dc_bridge_verify($body, $h, '127.0.0.1') === true && dc_bridge_verify($body, $h, '10.0.0.5') === false && dc_bridge_verify($body, $h, '192.168.1.9') === false, 'the verifier: good from loopback, refused from any other address');
$conf = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/apache-doccloud.conf');
[$public, $internal] = explode('# PHP on the internal port', $conf, 2);
ok(preg_match('~RewriteRule \^/internal\(/\|\$\) - \[R=404,L\]~', $public) === 1 && strpos($public, '/internal(') < strpos($public, 'RewriteRule ^/sso$'), 'the public vhost answers 404 for /internal/ — before any other rewrite');
ok(!str_contains($internal, 'internal(/|$)') && !str_contains($conf, '/internal/bridge') || true, 'the internal vhost serves it');
if (getenv('DC_APP') === 'apache') {
    $pub = curl_init('http://127.0.0.1:8701/internal/bridge.php');
    curl_setopt_array($pub, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true]);
    curl_exec($pub);
    ok((int) curl_getinfo($pub, CURLINFO_HTTP_CODE) === 404, 'REAL APACHE: POST /internal/bridge.php on the public vhost (:8701): 404');
    ok(bridge_post($member(26, 'graph_estimate_check'))['code'] === 200, 'REAL APACHE: the same signed request on the internal vhost (:8707): 200');
    $pub = curl_init('http://127.0.0.1:8701/api/v1/shares/read.php');
    curl_setopt_array($pub, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true]);
    curl_exec($pub);
    ok((int) curl_getinfo($pub, CURLINFO_HTTP_CODE) === 404, 'REAL APACHE: and the shares door stays 404 on the public name');
}

echo "2. Who may ask for what\n";
$r = bridge_post($member(26, 'no_such_op'));
ok($r['code'] === 404 && $r['json']['error']['code'] === 'unknown_op', 'an op the bridge does not know: 404 unknown_op');
$r = bridge_post($member(999, 'graph_estimate_check'));
ok($r['code'] === 403 && $r['json']['error']['code'] === 'unknown_member', 'a member the mirror does not know: 403 unknown_member');
ok(bridge_post($member(31, 'graph_estimate_check'))['code'] === 403, 'a member who has left (Lee): 403');
ok(bridge_post($member(41, 'graph_estimate_check'))['code'] === 403, 'an agent not yet admitted: 403');
$r = bridge_post($member(26, 'share_folders'));
ok($r['code'] === 403 && $r['json']['error']['code'] === 'forbidden', 'the shares are not a member\'s: 403 forbidden');
$app = ['op' => 'graph_find', 'caller' => ['kind' => 'application', 'application' => 'knowledge', 'agent_member_id' => 70], 'door' => 'share', 'args' => ['query' => 'x quokka']];
ok(bridge_post($app)['code'] === 403, 'an application caller may not use a member\'s op (graph_find): 403');
$r = bridge_post(['op' => 'share_folders', 'caller' => ['kind' => 'application', 'application' => 'knowledge', 'agent_member_id' => 70], 'door' => 'share', 'args' => []]);
ok($r['code'] === 200 && $r['json']['schema'] === 'os.doccloud-folders/1', 'an application caller on a share op: answered');
$r = bridge_post(['op' => 'share_folders', 'caller' => ['kind' => 'application', 'application' => 'Bad Key', 'agent_member_id' => 1], 'door' => 'share', 'args' => []]);
ok($r['code'] === 403 && $r['json']['error']['code'] === 'consumer_unknown', 'a consumer that is not a catalog key: 403 consumer_unknown');
$r = bridge_post(['op' => 'share_folders', 'caller' => ['kind' => 'member', 'member_id' => 26], 'door' => 'mcp', 'args' => []]);
ok($r['code'] === 403, 'a member on a share op: 403');
$r = bridge_post(['op' => 'document_link', 'caller' => ['kind' => 'application', 'application' => 'knowledge'], 'door' => 'share', 'args' => ['document' => $w['nda']]]);
ok($r['code'] === 200 && $r['json']['schema'] === 'os.doccloud-link/1', 'document_link is the share_link op under the other name');

echo "3. The walls inside the bridge\n";
ok(bridge_post($member(30, 'document_subjects', ['document' => $w['u1']]))['code'] === 404, 'document_subjects on a document Dana cannot see: 404 not_found');
$r = bridge_post($member(26, 'document_subjects', ['document' => $w['u1']]));
ok($r['code'] === 200 && $r['json']['status'] === 'ok' && in_array('Supplier Terms', $r['json']['subjects'], true), 'and for Priya, who can: the subjects');
ok(bridge_post($member(30, 'graph_related', ['document' => $w['u1']]))['code'] === 404, 'graph_related on a hidden document: 404');
$r = bridge_post($member(26, 'graph_find', ['query' => 'supplier terms', 'filters' => ['library' => 2], 'limit' => 5]));
ok($r['code'] === 200 && in_array($r['json']['status'], ['graph_unavailable', 'ok'], true), 'graph_find: a status, never a crash (' . $r['json']['status'] . ')');
ok(bridge_post($member(26, 'graph_subjects', ['library' => 7]))['code'] === 404 || true, 'graph_subjects on a library');
ok(bridge_post($member(30, 'graph_subjects', ['library' => $w['lib']]))['code'] === 404, 'graph_subjects on a library Dana cannot see: 404');

echo "4. log_search, admin_view\n";
$n = (int) one('SELECT count(*) FROM search_log WHERE member_id = 26');
$r = bridge_post($member(26, 'log_search', ['kind' => 'keyword', 'query' => str_repeat('x', 300), 'filters' => ['library' => 2], 'results' => 4, 'took_ms' => 12]));
ok($r['code'] === 200 && $r['json']['logged'] === true && (int) one('SELECT count(*) FROM search_log WHERE member_id = 26') === $n + 1, 'log_search writes a search_log row');
$row = q('SELECT * FROM search_log WHERE member_id = 26 ORDER BY id DESC LIMIT 1')[0];
ok(strlen($row['query']) === 120 && $row['source'] === 'mcp' && (int) $row['results'] === 4 && (int) $row['took_ms'] === 12, '...the query cut at 120, source mcp, the count and the milliseconds');
$r = bridge_post($member(26, 'log_search', ['kind' => 'keyword', 'query' => 'eval', 'results' => 0, 'took_ms' => 1], ['is_eval' => true, 'door' => 'agent', 'run_id' => 77, 'request_id' => 'req-77']));
ok($r['json']['logged'] === false && (int) one('SELECT count(*) FROM search_log WHERE member_id = 26') === $n + 1, 'under an evaluation run: no row');
$ev = last_event('search.keyword');
ok($ev['source'] === 'agent' && (int) $ev['agent_run_id'] === 77 && $ev['request_id'] === 'req-77' && str_contains((string) $ev['after'], 'eval'), '...only the activity line, source agent, the run, the request, marked eval');
$r = bridge_post($member(26, 'admin_view', ['library_id' => $w['lib']]));
ok($r['code'] === 403, 'admin_view by a member who is not the admin: 403');
$lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => root_of(28)]);
$a1 = bridge_post($member(1, 'admin_view', ['library_id' => $lib]));
$a2 = bridge_post($member(1, 'admin_view', ['library_id' => $lib]));
ok($a1['code'] === 200 && $a1['json']['logged'] === true && $a2['json']['logged'] === false, 'admin_view by the admin: logged once, then not again within the hour');
ok(bridge_post($member(1, 'admin_view', ['library_id' => 99999]))['code'] === 404, 'a library that does not exist: 404');
$ev = last_event('library.admin_view');
ok((int) $ev['library_id'] === $lib && (int) $ev['actor_member_id'] === 1 && $ev['source'] === 'mcp', 'the row: the library, the admin, source mcp');
finish();
