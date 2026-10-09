<?php
declare(strict_types=1);

/**
 * THE OUTBOX SENDER (slice 6, docs/build-specs/trash-locks-quotas-worker.md "Divergences" 4): every row dc_notify() queued — one per recipient and channel — is delivered here by the
 * worker, at most 50 emails and 20 texts a pass. Email goes through MaluMail (app/mail.php malumail_send(); DocCloud holds no mailbox), texts through the kernel's K6 (k6.php).
 * THE RULES:
 *   - sent: the row says so with the provider's reference; skipped: the kernel or the member said no (opted_out, no_sender, an inactive member, no address) with the code;
 *   - failed: MaluMail refused the mail (a 4xx — nothing to retry — or a 200 that rejected the address) with the detail, or a retry-able failure (a 5xx, no answer) after
 *     THREE attempts; between attempts send_after moves out (2, then 4 minutes);
 *   - never an address, a subject or a body in the trail: the channel, the kind, a code.
 */
require_once __DIR__ . '/k6.php';

const OUTBOX_MAX_ATTEMPTS = 3;
const OUTBOX_EMAILS_PER_PASS = 50;
const OUTBOX_TEXTS_PER_PASS = 20;

/** One email through MaluMail. Answers [status sent|skipped|failed|retry, detail, ref]. */
function outbox_send_email(array $row): array
{
    $to = trim((string) ($row['to_email'] ?? '')) !== '' ? (string) $row['to_email'] : trim((string) ($row['member_email'] ?? ''));
    if ($to === '') {
        return ['status' => 'skipped', 'detail' => 'no_address', 'ref' => null];
    }
    $subject = (string) ($row['subject'] ?? '') !== '' ? (string) $row['subject'] : 'A notice from ' . app_name();
    $link = app_url('/notifications');
    $text = trim((string) $row['body']) . "\n\n" . $link . "\n\n-- " . app_name() . "\n";
    $html = '<p>' . nl2br(htmlspecialchars(trim((string) $row['body']), ENT_QUOTES, 'UTF-8')) . '</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Open ' . htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') . '</a></p>';
    try {
        $r = malumail_send(['from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html]);
    } catch (Throwable $e) {
        error_log('outbox ' . $row['id'] . ': ' . $e->getMessage());
        return ['status' => 'retry', 'detail' => 'transport', 'ref' => null];
    }
    $code = (int) $r['status'];
    if ($code >= 200 && $code < 300) {
        $rejected = $r['body']['rejected'][0]['reason'] ?? null;
        if (empty($r['body']['accepted']) && $rejected !== null) {
            return ['status' => 'failed', 'detail' => 'MaluMail rejected the address: ' . mb_substr((string) $rejected, 0, 120), 'ref' => null];
        }
        return ['status' => 'sent', 'detail' => null, 'ref' => $r['message_id'] ?? null];
    }
    if ($code >= 400 && $code < 500 && $code !== 429 && $code !== 408) {
        $why = $r['body']['rejected'][0]['reason'] ?? ($r['body']['error'] ?? '');
        return ['status' => 'failed', 'detail' => 'MaluMail answered ' . $code . (is_string($why) && $why !== '' ? ': ' . mb_substr($why, 0, 120) : ''), 'ref' => null];
    }
    return ['status' => 'retry', 'detail' => 'MaluMail answered ' . $code, 'ref' => null];
}

/** Record one row's outcome: the row, the trail (outbox.sent|skipped|failed — a code, never a body), a retry's backoff. Returns the final word: sent, skipped, failed or retried. */
function outbox_settle(PDO $pdo, array $row, array $r): string
{
    $attempts = (int) $row['attempts'] + 1;
    $status = $r['status'];
    $detail = $r['detail'] === null ? null : mb_substr((string) $r['detail'], 0, 200);
    if ($status === 'retry') {
        if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
            $status = 'failed';
            $detail = 'After ' . $attempts . ' attempts: ' . ($detail ?? 'no answer');
        } else {
            $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = now() + make_interval(mins => :m) WHERE id = :id AND status = 'queued'")
                ->execute(['a' => $attempts, 'd' => $detail, 'm' => 2 ** $attempts, 'id' => (int) $row['id']]);
            return 'retried';
        }
    }
    $pdo->prepare("UPDATE notification_outbox SET status = :s, attempts = :a, detail = :d, provider_ref = :r, sent_at = CASE WHEN :s2 = 'sent' THEN now() ELSE sent_at END WHERE id = :id")
        ->execute(['s' => $status, 's2' => $status, 'a' => $attempts, 'd' => $detail, 'r' => $r['ref'] === null ? null : mb_substr((string) $r['ref'], 0, 200), 'id' => (int) $row['id']]);
    log_activity($pdo, 'outbox.' . $status, 'notification_outbox', (int) $row['id'], ['source' => 'cron', 'actor_member_id' => null,
        'after' => ['channel' => $row['channel'], 'kind' => $row['kind'], 'member_id' => (int) $row['member_id'], 'attempts' => $attempts] + ($status === 'sent' || $detail === null ? [] : ['code' => $status === 'failed' ? 'failed' : $detail])]);
    return $status;
}

/** Deliver what is due: at most 50 emails and 20 texts. Answers ['sent' =>, 'skipped' =>, 'failed' =>, 'retried' =>, 'emails' =>, 'texts' =>]. */
function worker_outbox(PDO $pdo): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0, 'emails' => 0, 'texts' => 0];
    foreach (['email' => OUTBOX_EMAILS_PER_PASS, 'text' => OUTBOX_TEXTS_PER_PASS] as $channel => $cap) {
        $st = $pdo->prepare("SELECT o.id, o.channel, o.member_id, o.to_email, o.kind, o.record_type, o.record_id, o.subject, o.body, o.attempts, m.email AS member_email, m.status AS member_status
                               FROM notification_outbox o JOIN members m ON m.id = o.member_id
                              WHERE o.status = 'queued' AND o.channel = :c AND o.send_after <= now() ORDER BY o.send_after, o.id LIMIT :l");
        $st->bindValue('c', $channel);
        $st->bindValue('l', $cap, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $row) {
            try {
                if ($row['member_status'] !== 'active') {
                    $r = ['status' => 'skipped', 'detail' => 'inactive', 'ref' => null];
                } else {
                    $r = $channel === 'text' ? outbox_send_text($row) : outbox_send_email($row);
                }
                $word = outbox_settle($pdo, $row, $r);
            } catch (Throwable $e) {
                error_log('outbox row ' . $row['id'] . ': ' . $e->getMessage());
                $word = outbox_settle($pdo, $row, ['status' => 'retry', 'detail' => 'error', 'ref' => null]);
            }
            $out[$word]++;
            $out[$channel === 'text' ? 'texts' : 'emails']++;
        }
    }
    return $out;
}
