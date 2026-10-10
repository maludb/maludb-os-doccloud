<?php
/** Grid & media proof — the two actions (spec "Actions"): document_preview_hide / document_preview_show by right and level, 422 for a PDF, the log rows with the name and the kind, updated_at untouched, idempotent, JSON and an agent, the HTMX tile swap, a lock not in the way, the registry and the expert's grants. */
require __DIR__ . '/lib.php';
$s = media_seed();
$ids = $s['ids'];
$png = $ids['png']; $mp4 = $ids['mp4']; $pdf = $ids['pdf']; $media = $s['media'];
worker('extract_text,thumbnails');
$share = post_j(27, '/access/share.php', ['folder' => $media, 'member' => 28, 'level' => 'view']);
ok(in_array($share['code'], [200, 201], true), 'Marco shares Media with member 28 at view (' . $share['code'] . ')');
$lv = ['26' => level_of(26, 'document', $png), '40' => level_of(40, 'document', $png), '28' => level_of(28, 'document', $png), '31' => level_of(31, 'document', $png)];
ok($lv['26'] === 'edit' && $lv['40'] === 'edit' && $lv['28'] === 'view' && $lv['31'] === 'none', 'levels on beach.png: Priya edit, the agent edit, 28 view, 31 none (' . json_encode($lv) . ')');

echo "1. Hide from the document page (a browser form)\n";
$before = doc_row($png)['updated_at'];
$r = post(27, '/documents/preview-hide.php', ['document' => $png, 'return_to' => '/documents/' . $png]);
ok($r['code'] === 302 && str_contains($r['location'], '/documents/' . $png . '?notice=preview_hidden'), 'Marco hides it: back to the document with the notice (' . $r['code'] . ' ' . $r['location'] . ')');
ok(is_hidden($png), 'a row in document_preview_hidden');
ok(doc_row($png)['updated_at'] === $before, 'documents.updated_at untouched — "Modified" does not move');
$ev = last_event('document.preview_hide');
$after = json_decode((string) $ev['after'], true);
ok((int) $ev['document_id'] === $png && (int) $ev['folder_id'] === $media && (int) $ev['actor_member_id'] === 27 && $ev['source'] === 'web', 'document.preview_hide logged as Marco from the web with the document and the folder');
ok($after['name'] === 'beach.png' && $after['mime_kind'] === 'image' && $after['preview_hidden'] === true && count($after) === 3, 'the payload: the name, the kind and the state — nothing else');
$n = count(activity('document.preview_hide'));
$r = post(27, '/documents/preview-hide.php', ['document' => $png, 'return_to' => '/documents/' . $png]);
ok($r['code'] === 302 && count(activity('document.preview_hide')) === $n, 'hiding again changes nothing and logs nothing');

echo "2. Refusals\n";
$r = post_json(27, '/documents/preview-hide.php', ['document' => $pdf]);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'image') && !is_hidden($pdf), 'a PDF: 422 on document, in words');
ok(post(28, '/documents/preview-show.php', ['document' => $png])['code'] === 403 && is_hidden($png), 'a viewer (28 at view): 403, still hidden');
ok(post(31, '/documents/preview-show.php', ['document' => $png])['code'] === 404, 'someone who cannot see it (31): 404, the same nothing');
ok(in_array(post(29, '/documents/preview-show.php', ['document' => $png])['code'], [403, 404], true), 'a guest: refused');
$r = req('POST', '/documents/preview-show.php', ['jar' => actor(27), 'form' => ['document' => $png]]);
ok($r['code'] >= 400 && is_hidden($png), 'no CSRF token: refused (' . $r['code'] . ')');
ok(req('GET', '/documents/preview-show.php?document=' . $png, ['jar' => actor(27)])['code'] === 405, 'a GET: 405');

echo "3. JSON and an agent\n";
$r = post_json(26, '/documents/preview-show.php', ['document' => $png]);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) === $png && str_contains((string) ($r['json']['did'] ?? ''), 'Showed the preview of beach.png') && !is_hidden($png), 'Priya (edit) shows it under an action token: ok, record_id, "did" (' . $r['code'] . ')');
ok(last_event('document.preview_show')['document_id'] == $png, 'document.preview_show logged');
$r = post_agent(40, 903, '/documents/preview-hide.php', ['document' => $mp4]);
ok($r['code'] === 200 && ($r['json']['status'] ?? '') !== 'pending_approval' && is_hidden($mp4), 'the agent hides the MP4 at once — no approval (' . $r['code'] . ')');
ok(last_event('document.preview_hide')['source'] === 'agent', '...logged with source agent');

echo "4. The tile swap (HTMX)\n";
$r = post(27, '/documents/preview-show.php', ['document' => $mp4, 'swap' => 'tile', 'select' => '1', 'return_to' => '/f/' . $media], ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_starts_with(ltrim($r['body']), '<div class="card h-100 mb-0 listing-tile" id="tile-document-' . $mp4 . '"') && !str_contains($r['body'], '<html'), 'swap=tile answers the tile alone, shown (' . $r['code'] . ')');
ok(str_contains($r['body'], '/d/' . $mp4 . '/thumb?v=') && str_contains($r['body'], 'tile-play') && str_contains($r['body'], 'tile-duration') && str_contains($r['body'], '0:03'), '...with the thumbnail, the play mark and the length 0:03');
ok(str_contains($r['body'], 'action="/documents/preview-hide.php"') && !preg_match('/id="tile-document-' . $mp4 . '-switch" checked/', $r['body']) && str_contains($r['body'], 'listing-check'), '...the switch now hides, unchecked; the select box kept');
ok(preg_match('/^HX-Trigger: documentChanged/mi', $r['headers']) === 1, 'HX-Trigger: documentChanged');
$r = post(27, '/documents/preview-hide.php', ['document' => $mp4, 'swap' => 'tile', 'select' => '0', 'return_to' => '/f/' . $media], ['headers' => ['HX-Request: true']]);
ok(str_contains($r['body'], 'listing-tile is-hidden') && str_contains($r['body'], 'tile-placeholder') && !str_contains($r['body'], '/d/' . $mp4 . '/thumb') && !str_contains($r['body'], 'tile-duration'), 'hidden: the placeholder, no thumbnail URL at all, no length');
ok(str_contains($r['body'], 'action="/documents/preview-show.php"') && preg_match('/id="tile-document-' . $mp4 . '-switch" checked/', $r['body']) === 1 && !str_contains($r['body'], 'listing-check'), '...the switch checked and now shows; no select box when select=0');

echo "5. A lock is not in the way\n";
$r = post(26, '/documents/lock.php', ['document' => $png]);
ok(in_array($r['code'], [200, 302], true) && (int) doc_row($png)['locked_by'] === 26, 'Priya locks beach.png');
$r = post(27, '/documents/preview-hide.php', ['document' => $png]);
ok($r['code'] === 302 && is_hidden($png), 'Marco still hides its preview (the setting is not the content)');
post(26, '/documents/unlock.php', ['document' => $png]);

echo "6. The schema, the registry and the expert\n";
ok(one("SELECT confdeltype FROM pg_constraint WHERE conrelid = 'document_preview_hidden'::regclass AND contype = 'f' AND confrelid = 'documents'::regclass") === 'c', 'a hidden row goes with its document (ON DELETE CASCADE)');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$a = array_column($reg['actions'], null, 'action');
ok(($a['document_preview_hide']['built'] ?? false) && ($a['document_preview_show']['built'] ?? false) && $a['document_preview_hide']['approval'] === null && $a['document_preview_hide']['log_event'] === 'document.preview_hide' && $a['document_preview_show']['undo'] === 'document_preview_hide', 'the registry: both built, free for agents, the log events, each the other\'s undo');
ok(count($reg['actions']) === 97 && count(array_filter($reg['actions'], fn ($x) => $x['built'])) === 97, 'the registry holds 97 actions, every one built');
$m = json_decode(file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$expert = array_values(array_filter($m['agents'], fn ($x) => $x['key'] === 'expert'))[0];
$granted = $expert['tool_grants']['Actions MCP'] ?? [];
ok(in_array('document_preview_hide', $granted, true) && in_array('document_preview_show', $granted, true), 'the expert is granted both');
finish();
