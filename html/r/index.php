<?php
declare(strict_types=1);
/**
 * THE REQUEST DOOR /r/<token> (visibility.md §6; `public-request`): the maker's display name and the message, a name field, the drop zone; nothing of
 * the folder is shown — not its name, not its contents, not earlier uploads. The rate rule per address (60 opens a minute; 20 uploads an hour).
 * POST …/upload = one file per request (≤ 64 MB) → upload_one as the maker (source request, keep both), the request tag, the maker told; the
 * uploader sees "thank you" and nothing else. The password step as /s/.
 */
require_once dirname(__DIR__, 2) . '/app/features/links/public.php';
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '', false)");
$raw = (string) ($_GET['token'] ?? '');
$rest = (string) ($_GET['rest'] ?? '');
$hash = public_address_hash();
public_rate_or_refuse($pdo, 'open_minute', null);
$link = link_resolve($pdo, $raw);
if ($link === null || $link['kind'] !== 'request') {
    public_unavailable('not_found', $raw);
}
$linkId = (int) $link['link_id'];
$GLOBALS['__public_link_id'] = $linkId;
$base = '/r/' . $raw;
if (!public_password_ok($link)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $rest === '/password') {
        verify_csrf();
        if (!public_password_try($pdo, $link, (string) ($_POST['password'] ?? ''))) {
            public_render($link['label'], view('public/password.php', ['link' => $link, 'base' => $base, 'wrong' => true]), 403);
        }
        header('Location: ' . $base, true, 302);
        exit;
    }
    public_render($link['label'], view('public/password.php', ['link' => $link, 'base' => $base, 'wrong' => false]));
}
if ($rest === '/upload' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/upload.php';
    exit;
}
if ($rest !== '' && $rest !== '/') {
    public_unavailable('not_found');
}
$maxBytes = min(64 * 1024 * 1024, (int) one_value($pdo, "SELECT dc_setting_int('max_document_bytes')"));
if (($_GET['done'] ?? '') === '1') {
    public_render('Thank you', view('public/thanks.php', ['link' => $link, 'base' => $base]));
}
link_open($pdo, $linkId, 'view', $hash, null);
log_activity($pdo, 'link.open', 'link', $linkId, ['actor_member_id' => null, 'folder_id' => (int) $link['folder_id'], 'after' => ['link_id' => $linkId, 'what' => 'view']]);
public_render($link['label'], view('public/request.php', ['link' => $link, 'base' => $base, 'maxBytes' => $maxBytes]));
