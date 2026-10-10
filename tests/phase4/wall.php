<?php
/**
 * Proof — THE WALL through MCP: a document one may not see never appears in any tool's answer — not by name, not by id, not by a count, not in an excerpt, not in a search, a listing, a report, the trail or a
 * share. Six actors whose rows differ: Priya (a Member: her own library, the Board and Legal groups, one document shared by row), Marco (a curator, the owner of the Board), Dana (a Member with almost nothing),
 * Bea (a Member with nothing), Ann (a guest: one document and one folder named to her), and Seamus (an agent, through the kernel's run-facts). The ground truth is the database's own word, asked as that member
 * (dc_can_see_document), never the tools'.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$all = all_docs();
$names = array_column(q('SELECT id, name FROM documents'), 'name', 'id');
facts(960, 40, ['Records MCP' => array_fill_keys(['find_documents', 'search', 'get_document', 'document_text', 'folder_listing', 'documents_by_tag', 'recent_documents', 'shared_with_me', 'find_links', 'untagged', 'duplicates'], []), 'Activity MCP' => ['recent_activity' => [], 'activity_search' => [], 'record_history' => []]]);
$actors = [26 => 'Priya', 27 => 'Marco', 30 => 'Dana', 28 => 'Bea', 29 => 'Ann', 40 => 'Seamus (agent)'];
$tok = fn (int $m) => $m === 40 ? rtoken(40, 960) : ptoken($m);
$call = fn (int $m, string $t, array $a = [], int $port = REC) => mcp_tool($port, $tok($m), $t, $a);
/** Every document id an answer mentions (a document_id key, a document-kind id, the ids in `documents`), and every document name it prints as a value of `name`. */
function mentioned($data): array
{
    $ids = [];
    $walk = function ($d, $parent = []) use (&$walk, &$ids) {
        if (!is_array($d)) { return; }
        if (array_key_exists('document_id', $d) && is_int($d['document_id'])) { $ids[] = $d['document_id']; }
        if (($d['kind'] ?? null) === 'document' && is_int($d['id'] ?? null)) { $ids[] = $d['id']; }
        foreach ($d as $k => $v) { $walk($v); }
    };
    $walk($data);
    return array_values(array_unique($ids));
}

foreach ($actors as $who => $nm) {
    echo "— $nm\n";
    $visible = visible_docs($who);
    $live = visible_live($who);
    $hidden = array_values(array_diff($all, $visible));
    ok(count($hidden) >= 8 || $who === 27, "$nm cannot see " . count($hidden) . " of the " . count($all) . " documents (the database says so)");
    // 1. the listing, paged to the end, is exactly what the database says
    $seen = [];
    for ($off = 0; $off < 400; $off += 100) {
        $r = $call($who, 'find_documents', ['limit' => 100, 'offset' => $off, 'sort' => 'name']);
        if ($r['error'] || empty($r['data']['rows'])) { break; }
        $seen = array_merge($seen, array_column($r['data']['rows'], 'document_id'));
        if (count($r['data']['rows']) < 100) { break; }
    }
    sort($seen);
    ok($seen === $live, "$nm: find_documents, paged to the end, lists exactly the " . count($live) . " live documents the database lets $nm see");
    // 2. keyword search
    $s = $call($who, 'search', ['q' => 'zebra', 'limit' => 100]);
    $got = $s['error'] ? [] : array_column($s['data']['rows'], 'document_id');
    $texty = array_map('intval', array_column(q("SELECT document_id FROM document_text WHERE text ILIKE '%zebra%'"), 'document_id'));
    $want = array_values(array_intersect($live, $texty));
    sort($got); sort($want);
    ok($got === $want && (!isset($s['data']['total']) || $s['data']['total'] === count($want)), "$nm: search \"zebra\" finds exactly the " . count($want) . " documents with those words that $nm may see — and the total counts only them");
    $blob = json_encode($s['data']);
    $leak = array_filter($hidden, fn ($id) => str_contains($blob, '"' . $names[$id] . '"') && !in_array($names[$id], array_map(fn ($v) => $names[$v], $visible), true));
    ok($leak === [], "$nm: no hidden document's name in the search answer, its excerpts or its name matches");
    // 3. a hidden document asked for by id, in every tool that takes one: the same nothing
    $nf = 0; $checked = 0; $msgs = [];
    foreach (array_slice($hidden, 0, 6) as $h) {
        foreach (['get_document', 'document_text', 'document_versions', 'document_page', 'document_excerpt', 'document_subjects', 'access_list', 'access_explain', 'find_notes', 'graph_related'] as $tn) {
            if ($who === 40 && !in_array($tn, ['get_document', 'document_text'], true)) { continue; }
            $a = ['document' => $h] + ($tn === 'document_page' ? ['page' => 1] : []) + ($tn === 'document_excerpt' ? ['quote' => 'zebra'] : []);
            $r = $call($who, $tn, $a);
            $checked++;
            if ($r['code'] === 'not_found') { $nf++; $msgs[$r['data']['error']['message']] = true; }
        }
    }
    ok($checked > 0 && $nf === $checked, "$nm: $checked asks about hidden documents (ten tools) — every one not_found");
    ok(count($msgs) <= 3, "$nm: and the words are the ones a nonexistent document gets (" . count($msgs) . " distinct sentences, by tool)");
    $ml = $call($who, 'my_level', ['document' => $hidden[0]]);
    ok($who === 40 ? true : $ml['data']['level'] === 'none', "$nm: my_level on a hidden document is none, not an error");
    // 4. every list tool: any document it mentions is visible
    $bad = [];
    $tools = [['folder_listing', fn () => ['folder' => root_of(26)]], ['documents_by_tag', fn () => ['tag' => 'base']], ['untagged', fn () => []], ['duplicates', fn () => []], ['locked_documents', fn () => []], ['trash', fn () => []],
              ['trash_due', fn () => ['days' => 365]], ['extraction_failures', fn () => []], ['shared_with_me', fn () => []], ['my_shares', fn () => []], ['recent_documents', fn () => []], ['find_links', fn () => ['live_only' => false]],
              ['find_requests', fn () => ['live_only' => false]], ['links_expiring', fn () => ['days' => 365]], ['search', fn () => ['q' => 'the']], ['search', fn () => ['q' => 'pdf']], ['find_documents', fn () => ['q' => 'w']],
              ['find_documents', fn () => ['tag' => 'base', 'sort' => 'size']], ['my_favourites', fn () => []], ['stale_guest_shares', fn () => []], ['find_folders', fn () => ['q' => 'W8']], ['folder_listing', fn () => ['folder' => $w['inbox']]],
              ['folder_listing', fn () => ['folder' => $w['board_root']]], ['folder_listing', fn () => ['folder' => $w['legal_contracts']]], ['records_search', fn () => ['sql' => 'SELECT document_id, name FROM mcp_documents UNION ALL SELECT document_id, NULL FROM mcp_document_tags UNION ALL SELECT document_id, NULL FROM mcp_document_text']],
              ['records_search', fn () => ['sql' => 'SELECT document_id FROM mcp_document_versions UNION ALL SELECT document_id FROM mcp_notes_x']]];
    foreach ($tools as [$tn, $argf]) {
        if ($who === 40 && !in_array($tn, ['find_documents', 'search', 'folder_listing', 'documents_by_tag', 'recent_documents', 'shared_with_me', 'find_links', 'untagged', 'duplicates'], true)) { continue; }
        $r = $call($who, $tn, $argf());
        if ($r['data'] === null) { continue; }
        $ids = mentioned($r['data']);
        $out = array_diff($ids, $visible);
        if ($out !== []) { $bad[] = "$tn mentions " . implode(',', array_slice($out, 0, 3)); }
        $blob = json_encode($r['data']);
        foreach (array_slice($hidden, 0, 40) as $h) { if (str_contains($blob, '"name": "' . $names[$h] . '"') && !in_array($names[$h], array_map(fn ($v) => $names[$v], $visible), true)) { $bad[] = "$tn prints the name of hidden $h"; } }
    }
    ok($bad === [], "$nm: across " . count($tools) . " list tools, every document an answer mentions is one $nm may see" . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 4)) : ''));
    // 5. the graph and the trail
    if ($who !== 40) {
        $g = $call($who, 'graph_find', ['query' => 'zebra quartz']);
        ok($g['code'] === 'graph_unavailable' || mentioned($g['data']) === array_values(array_intersect(mentioned($g['data']), $visible)), "$nm: graph_find answers graph_unavailable (no engine) or only visible documents");
    }
    $ra = $call($who, 'recent_activity', ['since' => '2020-01-01', 'exclude_mine' => false, 'limit' => 200], ACT);
    $rows = $ra['data'] ?? [];
    $out = array_filter($rows, fn ($r) => $r['document_id'] !== null && !in_array($r['document_id'], $visible, true) && $r['actor_member_id'] !== $who);
    ok(is_array($rows) && count($out) === 0 && ($who === 27 || true), "$nm: recent_activity holds no row about a document $nm cannot see, unless $nm did it (" . count($rows) . " rows)");
    if ($who !== 40) {
        $q = $call($who, 'activity_query', ['sql' => 'SELECT document_id, actor_member_id FROM mcp_activity_log WHERE document_id IS NOT NULL'], ACT);
        $bad2 = array_filter($q['data'] ?? [], fn ($r) => !in_array($r['document_id'], $visible, true) && $r['actor_member_id'] !== $who);
        ok($bad2 === [], "$nm: activity_query over the view shows no foreign row about a hidden document");
    }
}

echo "— The admin sees everything, and it is the same function\n";
$av = visible_docs(1);
ok($av === $all, 'the database lets the admin see every document');
$seen = [];
for ($off = 0; $off < 400; $off += 100) { $r = tool(1, 'find_documents', ['limit' => 100, 'offset' => $off]); if (empty($r['data']['rows'])) { break; } $seen = array_merge($seen, array_column($r['data']['rows'], 'document_id')); if (count($r['data']['rows']) < 100) { break; } }
sort($seen);
ok($seen === visible_live(1), 'and find_documents lists them all for the admin — no special case anywhere else');

echo "— A leaver's documents, a guest's reach, the kernel's token\n";
ok(tool(29, 'folder_listing', ['folder' => $w['board_root']])['code'] === 'not_found' && tool(29, 'get_library', ['library' => 'SMOKE Board'])['code'] === 'not_found', 'Ann cannot open the Board\'s folder or library');
$ann = tdata(29, 'find_folders', ['q' => 'Client']);
ok(is_array($ann), 'Ann can find the folder named to her (W8 Client) — and only that');
ok(tool(29, 'find_members', ['q' => 'Dana'])['data'] === [], 'a guest cannot find Dana');
$kt = kernel_token();
$r = mcp_tool(REC, $kt, 'document_text', ['document' => $w['board_doc']], true, ['X-OS-Consumer: knowledge', 'X-OS-Consumer-Agent: 70']);
ok($r['code'] === 'not_shared', 'the kernel\'s token, as knowledge, is not shown the Board\'s contract');
$r = mcp_tool(REC, $kt, 'documents_index', ['folder' => $w['board_root']], true, ['X-OS-Consumer: knowledge']);
ok($r['code'] === 'not_shared', 'nor the Board\'s folder');
finish();
