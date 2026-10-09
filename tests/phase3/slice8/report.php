<?php
/**
 * Slice 8 proof 2 — the Clerk's Monday store report: dc_clerk_report() across a seeded world (every section), refused to anyone else; clerk_propose by the Clerk's run token (deduped, validated, a person or
 * another agent refused); the worker's Monday sweep making the right proposals for the right people, once a week; the Monday notes; and never a document's words anywhere.
 */
require __DIR__ . '/lib.php';
$w = world();
$p = pdo();

echo "1. The report, section by section\n";
$r = report_as(CLERK);
ok(array_keys($r) === ['untagged', 'duplicate', 'stale_share', 'link_expiring', 'link_suspicious', 'request_open', 'trash_due', 'locked', 'no_text', 'quota', 'budget', 'orphaned'], 'the Clerk reads twelve sections: ' . implode(' ', array_keys($r)));
ok($r['untagged']['count'] >= 8 && in_array((string) $w['u1'], $r['untagged']['ids'], true) && in_array((string) $w['u2'], $r['untagged']['ids'], true) && in_array((string) $w['scan_ids'][0], $r['untagged']['ids'], true), 'untagged: the world\'s documents without tags (' . $r['untagged']['count'] . ')');
ok(!in_array((string) $w['board_doc'], $r['untagged']['ids'], true), '...and not the ones tagged base');
ok(array_key_exists((string) $w['scans'], $r['untagged']['detail']['by_folder'] ?? []) && (int) $r['untagged']['detail']['by_folder'][(string) $w['scans']] === 6, 'untagged: by folder, the Scans folder holds 6');
ok($r['duplicate']['count'] === 1 && (int) $r['duplicate']['detail'][0]['copies'] === 3 && count($r['duplicate']['detail'][0]['ids']) === 3, 'duplicates: one file in 3 copies');
ok($r['stale_share']['count'] === 1 && (int) $r['stale_share']['detail'][0]['guest'] === 29 && (int) $r['stale_share']['detail'][0]['folder_id'] === $w['client'], 'stale shares: the guest on Client, 100 days');
ok($r['link_expiring']['count'] === 1 && (int) $r['link_expiring']['detail'][0]['link_id'] === $w['exp_link'], 'links expiring: the one in 3 days');
ok($r['link_suspicious']['count'] === 1 && (int) $r['link_suspicious']['detail'][0]['addresses'] === 12, 'links opened widely: 12 addresses');
ok($r['request_open']['count'] === 1 && (int) $r['request_open']['detail'][0]['link_id'] === $w['request'], 'requests open: the one');
ok($r['trash_due']['count'] === 1 && (int) $r['trash_due']['detail'][0]['document_id'] === $w['trash'], 'trash: the document purging in 2 days');
ok($r['locked']['count'] === 1 && (int) $r['locked']['detail'][0]['document_id'] === $w['lock'], 'locks: the 30-hour lock');
ok($r['no_text']['count'] === 1 && ($r['no_text']['detail']['by_status']['unsupported'] ?? 0) === 1, 'no text: the unsupported file');
ok($r['quota']['count'] === 1 && (int) $r['quota']['detail'][0]['library_id'] === $w['lib'], 'quota: the Board library at 92%');
ok($r['budget']['count'] === 1 && (float) $r['budget']['detail']['pct'] === 85.0, 'budget: 85% of the month');
ok($r['orphaned']['count'] === 1 && array_key_exists('31', $r['orphaned']['detail']['owners'] ?? []), 'orphaned: a document of Lee, who has left');

echo "2. Who may read it, and what it never holds\n";
foreach ([26 => 'a member', 27 => 'a curator', 28 => 'a member outside the Board', 29 => 'a guest', EXPERT => 'the expert agent'] as $m => $who) {
    ok(report_as($m) === [], "$who gets an empty report");
}
$a = report_as(1);
ok(array_keys($a) === array_keys($r) && $a['untagged']['count'] === $r['untagged']['count'], 'the admin reads the same report');
$whole = json_encode($r);
ok(!str_contains($whole, WORLD_SECRET) && !str_contains($whole, 'Invoice for the quarter') && !str_contains($whole, 'Termination'), 'the report holds no document text');
$p->exec("UPDATE dc_settings SET clerk_report_enabled = false WHERE id = 1");
ok(report_as(CLERK) === [] && report_as(1) === [], 'switched off, even the Clerk and the admin read nothing');
$p->exec("UPDATE dc_settings SET clerk_report_enabled = true WHERE id = 1");

echo "3. clerk_propose under the Clerk's run token\n";
$f = ['kind' => 'tags', 'title' => 'Tag w8-invoice.pdf: supplier-terms', 'for_member' => 26, 'document' => $w['u1'], 'payload' => json_encode(['tags' => ['supplier-terms', 'net-thirty'], 'source' => 'graph'])];
$since = last_activity_id();
$bell0 = count(bell(26, 'proposal'));
$x = post_agent(CLERK, 8201, '/agents/propose.php', $f);
$id1 = (int) ($x['json']['record_id'] ?? 0);
ok($x['code'] === 200 && ($x['json']['ok'] ?? false) && $id1 > 0 && ($x['json']['proposed'] ?? false) === true, 'the Clerk proposes: 200, the proposal\'s id');
$row = proposals('id = :i', ['i' => $id1])[0] ?? [];
ok((int) ($row['for_member_id'] ?? 0) === 26 && (int) ($row['proposed_by'] ?? 0) === CLERK && $row['status'] === 'proposed' && $row['kind'] === 'tags' && (int) $row['document_id'] === $w['u1'], 'for Priya, by the Clerk, open, on the document');
ok(payload_of($row)['tags'] === ['supplier-terms', 'net-thirty'], 'the payload keeps the tags as a list');
$ev = activity('clerk.propose', $since)[0] ?? null;
ok($ev !== null && $ev['source'] === 'agent' && (int) $ev['actor_member_id'] === CLERK && (int) $ev['document_id'] === $w['u1'], 'logged clerk.propose, source agent, with the document');
ok(count(bell(26, 'proposal')) === $bell0 + 1, 'Priya has a bell notice of kind proposal');
$x = post_agent(CLERK, 8202, '/agents/propose.php', $f);
ok($x['code'] === 200 && ($x['json']['proposed'] ?? true) === false && count(proposals("kind = 'tags' AND document_id = :d", ['d' => $w['u1']])) === 1, 'the same proposal again is a no-op (deduped), not a second row');
ok(count(bell(26, 'proposal')) === $bell0 + 1, '...and tells nobody twice');
$x = post_agent(CLERK, 8203, '/agents/propose.php', ['kind' => 'duplicate', 'title' => '3 copies of w8-dup-a.pdf', 'for_member' => 27, 'document' => $w['dups'][0], 'payload' => json_encode(['copies' => 3])]);
ok($x['code'] === 200 && ($x['json']['proposed'] ?? false), 'a second proposal, another kind');
ok(count(bell(27, 'proposal')) === 1, 'Marco has one proposal notice');
$x = post_agent(CLERK, 8204, '/agents/propose.php', ['kind' => 'locked', 'title' => 'minutes-jan.docx has been locked since Monday', 'for_member' => 27, 'document' => $w['minutes'], 'payload' => '{}']);
ok($x['code'] === 200 && count(bell(27, 'proposal')) === 1, 'a second for Marco the same day: still ONE notice (once a day)');
echo "   refusals\n";
$x = post_agent(EXPERT, 8205, '/agents/propose.php', $f);
ok($x['code'] === 403 && str_contains($x['json']['error']['message'] ?? '', 'Clerk'), 'another agent (the expert) is refused: 403 "Only the Filing Clerk proposes"');
foreach ([26 => 'a member', 27 => 'a curator', 1 => 'the admin'] as $m => $who) {
    $x = post_json($m, '/agents/propose.php', $f);
    ok($x['code'] === 403, "$who by a person\'s token is refused: 403");
}
$x = post_agent(CLERK, 8206, '/agents/propose.php', ['kind' => 'tags', 'title' => 't', 'for_member' => 29]);
ok($x['code'] === 422 && str_contains($x['json']['error']['message'] ?? '', 'person'), 'for a guest: 422');
$x = post_agent(CLERK, 8207, '/agents/propose.php', ['kind' => 'tags', 'title' => 't', 'for_member' => EXPERT]);
ok($x['code'] === 422, 'for an agent: 422');
$x = post_agent(CLERK, 8208, '/agents/propose.php', ['kind' => 'nonsense', 'title' => 't', 'for_member' => 26]);
ok($x['code'] === 422, 'an unknown kind: 422');
$x = post_agent(CLERK, 8209, '/agents/propose.php', ['kind' => 'tags', 'for_member' => 26]);
ok($x['code'] === 422, 'no title: 422');
$x = post_agent(CLERK, 8210, '/agents/propose.php', ['kind' => 'tags', 'title' => 'x', 'for_member' => 26, 'payload' => json_encode(['excerpt' => str_repeat('word ', 80)])]);
ok($x['code'] === 422 && str_contains($x['json']['error']['message'] ?? '', 'text'), 'a fact over 300 characters (a document\'s words): 422');
$x = post_agent(CLERK, 8211, '/agents/propose.php', ['kind' => 'link_expiring', 'title' => 'x', 'for_member' => 26, 'payload' => json_encode(['url' => 'https://x.invalid/s/' . str_repeat('a1', 24)])]);
ok($x['code'] === 422 && str_contains($x['json']['error']['message'] ?? '', 'token'), 'a link\'s token in the facts: 422');
$x = post_agent(CLERK, 8212, '/agents/propose.php', ['kind' => 'tags', 'title' => 'x', 'for_member' => 26, 'document' => 99999999]);
ok($x['code'] === 422, 'a document that is not there: 422');
$x = post_agent(CLERK, 8213, '/agents/propose.php', ['kind' => 'tags', 'title' => 'x', 'for_member' => 26, 'payload' => 'not json']);
ok($x['code'] === 422, 'facts that are not JSON: 422');
$x = post_agent(CLERK, 8214, '/agents/propose.php', ['kind' => 'tags', 'title' => str_repeat('t', 201), 'for_member' => 26]);
ok($x['code'] === 422, 'a title over 200 characters: 422');
ok(isset($x['json']['error']['message']), 'the refusal says so in JSON');

echo "4. The Monday sweep (the worker's proposals pass)\n";
$n0 = (int) one('SELECT count(*) FROM clerk_proposals');
$r = worker_at('proposals', (new DateTimeImmutable('tuesday this week 09:00:00 UTC'))->format('Y-m-d\TH:i:s\Z'));
ok(($r['ran']['proposals']['skipped'] ?? '') === 'not_monday_morning' && (int) one('SELECT count(*) FROM clerk_proposals') === $n0, 'on a Tuesday the pass does nothing');
$r = worker_at('proposals', (new DateTimeImmutable('monday this week 06:00:00 UTC'))->format('Y-m-d\TH:i:s\Z'));
ok(($r['ran']['proposals']['skipped'] ?? '') === 'not_monday_morning', 'on Monday before 07:00 (the Clerk\'s own duty at 06:50) it waits');
$since = last_activity_id();
$r = worker_at('proposals', monday_8());
$res = $r['ran']['proposals'] ?? [];
ok($r['errors'] === [] && ($res['proposed'] ?? 0) >= 10, 'on Monday after 07:00 the sweep proposes: ' . json_encode($res));
$byKind = array_count_values(array_column(proposals(), 'kind'));
foreach (['tags', 'untagged', 'duplicate', 'stale_share', 'link_expiring', 'link_suspicious', 'request_open', 'trash_due', 'locked', 'no_text', 'quota', 'budget', 'orphaned'] as $k) {
    ok(($byKind[$k] ?? 0) >= 1, "a '$k' proposal exists");
}
$pr = static fn (string $kind, string $where = 'true', array $a = []): array => proposals("kind = '$kind' AND $where", $a)[0] ?? [];
$t = $pr('tags', 'document_id = :d', ['d' => $w['u1']]);
ok((int) $t['for_member_id'] === 26 && count(proposals("kind = 'tags' AND document_id = :d", ['d' => $w['u1']])) === 1, 'tags for Priya\'s invoice: ONE (the Clerk\'s own was kept, the sweep did not repeat it)');
// a fresh tags proposal from the sweep itself, on a second graph document
$u3 = seed_doc($w['priya_root'], 'w8-contract.pdf', 26, 'Another. ' . WORLD_SECRET);
in_graph($u3, ['doc:' . $u3, 'folder:Priya', 'Master Agreement', 'Renewal']);
worker_at('proposals', monday_8()); // already this week
ok(count(proposals("kind = 'tags' AND document_id = :d", ['d' => $u3])) === 0, 'a second sweep in the same week makes nothing (the marker)');
$r = worker_at('proposals', monday_8());
ok(($r['ran']['proposals']['skipped'] ?? '') === 'already_this_week', 'the pass says it already ran this week');
$u = $pr('untagged', 'document_id = :d', ['d' => $w['u2']]);
ok((int) ($u['for_member_id'] ?? 0) === 27 && payload_of($u)['tags'] === [], 'untagged: the loose note, for its owner Marco, no suggestion');
$u = $pr('untagged', 'folder_id = :f AND document_id IS NULL', ['f' => $w['scans']]);
ok((int) ($u['for_member_id'] ?? 0) === 27 && (int) payload_of($u)['count'] === 6 && count(payload_of($u)['document_ids']) === 6, 'untagged: the Scans folder (6 > 5) is ONE proposal for its owner');
ok(count(proposals("kind IN ('tags', 'untagged') AND document_id IN (" . implode(',', $w['scan_ids']) . ')')) === 0, '...and none per scan');
$d = $pr('duplicate');
ok((int) $d['for_member_id'] === 27 && (int) payload_of($d)['copies'] === 3 && (int) $d['document_id'] === $w['dups'][0], 'duplicate: for the first copy\'s owner, 3 copies');
$s = $pr('stale_share');
ok((int) $s['for_member_id'] === 27 && (int) $s['folder_id'] === $w['client'] && (int) payload_of($s)['guest_member_id'] === 29 && (int) payload_of($s)['days'] >= 100, 'stale share: for who granted it, on the folder, 100 days');
$l = $pr('link_expiring');
ok((int) $l['for_member_id'] === 27 && (int) $l['link_id'] === $w['exp_link'], 'link expiring: for its maker');
$l = $pr('link_suspicious');
ok((int) $l['for_member_id'] === 26 && (int) $l['link_id'] === $w['wide_link'] && (int) payload_of($l)['addresses'] === 12, 'link opened widely: for its maker Priya');
$l = $pr('request_open');
ok((int) $l['for_member_id'] === 27 && (int) $l['link_id'] === $w['request'], 'request open: for its maker');
$l = $pr('trash_due');
ok((int) $l['for_member_id'] === 27 && (int) $l['document_id'] === $w['trash'], 'trash due: for the owner');
$l = $pr('locked', 'document_id = :d', ['d' => $w['lock']]);
ok((int) $l['for_member_id'] === 27 && (int) $l['document_id'] === $w['lock'] && (int) payload_of($l)['locked_by'] === 26, 'long lock: for the owner, naming the holder in the facts');
$l = $pr('no_text', "payload->>'key' = 'owner:27'");
ok((int) $l['for_member_id'] === 27 && (int) payload_of($l)['count'] === 1, 'no text: one per owner');
$l = $pr('quota');
ok((int) $l['for_member_id'] === 1 && (int) $l['library_id'] === $w['lib'] && (int) payload_of($l)['pct'] === 92, 'quota: for the admin, 92%');
$l = $pr('budget');
ok((int) $l['for_member_id'] === 1 && (int) payload_of($l)['pct'] === 85, 'budget: for the admin, 85%');
$l = $pr('orphaned');
ok((int) $l['for_member_id'] === 1 && (int) payload_of($l)['owner_member_id'] === 31 && (int) payload_of($l)['count'] === 1, 'orphaned: for the admin, Lee\'s documents');
ok((int) one("SELECT count(*) FROM clerk_proposals WHERE proposed_by <> " . CLERK) === 0, 'every proposal is the Clerk\'s');
$ev = activity('clerk.report', $since)[0] ?? null;
$ea = json_decode($ev['after'] ?? '{}', true);
ok($ev !== null && $ev['source'] === 'cron' && ($ea['week'] ?? '') === week_label(monday_8()) && ($ea['proposed']['total'] ?? 0) >= 10, 'logged clerk.report with the ISO week and the counts');
$none = (int) one("SELECT count(*) FROM clerk_proposals WHERE payload::text LIKE '%" . WORLD_SECRET . "%' OR title LIKE '%" . WORLD_SECRET . "%' OR payload::text LIKE '%Invoice for the quarter%' OR title LIKE '%payment terms%'");
ok($none === 0, 'NO proposal holds a document\'s words (title or facts)');
$all = json_encode(q('SELECT title, payload FROM clerk_proposals'));
ok(!str_contains($all, 'Termination') && !preg_match('/[a-f0-9]{48}/', $all), '...nor a link\'s token');
$ac = (int) one("SELECT count(*) FROM activity_log WHERE after::text LIKE '%" . WORLD_SECRET . "%'");
ok($ac === 0, 'nor does the activity log');

echo "5. The Monday notes\n";
$wk = (int) (new DateTimeImmutable(monday_8()))->format('oW');
$board = (int) $w['group'];
$mn = q("SELECT * FROM notifications WHERE kind = 'monday_note' ORDER BY id");
$to = array_map(static fn ($n) => (int) $n['member_id'], $mn);
ok(in_array(27, $to, true), 'Marco, the Board\'s owner, got a Monday note');
ok(in_array(1, $to, true), 'the admin got a Monday note');
$marco = array_values(array_filter($mn, fn ($n) => (int) $n['member_id'] === 27))[0] ?? [];
ok(str_contains($marco['title'] ?? '', 'SMOKE Board') && str_contains($marco['body'] ?? '', '/curate/proposals') && $marco['record_type'] === 'group_week', 'the group note names the group and points at the proposals');
ok(!str_contains(json_encode($mn), WORLD_SECRET), 'no Monday note holds a document\'s words');
ok(count(array_filter($to, fn ($m) => $m === 27)) === 1 && count(array_filter($to, fn ($m) => $m === 1)) === 1, 'one each, not one per proposal');
ok(!in_array(EXPERT, $to, true) && !in_array(CLERK, $to, true) && !in_array(29, $to, true), 'no agent and no guest was told');
$admin = array_values(array_filter($mn, fn ($n) => (int) $n['member_id'] === 1))[0] ?? [];
ok(str_contains($admin['title'] ?? '', 'open proposal') && str_contains($admin['body'] ?? '', 'budget') || str_contains($admin['body'] ?? '', 'Graph'), 'the admin\'s note counts what is open by kind');
$ob = q("SELECT * FROM notification_outbox WHERE kind = 'monday_note' AND member_id = 27");
ok(count($ob) === 1 && $ob[0]['channel'] === 'email' && str_starts_with((string) $ob[0]['dedupe_key'], 'monday_note:' . week_label(monday_8())), 'the mail is queued once, keyed by the week');
finish();
