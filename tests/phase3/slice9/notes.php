<?php
/**
 * Slice 9 proof 4 — comments (notes) on a document: a viewer adds one and the owner is told; the walls (a person who cannot see the document: 404; the guest on the document shared with them: allowed); the author deletes
 * theirs, a manager deletes another's, a viewer cannot; refusals in words; the page's composer and delete buttons per person; nothing of the comment in the log; an agent comments under its run token.
 */
require __DIR__ . '/lib.php';
$w = world9();
$p = pdo();
$doc = $w['board_doc'];             // Marco's acme-msa.pdf in the Board: Priya and Seamus view it, Bea / Dana / Ann cannot
$guestDoc = $w['guest_doc'];        // Marco's for-ann.pdf: shared with Ann (view)
$notes = static fn (int $id, string $kind = 'document'): array => q('SELECT * FROM notes WHERE record_type = :t AND record_id = :i ORDER BY id', ['t' => $kind, 'i' => $id]);
$add = static fn (int $m, array $f) => post_json($m, '/notes/add.php', $f);

echo "1. A viewer comments; the owner is told\n";
$since = last_activity_id();
$n0 = (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'");
$x = $add(26, ['document' => $doc, 'body' => 'Please check clause 4 before Friday. ' . WORLD_SECRET]);
$nid = (int) ($x['json']['record_id'] ?? 0);
ok($x['code'] === 200 && ($x['json']['ok'] ?? false) && $nid > 0 && $x['json']['undo'] === 'note_delete', 'Priya (view on the document) comments: ok, record_id = the note, undo = note_delete');
$row = q('SELECT * FROM notes WHERE id = :i', ['i' => $nid])[0] ?? [];
ok(($row['record_type'] ?? '') === 'document' && (int) $row['record_id'] === $doc && (int) $row['member_id'] === 26 && str_starts_with($row['body'], 'Please check clause 4'), 'the notes row: document, hers, her words');
$b = bell(27, 'comment');
ok(count($b) === $n0 + 1 && str_contains($b[0]['title'], 'SMOKE Priya commented on acme-msa.pdf') && (int) $b[0]['document_id'] === $doc && (int) $b[0]['actor_member_id'] === 26, 'Marco, the owner, is told: "SMOKE Priya commented on acme-msa.pdf"');
$a = activity('note.add', $since);
$aft = json_decode((string) ($a[0]['after'] ?? '{}'), true);
ok(count($a) === 1 && (int) $a[0]['actor_member_id'] === 26 && (int) $a[0]['document_id'] === $doc && $aft['note_id'] === $nid && $aft['chars'] > 20, 'logged note.add: by Priya, on the document, with the note id and its length');
ok(!str_contains((string) $a[0]['after'], 'clause 4') && !str_contains((string) $a[0]['after'], WORLD_SECRET), '...and nothing of what she wrote');
$h = page(actor(27), '/documents/' . $doc)['body'];
ok(str_contains($h, 'id="note-' . $nid . '"') && str_contains($h, 'Please check clause 4') && str_contains($h, 'id="comments-list"') && str_contains($h, 'id="comment-form"') && str_contains($h, 'id="comment-body"') && str_contains($h, 'id="comment-add-btn"'), 'the document page: the comment, the list, the composer');
$j = get_json(27, '/documents/' . $doc)['json']['data'];
ok($j['notes'][0]['note_id'] === $nid && $j['notes'][0]['author'] === 'SMOKE Priya' && $j['may']['comment'] === true, 'the JSON carries the note with its author, and may.comment');

echo "2. The owner commenting on their own document is told nothing\n";
$n1 = (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'");
$x = $add(27, ['document' => $doc, 'body' => 'Noted — I will look at clause 4.']);
$mine = (int) $x['json']['record_id'];
ok($x['code'] === 200 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'") === $n1, 'Marco comments on his own document: no notice to himself');

echo "3. The walls\n";
foreach ([28 => 'Bea', 30 => 'Dana', 29 => 'Ann (the guest)'] as $m => $who) {
    $x = $add($m, ['document' => $doc, 'body' => 'let me in']);
    ok($x['code'] === 404 && str_contains($x['body'], 'Not found.'), "$who cannot see the document, so cannot comment: \"Not found.\" (404)");
}
ok(count($notes($doc)) === 2, 'no comment was left by any of them');
$x = $add(29, ['document' => $guestDoc, 'body' => 'Thank you — received.']);
$annNote = (int) ($x['json']['record_id'] ?? 0);
ok($x['code'] === 200 && $annNote > 0, 'Ann, a guest, comments on the document shared with her');
$b = bell(27, 'comment');
ok(str_contains($b[0]['title'], 'SMOKE Ann commented on for-ann.pdf'), '...and Marco is told');
$h = page(actor(29), '/documents/' . $guestDoc)['body'];
ok(str_contains($h, 'id="comment-form"') && str_contains($h, 'id="note-' . $annNote . '"') && str_contains($h, 'id="note-delete-' . $annNote . '"'), 'her page has the composer, her comment and its delete button');
$x = $add(26, ['document' => $guestDoc, 'body' => 'not mine to see']);
ok($x['code'] === 404, 'Priya cannot comment on a document that is not hers to see');
$x = req('POST', '/notes/add.php', ['headers' => ['Accept: application/json'], 'form' => ['document' => $doc, 'body' => 'x']]);
ok($x['code'] === 401, 'no session: 401');
$j26 = actor(26);
$x = req('POST', '/notes/add.php', ['jar' => $j26, 'form' => ['document' => $doc, 'body' => 'no token']]);
ok($x['code'] === 403, 'a browser POST without the CSRF token: 403');

echo "4. Refusals, in words\n";
$x = $add(26, ['document' => $doc, 'body' => '   ']);
ok($x['code'] === 422 && isset($x['json']['error']['fields']['body']) && str_contains($x['json']['error']['fields']['body'], 'Write the comment'), 'an empty comment: 422 on the field');
$x = $add(26, ['document' => $doc, 'body' => str_repeat('x', 4001)]);
ok($x['code'] === 422 && str_contains($x['json']['error']['fields']['body'], '4000'), 'over 4000 characters: 422 "at most 4000"');
$x = $add(26, ['document' => $doc, 'body' => str_repeat('é', 4000)]);
ok($x['code'] === 200, '4000 characters (multibyte) is allowed');
$x = $add(26, ['body' => 'on what?']);
ok($x['code'] === 422 && isset($x['json']['error']['fields']['document']), 'neither a document nor a folder named: 422');
$x = $add(26, ['document' => $doc, 'folder' => $w['contracts'], 'body' => 'both']);
ok($x['code'] === 422, 'both named: 422');
$x = $add(26, ['document' => 99999999, 'body' => 'ghost']);
ok($x['code'] === 404, 'a document that does not exist: 404');
$tr = seed_doc($w['contracts'], 'w9-trashed.pdf', 27, 'To the trash. ' . WORLD_SECRET);
$p->exec("UPDATE documents SET trashed_at = now(), trashed_by = 27, trashed_from_folder_id = folder_id WHERE id = $tr");
$x = $add(27, ['document' => $tr, 'body' => 'from the grave']);
ok($x['code'] === 422 && str_contains($x['body'], 'trash'), 'a document in the trash: 422');
ok(!str_contains(page(actor(27), '/documents/' . $tr)['body'], 'id="comment-form"'), '...and its page has no composer');

echo "5. A folder takes comments too\n";
$n2 = (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'");
$x = $add(26, ['folder' => $w['contracts'], 'body' => 'Is this folder complete?']);
$fn = (int) ($x['json']['record_id'] ?? 0);
ok($x['code'] === 200 && count($notes($w['contracts'], 'folder')) === 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment'") === $n2 + 1, 'Priya comments on the Contracts folder: the note, and its owner told');
ok($add(28, ['folder' => $w['contracts'], 'body' => 'x'])['code'] === 404, 'Bea cannot (she cannot see it): 404');

echo "6. An agent comments under its run token\n";
$since = last_activity_id();
$x = post_agent(EXPERT, 9301, '/notes/add.php', ['document' => $doc, 'body' => 'I have tagged this document.']);
$an = (int) ($x['json']['record_id'] ?? 0);
ok($x['code'] === 200 && $an > 0, 'Seamus (the expert, a Board member) comments on the document');
$a = activity('note.add', $since);
ok(count($a) === 1 && $a[0]['source'] === 'agent' && (int) $a[0]['actor_member_id'] === EXPERT, '...logged with source agent');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = " . EXPERT . " AND kind = 'comment'") === 0, 'an agent is never told');
$x = post_agent(41, 9302, '/notes/add.php', ['document' => $doc, 'body' => 'the Watcher']);
ok(in_array($x['code'], [401, 403], true), 'the Watcher (an agent the kernel has not admitted): refused');

echo "7. Delete: the author, a manager, a viewer\n";
$x = post_json(26, '/notes/delete.php', ['note' => $mine]);
ok($x['code'] === 403 && str_contains($x['body'], 'author'), 'Priya, a viewer, cannot delete Marco\'s comment: 403 "Only the author … or a manager"');
$x = post_json(28, '/notes/delete.php', ['note' => $mine]);
ok($x['code'] === 404, 'Bea cannot even see it: 404');
$x = post_json(29, '/notes/delete.php', ['note' => $nid]);
ok($x['code'] === 404, 'nor can Ann: 404');
$h = page(actor(26), '/documents/' . $doc)['body'];
ok(str_contains($h, 'id="note-delete-' . $nid . '"') && !str_contains($h, 'id="note-delete-' . $mine . '"') && !str_contains($h, 'id="note-delete-' . $an . '"'), 'Priya\'s page offers delete on her own comment only');
$h = page(actor(27), '/documents/' . $doc)['body'];
ok(str_contains($h, 'id="note-delete-' . $nid . '"') && str_contains($h, 'id="note-delete-' . $mine . '"') && str_contains($h, 'id="note-delete-' . $an . '"'), '...Marco, who manages it, may delete every one');
$since = last_activity_id();
$x = post_json(26, '/notes/delete.php', ['note' => $nid]);
ok($x['code'] === 200 && $x['json']['record_id'] === $nid && $notes($doc) !== [] && !in_array($nid, array_column($notes($doc), 'id'), true), 'the author deletes hers');
$a = activity('note.delete', $since);
$aft = json_decode((string) ($a[0]['after'] ?? '{}'), true);
ok(count($a) === 1 && (int) $a[0]['actor_member_id'] === 26 && $aft['note_id'] === $nid && $aft['by_author'] === true && (int) $a[0]['document_id'] === $doc, 'logged note.delete: by the author');
$x = post_json(27, '/notes/delete.php', ['note' => $an]);
ok($x['code'] === 200 && !in_array($an, array_column($notes($doc), 'id'), true), 'a manager (Marco) deletes the agent\'s');
$a = activity('note.delete', $since);
ok(count($a) === 2 && json_decode((string) $a[1]['after'], true)['by_author'] === false, '...logged as not by the author');
$x = post_json(29, '/notes/delete.php', ['note' => $annNote]);
ok($x['code'] === 200, 'Ann deletes her own comment');
$x = post_json(1, '/notes/delete.php', ['note' => $fn]);
ok($x['code'] === 200 && count($notes($w['contracts'], 'folder')) === 0, 'the admin (a manager of everything) deletes the folder comment');
ok(post_json(27, '/notes/delete.php', ['note' => $nid])['code'] === 404 && post_json(27, '/notes/delete.php', ['note' => 99999999])['code'] === 404, 'deleting a comment that is gone: 404');
ok(post_json(27, '/notes/delete.php', [])['code'] === 422, 'no comment named: 422');
ok(req('POST', '/notes/delete.php', ['headers' => ['Accept: application/json'], 'form' => ['note' => $mine]])['code'] === 401, 'no session: 401');

echo "8. From a browser\n";
$x = post(26, '/notes/add.php', ['document' => $doc, 'body' => 'From the page.', 'return_to' => '/documents/' . $doc]);
ok(in_array($x['code'], [302, 303], true) && str_contains($x['location'], '/documents/' . $doc) && str_contains($x['location'], 'notice=commented'), 'the form posts and lands on the document with a notice');
$h = page(actor(26), '/documents/' . $doc . '?notice=commented')['body'];
ok(str_contains($h, 'Your comment was added.') && str_contains($h, 'From the page.'), '...which shows the notice and the comment');
$last = (int) one('SELECT max(id) FROM notes WHERE record_id = :d', ['d' => $doc]);
$x = post(26, '/notes/delete.php', ['note' => $last, 'return_to' => '/documents/' . $doc]);
ok(in_array($x['code'], [302, 303], true) && str_contains($x['location'], 'notice=comment_deleted'), 'and its delete button');
ok(str_contains(page(actor(26), '/documents/' . $doc . '?notice=comment_deleted')['body'], 'The comment was deleted.'), '...with its notice');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$by = []; foreach ($reg['actions'] as $a2) { $by[$a2['action']] = $a2; }
ok(($by['note_add']['approval'] ?? '') === '' && ($by['note_delete']['approval'] ?? '') === '' && $by['note_add']['agent'] === true && $by['note_add']['log_event'] === 'note.add' && $by['note_delete']['log_event'] === 'note.delete', 'the registry: note_add and note_delete run unpaused, for agents too');
$since = last_activity_id(); $x = post(26, '/notes/add.php', ['document' => $doc, 'body' => 'Count me.']);
ok($x['code'] !== 500 && count(activity('note.add', $since)) === 1, 'every comment added is exactly one row in the log');
finish();
