<?php
/** Slice 6 proof 1–3 — the trash: a document trashed and restored (the root when its folder is trashed, "(2)" when the name is taken), a folder trashed with its subtree, purges (by the pass, by the admin, due only, all), an agent. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$acme = $s['board_doc']; $contracts = $s['contracts']; $boardRoot = $s['board_root'];
$since = last_activity_id();

echo "1. A document trashed and restored\n";
$r = post(26, '/documents/trash.php', ['document' => $acme]);
ok($r['code'] === 403 && str_contains($r['body'], 'You may not manage this document.'), 'a group member at edit may not trash it: 403 in words');
$r = post(27, '/documents/trash.php', ['document' => $acme, 'return_to' => '/f/' . $contracts]);
ok(in_array($r['code'], [200, 302], true) && one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $acme]), 'the owner trashes it (return_to honoured)');
$ev = last_event('document.trash');
ok($ev !== null && (int) $ev['document_id'] === $acme && (int) $ev['folder_id'] === $contracts && (int) $ev['library_id'] === (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $contracts]), 'document.trash logged with the document, the folder and the library');
ok(post(27, '/documents/trash.php', ['document' => $acme])['code'] === 422, 'trashing it again: 422');
$j = get_json(26, '/f/' . $contracts);
ok(!in_array('acme-msa.pdf', array_column($j['json']['data']['listing']['rows'] ?? $j['json']['data']['rows'] ?? [], 'name'), true), 'a group member no longer sees it in the folder');
ok(page(actor(26), '/documents/' . $acme)['code'] === 404, '...nor opens it (404)');
$j = get_json(27, '/trash/');
$row = null; foreach ($j['json']['data']['rows'] ?? [] as $x) { if ($x['kind'] === 'document' && (int) $x['id'] === $acme) { $row = $x; } }
ok($j['code'] === 200 && $row !== null && $row['name'] === 'acme-msa.pdf' && $row['status'] === 'secondary' && $row['due'] === false, 'the owner\'s trash lists it, status secondary');
ok($row !== null && abs(strtotime($row['purge_at']) - strtotime($row['trashed_at']) - 30 * 86400) < 5, '"purges on" is 30 days after');
ok($row !== null && (int) $row['from_folder_id'] === $contracts && $row['from_folder_name'] === 'Contracts', 'it remembers where it came from');
$j26 = get_json(26, '/trash/');
ok(!in_array($acme, array_column(array_filter($j26['json']['data']['rows'] ?? [], fn ($x) => $x['kind'] === 'document'), 'id'), true), 'Priya\'s trash does not list it');
$b = page(actor(27), '/trash/')['body'];
ok(str_contains($b, 'id="trash-table"') && str_contains($b, 'id="trash-row-document-' . $acme . '"') && str_contains($b, 'id="trash-restore-document-' . $acme . '"'), 'the page: the table, the row and its Restore button');
ok(!str_contains($b, 'id="trash-empty-btn"'), 'a curator has no "Empty what is due" (trash.purge is the admin\'s)');
$b = page(actor(27), '/documents/' . $acme)['body'];
ok(str_contains($b, 'id="document-trashed"') && str_contains($b, 'purges on') && str_contains($b, 'id="document-restore-btn"') && !str_contains($b, 'id="document-trash-btn"'), 'the document page: the banner "In the trash — purges on …" with Restore, no Trash button');
$b = page(actor(27), '/documents/' . $s['minutes'])['body'];
ok(str_contains($b, 'id="document-trash-btn"') && !str_contains($b, 'disabled title="The trash arrives'), 'a live document\'s Trash button is enabled now');
$r = post(26, '/trash/restore.php', ['document' => $acme]);
ok(in_array($r['code'], [403, 404], true) && one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $acme]), 'Priya cannot restore it');
$r = post_json(27, '/trash/restore.php', ['document' => $acme]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && (int) $r['json']['record_id'] === $acme && ($r['json']['to_root'] ?? null) === false && ($r['json']['renamed'] ?? null) === false, 'the owner restores it: ok, no move, no rename');
ok((int) one('SELECT folder_id FROM documents WHERE id = :d', ['d' => $acme]) === $contracts && one('SELECT trashed_at IS NULL FROM documents WHERE id = :d', ['d' => $acme]), 'it is back in its folder');
$ev = last_event('document.restore');
ok($ev !== null && (int) $ev['document_id'] === $acme && json_decode($ev['after'], true)['to_root'] === false, 'document.restore logged');
ok(post_json(27, '/trash/restore.php', ['document' => $acme])['code'] === 422, 'restoring what is not in the trash: 422');
$j = get_json(26, '/f/' . $contracts);
ok(in_array('acme-msa.pdf', array_column($j['json']['data']['listing']['rows'] ?? $j['json']['data']['rows'] ?? [], 'name'), true), 'the group sees it again');

echo "   restored after its folder was trashed → the library's top folder, with the notice\n";
$box = folder_in($boardRoot, 'T6 Box');
$dBox = seed_doc($box, 'in-box.pdf', 27, 'a document in the box');
post(27, '/documents/trash.php', ['document' => $dBox]);
post(27, '/folders/trash.php', ['folder' => $box]);
ok(one('SELECT trashed_at IS NOT NULL FROM folders WHERE id = :f', ['f' => $box]), 'the folder is trashed after the document');
$r = post_json(27, '/trash/restore.php', ['document' => $dBox]);
ok($r['code'] === 200 && ($r['json']['to_root'] ?? false) === true && (int) $r['json']['folder_id'] === $boardRoot && str_contains((string) $r['json']['location'], 'notice=restored_root'), 'its folder is gone: it lands at the library root and says so');
ok(str_contains($r['json']['did'] ?? '', 'top folder'), 'the sentence names the top folder');
$ev = last_event('document.restore');
ok(json_decode($ev['after'], true)['to_root'] === true, 'the log says to_root');
$b = page(actor(27), '/documents/' . $dBox . '?notice=restored_root')['body'];
ok(str_contains($b, 'Restored to the library&#039;s top folder') || str_contains($b, "Restored to the library's top folder"), 'the notice on the document page');
post(27, '/folders/restore.php', ['folder' => $box]);

echo "   a name already present → \"(2)\"\n";
$dup = seed_doc($boardRoot, 't6-dup.pdf', 27, 'first');
post(27, '/documents/trash.php', ['document' => $dup]);
$dup2 = seed_doc($boardRoot, 't6-dup.pdf', 27, 'second');
$r = post_json(27, '/trash/restore.php', ['document' => $dup]);
ok($r['code'] === 200 && ($r['json']['renamed'] ?? false) === true && $r['json']['name'] === 't6-dup (2).pdf', 'restored beside a live one of the same name: "t6-dup (2).pdf"');
ok((string) one('SELECT name FROM documents WHERE id = :d', ['d' => $dup]) === 't6-dup (2).pdf' && (string) one('SELECT name FROM documents WHERE id = :d', ['d' => $dup2]) === 't6-dup.pdf', 'the live one keeps its name');

echo "2. A folder trashed with its subtree\n";
$A = folder_in($boardRoot, 'T6 A'); $B = folder_in($A, 'T6 B');
$a1 = seed_doc($A, 'a1.pdf', 27, 'a1'); $b1 = seed_doc($B, 'b1.pdf', 27, 'b1'); $a2 = seed_doc($A, 'a2.pdf', 27, 'a2');
post(27, '/documents/trash.php', ['document' => $a2]);                   // trashed on its own first
sleep(1);
$r = post_json(27, '/folders/trash.php', ['folder' => $A]);
ok($r['code'] === 200 && (int) ($r['json']['folders'] ?? 0) === 2, 'the folder and its child are trashed (folders: 2)');
ok(one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $a1]) && one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $b1]), 'the documents under it went with it');
ok(page(actor(26), '/f/' . $A)['code'] === 404 && page(actor(26), '/f/' . $B)['code'] === 404, 'a group member loses the folder and its child (404)');
$j = get_json(27, '/trash/');
$kinds = array_map(fn ($x) => $x['kind'] . ':' . $x['name'], $j['json']['data']['rows'] ?? []);
ok(in_array('folder:T6 A', $kinds, true) && !in_array('folder:T6 B', $kinds, true), 'the trash lists the top of the subtree only');
ok(!in_array('document:a1.pdf', $kinds, true) && !in_array('document:b1.pdf', $kinds, true), 'documents trashed with the folder are not rows of their own');
ok(in_array('document:a2.pdf', $kinds, true), 'a document trashed on its own earlier is a row');
$j = get_json(27, '/f/' . $boardRoot . '/trash');
$kinds = array_map(fn ($x) => $x['kind'] . ':' . $x['name'], $j['json']['data']['rows'] ?? []);
ok($j['code'] === 200 && in_array('folder:T6 A', $kinds, true) && ($j['json']['data']['folder']['folder_id'] ?? 0) === $boardRoot, 'the folder\'s Trash tab (/f/{id}/trash) lists what was trashed from it');
ok(get_json(26, '/f/' . $boardRoot . '/trash')['code'] === 403, 'the tab is for managers: a member at edit gets 403');
$b = page(actor(27), '/f/' . $boardRoot)['body'];
ok(str_contains($b, 'id="folder-trash-tab"'), 'the folder page links to its Trash tab');
$r = post_json(27, '/folders/restore.php', ['folder' => $A]);
ok($r['code'] === 200 && (int) ($r['json']['folders'] ?? 0) === 2, 'the folder is restored whole (2 folders)');
ok(one('SELECT trashed_at IS NULL FROM documents WHERE id = :d', ['d' => $a1]) && one('SELECT trashed_at IS NULL FROM documents WHERE id = :d', ['d' => $b1]), 'its documents are back');
ok(one('SELECT trashed_at IS NOT NULL FROM documents WHERE id = :d', ['d' => $a2]), 'the one trashed on its own earlier stays in the trash');
ok(page(actor(26), '/f/' . $A)['code'] === 200, 'a group member sees the folder again');

echo "3. The purge\n";
$p1 = real_doc(27, $boardRoot, 'p6-one.pdf', 'purge-one');
$p2 = real_doc(27, $boardRoot, 'p6-held.pdf', 'purge-held');
$p3 = real_doc(27, $boardRoot, 'p6-copy.pdf', 'purge-held');                  // the same bytes: one blob, two holders
[$f1, $blob1] = blob_of($p1); [$f2, $blob2] = blob_of($p2);
ok($p1 > 0 && file_here($f1) && file_here($f2) && $blob2 === blob_of($p3)[1], 'three uploads on disk; two share one blob (' . (int) one('SELECT ref_count FROM blobs WHERE id = :b', ['b' => $blob2]) . ' holders)');
// a graph row so the purge queues the engine's forget
$mid = 'dc-doc-' . $p1;
pdo()->prepare("INSERT INTO graph_documents (document_id, version_id, maludb_document_id, policy) VALUES (:d, (SELECT current_version_id FROM documents WHERE id = :d), :m, 'index') ON CONFLICT DO NOTHING")->execute(['d' => $p1, 'm' => $mid]);
foreach ([$p1, $p2] as $d) { post(27, '/documents/trash.php', ['document' => $d]); age_trash($d, 29); }
$r = worker('trash_purge');
ok(exists_doc($p1) && exists_doc($p2) && ($r['ran']['trash_purge']['purged'] ?? -1) === 0, 'at 29 days nothing is purged by the pass');
foreach ([$p1, $p2] as $d) { age_trash($d, 31); }
$r = worker('trash_purge');
ok(!exists_doc($p1) && !exists_doc($p2) && ($r['ran']['trash_purge']['purged'] ?? 0) >= 2, 'at 31 days the pass purges both (' . ($r['ran']['trash_purge']['purged'] ?? '?') . ')');
$fg = q("SELECT * FROM graph_jobs WHERE kind = 'forget' AND maludb_document_id = :m", ['m' => $mid]);
ok(count($fg) >= 1 && (int) one('SELECT count(*) FROM graph_documents WHERE document_id = :d', ['d' => $p1]) === 0, 'the engine\'s forget is queued for the purged document (queued with the trash, kept through the purge)');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'document.purge' AND source = 'cron' AND document_id = :d", ['d' => $p1]) === 1, 'document.purge logged by the pass (source cron)');
ok(file_here($f1) && (int) one('SELECT ref_count FROM blobs WHERE id = :b', ['b' => $blob1]) === 0, 'the bytes stay until blobs_free runs (ref_count 0)');
ok((int) one('SELECT ref_count FROM blobs WHERE id = :b', ['b' => $blob2]) === 1, 'the shared blob is still held by the copy');
$r = worker('blobs_free');
ok(($r['ran']['blobs_free']['freed'] ?? -1) === 0 && file_here($f1), 'blobs_free leaves a blob younger than an hour');
age_blobs();
$r = worker('blobs_free');
ok(($r['ran']['blobs_free']['freed'] ?? 0) >= 1 && !file_here($f1), 'blobs_free unlinks the freed bytes' . (file_here($f1) ? ' [' . json_encode($r) . ' ' . $f1 . ']' : ''));
ok(file_here($f2) && one('SELECT deleted_at IS NULL FROM blobs WHERE id = :b', ['b' => $blob2]), 'the blob still held elsewhere is kept');
ok(one('SELECT deleted_at IS NOT NULL FROM blobs WHERE id = :b', ['b' => $blob1]), 'the freed blob is marked deleted');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'blob.free' AND source = 'cron'") >= 1, 'blob.free logged');
$r = worker('blobs_free');
ok(($r['ran']['blobs_free']['freed'] ?? -1) === 0, 'a second run frees nothing (idempotent)');
@unlink($f2 . '.x');

echo "   a missing file is fine\n";
$p4 = real_doc(27, $boardRoot, 'p6-gone.pdf', 'purge-gone');
[$f4, ] = blob_of($p4); unlink($f4); clearstatcache();
post(27, '/documents/trash.php', ['document' => $p4]); age_trash($p4, 40); worker('trash_purge'); age_blobs();
$r = worker('blobs_free');
ok(empty($r['errors']) && ($r['ran']['blobs_free']['freed'] ?? 0) >= 1, 'blobs_free with the file already gone: no error');

echo "   document_purge by the admin\n";
$q1 = real_doc(27, $boardRoot, 'p6-admin.pdf', 'purge-admin');
$r = post(1, '/trash/purge.php', ['document' => $q1, 'confirm' => 'yes']);
ok($r['code'] === 422 && exists_doc($q1), 'purging a live document: 422');
post(27, '/documents/trash.php', ['document' => $q1]);
$r = post(27, '/trash/purge.php', ['document' => $q1, 'confirm' => 'yes']);
ok($r['code'] === 403 && exists_doc($q1), 'the curator (no trash.purge) is refused');
$r = post_json(1, '/trash/purge.php', ['document' => $q1]);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['confirm']) && exists_doc($q1), 'without confirm: a field error, nothing purged');
$r = post_json(1, '/trash/purge.php', ['document' => $q1, 'confirm' => 'yes']);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) && !exists_doc($q1), 'the admin purges it now');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'document.purge' AND source <> 'cron' AND document_id = :d", ['d' => $q1]) === 1, 'document.purge logged (the admin)');

echo "   trash_purge: due only, then all\n";
$d1 = seed_doc($boardRoot, 'tp-old.pdf', 27, 'old'); $d2 = seed_doc($boardRoot, 'tp-new.pdf', 27, 'new'); $d3 = seed_doc($boardRoot, 'tp-mid.pdf', 27, 'mid');
foreach ([$d1, $d2, $d3] as $d) { post(27, '/documents/trash.php', ['document' => $d]); }
age_trash($d1, 31); age_trash($d3, 10);
$r = post_json(27, '/trash/purge-all.php', ['confirm' => 'yes']);
ok($r['code'] === 403, 'trash_purge is the admin\'s: 403 for a curator');
$r = post_json(1, '/trash/purge-all.php', []);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['confirm']), 'without confirm: a field error');
$r = post_json(1, '/trash/purge-all.php', ['confirm' => 'yes']);
ok($r['code'] === 200 && ($r['json']['documents'] ?? 0) >= 1 && !exists_doc($d1) && exists_doc($d2) && exists_doc($d3), 'due only: the 31-day-old is gone, the others stay (' . ($r['json']['did'] ?? '') . ')');
$r = post_json(1, '/trash/purge-all.php', ['confirm' => 'yes', 'due_only' => 'no', 'older_than_days' => 5]);
ok($r['code'] === 200 && !exists_doc($d3) && exists_doc($d2), 'older_than_days=5 takes the 10-day-old, not the fresh one');
$r = post_json(1, '/trash/purge-all.php', ['confirm' => 'yes', 'due_only' => 'no']);
ok($r['code'] === 200 && !exists_doc($d2), 'due_only=no empties everything');
ok(post_json(1, '/trash/purge-all.php', ['confirm' => 'yes', 'older_than_days' => 0])['code'] === 422, 'older_than_days outside 1–3650: 422');
$ev = last_event('trash.purge');
ok($ev !== null && json_decode($ev['after'], true)['due_only'] === false, 'trash.purge logged with its parameters');
$d4 = seed_doc($boardRoot, 'tp-lib.pdf', 27, 'lib'); $d5 = seed_doc($s['pol_root'], 'tp-other.pdf', 27, 'other');
foreach ([$d4, $d5] as $d) { post(27, '/documents/trash.php', ['document' => $d]); }
$lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $boardRoot]);
post_json(1, '/trash/purge-all.php', ['confirm' => 'yes', 'due_only' => 'no', 'library' => $lib]);
ok(!exists_doc($d4) && exists_doc($d5), 'the library parameter narrows it');
post_json(1, '/trash/purge-all.php', ['confirm' => 'yes', 'due_only' => 'no']);

echo "   folder_delete (a trashed folder, now)\n";
$F = folder_in($boardRoot, 'T6 F'); $G = folder_in($F, 'T6 G');
$f1d = real_doc(27, $F, 'f1.pdf', 'fd1'); $g1d = real_doc(27, $G, 'g1.pdf', 'fd2');
$r = post_json(1, '/trash/delete.php', ['folder' => $F, 'confirm_name' => 'T6 F']);
ok($r['code'] === 422 && exists_doc($f1d), 'a live folder is not deleted for good: 422');
post(27, '/folders/trash.php', ['folder' => $F]);
$r = post_json(1, '/trash/delete.php', ['folder' => $F, 'confirm_name' => 'wrong']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['confirm_name']) && exists_doc($f1d), 'the folder\'s name must be typed');
$r = post_json(27, '/trash/delete.php', ['folder' => $F, 'confirm_name' => 'T6 F']);
ok($r['code'] === 403, 'the curator is refused');
$r = post_json(1, '/trash/delete.php', ['folder' => $F, 'confirm_name' => 'T6 F']);
ok($r['code'] === 200 && (int) ($r['json']['documents'] ?? 0) === 2 && (int) ($r['json']['folders'] ?? 0) === 2, 'the admin deletes it: 2 documents, 2 folders');
ok(!exists_doc($f1d) && !exists_doc($g1d) && (int) one('SELECT count(*) FROM folders WHERE id IN (:a, :b)', ['a' => $F, 'b' => $G]) === 0, 'all of it is gone');
ok(last_event('folder.delete') !== null, 'folder.delete logged');

echo "   an agent\n";
$p9 = seed_doc($boardRoot, 'ag-1.pdf', 27, 'agent doc');
post(27, '/documents/trash.php', ['document' => $p9]);
$r = post_agent(40, 6101, '/trash/purge.php', ['document' => $p9, 'confirm' => 'yes']);
ok($r['code'] === 403 && exists_doc($p9), 'an agent without trash.purge cannot purge (403)');
$r = post_agent(40, 6102, '/trash/purge-all.php', ['confirm' => 'yes', 'due_only' => 'no']);
ok($r['code'] === 403, '...nor empty the trash');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$cats = []; foreach ($reg['actions'] as $x) { if (in_array($x['action'], ['document_purge', 'folder_delete', 'trash_purge', 'document_trash', 'document_restore'], true)) { $cats[$x['action']] = $x['approval'] ?? $x['agent_approval'] ?? ''; } }
ksort($cats);
ok($cats === ['document_purge' => 'deletion', 'document_restore' => '', 'document_trash' => '', 'folder_delete' => 'deletion', 'trash_purge' => 'deletion'], 'the registry pauses the purges (deletion) and lets the trash and restore through');
$r = post_agent(40, 6103, '/documents/trash.php', ['document' => $s['minutes']]);
ok($r['code'] === 403, 'an agent at edit (not manage) may not trash: the same gate as a person');
finish();
