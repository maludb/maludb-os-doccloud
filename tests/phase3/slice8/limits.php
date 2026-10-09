<?php
/**
 * Slice 8 proof 8 (run LAST: it fills the Clerk's hour) — the `notification` action is limited to 60 an hour for the Clerk: sixty sent two hours ago do not count, sixty in the hour stop it with a 429 in words,
 * the limit is the Clerk's own (the admin may still send). activity_log is append-only, so the proof's rows stay: nothing after this file may use the Clerk's notice.
 */
require __DIR__ . '/lib.php';
$w = world();
$p = pdo();
$run = 8900;
$send = function (array $f, int $as = CLERK) use (&$run): array { return $as === CLERK || $as === EXPERT ? post_agent($as, $run++, '/agents/notify.php', $f) : post_json($as, '/agents/notify.php', $f); };
echo "1. At most 60 an hour\n";
$p->exec("INSERT INTO activity_log (actor_member_id, source, action, occurred_at) SELECT " . CLERK . ", 'agent', 'notification.send', now() - interval '2 hours' FROM generate_series(1, 60)");
$x = $send(['member' => 28, 'title' => 'Sixty an hour ago do not count']);
ok($x['code'] === 200, '60 sent two hours ago do not count against this hour');
$p->exec("INSERT INTO activity_log (actor_member_id, source, action) SELECT " . CLERK . ", 'agent', 'notification.send' FROM generate_series(1, 60)");
$x = $send(['member' => 26, 'title' => 'One too many']);
ok($x['code'] === 429, 'after 60 in the hour: 429');
ok(str_contains($x['json']['error']['message'] ?? '', 'hour'), '...in words');
ok($send(['member' => 26, 'title' => 'The admin is not limited'], 1)['code'] === 200, 'the limit is the Clerk\'s: the admin may still send');

finish();
