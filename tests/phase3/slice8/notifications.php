<?php
/**
 * Slice 8 proof 5 — notifications: the `notification` action (the Clerk's notice to an owner: members only, once a week for a Monday note, once a day for a proposal, 60 an hour), a once-a-week rule that
 * holds for a person who is not mailed (the bell row is the dedupe), no notification for an agent, the bell and its count, mark all read, the kinds' icons and links, and an agent refused the bell.
 */
require __DIR__ . '/lib.php';
$w = world();
$p = pdo();
$run = 8500;
$send = function (array $f, int $as = CLERK) use (&$run): array { return $as === CLERK || $as === EXPERT ? post_agent($as, $run++, '/agents/notify.php', $f) : post_json($as, '/agents/notify.php', $f); };

echo "1. The Clerk tells a person\n";
$since = last_activity_id();
$m26 = count(bell(26, 'monday_note')); $m28 = count(bell(28, 'monday_note'));
$x = $send(['member' => 26, 'kind' => 'monday_note', 'title' => 'Monday note: your files', 'body' => "Three things need a hand:\n\n- 2 × Suggested tags\n- 1 × Same file"]);
ok($x['code'] === 200 && ($x['json']['sent'] ?? 0) === 1 && ($x['json']['ok'] ?? false), 'a Monday note to Priya: sent 1');
$n = bell(26, 'monday_note')[0] ?? [];
ok(($n['title'] ?? '') === 'Monday note: your files' && (int) ($n['actor_member_id'] ?? 0) === CLERK && str_contains($n['body'] ?? '', 'Suggested tags') && $n['read_at'] === null, 'on her bell: the title, the body, from the Clerk, unread');
$ob = q("SELECT * FROM notification_outbox WHERE member_id = 26 AND kind = 'monday_note' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok(($ob['channel'] ?? '') === 'email' && $ob['status'] === 'queued' && str_starts_with((string) $ob['dedupe_key'], 'monday_note:' . week_label() . ':note:m26'), 'and by her preferences an email is queued, keyed by the week');
$ev = activity('notification.send', $since)[0] ?? null; $ea = json_decode($ev['after'] ?? '{}', true);
ok($ev !== null && $ev['source'] === 'agent' && (int) $ev['actor_member_id'] === CLERK && $ea['kind'] === 'monday_note' && $ea['sent'] === 1 && !str_contains($ev['after'], 'Suggested'), 'logged notification.send by the Clerk (agent), without the body');
$x = $send(['member' => 26, 'kind' => 'monday_note', 'title' => 'Monday note: once more', 'body' => 'again']);
ok($x['code'] === 200 && ($x['json']['sent'] ?? -1) === 0 && ($x['json']['skipped'] ?? 0) === 1 && count(bell(26, 'monday_note')) === $m26 + 1, 'a second Monday note in the same week: nobody told (200, sent 0), one new bell row');
$x = $send(['member' => 27, 'kind' => 'proposal', 'title' => 'Something for you', 'body' => 'Open Proposals']);
ok($x['code'] === 200 && ($x['json']['sent'] ?? 0) === 1 || ($x['json']['skipped'] ?? 0) === 1, 'a proposal notice to Marco');
$x = $send(['member' => 27, 'kind' => 'proposal', 'title' => 'Something else', 'body' => 'x']);
ok($x['code'] === 200 && ($x['json']['sent'] ?? -1) === 0, '...once a day: a second the same day tells nobody');
$x = $send(['member' => 28, 'title' => 'Default kind', 'body' => 'no kind given means a Monday note']);
ok($x['code'] === 200 && ($x['json']['sent'] ?? 0) === 1 && count(bell(28, 'monday_note')) === $m28 + 1, 'no kind: a Monday note');

echo "2. Every owner of a group\n";
$x = $send(['group' => $w['group'], 'kind' => 'monday_note', 'title' => 'Monday note: SMOKE Board', 'body' => 'The Board library is in order.']);
$g = q("SELECT * FROM notifications WHERE kind = 'monday_note' AND record_type = 'group_week' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok($x['code'] === 200 && (($x['json']['sent'] ?? 0) >= 1 || ($x['json']['skipped'] ?? 0) >= 1) && (int) ($g['member_id'] ?? 0) === 27, 'to a group: its owner (Marco) is told (or already was this week by the Monday sweep)');
$owners = q("SELECT member_id FROM group_members WHERE group_id = :g AND role = 'owner' AND left_at IS NULL", ['g' => $w['group']]);
ok(count($owners) === 1 && !in_array(26, array_column($owners, 'member_id'), true), '(Priya and Seamus are members, not owners)');
$x = $send(['group' => $w['group'], 'kind' => 'monday_note', 'title' => 'Again', 'body' => 'x']);
ok(($x['json']['sent'] ?? -1) === 0, 'the group\'s note is once a week too');
$x = $send(['group' => 99999999, 'title' => 'x', 'body' => 'x']);
ok($x['code'] === 404, 'a group that is not there: 404');

echo "3. Only people are told\n";
foreach ([EXPERT => 'an agent (the expert)', 29 => 'a guest (Ann)', 31 => 'someone who has left (Lee)', 99999999 => 'a member that does not exist'] as $m => $who) {
    $b0 = (int) one('SELECT count(*) FROM notifications WHERE member_id = :m', ['m' => $m]);
    $x = $send(['member' => $m, 'kind' => 'monday_note', 'title' => 'Hello', 'body' => 'x']);
    ok($x['code'] === 200 && ($x['json']['sent'] ?? -1) === 0 && ($x['json']['skipped'] ?? 0) === 1 && (int) one('SELECT count(*) FROM notifications WHERE member_id = :m', ['m' => $m]) === $b0, "$who is skipped: nothing written");
}
ok((int) one('SELECT count(*) FROM notifications WHERE member_id IN (' . EXPERT . ', ' . CLERK . ', 41)') === 0, 'no agent has any notification row');

echo "4. Who may send, and what is refused\n";
$x = $send(['member' => 26, 'title' => 'Mine'], 1);
ok($x['code'] === 200, 'the admin (agents.settings) may send too');
foreach ([26 => 'a member', 27 => 'a curator'] as $m => $who) {
    $x = $send(['member' => 28, 'title' => 'x'], $m);
    ok($x['code'] === 403, "$who may not send: 403");
}
$x = $send(['member' => 28, 'title' => 'x'], EXPERT);
ok($x['code'] === 403, 'nor the expert (it is not the Clerk and holds no agents.settings): 403');
$x = $send(['title' => 'x']);
ok($x['code'] === 422 && isset($x['json']['error']['message']), 'no recipient: 422');
$x = $send(['member' => 26]);
ok($x['code'] === 422, 'no title: 422');
$x = $send(['member' => 26, 'title' => 'x', 'kind' => 'share_received']);
ok($x['code'] === 422, 'a kind other than monday_note or proposal: 422');
$x = $send(['member' => 26, 'title' => 'x', 'body' => str_repeat('b', 4001)]);
ok($x['code'] === 422, 'a body over 4000 characters: 422');
$x = $send(['member' => 26, 'title' => str_repeat('t', 201)]);
ok($x['code'] === 422, 'a title over 200 characters: 422');
$r = req('POST', '/agents/notify.php', ['headers' => ['Accept: application/json'], 'form' => ['member' => 26, 'title' => 'x']]);
ok($r['code'] === 401, 'no session: 401');

echo "5. The once-a-week rule holds for a person who is not mailed\n";
$m30 = count(bell(30, 'monday_note'));
$p->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (30, false, false, '{}', '{}') ON CONFLICT (member_id) DO UPDATE SET email_enabled = false, text_enabled = false, kinds = '{}', text_kinds = '{}'");
$x = $send(['member' => 30, 'kind' => 'monday_note', 'title' => 'Dana\'s note', 'body' => 'x'], 1);
$y = $send(['member' => 30, 'kind' => 'monday_note', 'title' => 'Dana\'s note again', 'body' => 'x'], 1);
ok(($x['json']['sent'] ?? 0) === 1 && ($y['json']['sent'] ?? -1) === 0 && count(bell(30, 'monday_note')) === $m30 + 1, 'Dana turned every channel off: one bell row, the second refused by the bell row (dc_notify alone would have repeated it)');
ok((int) one('SELECT count(*) FROM notification_outbox WHERE member_id = 30') === 0, '...and nothing queued to mail or text her');
$p->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (31, true, false, '{}', '{}') ON CONFLICT DO NOTHING");

echo "6. The bell\n";
$j = get_json(26, '/notifications');
$rows = $j['json']['data']['notifications'] ?? [];
ok($j['code'] === 200 && count($rows) >= 1 && $rows[0]['kind'] === 'monday_note', 'her notifications list the Clerk\'s note');
$c = page(actor(26), '/notifications?count=1');
ok($c['code'] === 200 && preg_match('/id="header-bell-count">(\d+|99\+)</', $c['body'], $m) === 1 && (int) $m[1] === (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL'), 'the bell\'s count route answers her unread count (' . ($m[1] ?? '?') . ')');
$html = page(actor(26), '/notifications')['body'];
ok(str_contains($html, 'feather-clipboard') && str_contains($html, 'href="/curate/proposals?back='), 'the page shows the kind\'s icon and the note links to the proposals');
ok(str_contains(page(actor(26), '/')['body'], 'every 20s'), 'the bell polls every 20 seconds');
$x = post_json(26, '/settings/notifications/read.php', []);
ok($x['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0, '"Mark all read" empties her unread');
$c = page(actor(26), '/notifications?count=1');
ok(!str_contains($c['body'], 'header-bell-count"'), '...and the bell\'s badge is gone');
$bell28 = (int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL');
$x = post_json(26, '/settings/notifications/read.php', []);
ok((int) one('SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL') === $bell28, 'hers only: Bea\'s notices are untouched');

echo "7. An agent is refused the bell\n";
$r = req('GET', '/notifications', ['headers' => as_agent(run_token(EXPERT, 8590))]);
ok($r['code'] === 403 && str_contains($r['body'], 'agent uses the tools'), 'the bell page under an agent\'s run token: 403, in words');
$r = req('GET', '/notifications?count=1', ['headers' => as_agent(run_token(EXPERT, 8591))]);
ok($r['code'] === 403, 'the count too: 403');
$r = req('GET', '/settings/', ['headers' => as_agent(run_token(EXPERT, 8592))]);
ok($r['code'] === 403, 'and the settings: 403');
finish();
