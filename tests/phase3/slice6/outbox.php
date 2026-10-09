<?php
/** Slice 6 proof 7 — the outbox: an email through the fake MaluMail (sent, the provider reference), a 4xx failed with its detail, a 5xx retried with backoff and failed after three attempts, a text through the fake kernel's K6 (sent), K6 refusals skipped with the code, at most 50 emails and 20 texts a pass, the trail without addresses or bodies. */
require __DIR__ . '/lib.php';
seed_texts();
$p = pdo();
$p->exec("UPDATE notification_outbox SET status = 'skipped', detail = 'proof reset' WHERE status = 'queued'");
$p->exec("DELETE FROM notification_outbox WHERE dedupe_key IS NULL AND 1 = 0");
kernel_state(function ($st) { $st['sms'] = ['mode' => 'ok']; return $st; });
$mail0 = count(mail_log()); $sms0 = count(sms_log());
$run = static fn () => worker('outbox')['ran']['outbox'] ?? [];

echo "1. Email through MaluMail\n";
$a = queue_mail(26, 'good-1@example.invalid', 'Hello one');
$r = $run();
$row = outbox_row($a);
ok($row['status'] === 'sent' && $row['sent_at'] !== null && (int) $row['attempts'] === 1, 'a queued email is sent: status sent, sent_at, one attempt');
ok(str_starts_with((string) $row['provider_ref'], 'fake-'), 'the provider\'s reference is kept (' . $row['provider_ref'] . ')');
$ml = array_slice(mail_log(), $mail0);
ok(count($ml) === 1 && $ml[0]['to'] === 'good-1@example.invalid' && $ml[0]['subject'] === 'Hello one' && str_contains($ml[0]['text'], 'a body') && ($ml[0]['from'] ?? '') === 'doccloud@example.invalid', 'MaluMail got one mail: to, subject, the body and the link, from the business');
ok(str_contains($ml[0]['text'], '/notifications'), '...with a link to the notifications');
ok(($r['sent'] ?? 0) === 1 && ($r['emails'] ?? 0) === 1, 'the pass reports 1 sent');
$ev = last_event('outbox.sent');
$after = json_decode($ev['after'], true);
ok($ev !== null && $ev['source'] === 'cron' && $after['channel'] === 'email' && $after['kind'] === 'share_received' && !str_contains($ev['after'], '@') && !str_contains($ev['after'], 'Hello'), 'outbox.sent logged by the worker: the channel and kind, never an address or a subject');
$r = $run();
ok(($r['sent'] ?? -1) === 0 && count(mail_log()) === $mail0 + 1, 'a second pass sends nothing again (at most once)');

echo "   a notification queued by dc_notify reaches Priya's inbox\n";
$ids = notify(26, 'share_received', 'Marco shared w6.pdf', 'w6-notify-1');
ok(count($ids) >= 1, 'dc_notify queued ' . count($ids) . ' outbox row(s) by her preferences');
$p->exec("UPDATE members SET email = 'priya.w6@example.invalid' WHERE id = 26");
$p->exec("UPDATE notification_outbox SET to_email = 'priya.w6@example.invalid' WHERE id = " . $ids[0]);
$run();
$ml = array_slice(mail_log(), $mail0 + 1);
ok(count($ml) === 1 && $ml[0]['subject'] === 'Marco shared w6.pdf' && outbox_row($ids[0])['status'] === 'sent', 'sent with the notification\'s own title');

echo "2. A 4xx is failed with its detail\n";
$f = queue_mail(26, 'suppressed-1@example.invalid', 'Suppressed');
$run();
$row = outbox_row($f);
ok($row['status'] === 'failed' && str_contains((string) $row['detail'], 'MaluMail answered 400') && str_contains((string) $row['detail'], 'suppressed'), 'a suppressed address: failed, "' . $row['detail'] . '"');
ok((int) $row['attempts'] === 1, 'one attempt: a refusal is not retried');
$f2 = queue_mail(26, 'rejected-1@example.invalid', 'Rejected');
$run();
ok(outbox_row($f2)['status'] === 'failed' && str_contains((string) outbox_row($f2)['detail'], 'rejected'), 'a 200 that rejects the address: failed with the reason');
$ev = last_event('outbox.failed');
ok($ev !== null && !str_contains($ev['after'], '@'), 'outbox.failed logged without the address');

echo "3. A 5xx is retried with backoff, then failed after three attempts\n";
$t = queue_mail(26, 'flaky-1@example.invalid', 'Flaky');
$run();
$row = outbox_row($t);
ok($row['status'] === 'queued' && (int) $row['attempts'] === 1 && strtotime($row['send_after']) > time() + 60, 'the first failure: still queued, attempt 1, send_after moved out (' . round((strtotime($row['send_after']) - time()) / 60) . ' min)');
$n = count(mail_log());
$run();
ok(count(mail_log()) === $n, 'a pass before send_after does not try again');
$p->exec("UPDATE notification_outbox SET send_after = now() - interval '1 minute' WHERE id = $t");
$r = $run();
$row = outbox_row($t);
ok($row['status'] === 'queued' && (int) $row['attempts'] === 2 && ($r['retried'] ?? 0) === 1, 'the second attempt fails too: attempt 2, retried');
ok(strtotime($row['send_after']) - time() > 180, '...and waits longer (backoff)');
$p->exec("UPDATE notification_outbox SET send_after = now() - interval '1 minute' WHERE id = $t");
$r = $run();
$row = outbox_row($t);
ok($row['status'] === 'failed' && (int) $row['attempts'] === 3 && str_contains((string) $row['detail'], 'After 3 attempts') && str_contains((string) $row['detail'], '502'), 'the third: failed with the detail "' . $row['detail'] . '"');
ok(($r['failed'] ?? 0) === 1, 'the pass reports 1 failed');

echo "4. Texts through the kernel's K6\n";
$x = queue_text(26, 'Marco sent you a file request');
$r = $run();
$row = outbox_row($x);
$sm = array_slice(sms_log(), $sms0);
ok($row['status'] === 'sent' && (int) $row['attempts'] === 1 && $row['provider_ref'] !== null, 'a text is sent: status sent, the kernel\'s reference (' . $row['provider_ref'] . ')');
ok(count($sm) === 1 && (int) $sm[0]['member_id'] === 26 && str_contains($sm[0]['text'], 'file request') && str_starts_with((string) $sm[0]['reference'], 'request_received:'), 'the kernel got one text for member 26 with a reference "kind:record"');
$ev = last_event('outbox.sent');
ok(json_decode($ev['after'], true)['channel'] === 'text', 'outbox.sent logged for the text');
kernel_state(function ($st) { $st['sms']['members'] = ['27' => 'opted_out', '30' => 'no_sender', '31' => 'server_error']; return $st; });
$o = queue_text(27, 'for an opted-out member');
$ns = queue_text(30, 'no sender configured');
$se = queue_text(31, 'the kernel is down');
$r = $run();
ok(outbox_row($o)['status'] === 'skipped' && outbox_row($o)['detail'] === 'opted_out', 'K6 opted_out: skipped with the code');
ok(outbox_row($ns)['status'] === 'skipped' && outbox_row($ns)['detail'] === 'no_sender', 'K6 no_sender: skipped with the code');
ok(outbox_row($se)['status'] === 'queued' && (int) outbox_row($se)['attempts'] === 1, 'K6 server_error: retried with backoff, not skipped');
$ev = last_event('outbox.skipped');
ok($ev !== null && json_decode($ev['after'], true)['code'] === 'no_sender', 'outbox.skipped logged with the code');
$p->exec("UPDATE notification_outbox SET send_after = now() - interval '1 minute' WHERE id = $se"); $run();
$p->exec("UPDATE notification_outbox SET send_after = now() - interval '1 minute' WHERE id = $se"); $run();
ok(outbox_row($se)['status'] === 'failed' && (int) outbox_row($se)['attempts'] === 3, 'a text the kernel never takes is failed after three attempts');
kernel_state(function ($st) { $st['sms'] = ['mode' => 'ok']; return $st; });

echo "5. Members who cannot be reached\n";
$p->exec("UPDATE members SET status = 'inactive' WHERE id = 31");
$ia = queue_mail(31, 'inactive-1@example.invalid', 'To a leaver');
$ne = (int) one("INSERT INTO notification_outbox (channel, member_id, kind, subject, body) VALUES ('email', 30, 'share_received', 'No address', 'x') RETURNING id");
$p->exec("UPDATE members SET email = NULL WHERE id = 30");
$n = count(mail_log());
$run();
ok(outbox_row($ia)['status'] === 'skipped' && outbox_row($ia)['detail'] === 'inactive', 'an inactive member: skipped "inactive"');
ok(outbox_row($ne)['status'] === 'skipped' && outbox_row($ne)['detail'] === 'no_address', 'no address: skipped "no_address"');
ok(count(mail_log()) === $n, 'neither reached MaluMail');
$p->exec("UPDATE members SET status = 'active' WHERE id = 31");

echo "6. At most 50 emails and 20 texts a pass\n";
for ($i = 1; $i <= 55; $i++) { queue_mail(26, "bulk-$i@example.invalid", "Bulk $i"); }
for ($i = 1; $i <= 25; $i++) { queue_text(26, "bulk text $i"); }
$n = count(mail_log()); $m = count(sms_log());
$r = $run();
ok(count(mail_log()) === $n + 50 && count(sms_log()) === $m + 20 && ($r['emails'] ?? 0) === 50 && ($r['texts'] ?? 0) === 20, 'one pass: 50 emails and 20 texts (' . ($r['emails'] ?? '?') . ' / ' . ($r['texts'] ?? '?') . ')');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE status = 'queued' AND channel = 'email'") === 5 && (int) one("SELECT count(*) FROM notification_outbox WHERE status = 'queued' AND channel = 'text'") === 5, '5 emails and 5 texts wait');
$r = $run();
ok(count(mail_log()) === $n + 55 && count(sms_log()) === $m + 25, 'the next pass takes the rest');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE status = 'queued'") === 0, 'the queue is empty');
$ids = array_column(q("SELECT id FROM notification_outbox WHERE subject LIKE 'Bulk %' AND channel = 'email' ORDER BY id"), 'id');
$logged = array_map(fn ($x) => $x['subject'], array_slice(mail_log(), $n));
ok($logged[0] === 'Bulk 1' && $logged[49] === 'Bulk 50', 'oldest first');

echo "7. The pass\n";
$r = worker('outbox');
ok(isset($r['ran']['outbox']) && $r['steps']['outbox']['count'] >= 0 && isset($r['steps']['outbox']['ms']), '--passes=outbox runs the one step and times it');
$r2 = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/worker.php --only=outbox 2>&1');
ok(str_contains((string) $r2, '"outbox"'), '--only=outbox is the same');
$p->exec("UPDATE notification_outbox SET created_at = now() - interval '40 days' WHERE status = 'sent'");
$r = worker('housekeeping');
ok(($r['ran']['housekeeping']['pruned'] ?? 0) >= 5 && (int) one("SELECT count(*) FROM notification_outbox WHERE status = 'sent'") === 0, 'housekeeping prunes sent mail older than 30 days (' . ($r['ran']['housekeeping']['pruned'] ?? '?') . ')');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE status = 'failed'") >= 3, '...and keeps the failed ones for the admin');
finish();
