<?php
/** Slice 2 proof — versions (spec "Proof" 9): restore makes version N+1 from the old blob; purge by the admin releases the bytes, is refused for the current, pauses for an agent (the registry's category); the versions screen; a new version through the upload screen's ?document=. */
require __DIR__ . '/lib.php';
$seed = seed_store();
$contracts = $seed['contracts'];
$v1 = fx_write('plan-v1.txt', fx_para(11, 1200));
$v2 = fx_write('plan-v2.txt', fx_para(12, 1500));
$u = upload_file(27, $contracts, $v1, ['filename' => 'plan.txt']); $id = (int) $u['finish']['json']['record_id'];
$u = upload_file(27, $contracts, $v2, ['filename' => 'plan.txt', 'document_id' => $id]);
ok($u['finish']['code'] === 200 && (int) $u['finish']['json']['version_no'] === 2, 'two versions');
$sha1 = hash_file('sha256', $v1);

echo "1. Restore\n";
$r = get_json(26, '/documents/' . $id . '/versions');
ok($r['code'] === 200 && count($r['json']['data']['versions'] ?? []) === 2 && ($r['json']['data']['versions'][0]['is_current'] ?? false) === true && str_contains($r['json']['data']['window'] ?? '', '30 days'), 'the versions screen lists both, the current first, with the window sentence');
$r = post_j(26, '/documents/versions/restore.php', ['document' => $id, 'version' => 1]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM document_versions WHERE document_id = :d', ['d' => $id]) === 3 && doc_row($id)['sha256'] === $sha1, 'a group member restores version 1: version 3 is current with version 1\'s bytes');
$ev = last_event('version.restore');
ok(json_decode($ev['after'], true)['from_version_no'] === 1 && json_decode($ev['after'], true)['new_version_no'] === 3, 'logged version.restore {from 1, new 3}');
ok((int) one('SELECT ref_count FROM blobs WHERE sha256 = :s', ['s' => $sha1]) === 2, 'the old blob is held twice (v1 and v3)');
$r = post_j(31, '/documents/versions/restore.php', ['document' => $id, 'version' => 2]);
ok($r['code'] === 404, 'an outsider: 404');
$r = post_j(26, '/documents/versions/restore.php', ['document' => $id, 'version' => 9]);
ok($r['code'] === 404, 'no such version: 404');

echo "2. Purge\n";
$r = post_j(26, '/documents/versions/purge.php', ['document' => $id, 'version' => 2]);
ok($r['code'] === 403, 'a member cannot purge (403: the admin\'s right)');
$r = post_j(1, '/documents/versions/purge.php', ['document' => $id, 'version' => 3]);
ok($r['code'] === 422 && str_contains($r['json']['error']['message'] ?? '', 'current'), 'the current version is never purged (422)');
$used0 = (int) one('SELECT used_bytes FROM libraries WHERE id = (SELECT library_id FROM documents WHERE id = :d)', ['d' => $id]);
$r = post_j(1, '/documents/versions/purge.php', ['document' => $id, 'version' => 2]);
ok($r['code'] === 200 && one('SELECT purged_at IS NOT NULL FROM document_versions WHERE document_id = :d AND version_no = 2', ['d' => $id]) && (int) one('SELECT used_bytes FROM libraries WHERE id = (SELECT library_id FROM documents WHERE id = :d)', ['d' => $id]) === $used0 - filesize($v2), 'the admin purges version 2: the bytes released from the library');
ok((int) one('SELECT ref_count FROM blobs WHERE sha256 = :s', ['s' => hash_file('sha256', $v2)]) === 0, '...the blob is held by nobody (freed by slice 6\'s pass)');
ok(page(actor(26), '/d/' . $id . '/v/2')['code'] === 404, 'a purged version serves nothing (404)');
$r = post_j(1, '/documents/versions/purge.php', ['document' => $id, 'version' => 2]);
ok($r['code'] === 422, 'purging it again: 422');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$purge = array_values(array_filter($reg['actions'], static fn (array $a): bool => $a['action'] === 'version_purge'))[0] ?? [];
ok(($purge['approval'] ?? $purge['agent_approval'] ?? '') === 'deletion', 'version_purge is paused for an agent by the registry (deletion) — the kernel\'s hook holds it');
$r = post_agent(40, 902, '/documents/versions/purge.php', ['document' => $id, 'version' => 1]);
ok($r['code'] === 403, 'an agent without the right is refused version_purge (403)');

echo "3. A new version through the upload screen\n";
$r = page(actor(26), '/f/' . $contracts . '/upload?document=' . $id);
ok($r['code'] === 200 && str_contains($r['body'], 'A new version of plan.txt') && str_contains($r['body'], 'data-document="' . $id . '"'), 'the upload screen for a version names the document');
$r = page(actor(26), '/f/' . $contracts . '/upload');
ok($r['code'] === 200 && str_contains($r['body'], 'id="drop-zone"') && str_contains($r['body'], 'id="upload-files-input"') && str_contains($r['body'], 'dc-upload.js'), 'the upload screen: the drop zone, the inputs, the uploader');
ok(page(actor(31), '/f/' . $contracts . '/upload')['code'] === 404, 'an outsider: 404');
$page = page(actor(26), '/documents/' . $id)['body'];
ok(str_contains($page, 'v3 of 3') && str_contains($page, 'id="version-row-2"') && str_contains($page, 'purged'), 'the document page says v3 of 3 and shows the purged row');
finish();
