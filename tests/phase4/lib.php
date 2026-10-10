<?php
/**
 * Helpers for the Phase 4 proofs (docs/doccloud-mcp-tool-surface.md). Run through tests/phase4/run.sh: a SCRATCH database (dc_dev10 — never the installed one), the application on :8701, a fake kernel on :8702 (run-facts, K6),
 * a fake MaluDB + MaluMail on :8706, and the TWO MCP SERVERS on :8704 (records) and :8705 (activity); the records server reaches the application's own door (the bridge) on the internal port. Everything a proof makes is named
 * "SMOKE …" or "p4 …". Builds on slice 9's helpers (so 8's … 1's and Phase 2's): the cast is the admin (1), Priya (26, a Member), Marco (27, a curator, owner of the Board), Bea (28), Ann (29, a guest), Dana (30), Lee (31, has left),
 * Seamus (40, the expert agent), the Watcher (41, an agent the kernel never vouched for), the Clerk (42, an agent). The world is slice 9's world (which holds slices 1-8's) plus p4_world()'s additions.
 */
require dirname(__DIR__) . '/phase3/slice9/lib.php';

const REC = 8704;
const ACT = 8705;
/** The tools that take no arguments (the client sends no `params`). */
const NO_ARGS = ['app_roles', 'get_settings', 'my_favourites'];

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** An API KEY (scope api) — the token API's, refused by the MCP servers. */
function ktoken_api(int $member): string
{
    $raw = 'dck_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4 key', :h, 'api')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string { return run_token($member, $run, $ttl); }
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string { return person_token($member, $ttl); }
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function kernel_token(string $app = 'doccloud', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp', array $extra = []): array
{
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'], $extra);
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp', array $extra = []): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path, $extra);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path, $extra); }
    return [$sess, $st, $init];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, array $extra = []): ?array
{
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, '/mcp', $extra);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/** The full tools/list answer (names, descriptions, annotations, input schemas). */
function mcp_tool_defs(int $port, string $token): array
{
    [$sess] = mcp_open($port, $token);
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess);
    return $a['result']['tools'] ?? [];
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null, 'code' => the error code when the tool answered {ok:false,error:{code}}]. Arguments are wrapped as
 * {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat) and the tools that take none (app_roles, get_settings, my_favourites).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, array $extra = []): array
{
    @file_put_contents(need('DC_DEV_STATE') . '/p4-called.txt', $tool . "\n", FILE_APPEND);
    [$sess, $st] = mcp_open($port, $token, '/mcp', $extra);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null, 'code' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, NO_ARGS, true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, '/mcp', $extra);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    $code = is_array($data) && ($data['ok'] ?? null) === false ? ($data['error']['code'] ?? null) : null;
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || $code !== null, 'text' => $text, 'data' => is_array($data) ? $data : null, 'code' => $code];
}
/** A tool as a person (their own token): the whole answer, or just the decoded data. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** Every key anywhere in a decoded answer. */
function has_key($data, string $key): bool
{
    if (!is_array($data)) { return false; }
    foreach ($data as $k => $v) { if ($k === $key || has_key($v, $key)) { return true; } }
    return false;
}
/** All the values of a key anywhere in a decoded answer. */
function key_values($data, string $key): array
{
    $out = [];
    if (!is_array($data)) { return $out; }
    foreach ($data as $k => $v) { if ($k === $key) { $out[] = $v; } $out = array_merge($out, key_values($v, $key)); }
    return $out;
}
function find_row(?array $rows, string $key, $value): ?array { foreach ($rows ?? [] as $r) { if ((string) ($r[$key] ?? '') === (string) $value) { return $r; } } return null; }
function ids(?array $rows, string $key): array { return array_values(array_map(fn ($r) => $r[$key], $rows ?? [])); }
/** SQL over the views AS a member (the same rows the server hands that member). */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return $rows;
}
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }
/** The ids of the documents a member may see (the database's own word, one statement as that member; the proofs' small store only). */
function visible_docs(int $member): array { return array_map('intval', array_column(sql_as($member, 'SELECT id FROM documents WHERE dc_can_see_document(id) ORDER BY id'), 'id')); }
/** The same, live documents only (not in the trash): what a listing or a search shows. */
function visible_live(int $member): array { return array_map('intval', array_column(sql_as($member, 'SELECT id FROM documents WHERE trashed_at IS NULL AND dc_can_see_document(id) ORDER BY id'), 'id')); }
function all_docs(): array { return array_map('intval', array_column(q('SELECT id FROM documents ORDER BY id'), 'id')); }

/**
 * The Phase 4 world: slice 9's (slices 1-8's) plus — made once per run, named p4 —
 *   a group "SMOKE p4 Legal" (Marco owns, Priya a member) whose library holds a folder "p4 Contracts" with two documents (one tagged p4-legal) and a request link; a document of Dana's own that nobody else sees (p4-dana-private.pdf);
 *   a saved search of Priya's (keyword, shared) and one of Marco's (graph); a favourite and a note; an upload session of Priya's; a shared tag proposal; the application row "knowledge" on the Contracts folder and a document with an
 *   application row of its own (the shares); a share.read in the log; and each actor's MCP token.
 */
function p4_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $file = (string) getenv('DC_DEV_STATE') . '/world10.json';
    if (is_file($file)) { return $w = json_decode((string) file_get_contents($file), true); }
    $r = world9();
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '27', false)");
    $g = one("SELECT id FROM groups WHERE key = 'p4-legal'");
    if ($g === false) {
        $p->exec("INSERT INTO groups (key, name, kind, created_by) VALUES ('p4-legal', 'SMOKE p4 Legal', 'custom', 27)");
        $g = one("SELECT id FROM groups WHERE key = 'p4-legal'");
        $p->exec("INSERT INTO group_members (group_id, member_id, role, added_by) VALUES ($g, 27, 'owner', 27), ($g, 26, 'member', 27) ON CONFLICT DO NOTHING");
    }
    $legalRoot = (int) one('SELECT root_folder_id FROM libraries WHERE group_id = :g', ['g' => $g]);
    $contracts = folder_in($legalRoot, 'p4 Contracts', 27);
    folder_in($legalRoot, 'Contracts', 27);                              // a second folder named Contracts (the Board has one): a name that is ambiguous
    $nda = seed_doc($contracts, 'p4-nda.pdf', 27, 'Mutual non-disclosure agreement. quokka clause. ' . WORLD_SECRET);
    $lease = seed_doc($contracts, 'p4-lease.pdf', 27, 'Office lease for the warehouse. quokka renewal. ' . WORLD_SECRET);
    $dana = seed_doc(root_of(30), 'p4-dana-private.pdf', 30, 'Dana private quokka note. ' . WORLD_SECRET);
    $tag = tag_id_of('p4-legal');
    $p->exec("UPDATE document_text SET pages = jsonb_build_array(jsonb_build_object('page_no', 1, 'char_from', 0, 'char_to', 33), jsonb_build_object('page_no', 2, 'char_from', 33, 'char_to', char_count)) WHERE document_id = $nda");
    tag_doc($nda, $tag);
    $req = make_request(27, ['folder' => $contracts, 'label' => 'p4 legal request']);
    // the shares: the application knowledge on the Contracts folder (a row at its access root), helpdesk on nothing, and one document with an application row of its own
    $p->exec("INSERT INTO folder_access (folder_id, principal_kind, principal_ref, level, granted_by) VALUES ($contracts, 'application', 'knowledge', 'view', 27) ON CONFLICT DO NOTHING");
    $own = seed_doc($legalRoot, 'p4-own-row.pdf', 27, 'A document with a row of its own. quokka ' . WORLD_SECRET);
    $p->exec("INSERT INTO document_access (document_id, principal_kind, principal_ref, level, granted_by) VALUES ($own, 'application', 'knowledge', 'view', 27) ON CONFLICT DO NOTHING");
    $p->exec("INSERT INTO saved_searches (owner_id, name, query, filters, kind, is_shared) VALUES (26, 'p4 quokka', 'quokka', '{}', 'keyword', true), (27, 'p4 graphs', 'warehouse', '{}', 'graph', false) ON CONFLICT DO NOTHING");
    $p->exec("INSERT INTO favourites (member_id, document_id) VALUES (26, $nda) ON CONFLICT DO NOTHING");
    $p->exec("INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('document', $nda, 27, 'p4 note on the nda')");
    $p->exec("INSERT INTO upload_sessions (member_id, folder_id, filename, byte_size, part_bytes, parts_total, parts_done, status, expires_at) VALUES (26, $contracts, 'p4-big.bin', 20000000, 8388608, 3, 1, 'open', now() + interval '1 day')");
    $p->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, document_id, folder_id, library_id, after) VALUES (NULL, 'application', 'share.read', 'share', NULL, NULL, NULL, '{\"direction\":\"in\",\"tool\":\"folders_index\",\"consumer\":\"knowledge\",\"rows\":1,\"outcome\":\"ok\"}'),
                                                                                                         (NULL, 'application', 'share.refused', 'share', NULL, NULL, NULL, '{\"direction\":\"in\",\"tool\":\"documents_index\",\"consumer\":\"helpdesk\",\"reason\":\"not_shared\"}')");
    $w = $r + ['legal_group' => (int) $g, 'legal_root' => $legalRoot, 'legal_contracts' => $contracts, 'nda' => $nda, 'lease' => $lease, 'dana_doc' => $dana, 'legal_tag' => $tag, 'legal_request' => (int) $req['link_id'], 'own_row' => $own];
    file_put_contents($file, json_encode($w));
    return $w;
}
/** The tool surface (docs/doccloud-mcp-tool-surface.md) as the proofs read it: the named tools of each server, sorted. */
function surface(): array
{
    static $s = null;
    if ($s !== null) { return $s; }
    $t = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/doccloud-mcp-tool-surface.md');
    $a = strpos($t, '## Records server'); $b = strpos($t, '## The shares, for the kernel'); $c = strpos($t, '## Activity server'); $d = strpos($t, '### The activity vocabulary');
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $a, $b - $a), $rec);
    preg_match_all('/^\| `([a-z_]+)`/m', substr($t, $c, $d - $c), $act);
    $shares = ['folders_index', 'documents_index', 'document_text', 'document_link'];
    $records = array_values(array_unique(array_merge($rec[1], ['app_roles', 'records_search'], $shares))); sort($records);
    $activity = $act[1]; sort($activity);
    return $s = ['records' => $records, 'activity' => $activity, 'shares' => $shares, 'kernel_only' => ['folders_index', 'documents_index', 'document_link']];
}
/** An agent's grants as maludb-os.json declares them: ['Records MCP' => [tool => []], 'Activity MCP' => ..., 'Actions MCP' => ...]. */
function agent_grants(string $key): array
{
    $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
    foreach ($m['agents'] as $a) { if ($a['key'] === $key) { return array_map(fn ($tools) => array_fill_keys($tools, []), $a['tool_grants']); } }
    return [];
}
/** The signed bridge request, as the records server makes it (or a wrong one): POST /internal/bridge.php on the internal port. */
function bridge_post(array $body, array $o = []): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    $ts = $o['ts'] ?? time();
    $key = $o['key'] ?? need('ACTIONS_RELAY_KEY');
    $sig = hash_hmac('sha256', 'dc-bridge:' . $ts . '.' . hash('sha256', $o['signed'] ?? $json), $key);
    $port = (int) need('APP_INTERNAL_PORT');
    $ch = curl_init("http://127.0.0.1:$port/internal/bridge.php");
    $h = ['Content-Type: application/json'];
    if (!isset($o['no_header'])) { $h[] = 'X-DC-Bridge: ' . $ts . '.' . $sig; }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = (string) curl_exec($ch);
    return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($b, true) ?: [], 'body' => $b];
}

// ---- the engine (DC_ENGINE=1: the real MaluDB API over a scratch memory database and a fake model provider; run.sh starts them) — the recipe of slice 5 --------------------------------
function engine(): bool { return getenv('DC_ENGINE') === '1'; }
function fake_url(): string { return rtrim((string) getenv('DC_FAKE_PROVIDER'), '/'); }
/** The model set through the admin's handler (the fake provider, the default prompt, a credential typed once). */
function graph_ready(): array
{
    static $done = null;
    if ($done !== null) { return $done; }
    $done = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1',
        'embedding_model' => 'maludb-local-dev', 'token' => 'fake-secret-' . bin2hex(random_bytes(4))]);
    return $done;
}
/** The worker's graph pass; returns the pass's report. */
function run_graph(array $env = []): array { $r = worker('graph_jobs', $env); return $r['ran']['graph_jobs'] ?? $r; }
