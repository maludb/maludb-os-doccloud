<?php
declare(strict_types=1);
/**
 * /settings/ — the person's own settings (screen `settings`): HOW I AM TOLD (email, text, which events, which by text), MY TIME ZONE (the directory's — changed in the operating system). Texts go to the phone verified in the operating system —
 * this application never sees it; what the kernel last said about it is shown in words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$tab = 'notify';
$prefs = find_prefs($pdo, $me);
$tz = member_timezone();
$refusal = last_text_refusal($pdo, $me);
$recent = $pdo->prepare('SELECT id, kind, channel, subject, status, detail, created_at FROM notification_outbox WHERE member_id = :m ORDER BY id DESC LIMIT 10');
$recent->execute(['m' => $me]);
$recent = $recent->fetchAll();
log_screen_view($pdo, 'settings');
if (wants_json()) {
    respond_screen(['tab' => $tab, 'notify' => present_prefs($prefs) + ['text_note' => $refusal], 'kinds' => NOTICE_KINDS, 'timezone' => $tz,
        'may' => ['settings' => has_right('settings.manage')]]);
}
$notices = ['prefs_saved' => ['success', 'Saved how you are told.'], ];
render_screen('My settings', view('settings/index.php', ['tab' => $tab, 'prefs' => $prefs, 'refusal' => $refusal, 'tz' => $tz, 'may' => ['settings' => has_right('settings.manage')],
    'osChannels' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings/channels', 'osProfile' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings',
    'recent' => $recent, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'settings']);
