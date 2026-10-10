<?php
/**
 * Proof — access, shares and links (A1-A7): access_list, access_explain, my_level, shared_with_me, my_shares, stale_guest_shares, find_links, links_expiring, links_suspicious, find_requests, link_opens, access_history, access_changes.
 * Who can see a thing is a MANAGER's question (managers_only for one who sees it but does not manage it, not_found for one who cannot see it); what a person may do is anyone's. Never a token, a hash, a URL.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$nda = $w['nda']; $fold = $w['legal_contracts'];

echo "1. access_list, access_explain, my_level\n";
$al = tdata(27, 'access_list', ['folder' => $fold]);
$kinds = array_column($al['rows'], 'principal_kind');
sort($kinds);
ok($al['thing']['name'] === 'p4 Contracts' && $al['owner']['display_name'] === 'SMOKE Marco' && $al['library']['base'] === ['principal' => 'group', 'principal_name' => 'SMOKE p4 Legal', 'level' => 'edit'], 'access_list: the folder, its owner, the library\'s base line (the group at edit)');
ok($kinds === ['application', 'group'] && find_row($al['rows'], 'principal_kind', 'application')['principal_ref'] === 'knowledge' && find_row($al['rows'], 'principal_kind', 'group')['level'] === 'edit', 'the explicit rows: the group at edit, the application knowledge at view');
ok($al['rows'][0]['granted_by_name'] === 'SMOKE Marco' && $al['rows'][0]['inherited'] === false && $al['rows'][0]['is_guest'] === false, 'each row: who granted it, not inherited, not a guest');
$doc = tdata(27, 'access_list', ['document' => $nda]);
ok($doc['inherited_from']['folder_id'] === $fold && $doc['rows'][0]['inherited'] === true, 'a document with no rows of its own inherits its folder\'s: said so, the rows greyed inherited');
$own = tdata(27, 'access_list', ['document' => $w['own_row']]);
ok(count($own['rows']) === 1 && $own['rows'][0]['principal_name'] === 'the application knowledge' && $own['inherited_from'] === null, 'a document with a row of its own lists only that');
$sub = folder_in($fold, 'p4 Sub', 27);
$ch = tdata(27, 'access_list', ['folder' => $sub]);
ok($ch['inherited_from']['folder_id'] === $fold && $ch['rows'][0]['inherited'] === true, 'a subfolder shows the access it inherits, from where');
ok(tool(26, 'access_list', ['folder' => $fold])['code'] === 'managers_only', 'Priya sees the folder but does not manage it: managers_only');
ok(tool(30, 'access_list', ['folder' => $fold])['code'] === 'not_found' && tool(29, 'access_list', ['document' => $nda])['code'] === 'not_found', 'Dana and Ann cannot see it at all: not_found');
ok(tool(27, 'access_list', ['folder' => $fold, 'document' => $nda])['code'] === 'invalid_argument' && tool(27, 'access_list')['code'] === 'invalid_argument', 'one of document or folder, not both and not neither');
ok(tdata(1, 'access_list', ['document' => $nda])['thing']['id'] === $nda, 'the admin manages everything');
$ex = tdata(26, 'access_explain', ['document' => $nda]);
ok($ex['level'] === 'edit' && $ex['member']['display_name'] === 'SMOKE Priya' && in_array('library', array_column($ex['reasons'], 'reason'), true) && in_array('folder', array_column($ex['reasons'], 'reason'), true), 'access_explain for oneself: edit, because of the library\'s group and the folder\'s row');
$ex = tdata(27, 'access_explain', ['document' => $nda, 'member' => 'SMOKE Priya']);
ok($ex['member']['member_id'] === 26 && $ex['level'] === 'edit', 'a manager asks about Priya');
$ex = tdata(27, 'access_explain', ['document' => $nda, 'member' => 'SMOKE Dana']);
ok($ex['level'] === 'none' && $ex['reasons'] === [], 'about Dana: none, no reasons');
ok(tool(26, 'access_explain', ['document' => $nda, 'member' => 'SMOKE Dana'])['code'] === 'managers_only', 'Priya may not ask about another person');
$ex = tdata(27, 'access_explain', ['document' => $nda]);
ok($ex['level'] === 'manage' && $ex['reasons'][0]['reason'] === 'owner', 'the owner: manage, "owner"');
$ml = tdata(26, 'my_level', ['document' => $nda]);
ok($ml['level'] === 'edit' && $ml['can'] === ['view' => true, 'add' => true, 'edit' => true, 'manage' => false], 'my_level: edit — view, add, edit, not manage');
ok(tdata(30, 'my_level', ['document' => $nda])['level'] === 'none' && tdata(30, 'my_level', ['document' => 987654])['level'] === 'none', 'none is an answer — for a document one may not see and one that does not exist');
ok(tdata(26, 'my_level', ['folder' => $fold])['level'] === 'edit' && tdata(1, 'my_level', ['folder' => 'p4 Contracts'])['level'] === 'manage', 'a folder by id, or by name');
ok(tdata(29, 'my_level', ['document' => $w['guest_doc']])['level'] === 'view', 'Ann: view on the one document named to her');

echo "2. shared_with_me, my_shares, stale_guest_shares\n";
$sw = tdata(26, 'shared_with_me');
ok(in_array('w9-shared-new.pdf', array_column($sw, 'name'), true) && find_row($sw, 'name', 'w9-shared-new.pdf')['shared_by_name'] === 'SMOKE Marco' && find_row($sw, 'name', 'w9-shared-new.pdf')['via'] === 'member', 'Priya: what Marco shared with her, who by, how');
ok(array_unique(array_column(tdata(26, 'shared_with_me', ['kind' => 'document']), 'kind')) === ['document'], 'kind filters');
ok(tdata(30, 'shared_with_me') === [] || !in_array('w9-shared-new.pdf', array_column(tdata(30, 'shared_with_me'), 'name'), true), 'Dana has nothing shared that is Priya\'s');
$ms = tdata(27, 'my_shares');
ok(count($ms) >= 3 && find_row($ms, 'principal_ref', 'knowledge') !== null, 'my_shares: Marco\'s shares out, the application among them');
$gs = tdata(27, 'my_shares', ['guests_only' => true]);
ok(count($gs) >= 1 && array_unique(array_column($gs, 'is_guest')) === [true], 'guests_only: those to the guest Ann');
$st = tdata(27, 'stale_guest_shares');
ok(count($st) === 1 && $st[0]['guest_name'] === 'SMOKE Ann' && $st[0]['name'] === 'W8 Client', 'stale_guest_shares: Ann on W8 Client, 100 days old');
ok(tdata(27, 'stale_guest_shares', ['days' => 200]) === [] && tdata(26, 'stale_guest_shares') === [], 'a longer window finds none; Priya manages none of them');

echo "3. links and requests\n";
$fl = tdata(27, 'find_links', ['document' => $w['board_doc']]);
ok(count($fl) >= 1 && find_row($fl, 'label', 'w8 expiring link') !== null && !has_key($fl, 'token') && !has_key($fl, 'token_hash') && !has_key($fl, 'password_hash'), 'find_links on a document: the expiring link — never a token or a hash');
$row = find_row($fl, 'label', 'w8 expiring link');
ok($row['made_by_name'] === 'SMOKE Marco' && $row['has_password'] === false && $row['kind'] === 'view' && $row['revoked_at'] === null, 'its facts: who made it, no password, a view link');
$r1 = tdata(27, 'find_links', ['q' => 'expiring']);
ok(array_keys($r1[0]) === ['link_id', 'label', 'kind'], 'q alone resolves a link by label');
ok(count(tdata(26, 'find_links', ['mine' => true])) >= 2 && count(tdata(30, 'find_links')) === 0, 'mine: Priya\'s links; Dana has none');
ok(count(tdata(26, 'find_links', ['document' => $w['board_doc']])) === 0, 'Priya does not manage the Board\'s contract: she sees none of its links');
$ex = tdata(27, 'links_expiring', ['days' => 7]);
ok(ids($ex, 'label') === ['w8 expiring link'] && count(tdata(27, 'links_expiring', ['days' => 1])) === 0, 'links_expiring: the one ending in 3 days');
ok(in_array('w9 priya ending link', ids(tdata(26, 'links_expiring'), 'label'), true), 'Priya\'s own ending link');
$su = tdata(1, 'links_suspicious');
ok(ids($su, 'label') === ['w8 widely opened'] && $su[0]['addresses'] === 12 && $su[0]['opens'] === 12, 'links_suspicious (links.all): the one opened from 12 addresses');
ok(tool(27, 'links_suspicious')['code'] === 'managers_only', 'a curator without links.all: managers_only');
$rq = tdata(27, 'find_requests');
ok(count($rq) >= 2 && array_unique(array_column($rq, 'kind')) === ['request'] && find_row($rq, 'label', 'p4 legal request') !== null, 'find_requests: the open file requests');
ok(ids(tdata(27, 'find_requests', ['folder' => $fold]), 'label') === ['p4 legal request'] && ids(tdata(27, 'find_requests', ['q' => 'legal']), 'label') === ['p4 legal request'], 'by folder; q alone resolves one by label');
ok(count(tdata(30, 'find_requests')) === 0, 'Dana sees none');
$lo = tdata(1, 'link_opens', ['link' => $w['wide_link']]);
ok(count($lo) === 12 && strlen($lo[0]['address_key']) === 8 && $lo[0]['what'] === 'view' && !has_key($lo, 'address_hash'), 'link_opens: 12 opens, an address as 8 characters');
ok(tool(30, 'link_opens', ['link' => $w['wide_link']])['code'] === 'not_found', 'Dana: not_found');
ok(count(tdata(1, 'link_opens', ['link' => $w['wide_link'], 'since' => '2999-01-01'])) === 0, 'since');

echo "4. access_history and access_changes\n";
q("SELECT set_config('app.member_id', '27', false)");
$ac = tdata(27, 'access_changes', ['folder' => $fold]);
ok(count($ac) >= 1 && isset($ac[0]['principal_name']) && isset($ac[0]['new_level']) && isset($ac[0]['changed_at']), 'access_changes: the trail on the folder (who was given what)');
ok(tool(26, 'access_changes', ['folder' => $fold])['code'] === 'managers_only' || tdata(26, 'access_changes', ['folder' => $fold]) === [], 'Priya does not manage it: nothing, or managers_only');
$ah = tdata(27, 'access_history', ['folder' => $fold, 'at' => gmdate('c', time() + 60)]);
ok(isset($ah['rows']) && str_contains($ah['note'], 'explicit rows only') && count($ah['rows']) >= 1, 'access_history: the explicit rows on a date, with the note');
ok(tdata(27, 'access_history', ['folder' => $fold, 'at' => '2020-01-01'])['rows'] === [], 'before it existed: nothing');
ok(tool(26, 'access_history', ['folder' => $fold, 'at' => '2026-10-10'])['code'] === 'managers_only', 'Priya: managers_only');
ok(tool(27, 'access_history', ['folder' => $fold, 'at' => 'last tuesday'])['code'] === 'invalid_argument', 'a time that is not a time: invalid_argument');
finish();
