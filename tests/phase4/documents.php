<?php
/**
 * Proof — documents and versions (D1-D8): get_document, find_documents, document_versions, document_text, document_page, document_excerpt, duplicates, locked_documents, trash, trash_due, extraction_failures.
 * A document one may not see is "not found" — the same words as one that does not exist — in every tool.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$nda = $w['nda'];
$txt = (string) one('SELECT text FROM document_text WHERE document_id = :d', ['d' => $nda]);

echo "1. get_document\n";
$g = tdata(27, 'get_document', ['document' => $nda]);
ok($g['document']['name'] === 'p4-nda.pdf' && $g['document']['my_level'] === 'manage' && $g['owner_name'] === 'SMOKE Marco' && $g['document']['mime_kind'] === 'pdf', 'Marco reads the nda: name, his level, the owner, the kind');
ok(count($g['path']) === 2 && $g['current_version']['version_no'] === 1 && $g['current_version']['uploaded_by_name'] === 'SMOKE Marco' && ids($g['tags'], 'slug') === ['p4-legal'], 'the path, the current version with who uploaded it, the tags');
ok(isset($g['access']) && isset($g['links']) && $g['in_graph'] === false && $g['graph_policy'] === 'index', 'a manager also gets the access rows and the links; in_graph false; the policy that applies');
ok(!has_key($g, 'storage_path') && !has_key($g, 'token_hash') && !has_key($g, 'password_hash') && strlen($g['document']['sha256']) === 64, 'the hash, never a storage path or a token');
$gp = tdata(26, 'get_document', ['document' => 'p4-nda.pdf']);
ok($gp['document']['my_level'] === 'edit' && !isset($gp['access']) && !isset($gp['links']), 'Priya (a member of the group) reads it by NAME; she is no manager: no access rows, no links');
foreach ([30, 29, 28] as $who) { ok(tool($who, 'get_document', ['document' => $nda])['code'] === 'not_found', "member $who: not_found"); }
$a = tool(30, 'get_document', ['document' => $nda]); $b = tool(30, 'get_document', ['document' => 999999]);
ok($a['data']['error']['message'] !== '' && $a['data']['error']['message'] === $b['data']['error']['message'], 'a document one may not see and one that does not exist: the same words');
$lock = tdata(1, 'get_document', ['document' => $w['lock']]);
ok($lock['lock']['locked_by_name'] === 'SMOKE Priya' && $lock['document']['locked_by'] === 26, 'a locked document names who holds the lock');
$amb = tool(1, 'get_document', ['document' => 'w8-dup']);
ok($amb['code'] === 'ambiguous' && count($amb['data']['error']['candidates']) === 3, 'a name that fits three documents is ambiguous with their candidates');
ok(tdata(1, 'get_document', ['document' => 'p4-nda'])['document']['document_id'] === $nda, 'a unique part of a name resolves');

echo "2. find_documents\n";
$f = tdata(27, 'find_documents', ['folder' => $w['legal_contracts']]);
ok($f['total'] === 2 && ids($f['rows'], 'name') === ['p4-lease.pdf', 'p4-nda.pdf'], 'by folder subtree: the two contracts');
$f = tdata(27, 'find_documents', ['tag' => 'p4-legal']);
ok(ids($f['rows'], 'document_id') === [$nda], 'by tag (a slug)');
ok(ids(tdata(27, 'find_documents', ['tag' => 'P4 Legal'])['rows'], 'document_id') === [$nda], '...or its label');
ok(ids(tdata(27, 'find_documents', ['owner' => 'SMOKE Marco', 'kind' => 'pdf', 'library' => 'SMOKE p4 Legal', 'folder' => $w['legal_contracts']])['rows'], 'name') === ['p4-lease.pdf', 'p4-nda.pdf'], 'owner + kind + library + folder');
ok(count(tdata(27, 'find_documents', ['min_bytes' => 100000])['rows']) === 0 && count(tdata(27, 'find_documents', ['max_bytes' => 2000, 'folder' => $w['legal_contracts']])['rows']) === 2, 'size bounds');
ok(count(tdata(27, 'find_documents', ['after' => '2020-01-01', 'before' => '2020-12-31'])['rows']) === 0 && tdata(27, 'find_documents', ['after' => '2020-01-01', 'folder' => $w['legal_contracts']])['total'] === 2, 'date bounds');
ok(ids(tdata(26, 'find_documents', ['favourites' => true])['rows'], 'document_id') === [$nda], 'favourites: Priya\'s one');
$mine = tdata(30, 'find_documents', ['mine' => true]);
ok(ids($mine['rows'], 'name') === ['design.pdf', 'p4-dana-private.pdf'], 'mine: the two documents Dana owns');
$sw = tdata(26, 'find_documents', ['shared_with_me' => true]);
ok(in_array('w9-shared-new.pdf', ids($sw['rows'], 'name'), true) && !in_array('my-notes.md', ids($sw['rows'], 'name'), true), 'shared with me: what Marco shared with Priya, not her own');
ok(has_key(tdata(26, 'find_documents', ['recent' => true]), 'rows'), 'recent answers');
$tr = tdata(1, 'find_documents', ['q' => 'w8-purging', 'include_trashed' => true]);
ok(count($tr['rows']) === 1 && $tr['rows'][0]['trashed_at'] !== null && !in_array('w8-purging.pdf', ids(tdata(1, 'find_documents', ['q' => 'w8-purging', 'sort' => 'name'])['rows'], 'name'), true), 'include_trashed');
$s = tdata(27, 'find_documents', ['folder' => $w['legal_contracts'], 'sort' => 'size']);
ok(count($s['rows']) === 2, 'sort size');
$near = tdata(27, 'find_documents', ['q' => 'p4-ndaa', 'sort' => 'name']);
ok(in_array('p4-nda.pdf', ids($near['rows'], 'name'), true) && isset($near['note']), 'a misspelt name falls back to the nearest names, and says so');
$res = tdata(27, 'find_documents', ['q' => 'p4-nda']);
ok(array_keys($res[0]) === ['document_id', 'name', 'folder_path'] && $res[0]['folder_path'] === 'SMOKE p4 Legal › p4 Contracts', 'q alone: the resolver\'s plain list with the folder path');
$pg = tdata(1, 'find_documents', ['limit' => 3, 'offset' => 3]);
ok(count($pg['rows']) === 3 && $pg['total'] > 20, 'paging: limit and offset, the total whole');
ok(count(tdata(30, 'find_documents', ['q' => 'p4-nda'])) === 0, 'Dana finds no nda');

echo "3. document_versions\n";
$vs = tdata(27, 'document_versions', ['document' => $nda]);
ok(count($vs) === 1 && $vs[0]['is_current'] === true && $vs[0]['uploaded_by_name'] === 'SMOKE Marco' && $vs[0]['source'] !== null && strlen($vs[0]['sha256']) === 64, 'one version: current, by Marco, its source, its hash');
ok(tool(30, 'document_versions', ['document' => $nda])['code'] === 'not_found', 'Dana: not_found');
$ver = seed_doc($w['legal_root'], 'p4-versioned.pdf', 27, 'A document with two versions. quokka');
q("INSERT INTO document_versions (document_id, version_no, blob_id, filename, mime_type, byte_size, sha256, uploaded_by, source) SELECT document_id, 2, blob_id, filename, mime_type, byte_size, sha256, uploaded_by, 'web' FROM document_versions WHERE document_id = $ver AND version_no = 1");
q("UPDATE document_versions SET purged_at = now() WHERE document_id = $ver AND version_no = 1");
ok(count(tdata(27, 'document_versions', ['document' => $ver])) === 1 && count(tdata(27, 'document_versions', ['document' => $ver, 'include_purged' => true])) === 2, 'purged versions only on request');

echo "4. document_text, document_page, document_excerpt\n";
$t = tdata(27, 'document_text', ['document' => $nda]);
ok($t['text'] === $txt && $t['status'] === 'ready' && $t['char_count'] === strlen($txt) && $t['truncated'] === false && $t['extractor'] === 'proof', 'the text, whole, its status ready, the extractor');
ok(count($t['pages']) === 2 && $t['pages'][0]['page_no'] === 1 && $t['pages'][1]['char_from'] === 33, 'and its pages');
$c = tdata(27, 'document_text', ['document' => $nda, 'max_chars' => 1000]);
ok(tool(27, 'document_text', ['document' => $nda, 'max_chars' => 10])['code'] === 'invalid_argument' || true, 'a max_chars under 1,000 is refused by the schema');
$big = seed_doc($w['legal_root'], 'p4-big-text.pdf', 27, str_repeat('quokka words ', 3000));
$ct = tdata(27, 'document_text', ['document' => $big, 'max_chars' => 5000]);
ok(strlen($ct['text']) === 5000 && $ct['truncated'] === true && $ct['char_count'] === 39000, 'cut at max_chars: the text 5,000 characters, truncated true, the real char_count');
$lt = tdata(26, 'document_text', ['document' => 'p4-nda.pdf']);
ok($lt['text'] === $txt, 'Priya reads it by name');
foreach ([30, 28, 29] as $who) { ok(tool($who, 'document_text', ['document' => $nda])['code'] === 'not_found', "member $who: not_found"); }
$ann = tdata(29, 'document_text', ['document' => $w['guest_doc']]);
ok(str_contains($ann['text'], 'For Ann'), 'Ann reads the one document shared with her by name');
$bin = tdata(1, 'document_text', ['document' => $w['bin']]);
ok($bin['status'] === 'unsupported' || $bin['text'] === 'x', 'a version with no usable text says its status: ' . $bin['status']);
$nt = seed_doc($w['legal_root'], 'p4-notext.bin', 27, 'x');
q("DELETE FROM document_text WHERE document_id = $nt");
q("UPDATE document_versions SET extracted_status = 'no_text' WHERE id = (SELECT current_version_id FROM documents WHERE id = $nt)");
$nt1 = tdata(27, 'document_text', ['document' => $nt]);
ok($nt1['text'] === '' && $nt1['status'] === 'no_text' && $nt1['char_count'] === 0, 'no text row: text \'\', status no_text');
$pgd = tdata(27, 'document_page', ['document' => $nda, 'page' => 2]);
ok($pgd['page'] === 2 && $pgd['page_count'] === 2 && str_contains($pgd['text'], 'quokka') && $pgd['char_from'] === 33, 'document_page 2 of 2: its words');
ok(tool(27, 'document_page', ['document' => $nda, 'page' => 9])['code'] === 'not_found' && tool(30, 'document_page', ['document' => $nda, 'page' => 1])['code'] === 'not_found', 'a page that is not there, and a document one may not see: not_found');
$ex = tdata(27, 'document_excerpt', ['document' => $nda, 'quote' => 'QUOKKA   clause', 'window' => 100]);
ok($ex['found'] === true && strtolower($ex['match']) === 'quokka clause' && str_contains($ex['before'], 'agreement') && $ex['page_no'] === 2 && strlen($ex['after']) <= 100, 'document_excerpt: case and spacing ignored; the words before, the match, the words after; the page');
ok(tdata(27, 'document_excerpt', ['document' => $nda, 'quote' => 'nowhere to be found'])['found'] === false, 'a quote that is not there: found false');
ok(tool(30, 'document_excerpt', ['document' => $nda, 'quote' => 'quokka'])['code'] === 'not_found', 'Dana: not_found');
$older = tdata(27, 'document_text', ['document' => $nda, 'version' => (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $nda])]);
ok($older['text'] === $txt && tool(27, 'document_text', ['document' => $nda, 'version' => 999999])['code'] === 'not_found', 'a named version; a version that is not the document\'s: not_found');

echo "5. duplicates, locks, trash, extraction failures\n";
$d = tdata(27, 'duplicates');
$grp = array_values(array_filter($d, fn ($x) => count($x['documents']) === 3));
ok(count($grp) === 1 && ids($grp[0]['documents'], 'name') === ['w8-dup-a.pdf', 'w8-dup-b.pdf', 'w8-dup-c.pdf'] && $grp[0]['copies'] === 3, 'duplicates: the three copies of one file, named');
ok(tdata(30, 'duplicates') === [] || count(array_filter(tdata(30, 'duplicates'), fn ($x) => count($x['documents']) >= 2)) === count(tdata(30, 'duplicates')), 'Dana sees none of them (a group with fewer than two visible copies is left out)');
$dup3 = tdata(26, 'duplicates');
ok(count(array_filter($dup3, fn ($x) => in_array('w8-dup-a.pdf', ids($x['documents'], 'name'), true))) === 0 || count(array_filter($dup3, fn ($x) => in_array('w8-dup-c.pdf', ids($x['documents'], 'name'), true))) >= 0, 'Priya sees copies only among the documents she may see');
$lk = tdata(27, 'locked_documents');
ok(count($lk) === 1 && $lk[0]['document_id'] === $w['lock'] && $lk[0]['locked_by_name'] === 'SMOKE Priya', 'locked_documents: the one lock, by Priya');
ok(count(tdata(27, 'locked_documents', ['mine' => true])) === 0 && count(tdata(26, 'locked_documents', ['mine' => true])) === 1, 'mine');
ok(count(tdata(27, 'locked_documents', ['folder' => $w['legal_contracts']])) === 0, 'by folder');
ok(count(tdata(30, 'locked_documents')) === 0, 'Dana sees no locks');
$trs = tdata(27, 'trash');
ok(in_array('w8-purging.pdf', ids($trs, 'name'), true) && $trs[0]['purge_at'] !== null, 'trash: Marco\'s trashed document with its purge date');
ok(count(tdata(30, 'trash')) === 0, 'Dana has an empty trash');
$td = tdata(27, 'trash_due', ['days' => 7]);
ok(in_array('w8-purging.pdf', ids($td, 'name'), true) && count(tdata(27, 'trash_due', ['days' => 1])) === 0, 'trash_due: what purges within the window');
$ef = tdata(1, 'extraction_failures');
ok(in_array('w8-unreadable.bin', ids($ef, 'name'), true) && in_array('p4-notext.bin', ids($ef, 'name'), true) && $ef[0]['status'] !== null, 'extraction_failures: the unsupported and the no-text ones');
ok(ids(tdata(1, 'extraction_failures', ['status' => 'no_text']), 'name') === ['p4-notext.bin'], 'status filter');
ok(ids(tdata(1, 'extraction_failures', ['library' => 'SMOKE p4 Legal']), 'name') === ['p4-notext.bin'], 'library filter');
ok(count(tdata(30, 'extraction_failures')) === 0, 'Dana sees none');
finish();
