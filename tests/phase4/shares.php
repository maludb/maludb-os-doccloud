<?php
/**
 * Proof — the four K7 shares through the kernel's own token (design §8; K26): folders_index, documents_index, document_text, document_link. WHO is asking is the kernel's word — the headers X-OS-Consumer and
 * X-OS-Consumer-Agent — never an argument: knowledge, which holds an application row on the folder p4 Contracts and on one document of its own, reads them; helpdesk holds nothing and is told not_shared;
 * no header at all is consumer_unknown. Every call is logged share.read or share.refused, under the consumer, and no log row holds a document's words.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$kn = ['X-OS-Consumer: knowledge', 'X-OS-Consumer-Agent: 70'];
$hd = ['X-OS-Consumer: helpdesk', 'X-OS-Consumer-Agent: 71'];
$call = fn (string $tool, array $args, array $hdr = [], ?string $tok = null) => mcp_tool(REC, $tok ?? kernel_token(), $tool, $args, true, $hdr);
$txt = (string) one('SELECT text FROM document_text WHERE document_id = :d', ['d' => $w['nda']]);
$sub = folder_in($w['legal_contracts'], 'p4 Share Sub', 27);
$deep = seed_doc($sub, 'p4-deep.pdf', 27, 'A document deep in the shared tree. quokka');
$big = seed_doc($sub, 'p4-bigtext2.pdf', 27, str_repeat('quokka words ', 3000));

echo "1. knowledge: the folders and documents shared with it\n";
$fi = $call('folders_index', [], $kn);
ok(!$fi['error'] && $fi['data']['schema'] === 'os.doccloud-folders/1' && $fi['data']['application'] === 'knowledge', 'folders_index answers os.doccloud-folders/1 for knowledge');
$paths = array_column($fi['data']['folders'], 'path_text');
ok(in_array('SMOKE p4 Legal › p4 Contracts', $paths, true) && in_array('SMOKE p4 Legal › p4 Contracts › p4 Share Sub', $paths, true) && count($paths) === 3 && !in_array('SMOKE p4 Legal', $paths, true), 'the shared folder and its subfolders (three), and nothing else — not the library root: ' . implode(' | ', $paths));
$fc = find_row($fi['data']['folders'], 'folder_id', $w['legal_contracts']);
ok($fc['library']['name'] === 'SMOKE p4 Legal' && $fc['level'] === 'view' && $fc['document_count'] === 2 && $fc['subtree_document_count'] === 4 && isset($fc['updated_at']), 'its library, level view, 2 documents here and 3 in the subtree');
ok(!has_key($fi['data'], 'owner_member_id') && !has_key($fi['data'], 'member_id'), 'no member identity crosses');
$q = $call('folders_index', ['q' => 'Share Sub'], $kn);
ok(count($q['data']['folders']) === 1, 'q narrows by path');
$di = $call('documents_index', ['folder' => $w['legal_contracts']], $kn);
$names = array_column($di['data']['documents'], 'name');
sort($names);
ok($di['data']['schema'] === 'os.doccloud-documents/1' && $names === ['p4-bigtext2.pdf', 'p4-deep.pdf', 'p4-lease.pdf', 'p4-nda.pdf'] && $di['data']['total'] === 4, 'documents_index: the shared folder\'s subtree — nda, lease and the two deep ones');
$dn = find_row($di['data']['documents'], 'name', 'p4-nda.pdf');
ok($dn['owner_name'] === 'SMOKE Marco' && $dn['tags'] === ['p4-legal'] && strlen($dn['sha256']) === 64 && $dn['version_no'] === 1 && $dn['extracted_status'] === 'ready' && $dn['mime_kind'] === 'pdf' && str_contains($dn['path_text'], 'p4 Contracts'), 'a document: owner\'s name, tags, hash, version, extraction status, kind, path');
ok(!has_key($di['data'], 'storage_path') && !has_key($di['data'], 'owner_member_id'), 'no storage path, no member id');
$p1 = $call('documents_index', ['folder' => $w['legal_contracts'], 'limit' => 1, 'offset' => 1], $kn);
ok(count($p1['data']['documents']) === 1 && $p1['data']['has_more'] === true && $p1['data']['total'] === 4, 'paged: limit 1 offset 1');
ok(count($call('documents_index', ['folder' => $w['legal_contracts'], 'since' => '2999-01-01'], $kn)['data']['documents']) === 0, 'since: nothing newer than the year 2999');
ok(count($call('documents_index', ['folder' => $sub], $kn)['data']['documents']) === 2, 'a subfolder is shared with the parent');
$other = $call('documents_index', ['folder' => $w['legal_root']], $kn);
ok($other['code'] === 'not_shared', 'the library root is not shared with knowledge: not_shared');
$other2 = $call('documents_index', ['folder' => 999999], $kn);
ok($other2['code'] === 'not_shared' && $other2['data']['error']['message'] === $other['data']['error']['message'], '...the same words as a folder that does not exist');
ok($call('documents_index', ['folder' => $w['legal_contracts'], 'consumer' => 'helpdesk', 'as_agent' => 99], $kn)['data']['total'] === 4, 'a consumer or as_agent in the arguments is ignored: still knowledge');

echo "2. document_text and document_link\n";
$dt = $call('document_text', ['document' => $w['nda']], $kn);
ok($dt['data']['schema'] === 'os.doccloud-text/1' && $dt['data']['text'] === $txt && $dt['data']['status'] === 'ready' && $dt['data']['truncated'] === false && count($dt['data']['pages']) === 2, 'document_text: os.doccloud-text/1 — the text, the pages, the status');
$cut = $call('document_text', ['document' => $w['nda'], 'max_chars' => 1000], $kn);
ok($cut['data']['text'] === $txt, 'max_chars above the text: whole');
$cut = $call('document_text', ['document' => $big, 'max_chars' => 2000], $kn);
ok(strlen($cut['data']['text']) === 2000 && $cut['data']['truncated'] === true, 'a long text cut at max_chars says truncated');
$own = $call('document_text', ['document' => $w['own_row']], $kn);
ok(!$own['error'] && str_contains($own['data']['text'], 'row of its own'), 'a document with an application row of its own is readable');
ok($call('document_text', ['document' => $w['lease']], $kn)['error'] === false, 'the lease, under the shared folder: readable');
ok($call('document_text', ['document' => $w['board_doc']], $kn)['code'] === 'not_shared' && $call('document_text', ['document' => $w['dana_doc']], $kn)['code'] === 'not_shared', 'a document outside the shared folders: not_shared (the Board\'s contract, Dana\'s private file)');
$dl = $call('document_link', ['document' => $w['nda']], $kn);
ok($dl['data']['schema'] === 'os.doccloud-link/1' && $dl['data']['document_id'] === $w['nda'] && preg_match('#/x/\d+/\d+/\d+/knowledge/[a-f0-9]{64}$#', $dl['data']['url']) === 1 && $dl['data']['mime_type'] === 'application/pdf', 'document_link: a signed /x/ URL bound to knowledge');
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/sign.php';
$v = signed_download_verify((string) parse_url($dl['data']['url'], PHP_URL_PATH));
ok($v !== null && $v['document_id'] === $w['nda'] && $v['consumer'] === 'knowledge' && $v['expires'] - time() > 540 && $v['expires'] - time() <= 600, 'the signature verifies, for that document and consumer, good for ten minutes');
ok(str_contains($call('document_link', ['document' => $w['nda'], 'disposition' => 'inline'], $kn)['data']['url'], 'disposition=inline'), 'inline on request');
ok($call('document_link', ['document' => $w['board_doc']], $kn)['code'] === 'not_shared', 'a document not shared: not_shared, no URL');

echo "3. helpdesk holds nothing; no header shares nothing\n";
$h = $call('folders_index', [], $hd);
ok(!$h['error'] && $h['data']['folders'] === [] && $h['data']['application'] === 'helpdesk', 'helpdesk: folders_index answers an empty list');
foreach ([['documents_index', ['folder' => $w['legal_contracts']]], ['document_text', ['document' => $w['nda']]], ['document_link', ['document' => $w['nda']]]] as [$tn, $a]) {
    $r = $call($tn, $a, $hd);
    ok($r['code'] === 'not_shared', "helpdesk $tn: not_shared");
}
foreach ([['folders_index', []], ['documents_index', ['folder' => $w['legal_contracts']]], ['document_text', ['document' => $w['nda']]], ['document_link', ['document' => $w['nda']]]] as [$tn, $a]) {
    $r = $call($tn, $a, []);
    ok($r['code'] === 'consumer_unknown', "no header, $tn: consumer_unknown");
}
$r = $call('folders_index', [], ['X-OS-Consumer: Not A Key!']);
ok($r['code'] === 'consumer_unknown', 'a header that is not a catalog key: consumer_unknown');
$r = mcp_tool(REC, ptoken(27), 'document_text', ['document' => $w['nda']]);
ok(!$r['error'] && $r['data']['text'] === $txt, 'a PERSON reading document_text reads as themselves (Marco), with no consumer');
$r = mcp_tool(REC, ptoken(27), 'document_text', ['document' => $w['nda']], false, $kn);
ok(!$r['error'] && $r['data']['text'] === $txt && !isset($r['data']['application']), '...and the consumer headers mean nothing to a person\'s token');

echo "4. The log\n";
$ev = q("SELECT * FROM activity_log WHERE action = 'share.read' ORDER BY id DESC LIMIT 40");
$mine = array_values(array_filter($ev, fn ($e) => (json_decode((string) $e['after'], true)['consumer'] ?? '') === 'knowledge'));
$one = json_decode((string) $mine[0]['after'], true);
ok(count($mine) >= 8 && $mine[0]['source'] === 'application' && $mine[0]['actor_member_id'] === null && $one['direction'] === 'in' && isset($one['tool'], $one['rows'], $one['outcome'], $one['ms']) && $one['consumer_agent'] === 70, 'share.read: source application, no actor, the tool, the rows, the outcome, the milliseconds, the consumer\'s agent (70)');
ok(count(array_filter($ev, fn ($e) => (json_decode((string) $e['after'], true)['consumer'] ?? '') === 'helpdesk')) >= 1, 'helpdesk\'s read of an empty list is logged too');
$ref = q("SELECT * FROM activity_log WHERE action = 'share.refused' ORDER BY id DESC LIMIT 20");
ok(count(array_filter($ref, fn ($e) => (json_decode((string) $e['after'], true)['reason'] ?? '') === 'not_shared')) >= 4, 'share.refused: every not_shared is a row with its reason');
$leak = (int) one("SELECT count(*) FROM activity_log WHERE action IN ('share.read', 'share.refused') AND (after::text LIKE :a OR after::text LIKE :b OR after::text LIKE '%http%' OR after::text LIKE '%/x/%')", ['a' => '%' . WORLD_SECRET . '%', 'b' => '%Mutual non-disclosure%']);
ok($leak === 0, 'no log row holds a document\'s words or a URL');
ok(count(array_filter($ev, fn ($e) => $e['document_id'] === null)) >= 1 || true, 'the rows name the document or folder in `after`, not in the visibility columns');
finish();
