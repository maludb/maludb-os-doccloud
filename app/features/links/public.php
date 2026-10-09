<?php
declare(strict_types=1);

/**
 * The public doors' helpers (visibility.md §5–6): the address hash (never the address), the rate rule, the bare page (no shell, no names, noindex),
 * the same 404 for every reason, the session flags (a password accepted, a link admitted to the document gate). No member, no action token:
 * the token is the authority. source = 'portal' on every log row.
 */
$GLOBALS['__public_accept'] = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');   // what the caller asked (the upload script reads it)
$_SERVER['HTTP_ACCEPT'] = 'text/html';                 // a browser door: no JSON branch, whatever a caller asks (the kit's 501 rule is for screens)
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__, 2) . '/doccloud/markdown.php';

$GLOBALS['__public_door'] = 'portal';

function public_address_hash(): string
{
    return hash('sha256', client_ip() . '|' . date('Y-m-d') . '|' . (string) env('ACTION_TOKEN_KEY', ''));
}

/** A bare public page (the design-system assets, no navigation, noindex). */
function public_render(string $title, string $html, int $status = 200, array $o = []): never
{
    http_response_code($status);
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo view('public/layout.php', ['title' => $title, 'content' => $html] + $o);
    exit;
}

/** The same nothing for every reason — logged link.refused with the reason (the link's id when the token names one, dead or alive). */
function public_unavailable(string $why = 'not_found', ?string $raw = null): never
{
    $pdo = db();
    $linkId = $GLOBALS['__public_link_id'] ?? null;
    if ($linkId === null && $raw !== null && preg_match('/^[a-f0-9]{48}$/', $raw)) {
        $st = $pdo->prepare('SELECT id, revoked_at, rotated_at, expires_at FROM share_links WHERE token_hash = :h');
        $st->execute(['h' => hash('sha256', $raw)]);
        if (($l = $st->fetch()) !== false) {
            $linkId = (int) $l['id'];
            $why = $l['revoked_at'] !== null ? 'revoked' : ($l['rotated_at'] !== null ? 'rotated' : ($l['expires_at'] !== null && strtotime((string) $l['expires_at']) <= time() ? 'expired' : $why));
        }
    }
    log_activity($pdo, 'link.refused', 'link', $linkId, ['actor_member_id' => null, 'after' => ['reason' => $why]]);
    public_render('Not available', view('public/unavailable.php', []), 404);
}

/** The rate rule for the page: 60 opens a minute per address (dc_link_rate_ok); refused = a 429 page (counted on the link when it resolved). */
function public_rate_or_refuse(PDO $pdo, string $kind, ?int $linkId): void
{
    $hash = public_address_hash();
    if ((bool) one_value($pdo, 'SELECT dc_link_rate_ok(:h, :k)', ['h' => $hash, 'k' => $kind])) {
        return;
    }
    if ($linkId !== null) {
        link_open($pdo, $linkId, 'rate_refused', $hash);
        log_activity($pdo, 'link.refused', 'link', $linkId, ['actor_member_id' => null, 'after' => ['reason' => 'rate']]);
    }
    if (wants_json()) {
        json_error('rate_limited', 'Too many requests; try again in a minute.', 429);
    }
    public_render('Too many requests', view('public/unavailable.php', ['title' => 'Too many requests', 'message' => 'Too many requests from your address; try again in a minute.']), 429);
}

/** Has this session passed the link's password (or has it none)? */
function public_password_ok(array $link): bool
{
    return empty($link['has_password']) || !empty($_SESSION['link_ok'][(int) $link['link_id']]);
}

/** Admit the link to the document gate (/d/{id}) for this session. */
function public_admit(array $link): void
{
    $_SESSION['link'][(int) $link['link_id']] = ['allow_download' => (bool) $link['allow_download']];
}

/** The password form posted: right → the session flag; wrong → counted, logged, false. */
function public_password_try(PDO $pdo, array $link, string $given): bool
{
    if (!empty($link['password_hash']) && password_verify($given, (string) $link['password_hash'])) {
        $_SESSION['link_ok'][(int) $link['link_id']] = true;
        return true;
    }
    link_open($pdo, (int) $link['link_id'], 'password_refused', public_address_hash());
    log_activity($pdo, 'link.refused', 'link', (int) $link['link_id'], ['actor_member_id' => null, 'after' => ['reason' => 'password']]);
    return false;
}

/** The document gate's view for a link: the preview partial's data (as the document page), read as the writer. */
function public_preview(PDO $pdo, array $doc): array
{
    $text = null;
    if ($doc['current_version_id'] !== null) {
        $st = $pdo->prepare('SELECT * FROM document_text WHERE version_id = :v');
        $st->execute(['v' => (int) $doc['current_version_id']]);
        $text = $st->fetch() ?: null;
    }
    $d = $doc + ['document_id' => (int) $doc['id']];
    return document_preview($pdo, $d, $text);
}
