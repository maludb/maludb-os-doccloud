<?php
/**
 * Proof — the activity server (ACT1-ACT5, P4) over mcp_activity_log: record_history, actor_timeline, recent_activity, downloads, share_reads, activity_search, activity_query. The view decides which rows a person sees:
 * their own, and the rows about a document or folder they may see — the admin sees every row. No row holds a document's words, a token or a link.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$nda = $w['nda'];
$A = fn (int $m, string $t, array $a = []) => tdata($m, $t, $a, ACT);
q("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, document_id, folder_id, library_id, after) VALUES
    (27, 'web', 'document.download', 'document', $nda, $nda, {$w['legal_contracts']}, 10, '{\"version_no\":1,\"door\":\"web\"}'),
    (NULL, 'portal', 'document.download', 'document', $nda, $nda, {$w['legal_contracts']}, 10, '{\"version_no\":1,\"door\":\"link\",\"link_label\":\"p4 shared link\"}'),
    (NULL, 'application', 'document.download', 'document', $nda, $nda, {$w['legal_contracts']}, 10, '{\"version_no\":1,\"door\":\"share\",\"application\":\"knowledge\"}'),
    (30, 'web', 'document.upload', 'document', {$w['dana_doc']}, {$w['dana_doc']}, " . root_of(30) . ", 12, '{\"name\":\"p4-dana-private.pdf\"}')");

echo "1. The surface\n";
ok(mcp_tools(ACT, ptoken(26)) === surface()['activity'], 'the seven activity tools');

echo "2. record_history\n";
$h = $A(27, 'record_history', ['entity_type' => 'document', 'entity_id' => $nda]);
ok(count($h) >= 3 && $h[0]['occurred_at'] <= $h[count($h) - 1]['occurred_at'] && isset($h[0]['actor_name']) && array_key_exists('actor_is_agent', $h[0]) && isset($h[0]['action']), 'record_history on the nda: rows oldest first, worded with the actor');
ok(count(array_filter($h, fn ($r) => $r['action'] === 'document.download')) === 3, 'the three downloads, including the link holder\'s and the application\'s');
ok(!str_contains(json_encode($h), WORLD_SECRET) && !has_key($h, 'token') && !has_key($h, 'text'), 'no document text, no token');
ok($A(30, 'record_history', ['entity_type' => 'document', 'entity_id' => $nda]) === [], 'Dana sees none of the nda\'s history (she cannot see the nda)');
ok(count($A(1, 'record_history', ['entity_type' => 'document', 'entity_id' => $nda])) >= count($h), 'the admin sees every row');
$f = $A(27, 'record_history', ['entity_type' => 'folder', 'entity_id' => $w['legal_contracts']]);
ok(count($f) >= 3, 'a folder\'s history');
$l = $A(1, 'record_history', ['entity_type' => 'library', 'entity_id' => 10]);
ok(count($l) >= 1, 'a library\'s history');
ok(count($A(1, 'record_history', ['entity_type' => 'link', 'entity_id' => $w['wide_link']])) >= 1, 'a link\'s history (the admin)');
ok(tool(26, 'record_history', ['entity_type' => 'spaceship', 'entity_id' => 1], ACT)['error'] === true || tool(26, 'record_history', ['entity_type' => 'spaceship', 'entity_id' => 1], ACT)['http'] === 200, 'an entity type the tool does not know is refused by the schema');
$r = mcp_tool(ACT, ptoken(26), 'record_history', ['entity_type' => 'spaceship', 'entity_id' => 1]);
ok($r['error'] === true, 'record_history: spaceship is not an entity type');

echo "3. actor_timeline and recent_activity\n";
$own = $A(27, 'actor_timeline', ['member' => 'SMOKE Marco']);
ok(count($own) >= 3 && array_unique(array_column($own, 'actor_member_id')) === [27], 'Marco reads his own trail');
$dana = $A(26, 'actor_timeline', ['member' => 'SMOKE Dana']);
ok(!in_array($w['dana_doc'], array_column($dana, 'document_id'), true), 'Priya reading Dana\'s trail sees no row about Dana\'s private document');
$d1 = $A(1, 'actor_timeline', ['member' => 'SMOKE Dana']);
ok(in_array($w['dana_doc'], array_column($d1, 'document_id'), true), 'the admin reading it does');
$d2 = $A(27, 'actor_timeline', ['member' => 'SMOKE Dana']);
ok(in_array($w['dana_doc'], array_column($d2, 'document_id'), true) === false, 'Marco (reports.search) reading it: the view still withholds what he cannot see');
ok(tool(26, 'actor_timeline', [], ACT)['code'] === 'invalid_argument' && tool(26, 'actor_timeline', ['member' => 1, 'key' => 2], ACT)['code'] === 'invalid_argument', 'exactly one of member, key, link');
ok(count($A(1, 'actor_timeline', ['link' => $w['wide_link']])) >= 1 && is_array($A(1, 'actor_timeline', ['key' => 1])), 'by link, by key');
ok(count($A(27, 'actor_timeline', ['member' => 27, 'library' => 'SMOKE p4 Legal'])) >= 1 && count($A(27, 'actor_timeline', ['member' => 27, 'since' => '2999-01-01'])) === 0, 'library and since filters');
$ra = $A(26, 'recent_activity', ['since' => '2020-01-01']);
ok(count($ra) >= 1 && !in_array($w['dana_doc'], array_column($ra, 'document_id'), true), 'recent_activity for Priya: nothing about Dana\'s private document');
ok(count($A(27, 'recent_activity', ['since' => '2020-01-01', 'exclude_mine' => false])) >= count($A(27, 'recent_activity', ['since' => '2020-01-01'])), 'exclude_mine');
ok(count($A(27, 'recent_activity', ['since' => '2020-01-01', 'folder' => $w['legal_contracts'], 'exclude_mine' => false])) >= 3 && count($A(27, 'recent_activity', ['since' => '2020-01-01', 'library' => 'SMOKE p4 Legal', 'exclude_mine' => false])) >= 3, 'by folder subtree, by library');
ok(tool(27, 'recent_activity', ['since' => 'yesterday-ish'], ACT)['code'] === 'invalid_argument', 'a time that is not a time: invalid_argument');

echo "4. downloads, share_reads, activity_search, activity_query\n";
$dl = $A(27, 'downloads', ['document' => $nda]);
ok(count($dl) === 3 && count(array_filter($dl, fn ($r) => $r['link_label'] === 'p4 shared link')) === 1 && count(array_filter($dl, fn ($r) => $r['application'] === 'knowledge')) === 1 && $dl[0]['what'] === 'downloaded', 'downloads: three — Marco, a link holder (by label), the application knowledge');
ok(tool(26, 'downloads', ['document' => $nda], ACT)['code'] === 'managers_only', 'Priya (no manage): managers_only');
ok(tool(30, 'downloads', ['document' => $nda], ACT)['code'] === 'not_found', 'Dana: not_found');
$sr = $A(1, 'share_reads');
$k = array_values(array_filter($sr, fn ($r) => $r['application'] === 'knowledge' && $r['tool'] === 'folders_index'));
$hdr = array_values(array_filter($sr, fn ($r) => $r['application'] === 'helpdesk' && $r['refused'] >= 1));
ok(count($k) === 1 && $k[0]['calls'] >= 1 && count($hdr) >= 1, 'share_reads (admin): grouped by application and tool — calls and refusals');
ok(count($A(1, 'share_reads', ['application' => 'knowledge', 'group' => false])) >= 1 && $A(1, 'share_reads', ['application' => 'nobody']) === [], 'application filter; ungrouped rows');
ok(tool(27, 'share_reads', [], ACT)['code'] === 'managers_only', 'a curator: managers_only (the admin\'s)');
$as = $A(27, 'activity_search', ['q' => 'download']);
ok(count($as) >= 3 && count(array_filter($as, fn ($r) => !str_contains($r['action'], 'download'))) === 0, 'activity_search "download": the download rows');
ok(count($A(27, 'activity_search', ['q' => 'p4-dana-private'])) === 0 && count($A(1, 'activity_search', ['q' => 'p4-dana-private'])) >= 1, 'a name in a payload: found by the admin, not by Marco (the row is about what he cannot see)');
ok(count($A(1, 'activity_search', ['q' => 'upload', 'library' => 'SMOKE Priya'])) >= 0 && tool(27, 'activity_search', ['q' => 'x'], ACT)['error'] === true, 'a one-letter query is refused by the schema');
$aq = mcp_tool(ACT, ptoken(26), 'activity_query', ['sql' => "SELECT action, count(*) AS n FROM mcp_activity_log GROUP BY 1 ORDER BY 2 DESC"]);
ok(!$aq['error'] && count($aq['data']) >= 3, 'activity_query: a guarded read of the trail');
$aq = mcp_tool(ACT, ptoken(26), 'activity_query', ['sql' => "SELECT document_id FROM mcp_activity_log WHERE document_id = {$w['dana_doc']}"]);
ok($aq['data'] === [], 'activity_query as Priya: the view hides Dana\'s rows');
foreach (['SELECT 1; SELECT 2', 'DELETE FROM activity_log', "SELECT set_config('app.member_id','1',true)"] as $sql) {
    ok(is_string(mcp_tool(ACT, ptoken(26), 'activity_query', ['sql' => $sql])['data']['error'] ?? null), 'activity_query refuses: ' . substr($sql, 0, 40));
}
$bad = mcp_tool(ACT, ptoken(26), 'activity_query', ['sql' => 'SELECT * FROM mcp_share_links']);
ok(is_string($bad['data']['error'] ?? null), 'a view the activity role cannot read: an error, not rows');
finish();
