<?php
declare(strict_types=1);

/**
 * Notifications from slice 8 (the Clerk's notices, the proposals' notices): the wrapper over dc_notify() (db/013) that every caller in this slice uses.
 * dc_notify() writes the bell row and, from the person's preferences, the outbox rows (email, text) the worker's outbox pass delivers (slice 6). It dedupes ONLY through the outbox key,
 * which a kind a person does not mail by default (or an opted-out person) never writes — so notify() adds the rule slice 6 found: a once-per-period notice is checked against the BELL
 * ROW of the period (kind + record + created_at), whatever the person's preferences.
 * An agent is never notified (the spec's divergence 4): the recipient must be an active person admitted here (no agent, no guest, no external).
 */

/** Who may be told: an active, admitted, internal PERSON. */
function notifiable_member(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare("SELECT id, display_name FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL AND member_kind = 'human' AND NOT is_external AND NOT dc_member_is_guest(id)");
    $st->execute(['id' => $memberId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * Tell a person. $o: record_type, record_id, document_id, folder_id, actor, dedupe (the outbox key), once_since (an SQL timestamp expression such as date_trunc('week', now()) — a bell row of the
 * same kind and record since then means they were told: nothing is written). Returns the notification id, or null (an agent or a guest recipient, or already told).
 */
function notify(PDO $pdo, int $memberId, string $kind, string $title, string $body, array $o = []): ?int
{
    if (notifiable_member($pdo, $memberId) === null) {
        return null;
    }
    if (isset($o['once_since'])) {
        $since = (string) $o['once_since'];
        if (!preg_match("/^[a-z_(), '0-9\\-]+$/i", $since)) {
            throw new InvalidArgumentException('once_since is an SQL timestamp expression.');
        }
        $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = :k AND record_type IS NOT DISTINCT FROM :rt AND record_id IS NOT DISTINCT FROM :rid AND created_at >= $since LIMIT 1");
        $told->execute(['m' => $memberId, 'k' => $kind, 'rt' => $o['record_type'] ?? null, 'rid' => $o['record_id'] ?? null]);
        if ($told->fetchColumn() !== false) {
            return null;
        }
    }
    $st = $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, :a, :dd)');
    $st->execute(['m' => $memberId, 'k' => $kind, 't' => mb_substr($title, 0, 200), 'b' => mb_substr($body, 0, 4000), 'rt' => $o['record_type'] ?? null, 'rid' => $o['record_id'] ?? null,
                  'd' => $o['document_id'] ?? null, 'f' => $o['folder_id'] ?? null, 'a' => $o['actor'] ?? null, 'dd' => $o['dedupe'] ?? null]);
    $id = $st->fetchColumn();
    return $id === false || $id === null ? null : (int) $id;
}

/** How many notifications an agent has sent in the last hour (the Clerk's rate limit counts the `notification.send` rows it caused). */
function notifications_sent_by_last_hour(PDO $pdo, int $memberId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'notification.send' AND actor_member_id = :m AND occurred_at > now() - interval '1 hour'", ['m' => $memberId]);
}

/** The Clerk (or an admin) may send this many notifications an hour through the `notification` action: a Monday sweep is a few dozen, a loop is stopped. */
const NOTIFICATION_RATE_PER_HOUR = 60;

/** ISO week of a moment: ['label' => '2026-W42', 'number' => 202642]. */
function iso_week(DateTimeImmutable $at): array
{
    return ['label' => $at->format('o') . '-W' . $at->format('W'), 'number' => (int) ($at->format('o') . $at->format('W'))];
}

/**
 * The `notification` action (the Clerk's notice to an owner): $f member (one recipient) or group (every owner of it), title, body (Markdown), kind (monday_note | proposal). A recipient must be an
 * active person here — an agent, a guest or an outsider is skipped. A monday_note is told once a week per recipient (per group when addressed to one); a proposal notice once a day.
 * Returns ['sent' => n, 'skipped' => n, 'recipients' => ids told].
 */
function notification_send(PDO $pdo, array $f, int $by): array
{
    $kind = (string) ($f['kind'] ?? 'monday_note');
    if (!in_array($kind, ['monday_note', 'proposal'], true)) {
        throw new DomainException('A notice from the Clerk is a monday_note or a proposal.');
    }
    $title = trim((string) ($f['title'] ?? ''));
    $body = trim((string) ($f['body'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) {
        throw new DomainException('A notice has a title of up to 200 characters.');
    }
    if (mb_strlen($body) > 4000) {
        throw new DomainException('The body is up to 4000 characters.');
    }
    $recipients = [];
    $group = isset($f['group']) ? (int) $f['group'] : null;
    if ($group !== null) {
        $st = $pdo->prepare("SELECT gm.member_id FROM group_members gm JOIN groups g ON g.id = gm.group_id WHERE gm.group_id = :g AND gm.role = 'owner' AND gm.left_at IS NULL AND g.archived_at IS NULL ORDER BY gm.member_id");
        $st->execute(['g' => $group]);
        $recipients = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($recipients === [] && one_value($pdo, 'SELECT 1 FROM groups WHERE id = :g', ['g' => $group]) === null) {
            throw new DomainException('Not found.');
        }
    } elseif (isset($f['member'])) {
        $recipients = [(int) $f['member']];
    } else {
        throw new DomainException('Say who it is for: a member, or a group (its owners).');
    }
    $week = iso_week(new DateTimeImmutable());
    $sent = [];
    $skipped = 0;
    foreach ($recipients as $m) {
        $o = ['actor' => $by];
        if ($kind === 'monday_note') {
            $o += ['record_type' => $group !== null ? 'group_week' : 'week', 'record_id' => $group !== null ? $group * 1000000 + $week['number'] : $week['number'], 'once_since' => "'-infinity'",
                   'dedupe' => 'monday_note:' . $week['label'] . ($group !== null ? ':g' . $group : ':note') . ':m' . $m];
        } else {
            $o += ['record_type' => 'clerk', 'once_since' => "date_trunc('day', now())"];
        }
        $id = notify($pdo, $m, $kind, $title, $body, $o);
        if ($id === null) {
            $skipped++;
        } else {
            $sent[] = $m;
        }
    }
    return ['sent' => count($sent), 'skipped' => $skipped, 'recipients' => $sent];
}
