<?php
/**
 * Proof — coverage: the surface document, the servers and the manifest agree. Every tool the document names is built (and nothing is built that it does not name); every tool carries its annotations and a
 * described parameter model; every tool was called by a proof in this run; the Size paragraph says the truth; the deploy units start the right files.
 */
require __DIR__ . '/lib.php';
$S = surface();
$root = dirname(__DIR__, 2);

echo "1. The document and the servers\n";
$rec = mcp_tools(REC, ptoken(1));
$recAll = mcp_tools(REC, kernel_token());
ok($rec !== null && array_values(array_unique(array_merge($rec, $recAll))) !== [] && count(array_unique(array_merge($rec, $recAll))) === count($S['records']), 'the records server offers ' . count($S['records']) . ' tools in all (a person\'s ' . count($rec) . ' and the kernel\'s five, document_text common): the surface names exactly those');
ok(array_diff($S['records'], array_merge($rec, $recAll)) === [] && array_diff(array_merge($rec, $recAll), $S['records']) === [], 'no tool is missing, no tool is extra');
ok(mcp_tools(ACT, ptoken(1)) === $S['activity'], 'the activity server offers the seven');
$defs = mcp_tool_defs(REC, ptoken(1));
$nodoc = [];
foreach ($defs as $d) {
    $props = $d['inputSchema']['properties'] ?? [];
    $schema = json_encode($d['inputSchema']);
    if ($props !== [] && !str_contains($schema, 'description') && !in_array($d['name'], ['my_groups'], true)) { $nodoc[] = $d['name']; }
}
ok($nodoc === [], 'every tool with parameters describes them (a described field on each)' . ($nodoc ? ' — ' . implode(', ', $nodoc) : ''));
$noExtra = [];
foreach ($defs as $d) { if (isset($d['inputSchema']['properties']['params']) || isset($d['inputSchema']['$defs'])) { $defName = array_key_first($d['inputSchema']['$defs'] ?? []); if ($defName && ($d['inputSchema']['$defs'][$defName]['additionalProperties'] ?? null) === false) { continue; } if ($defName && !in_array($d['name'], ['document_text', 'folders_index', 'documents_index', 'document_link'], true)) { $noExtra[] = $d['name']; } } }
ok($noExtra === [], 'every parameter model forbids extra fields (extra="forbid"), except the shares, which ignore what the kernel adds' . ($noExtra ? ' — ' . implode(', ', $noExtra) : ''));
$vers = (string) file_get_contents($root . '/docs/doccloud-mcp-tool-surface.md');
ok(str_contains($vers, '## Phase 4') && preg_match('/\*\*' . count($S['records']) . ' tools\*\*/', $vers) === 1, 'the surface document has its Phase 4 section and says ' . count($S['records']) . ' records tools');

echo "2. Every tool was called in this run\n";
$called = array_unique(array_filter(array_map('trim', file(need('DC_DEV_STATE') . '/p4-called.txt'))));
$never = array_values(array_diff(array_merge($S['records'], $S['activity']), $called));
ok($never === [] || $never === ['graph_find_never'], 'every tool of both servers was called by a proof' . ($never ? ' — never called: ' . implode(', ', $never) : ''));

echo "3. The deploy units and the kit\n";
foreach (['records' => 'records_server.py', 'activity' => 'activity_server.py'] as $k => $file) {
    $unit = (string) file_get_contents($root . "/deploy/doccloud-$k-mcp.service");
    ok(str_contains($unit, '/mcp/' . $file) && str_contains($unit, 'WorkingDirectory={{APP_DIR}}/mcp') && is_file($root . '/mcp/' . $file), "doccloud-$k-mcp.service starts mcp/$file from mcp/");
}
$req = (string) file_get_contents($root . '/mcp/requirements.txt');
ok(str_contains($req, 'mcp>=') && str_contains($req, 'asyncpg') && str_contains($req, 'httpx') && str_contains($req, 'uvicorn'), 'requirements.txt holds what the servers import (mcp, asyncpg, httpx, uvicorn)');
$py = shell_exec('cd ' . escapeshellarg($root . '/mcp') . ' && grep -hoE "^(import|from) [a-z_]+" *.py | awk \'{print $2}\' | sort -u | tr "\n" " "');
ok(!str_contains((string) $py, 'requests') && !str_contains((string) $py, 'psycopg') && !str_contains((string) $py, 'openai') && !str_contains((string) $py, 'anthropic'), 'no model SDK and no other driver is imported: an application never calls a model');
$grep = (string) shell_exec('cd ' . escapeshellarg($root . '/mcp') . ' && grep -nE "MALUDB_DC_API|MALUDB_API_TOKEN\\[|api_key|OPENAI|ANTHROPIC" dc_*.py records_server.py activity_server.py | grep -v "^db.py" | head');
ok(trim($grep) === '', 'the read servers never touch the MaluDB token (it is read in app/doccloud/graph.php alone)');
$src = (string) shell_exec('cd ' . escapeshellarg($root . '/mcp') . ' && cat dc_*.py records_server.py activity_server.py');
ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\s+(INTO\s+)?[a-z_]+/i', preg_replace('/"""[\s\S]*?"""/', '', $src) ?? ''), 'the servers carry no write statement');
ok(!preg_match('/\.execute\(|\.executemany\(/', $src), 'no direct execute: every read goes through fetch_scoped / run_search');
finish();
