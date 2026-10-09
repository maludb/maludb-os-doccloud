<?php
/**
 * Slice 8 proof 6 — the Clerk's notices reach people by mail and by text (K6 through the fake kernel): a proposal notice queued for email and text by the person's preferences, delivered by the
 * outbox pass (MaluMail; the kernel's /notify/sms.php), an opted-out person skipped with the kernel's reason, and the settings screen offering the text channel for the Clerk's kinds.
 */
require __DIR__ . '/lib.php';
$w = world();
$p = pdo();
$p->exec("UPDATE notification_outbox SET status = 'skipped', detail = 'proof reset' WHERE status = 'queued'");
$p->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (28, true, true, '{proposal,monday_note}', '{proposal}')
          ON CONFLICT (member_id) DO UPDATE SET email_enabled = true, text_enabled = true, kinds = '{proposal,monday_note}', text_kinds = '{proposal}'");
$p->exec("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (30, false, true, '{proposal}', '{proposal}')
          ON CONFLICT (member_id) DO UPDATE SET email_enabled = false, text_enabled = true, kinds = '{proposal}', text_kinds = '{proposal}'");
kernel_state(function ($st) { $st['sms'] = ['mode' => 'ok', 'members' => ['30' => 'opted_out']]; return $st; });
$mail0 = count(mail_log()); $sms0 = count(sms_log());

echo "1. A proposal notice is queued by the person's preferences\n";
$a = post_agent(CLERK, 8600, '/agents/propose.php', ['kind' => 'budget', 'title' => 'The document graph has used 85% of this month\'s text budget', 'for_member' => 28, 'key' => 'k6-bea', 'payload' => json_encode(['pct' => 85])]);
$b = post_agent(CLERK, 8601, '/agents/propose.php', ['kind' => 'budget', 'title' => 'The document graph has used 86% of this month\'s text budget', 'for_member' => 30, 'key' => 'k6-dana', 'payload' => json_encode(['pct' => 86])]);
ok($a['code'] === 200 && $b['code'] === 200, 'the Clerk proposes to Bea and to Dana');
$rows = q("SELECT channel, status, subject, body, kind FROM notification_outbox WHERE member_id = 28 AND kind = 'proposal' ORDER BY channel");
ok(count($rows) === 2 && $rows[0]['channel'] === 'email' && $rows[1]['channel'] === 'text' && $rows[0]['status'] === 'queued' && $rows[1]['status'] === 'queued', 'Bea: an email and a text queued');
ok(mb_strlen($rows[1]['body']) <= 300 && str_contains($rows[1]['body'], '85%'), 'the text is short (300 characters at most) and says what');
$rows30 = q("SELECT channel FROM notification_outbox WHERE member_id = 30 AND kind = 'proposal'");
ok(count($rows30) === 1 && $rows30[0]['channel'] === 'text', 'Dana: a text only (her email is off)');

echo "2. The outbox pass delivers\n";
$r = worker('outbox')['ran']['outbox'] ?? [];
ok(($r['sent'] ?? 0) >= 2, 'the pass sent ' . ($r['sent'] ?? 0));
$ml = array_slice(mail_log(), $mail0);
$mine = array_values(array_filter($ml, fn ($m) => ($m['to'] ?? '') === 'bea@example.invalid'));
ok(count($mine) === 1 && $mine[0]['subject'] === 'The Clerk has something for you to decide' && str_contains($mine[0]['text'], '/notifications'), 'MaluMail got Bea\'s mail: the Clerk\'s subject and a link');
$sm = array_slice(sms_log(), $sms0);
$bea = array_values(array_filter($sm, fn ($s) => (int) ($s['member_id'] ?? 0) === 28));
ok(count($bea) === 1 && ($bea[0]['accepted'] ?? false) === true && str_contains($bea[0]['text'], '85%'), 'the kernel was asked to text Bea (member 28) — the application holds no phone number and no Twilio key');
ok(!isset($bea[0]['phone']) && !isset($bea[0]['to']), '...it sent the member id and the words, nothing else');
$t = q("SELECT status, detail, provider_ref FROM notification_outbox WHERE member_id = 28 AND channel = 'text' AND kind = 'proposal'")[0];
ok($t['status'] === 'sent' && str_starts_with((string) $t['provider_ref'], 'kernel:'), 'Bea\'s text is sent, with the kernel\'s reference');
$t30 = q("SELECT status, detail FROM notification_outbox WHERE member_id = 30 AND channel = 'text' AND kind = 'proposal'")[0];
ok($t30['status'] === 'skipped' && str_contains((string) $t30['detail'], 'opted_out'), 'Dana opted out at the kernel: skipped with its reason (opted_out)');
$r = worker('outbox')['ran']['outbox'] ?? [];
ok(count(array_slice(sms_log(), $sms0)) === count($sm) && count(array_slice(mail_log(), $mail0)) === count($ml), 'a second pass sends nothing again');

echo "3. The settings screen offers the Clerk's kinds, including by text\n";
$html = page(actor(28), '/settings/')['body'];
ok(str_contains($html, 'The Clerk proposes something') && str_contains($html, 'Monday note'), 'the preferences list the Clerk\'s two kinds');
ok(preg_match('/name="text_kinds\[\]" value="proposal"/', $html) === 1 && preg_match('/name="text_kinds\[\]" value="monday_note"/', $html) === 1, '...each with a text box');
$x = post_json(28, '/settings/prefs.php', ['text_kinds' => ['proposal', 'monday_note']]);
ok($x['code'] === 200 && one('SELECT text_kinds::text FROM notification_prefs WHERE member_id = 28') === '{proposal,monday_note}', 'she chooses to be texted the Monday note too');
$x = post_json(28, '/settings/prefs.php', ['kinds' => ['proposal'], 'text_kinds' => ['proposal', 'monday_note']]);
ok($x['code'] === 200, 'saving again is fine');
$b0 = (int) one('SELECT count(*) FROM notifications WHERE member_id = ' . EXPERT);
post_agent(CLERK, 8602, '/agents/propose.php', ['kind' => 'budget', 'title' => 'x', 'for_member' => EXPERT, 'key' => 'k6-agent']);
ok((int) one('SELECT count(*) FROM notifications WHERE member_id = ' . EXPERT) === $b0 && (int) one('SELECT count(*) FROM notification_outbox WHERE member_id IN (' . EXPERT . ', ' . CLERK . ')') === 0, 'an agent is never notified or texted: nothing queued for the expert or the Clerk');
finish();
