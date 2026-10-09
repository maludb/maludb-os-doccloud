<?php
/** Slice 4 proof — saved searches (spec "Proof" 8) and the search report's data (9): saved from the page, listed, run, a shared one visible to another member, deleted; search_gaps clusters. */
require __DIR__ . '/lib.php';
$s = seed_texts();
echo "1. Save, list, run\n";
$r = post_j(27, '/search/save.php', ['name' => 'Acme contracts', 'query' => 'acme', 'filters' => json_encode(['tag' => 'contract', 'kind' => 'pdf']), 'is_shared' => 'no']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0 && str_ends_with($r['json']['location'] ?? '', '/search/saved?notice=saved'), 'saved_search_save: the id, lands on the saved searches');
$id = (int) $r['json']['record_id'];
ok(last_event('search.save') !== null && json_decode(last_event('search.save')['after'], true)['name'] === 'Acme contracts', 'logged search.save');
$r = post_j(27, '/search/save.php', ['name' => '', 'query' => 'x']);
ok($r['code'] === 422 && ($r['json']['error']['fields']['name'] ?? '') !== '', 'a name is needed');
$r = post_j(27, '/search/save.php', ['name' => 'Bad filters', 'query' => 'x', 'filters' => '[not json']);
ok($r['code'] === 422, 'the filters are a JSON object');
$r = post_j(27, '/search/save.php', ['name' => 'Acme contracts', 'query' => 'acme corp', 'filters' => json_encode(['tag' => 'contract'])]);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $id && one('SELECT query FROM saved_searches WHERE id = :id', ['id' => $id]) === 'acme corp', 'the same name saves over itself');
$r = page(actor(27), '/search/saved');
ok($r['code'] === 200 && str_contains($r['body'], 'id="saved-search-' . $id . '"') && str_contains($r['body'], 'id="saved-search-run-' . $id . '"') && str_contains($r['body'], 'id="saved-search-delete-' . $id . '"'), 'the saved searches screen: run and delete');
$j = get_json(27, '/search/saved')['json']['data']['saved'] ?? [];
$mine = array_values(array_filter($j, static fn (array $x): bool => $x['saved_search_id'] === $id));
ok(count($mine) === 1 && $mine[0]['url'] === '/search?q=acme+corp&tag=contract' && $mine[0]['mine'] === true, 'the JSON: the run URL rebuilt from the query and filters');
$run = get_json(27, $mine[0]['url'])['json']['data']['search'] ?? [];
ok(($run['filters']->tag ?? ($run['filters']['tag'] ?? '')) === 'contract', 'running it applies the filters');

echo "2. Shared, another member, delete\n";
$r = post_j(27, '/search/save.php', ['name' => 'Everyone: policies', 'query' => 'policy', 'is_shared' => 'yes']);
$shared = (int) $r['json']['record_id'];
$r = page(actor(26), '/search/saved');
ok($r['code'] === 200 && str_contains($r['body'], 'id="saved-search-' . $shared . '"') && !str_contains($r['body'], 'id="saved-search-' . $id . '"') && !str_contains($r['body'], 'id="saved-search-delete-' . $shared . '"'), 'another member sees the shared one (not the private one) and cannot delete it');
$r = post_j(26, '/search/delete.php', ['saved_search' => $shared]);
ok($r['code'] === 404, 'deleting another\'s saved search: 404');
$r = page(actor(29), '/search/saved');
ok($r['code'] === 403, 'a guest holds no search.use');
$r = post_j(27, '/search/delete.php', ['saved_search' => $shared]);
ok($r['code'] === 200 && one('SELECT count(*) FROM saved_searches WHERE id = :id', ['id' => $shared]) === 0 && last_event('search.delete') !== null, 'the owner deletes it; logged');
$r = page(actor(27), '/search');
ok(str_contains($r['body'], 'id="search-saved-' . $id . '"'), 'the empty search page offers my saved searches');

echo "3. The search report's data\n";
foreach (['xyzzy plugh', 'Xyzzy Plugh', 'absent words'] as $qq) { search_as(27, $qq); }
search_as(26, 'xyzzy plugh');
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/search/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/search/parse.php';
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '27', false)");
$gaps = search_gaps($pdo, '1 day');
ok(($gaps[0]['query'] ?? '') === 'xyzzy plugh' && (int) ($gaps[0]['times'] ?? 0) === 3, 'search_gaps clusters what found nothing, case-folded: "xyzzy plugh" three times');
$pdo->exec("SELECT set_config('app.member_id', '26', false)");
ok(search_gaps($pdo, '1 day') === [], 'a member without reports.search gets nothing of the report');
finish();
