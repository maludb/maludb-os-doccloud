<?php
/** Slice 4 proof — JSON mode (spec "Proof" 9, 10): every action under a person's action token with record_id and location; an agent's run token runs tag_apply and search; tag_delete by an agent is refused and the registry pauses it (deletion); every screen answers JSON. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$acme = $s['board_doc']; $minutes = $s['minutes'];
$ok = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['record_id'], $r['json']['location'], $r['json']['did']);

echo "1. Every action under a person's action token\n";
$r = post_json(27, '/tags/save.php', ['label' => 'Json Managed', 'kind' => 'managed']);
ok($ok($r) && ($r['json']['slug'] ?? '') === 'json-managed', 'tag_create: ok, record_id, location, the slug');
$tid = (int) $r['json']['record_id'];
$r = post_json(27, '/tags/save.php', ['tag' => $tid, 'label' => 'Json Managed 2', 'colour' => '#ef4444']);
ok($ok($r), 'tag_update');
$r = post_json(27, '/tags/apply.php', ['document' => $acme, 'tags' => 'json-managed,Json Free']);
ok($ok($r) && ($r['json']['applied'] ?? 0) === 2 && in_array('json-free', tags_of($acme), true), 'tag_apply with a comma list (an agent\'s shape): two applied, the free one made');
$r = post_json(27, '/tags/remove.php', ['document' => $acme, 'tags' => 'json-free']);
ok($ok($r) && ($r['json']['removed'] ?? []) === ['json-free'], 'tag_remove');
$r = post_json(27, '/tags/propose.php', ['document' => $minutes, 'tag' => 'json-managed', 'note' => 'proposed in JSON']);
ok($ok($r), 'tag_propose');
$pid = (int) $r['json']['record_id'];
$r = post_json(27, '/tags/proposals/decide.php', ['tag_proposal' => $pid, 'decision' => 'accept']);
ok($ok($r) && ($r['json']['outcome'] ?? '') === 'applied' && in_array('json-managed', tags_of($minutes), true), 'tag_proposal_decide: accept applied it');
$r = post_json(27, '/tags/save.php', ['label' => 'To Merge']);
$merge = (int) $r['json']['record_id'];
$r = post_json(27, '/tags/merge.php', ['tag' => $merge, 'into' => $tid]);
ok($ok($r) && ($r['json']['alias'] ?? '') === 'to-merge', 'tag_merge');
$r = post_json(27, '/tags/archive.php', ['tag' => $tid]);
ok($ok($r), 'tag_archive');
$r = post_json(27, '/tags/restore.php', ['tag' => $tid]);
ok($ok($r), 'tag_restore');
$r = post_json(27, '/tags/save.php', ['label' => 'Delete Me']);
$del = (int) $r['json']['record_id'];
$r = post_json(27, '/tags/delete.php', ['tag' => $del]);
ok($ok($r) && one('SELECT count(*) FROM tags WHERE id = :id', ['id' => $del]) === 0, 'tag_delete');
$r = post_json(27, '/search/save.php', ['name' => 'Json saved', 'query' => 'acme', 'filters' => '{"kind":"pdf"}', 'is_shared' => 'yes']);
ok($ok($r), 'saved_search_save');
$sid = (int) $r['json']['record_id'];
$r = post_json(27, '/search/delete.php', ['saved_search' => $sid]);
ok($ok($r), 'saved_search_delete');
$r = post_json(27, '/folders/auto-tags.php', ['folder' => $s['contracts'], 'auto_tags' => 'json-managed']);
ok($ok($r), 'folder_auto_tags_set (slice 1\'s row, proven again)');
$r = post_json(27, '/tags/apply.php', ['document' => 999999, 'tags' => 'x']);
ok($r['code'] === 404 && ($r['json']['error']['code'] ?? '') === 'not_found', 'a document that is not there: 404 not_found');
$r = post_json(27, '/tags/save.php', ['label' => '']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['label']), 'a refusal names the field');

echo "2. An agent's run token\n";
$r = post_agent(40, 4101, '/tags/apply.php', ['document' => $acme, 'tags' => 'agent-tag']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && in_array('agent-tag', tags_of($acme), true) && last_event('tag.apply')['source'] === 'agent' && (int) last_event('tag.apply')['actor_member_id'] === 40, 'Seamus (a Board member) tags under a run token; logged source agent');
$r = post_agent(40, 4102, '/tags/delete.php', ['tag' => $tid]);
ok($r['code'] === 403, 'an agent without tags.manage is refused tag_delete (403; the registry pauses it for one that holds it)');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$a = array_values(array_filter($reg['actions'], static fn (array $x): bool => $x['action'] === 'tag_delete'))[0] ?? [];
ok(($a['approval'] ?? $a['agent_approval'] ?? '') === 'deletion' && !empty($a['built']), 'the registry: tag_delete is built and paused as deletion');
$r = post_agent(41, 4103, '/tags/apply.php', ['document' => $acme, 'tags' => 'x']);
ok(in_array($r['code'], [401, 403], true), 'an agent the kernel has not admitted: refused');
$r = req('GET', '/search?q=acme', ['headers' => as_agent(run_token(40, 4104))]);
$j = json_decode($r['body'], true) ?: [];
ok($r['code'] === 200 && in_array('acme-msa.pdf', array_column($j['data']['search']['rows'] ?? [], 'name'), true), 'the search read under a run token answers the agent\'s own wall (the records tool\'s shape)');

echo "3. Every screen answers JSON\n";
foreach (['/tags', '/tags/' . $tid, '/tags/new', '/tags/' . $tid . '/edit', '/tags/proposals', '/tags/rules', '/tags/rules?folder=' . $s['contracts'], '/search', '/search?q=acme', '/search/saved'] as $path) {
    $r = get_json(27, $path);
    ok($r['code'] === 200 && isset($r['json']['data']) && !str_contains($r['body'], '<html'), "JSON: $path");
}
ok((int) one("SELECT count(*) FROM activity_log WHERE source = 'web' AND actor_member_id = 27 AND action = 'screen.view' AND screen = 'tag-list'") >= 1, 'a screen view is logged');
finish();
