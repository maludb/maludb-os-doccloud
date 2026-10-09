<?php
/** Slice 7 proof 5 — the four K7 shares through the loopback door, as the records server calls it (the bridge envelope, signed): the signature, the consumer, the folders shared with an application and their subtrees, documents paged with `since`, a document with rows of its own, the text, not_shared for everything else, share.read / share.refused logged under the consumer. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$board = $s['board_root']; $eng = $s['eng_root'];
$contracts = folder_in($board, 'S7 Shared');          // its own folder: the other proofs of the suite leave documents in Contracts
$acme = seed_doc($contracts, 'acme-msa.pdf', 27, 'Master services agreement. Termination clause: ninety days notice.');
$since0 = last_activity_id();
$kn = app_caller('knowledge', 70); $hd = app_caller('helpdesk', 71);

echo "1. The door: only the records server, only signed\n";
$r = door('share_folders', $kn);
ok($r['code'] === 200 && ($r['json']['schema'] ?? '') === 'os.doccloud-folders/1', 'a signed call answers an os.doccloud-folders/1 document');
ok(door('share_folders', $kn, [], ['sign' => false])['code'] === 401, 'no X-DC-Bridge header: 401');
ok(door('share_folders', $kn, [], ['key' => str_repeat('a', 64)])['code'] === 401, 'a signature made with another key: 401');
ok(door('share_folders', $kn, [], ['skew' => 90])['code'] === 401 && door('share_folders', $kn, [], ['skew' => -90])['code'] === 401, 'a timestamp 90 seconds off either way: 401');
ok(door('share_folders', $kn, [], ['skew' => 20])['code'] === 200, '...20 seconds off is within the 30');
$body = json_encode(['op' => 'share_folders', 'caller' => $kn]); $ts = (string) time();
$r = req('POST', door_base() . '/api/v1/shares/read.php', ['raw' => $body . ' ', 'headers' => ['X-DC-Bridge: ' . $ts . '.' . hash_hmac('sha256', 'dc-bridge:' . $ts . '.' . hash('sha256', $body), need('ACTIONS_RELAY_KEY'))]]);
ok($r['code'] === 401, 'a body changed after signing: 401');
$r = req('GET', door_base() . '/api/v1/shares/read.php');
ok($r['code'] === 405, 'GET: 405');
$raw = 'not json'; $ts = (string) time();
$r = req('POST', door_base() . '/api/v1/shares/read.php', ['raw' => $raw, 'headers' => ['X-DC-Bridge: ' . $ts . '.' . hash_hmac('sha256', 'dc-bridge:' . $ts . '.' . hash('sha256', $raw), need('ACTIONS_RELAY_KEY'))]]);
ok($r['code'] === 422, 'signed but not JSON: 422');
$r = door('share_whatever', $kn);
ok($r['code'] === 404 && $r['json']['error']['code'] === 'unknown_op', 'an op that is not one of the four: 404 unknown_op');
ok(!isset($r['headers']) || header_of($r, 'Set-Cookie') === null, 'no session cookie from the door');
if (getenv('DC_APP') === 'apache') { ok(req('POST', '/api/v1/shares/read.php', ['raw' => '{}'])['code'] === 404, 'on the PUBLIC vhost the door answers 404: it exists on the internal port only'); }
$ev = activity('share.read', $since0);
ok($ev !== [] && $ev[0]['source'] === 'application' && $ev[0]['actor_member_id'] === null, 'a share read is logged source application, no actor');

echo "2. No consumer, nothing shared\n";
$before = last_activity_id();
$r = door('share_folders', null);
ok($r['code'] === 403 && $r['json']['error']['code'] === 'consumer_unknown', 'no caller: 403 consumer_unknown');
ok(door('share_folders', ['kind' => 'member', 'member_id' => 27])['json']['error']['code'] === 'consumer_unknown', 'a member caller is no application: consumer_unknown');
ok(door('share_folders', ['kind' => 'application', 'application' => 'Knowledge; DROP'])['json']['error']['code'] === 'consumer_unknown' && door('share_folders', ['kind' => 'application', 'application' => ''])['json']['error']['code'] === 'consumer_unknown', 'a consumer that is not a catalog key: consumer_unknown');
$rf = activity('share.refused', $before);
ok(count($rf) === 4 && json_decode($rf[0]['after'], true)['reason'] === 'consumer_unknown' && json_decode($rf[0]['after'], true)['tool'] === 'folders_index' && json_decode($rf[0]['after'], true)['consumer'] === null, 'each is logged share.refused (reason consumer_unknown, no consumer)');
ok(activity('share.read', $before) === [], 'and no share.read');
$r = door('share_documents', null, ['folder' => $contracts]);
ok($r['code'] === 403 && !str_contains($r['body'], 'acme-msa'), 'documents_index without a consumer shares nothing');

echo "3. folders_index: the folders shared with the consumer, and their subtrees\n";
ok(door('share_folders', $kn)['json']['folders'] === [], 'before any share: no folders');
share_folder_with($contracts, 'knowledge');
$q1 = folder_in($contracts, 'Q1'); $q1b = folder_in($q1, 'Deep');
$priv = folder_in($contracts, 'Private');
pdo()->exec("SELECT dc_folder_restrict($priv, '[{\"principal_kind\":\"member\",\"principal_id\":27,\"level\":\"manage\"}]'::jsonb, 27)");     // restrict with a kept set: the application's inherited row is not kept
$privDoc = seed_doc($priv, 'private-notes.pdf', 27, 'Private to the owner.');
$r = door('share_folders', $kn);
$rows = $r['json']['folders']; $by = []; foreach ($rows as $x) { $by[$x['folder_id']] = $x; }
ok(array_keys($by) === [$contracts, $q1, $q1b] || (array_diff(array_keys($by), [$contracts, $q1, $q1b]) === [] && count($by) === 3), 'The shared folder and its subfolders Q1 and Deep — and not the Board root, not Private (restricted), not Priya\'s library');
ok($by[$contracts]['shared_root'] === true && $by[$q1]['shared_root'] === false && $by[$contracts]['level'] === 'view' && $by[$q1]['level'] === 'view', 'the shared root is marked; the level is view for all');
ok($by[$contracts]['path_text'] === 'SMOKE Board › S7 Shared' && $by[$q1b]['path_text'] === 'SMOKE Board › S7 Shared › Q1 › Deep' && $by[$contracts]['library']['name'] === 'SMOKE Board' && $by[$contracts]['library']['kind'] === 'group', 'path_text and the library {library_id, kind, name}');
ok($by[$contracts]['document_count'] === 1 && $by[$contracts]['subtree_document_count'] === 1, 'document_count and subtree_document_count (acme-msa.pdf; Private\'s document is not counted)');
ok($r['json']['application'] === 'knowledge' && !str_contains($r['body'], 'storage') && !str_contains($r['body'], '"email"') && !str_contains($r['body'], 'owner_member_id'), 'no storage path, no email, no member id in the document');
ok(door('share_folders', $hd)['json']['folders'] === [], 'helpdesk, with no row, sees no folders');
share_folder_with($eng, 'helpdesk', 'upload');
$h = door('share_folders', $hd)['json']['folders'];
ok(count($h) === 1 && $h[0]['folder_id'] === $eng && $h[0]['level'] === 'upload' && !in_array($contracts, array_column($h, 'folder_id'), true), 'helpdesk sees only the Engineering root it was given (level upload)');
ok(count(door('share_folders', $kn, ['q' => 'deep'])['json']['folders']) === 1 && door('share_folders', $kn, ['q' => 'nonesuch'])['json']['folders'] === [], 'q filters the path (case-insensitive)');
pdo()->exec("UPDATE folder_access SET level = 'none' WHERE folder_id = $contracts AND principal_ref = 'knowledge'");
ok(door('share_folders', $kn)['json']['folders'] === [], 'a row at level none shares nothing');
pdo()->exec("UPDATE folder_access SET level = 'view' WHERE folder_id = $contracts AND principal_ref = 'knowledge'");
pdo()->exec("SELECT set_config('app.member_id', '27', false)"); pdo()->exec("SELECT dc_folder_trash($q1b, 27)");
ok(!in_array($q1b, array_column(door('share_folders', $kn)['json']['folders'], 'folder_id'), true), 'a trashed subfolder drops out');

echo "4. documents_index\n";
$docs = [];
foreach ([1, 2] as $i) { $docs[$i] = seed_doc($q1, 'q1-doc-' . $i . '.pdf', 27, 'Quarter one document number ' . $i . ' about termination.'); }
sleep(2); $cut = gmdate('Y-m-d\TH:i:s\Z', time()); sleep(2);
foreach ([3, 4, 5] as $i) { $docs[$i] = seed_doc($q1, 'q1-doc-' . $i . '.pdf', 27, 'Quarter one document number ' . $i . ' about termination.'); }
$r = door('share_documents', $kn, ['folder' => $contracts]);
$names = array_column($r['json']['documents'] ?? [], 'name');
ok($r['code'] === 200 && $r['json']['schema'] === 'os.doccloud-documents/1' && $r['json']['folder_id'] === $contracts && $r['json']['total'] === 6, 'the subtree\'s documents: 6 (acme-msa.pdf and the five in Q1)');
ok(in_array('acme-msa.pdf', $names, true) && !in_array('private-notes.pdf', $names, true), 'the restricted folder\'s document is not among them');
$d0 = $r['json']['documents'][0];
ok(isset($d0['document_id'], $d0['name'], $d0['path_text'], $d0['mime_type'], $d0['mime_kind'], $d0['byte_size'], $d0['sha256'], $d0['tags'], $d0['owner_name'], $d0['version_no'], $d0['version_id'], $d0['extracted_status'], $d0['updated_at']) && is_array($d0['tags']), 'each carries id, name, path, mime, size, sha256, tags, owner_name, version, extraction state, updated_at');
$o = array_values(array_filter($r['json']['documents'], fn ($x) => $x['name'] === 'q1-doc-1.pdf'))[0];
ok($o['owner_name'] === 'SMOKE Marco' && $o['path_text'] === 'SMOKE Board › S7 Shared › Q1' && !array_key_exists('owner_member_id', $o), 'the owner is a display name only; the path is the folder\'s');
$p1 = door('share_documents', $kn, ['folder' => $contracts, 'limit' => 4]);
$p2 = door('share_documents', $kn, ['folder' => $contracts, 'limit' => 4, 'offset' => 4]);
ok(count($p1['json']['documents']) === 4 && $p1['json']['has_more'] === true && count($p2['json']['documents']) === 2 && $p2['json']['has_more'] === false && $p1['json']['total'] === 6, 'paged: 4 then 2, has_more says so');
ok(array_intersect(array_column($p1['json']['documents'], 'document_id'), array_column($p2['json']['documents'], 'document_id')) === [], 'the pages do not overlap');
$r = door('share_documents', $kn, ['folder' => $contracts, 'since' => $cut]);
$sn = array_column($r['json']['documents'], 'name'); sort($sn);
ok($sn === ['q1-doc-3.pdf', 'q1-doc-4.pdf', 'q1-doc-5.pdf'], 'since keeps what changed after it: the three documents made after the cut');
ok(door('share_documents', $kn, ['folder' => $contracts, 'since' => 'yesterday-ish'])['code'] === 422, 'a since that is not a time: 422');
ok(count(door('share_documents', $kn, ['folder' => $contracts, 'limit' => 100000])['json']['documents']) === 6 && door('share_documents', $kn, ['folder' => $contracts, 'limit' => 100000])['json']['limit'] === 200, 'the page is capped at 200');
$sub = door('share_documents', $kn, ['folder' => $q1]);
ok($sub['code'] === 200 && $sub['json']['total'] === 5, 'a subfolder inherited from the shared root can be asked for directly (5)');
foreach ([['folder' => $board], ['folder' => $priv], ['folder' => 999999], ['folder' => $eng]] as $a) {
    $r = door('share_documents', $kn, $a);
    $bad[] = $r['code'] === 403 && $r['json']['error']['code'] === 'not_shared';
}
ok($bad === [true, true, true, true], 'the Board root, the restricted folder, a missing folder and another application\'s folder: all 403 not_shared — the same words');
ok(door('share_documents', $kn, ['folder' => $contracts])['json']['documents'] !== [] && door('share_documents', $hd, ['folder' => $contracts])['json']['error']['code'] === 'not_shared', 'helpdesk is refused the folder knowledge may read');
ok(door('share_documents', $kn, [])['code'] === 422 && door('share_documents', $kn, ['folder' => 'x'])['code'] === 422, 'no folder / a bad folder: 422');
$hdDocs = door('share_documents', $hd, ['folder' => $eng]);
ok($hdDocs['code'] === 200 && array_diff(array_column($hdDocs['json']['documents'], 'name'), ['design.pdf']) === [], 'helpdesk reads the Engineering folder\'s design.pdf');

echo "   a document with rows of its own\n";
$own = $docs[2];
pdo()->exec("INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by) VALUES ($own, 'member', 26, 'view', 27) ON CONFLICT DO NOTHING");
ok((bool) one('SELECT own_access FROM documents WHERE id = :d', ['d' => $own]), 'a row on the document gives it its own access');
$r = door('share_documents', $kn, ['folder' => $contracts]);
ok($r['json']['total'] === 5 && !in_array('q1-doc-2.pdf', array_column($r['json']['documents'], 'name'), true), 'documents_index omits it');
ok(door('share_text', $kn, ['document' => $own])['json']['error']['code'] === 'not_shared' && door('share_link', $kn, ['document' => $own])['json']['error']['code'] === 'not_shared', 'its text and its link are not_shared');
share_document_with($own, 'knowledge');
$r = door('share_documents', $kn, ['folder' => $contracts]);
ok($r['json']['total'] === 6 && in_array('q1-doc-2.pdf', array_column($r['json']['documents'], 'name'), true) && door('share_text', $kn, ['document' => $own])['code'] === 200, 'with an application row of its own for knowledge it is back, text and all');
ok(door('share_text', $hd, ['document' => $own])['json']['error']['code'] === 'not_shared', '...and still not helpdesk\'s');
pdo()->exec("UPDATE documents SET trashed_at = now(), trashed_by = 27, trashed_from_folder_id = folder_id WHERE id = " . $docs[5]);
ok(door('share_documents', $kn, ['folder' => $contracts])['json']['total'] === 5, 'a trashed document drops out');

echo "5. document_text\n";
$pv = (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $acme]);
pdo()->prepare("UPDATE document_text SET text = :t, char_count = :c, pages = CAST(:p AS jsonb) WHERE version_id = :v")->execute(['t' => 'Page one text. Page two text here.', 'c' => 34, 'p' => json_encode([['page_no' => 1, 'char_from' => 0, 'char_to' => 14], ['page_no' => 2, 'char_from' => 15, 'char_to' => 34]]), 'v' => $pv]);
$r = door('share_text', $kn, ['document' => $acme]);
$j = $r['json'];
ok($r['code'] === 200 && $j['schema'] === 'os.doccloud-text/1' && $j['text'] === 'Page one text. Page two text here.' && count($j['pages']) === 2 && $j['char_count'] === 34 && $j['document_id'] === $acme && $j['version_id'] === $pv && $j['truncated'] === false, 'the text with its two pages, the version, the count');
ok($j['pages'][1]['page_no'] === 2 && $j['pages'][1]['char_from'] === 15, 'pages carry page_no and the character offsets');
ok(isset($j['status']) && $j['application'] === 'knowledge', 'status (the extraction state) and the application');
foreach ([$s['priya_doc'], $privDoc, $s['handbook'], $s['design']] as $x) { $bad2[] = door('share_text', $kn, ['document' => $x])['json']['error']['code'] ?? 'ok'; }
ok($bad2 === ['not_shared', 'not_shared', 'not_shared', 'not_shared'], 'Priya\'s notes, the restricted document, the handbook, the Engineering design: not_shared for knowledge');
ok(door('share_text', $kn, ['document' => 999999])['json']['error']['code'] === 'not_shared' && door('share_text', $kn, [])['code'] === 422, 'a missing document: the same not_shared; none named: 422');
$r = door('share_text', $kn, ['document' => $acme, 'max_chars' => 1000]);
ok($r['json']['truncated'] === false, 'max_chars above the text: untouched');
$big = str_repeat('0123456789', 700000);
pdo()->prepare("UPDATE document_text SET text = :t, char_count = :c, pages = '[]' WHERE version_id = :v")->execute(['t' => $big, 'c' => strlen($big), 'v' => $pv]);
$r = door('share_text', $kn, ['document' => $acme]);
ok($r['code'] === 200 && $r['json']['truncated'] === true && strlen($r['json']['text']) <= 5 * 1048576 && strlen($r['json']['text']) > 5 * 1048576 - 4 && $r['json']['char_count'] === 7000000, 'over 5 MB the text is cut at 5 MB and says truncated (char_count stays the whole)');
$r = door('share_text', $kn, ['document' => $acme, 'max_chars' => 5000]);
ok(strlen($r['json']['text']) === 5000 && $r['json']['truncated'] === true, 'max_chars cuts shorter');
$nt = real_doc(27, $q1, 'no-text-yet.bin', 'nt', 800);
$r = door('share_text', $kn, ['document' => $nt]);
ok($r['code'] === 200 && $r['json']['text'] === '' && $r['json']['pages'] === [], 'a document with no text yet: an empty text, no pages');

echo "6. document_link\n";
$real = real_doc(27, $q1, 'linked-bytes.bin', 'lk', 4096);
$r = door('share_link', $kn, ['document' => $real]);
$j = $r['json'];
ok($r['code'] === 200 && $j['schema'] === 'os.doccloud-link/1' && $j['document_id'] === $real && $j['name'] === 'linked-bytes.bin' && $j['byte_size'] === 4096 && preg_match('#^http://127.0.0.1:8701/x/' . $real . '/\d+/\d+/knowledge/[a-f0-9]{64}$#', $j['url']), 'a signed URL for the document: /x/{document}/{version}/{expires}/knowledge/{signature}');
ok(abs(strtotime($j['expires_at']) - time() - 600) < 5, 'expires_at is ten minutes away');
$u = parse_url($j['url'], PHP_URL_PATH); preg_match('#/x/\d+/\d+/(\d+)/#', $u, $m);
ok(abs((int) $m[1] - time() - 600) < 5, 'the URL carries the same expiry');
ok(!str_contains($r['body'], '"bytes"') && strlen($r['body']) < 1500, 'the answer is a URL, never bytes');
ok(door('share_link', $hd, ['document' => $real])['json']['error']['code'] === 'not_shared' && door('share_link', $kn, ['document' => $acme])['code'] === 200, 'helpdesk is refused; the shared acme-msa.pdf links');
ok(str_contains(door('share_link', $kn, ['document' => $real, 'disposition' => 'inline'])['json']['url'], '?disposition=inline'), 'disposition=inline is carried as a query');

echo "7. Every read is logged under the consumer\n";
$ev = activity('share.read', $since0);
$tools = array_count_values(array_map(fn ($e) => json_decode($e['after'], true)['tool'], $ev));
ok(isset($tools['folders_index'], $tools['documents_index'], $tools['document_text'], $tools['document_link']), 'share.read rows exist for all four tools');
$last = json_decode(end($ev)['after'], true);
ok($last['direction'] === 'in' && $last['consumer'] === 'knowledge' && $last['consumer_agent'] === 70 && $last['outcome'] === 'ok' && isset($last['ms']) && $last['rows'] === 1, 'the last: direction in, consumer knowledge, its agent 70, rows 1, ms');
$one = array_values(array_filter($ev, fn ($e) => json_decode($e['after'], true)['tool'] === 'documents_index' && json_decode($e['after'], true)['consumer'] === 'knowledge'));
ok(json_decode($one[0]['after'], true)['folder_id'] === $contracts && json_decode($one[0]['after'], true)['rows'] === 6, 'documents_index logs the folder and the rows returned');
$hdr = array_values(array_filter($ev, fn ($e) => json_decode($e['after'], true)['consumer'] === 'helpdesk'));
ok($hdr !== [] && json_decode($hdr[0]['after'], true)['consumer_agent'] === 71, 'helpdesk\'s reads are logged under helpdesk with its agent');
$noAgent = door('share_folders', app_caller('knowledge', null)); $allr = activity('share.read', $since0); $le = json_decode(end($allr)['after'], true);
ok($le['consumer_agent'] === null && $noAgent['code'] === 200, 'a caller with no asking agent is still served; the agent is logged as none');
$rf = activity('share.refused', $since0);
$reasons = array_count_values(array_map(fn ($e) => json_decode($e['after'], true)['reason'], $rf));
ok(($reasons['not_shared'] ?? 0) >= 8 && ($reasons['consumer_unknown'] ?? 0) === 5, 'refusals are logged share.refused with the reason (not_shared and consumer_unknown)');
ok(!str_contains(json_encode(array_merge($ev, $rf)), 'Page one text') && !str_contains(json_encode($ev), $j['url']), 'no text and no URL in any log row');
ok(count(array_filter($ev, fn ($e) => $e['source'] !== 'application' || $e['actor_member_id'] !== null)) === 0, 'every one is source application with no actor');

echo "8. The admin's connections screen shows them\n";
$r = get_json(1, '/admin/connections');
$by = []; foreach ($r['json']['data']['connections'] as $c) { $by[$c['application']] = $c; }
ok($r['code'] === 200 && isset($by['knowledge'], $by['helpdesk']) && count($by['knowledge']['folders']) >= 1 && $by['knowledge']['folders'][0]['path'] === 'SMOKE Board › S7 Shared', 'knowledge and helpdesk are listed, with the shared folder');
$kr = array_column($by['knowledge']['reads'], null, 'tool');
ok($kr['documents_index']['calls'] >= 8 && ($kr['documents_index']['refused'] ?? 0) >= 4 && $kr['folders_index']['calls'] >= 5, 'the reads by tool: calls and refusals');
ok(array_column($by['knowledge']['documents'], 'name') === ['q1-doc-2.pdf'], 'the single document shared on its own is listed');
$b = page(actor(1), '/admin/connections')['body'];
ok(str_contains($b, 'id="connection-knowledge"') && str_contains($b, 'id="connection-knowledge-folders"') && str_contains($b, 'S7 Shared') && str_contains($b, 'id="connections-recent"'), 'the page: a card per application, the folders, the latest reads');
ok(get_json(27, '/admin/connections')['code'] === 403 && get_json(26, '/admin/connections')['code'] === 403, 'a curator and a member: 403');
finish();
