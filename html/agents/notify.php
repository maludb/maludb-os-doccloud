<?php
declare(strict_types=1);
/**
 * Action `notification` (member — or group, every owner of it —, title, body, kind monday_note | proposal; log notification.send): the Clerk (or the admin) tells a PERSON on the bell and, by their
 * preferences, by mail and text. An agent, a guest or an outsider as recipient is skipped (never an error). A Monday note is told once a week per recipient (per group when addressed to one);
 * a proposal notice once a day. At most 60 an hour (429 after). Only the Clerk or a holder of agents.settings.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!caller_is_clerk($pdo) && !has_right('agents.settings')) {
    refuse(403, 'Only the Filing Clerk, or the admin, sends these notices.');
}
if (notifications_sent_by_last_hour($pdo, $me) >= NOTIFICATION_RATE_PER_HOUR) {
    refuse(429, 'That is enough notices for this hour; the rest can wait for the next.');
}
$errors = [];
if (!req_has('member') && !req_has('group')) {
    $errors['member'] = 'Say who it is for: a member, or a group (its owners).';
}
if ((string) (req_val('title') ?? '') === '') {
    $errors['title'] = 'Give the notice a title.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$f = ['member' => request_integer('member'), 'group' => request_integer('group'), 'title' => (string) req_val('title'), 'body' => (string) (req_val('body') ?? ''), 'kind' => (string) (req_val('kind') ?: 'monday_note')];
if ($f['member'] === null && $f['group'] === null) {
    dc_refuse_fields(['member' => 'Say who it is for: a member, or a group (its owners).']);
}
$r = dc_guard($pdo, static function () use ($pdo, $f, $me): array {
    $pdo->beginTransaction();
    $r = notification_send($pdo, $f, $me);
    log_activity($pdo, 'notification.send', 'notification', null, ['after' => ['kind' => $f['kind'], 'member_id' => $f['member'], 'group_id' => $f['group'], 'sent' => $r['sent'], 'skipped' => $r['skipped']]]);
    $pdo->commit();
    return $r;
});
dc_done($r['sent'] > 0 ? 'Told ' . $r['sent'] . ' ' . ($r['sent'] === 1 ? 'person' : 'people') : 'Nobody was told (already told, or not a person here)', null, dc_land('/curate/proposals', 'noted'), 'notificationChanged',
    ['sent' => $r['sent'], 'skipped' => $r['skipped']]);
