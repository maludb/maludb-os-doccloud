<?php
/** Slice 2 proof — JSON mode and agents (spec "Proof" 13, 15): every screen answers data from a presenter's whitelist; every action reports with record_id and location; an agent's run token runs the free actions and is refused the paused ones; the registry marks the slice built. */
require __DIR__ . '/lib.php';
$seed = seed_store();
$contracts = $seed['contracts'];
$txt = fx_write('json.txt', fx_para(31, 600));
$u = upload_file(27, $contracts, $txt); $id = (int) $u['finish']['json']['record_id'];
worker('extract_text');

echo "1. Every screen answers JSON\n";
$screens = [
    ['/documents/' . $id, 26, ['document', 'preview', 'versions', 'crumbs', 'may', 'extraction', 'starred', 'notes', 'timeline']],
    ['/documents/' . $id . '/edit', 27, ['document', 'values']],
    ['/documents/' . $id . '/move', 27, ['document', 'path', 'picker']],
    ['/documents/' . $id . '/copy', 26, ['document', 'path', 'picker']],
    ['/documents/' . $id . '/versions', 26, ['document', 'versions', 'may', 'window']],
    ['/f/' . $contracts . '/upload', 27, ['folder', 'settings', 'sessions', 'routes']],
    ['/exports', 26, ['exports']],
];
$bad = [];
foreach ($screens as [$url, $m, $keys]) { $r = get_json($m, $url); $data = $r['json']['data'] ?? null; if ($r['code'] !== 200 || !is_array($data) || array_diff($keys, array_keys($data)) !== []) { $bad[] = $url . ' (' . $r['code'] . ')'; } }
ok($bad === [], count($screens) . ' screens answer 200 with the presenter\'s keys' . ($bad ? ' — wrong: ' . implode('; ', $bad) : ''));
$j = get_json(26, '/documents/' . $id)['json']['data'];
ok(!isset($j['document']['storage_path']) && $j['document']['url'] === '/documents/' . $id && $j['document']['download_url'] === '/d/' . $id . '?download=1' && $j['preview']['mode'] === 'text' && is_string($j['preview']['text']), 'a document\'s JSON carries urls and the preview text, never a storage path');
ok(get_json(31, '/documents/' . $id)['code'] === 404, 'an outsider\'s JSON: 404');

echo "2. Every action reports\n";
$r = post_json(27, '/documents/save.php', ['document' => $id, 'name' => 'json-renamed.txt']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true && (int) $r['json']['record_id'] === $id && ($r['json']['location'] ?? '') === '/documents/' . $id . '?notice=renamed', 'document_rename: ok, record_id, location');
$r = post_json(27, '/documents/save.php', ['document' => $id, 'description' => 'json']);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Saved the description', 'document_describe');
$r = post_json(27, '/documents/move.php', ['document' => $id, 'target_folder' => $seed['board_root']]);
ok($r['code'] === 200 && (int) doc_row($id)['folder_id'] === $seed['board_root'], 'document_move');
$r = post_json(27, '/documents/copy.php', ['document' => $id, 'target_folder' => $contracts, 'name' => 'json-copy.txt']);
$cid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $cid > 0 && ($r['json']['location'] ?? '') === '/documents/' . $cid . '?notice=copied', 'document_copy: the copy\'s id and location');
$r = post_json(27, '/documents/lock.php', ['document' => $id]);
ok($r['code'] === 200 && isset($r['json']['lock_expires_at']), 'document_lock answers when it expires');
$r = post_json(27, '/documents/unlock.php', ['document' => $id]);
ok($r['code'] === 200, 'document_unlock');
$r = post_json(27, '/folders/export.php', ['folder' => $contracts]);
ok($r['code'] === 200 && (int) ($r['json']['export_id'] ?? 0) > 0 && ($r['json']['refresh'] ?? '') === 'exportsChanged', 'export_folder: export_id and refresh');
$r = post_json(27, '/documents/export.php', ['documents' => $id . ',' . $cid]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'The zip of 2 documents is being made', 'export_documents');
upload_file(27, $seed['board_root'], $txt, ['document_id' => $id, 'filename' => 'json-renamed.txt']);
$r = post_json(27, '/documents/versions/restore.php', ['document' => $id, 'version' => 1]);
ok($r['code'] === 200 && (int) $r['json']['document_id'] === $id && ($r['json']['location'] ?? '') === '/documents/' . $id . '/versions?notice=restored', 'version_restore: record_id the new version, document_id beside, location the versions screen');
$r = post_json(1, '/documents/versions/purge.php', ['document' => $id, 'version' => 2]);
ok($r['code'] === 200 && str_contains($r['json']['did'] ?? '', 'Purged version 2'), 'version_purge by the admin');
$r = post_json(27, '/documents/upload.php', ['folder' => $contracts, 'file_base64' => base64_encode('json bytes'), 'filename' => 'json-b64.txt']);
ok($r['code'] === 200 && ($r['json']['new_document'] ?? false) === true && (int) doc_row((int) $r['json']['record_id'])['byte_size'] === 10, 'document_upload under an action token with file_base64');
$r = req('POST', '/documents/save.php', ['headers' => ['Accept: application/json'], 'form' => ['document' => $id, 'name' => 'x']]);
ok($r['code'] === 401, 'a JSON write with no token: 401');
$r = req('POST', '/upload/start', ['headers' => ['Accept: application/json'], 'form' => ['folder_id' => $contracts, 'filename' => 'a', 'byte_size' => 1]]);
ok($r['code'] === 401, 'an anonymous upload start: 401');

echo "3. An agent under a run token\n";
$r = post_agent(40, 903, '/documents/upload.php', ['folder' => $contracts, 'file_base64' => base64_encode(fx_para(32, 400)), 'filename' => 'agent-notes.txt']);
$aid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $aid > 0 && (int) doc_row($aid)['owner_member_id'] === 40, 'Seamus uploads (document_upload): the document is his');
$r = post_agent(40, 903, '/documents/save.php', ['document' => $aid, 'name' => 'agent-renamed.txt']);
ok($r['code'] === 200 && last_event('document.rename')['source'] === 'agent', 'the agent renames his document; logged source agent');
$r = post_agent(40, 903, '/documents/move.php', ['document' => $aid, 'target_folder' => $seed['board_root']]);
ok($r['code'] === 200, 'the agent moves it within the group library');
$r = post_agent(40, 903, '/documents/copy.php', ['document' => $id, 'target_folder' => $contracts]);
ok($r['code'] === 200 && (int) doc_row((int) $r['json']['record_id'])['owner_member_id'] === 40, 'the agent copies a group document: the copy is his');
$r = post_agent(40, 903, '/documents/lock.php', ['document' => $aid]);
ok($r['code'] === 200, 'the agent locks his document');
$r = post_agent(40, 903, '/documents/versions/purge.php', ['document' => $id, 'version' => 1]);
ok($r['code'] === 403, 'the agent is refused version_purge (no retention right): 403');
$r = post_agent(40, 903, '/folders/export.php', ['folder' => $contracts]);
ok($r['code'] === 200, 'the agent asks for a zip (export_folder is free)');
$r = post_agent(41, 904, '/documents/upload.php', ['folder' => $contracts, 'file_base64' => base64_encode('x'), 'filename' => 'w.txt']);
ok($r['code'] === 401, 'an agent the directory has not admitted: 401');
$ev = q("SELECT count(*) AS n FROM activity_log WHERE agent_run_id = 903")[0]['n'];
ok((int) $ev >= 6, 'every agent action carries the run id (' . $ev . ')');

echo "4. The registry\n";
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$byName = []; foreach ($reg['actions'] as $a) { $byName[$a['action']] = $a; }
ok(($byName['version_purge']['approval'] ?? $byName['version_purge']['agent_approval'] ?? '') === 'deletion' && empty($byName['document_upload']['approval'] ?? $byName['document_upload']['agent_approval'] ?? ''), 'the registry pauses version_purge (deletion) and frees document_upload');
finish();
