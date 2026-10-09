<?php
declare(strict_types=1);

/**
 * The shell's menu: ONE table — the sidebar and the phone's tab bar read it, so a menu item and the right that opens its
 * screen can never disagree (design §9). An item shows only when the person holds its right (several joined by "|": any one).
 * Home · My files · Shared with me · Groups · Libraries · Recent · Favourites · Tags · Search · Graph · Trash, then Curate (curators) and
 * Admin (admins); "Me" holds the person's own pages. A folder's and a document's own screens are Phase 3's and hang under them, not here.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    $all = 'library.personal|documents.guest';                       // everyone admitted: a Member holds library.personal, a guest documents.guest
    return [
        'DocCloud' => [
            ['home',       '/',            'feather-home',        'Home',           $all],
            ['files',      '/files/',      'feather-folder',      'My files',       'library.personal'],
            ['shared',     '/shared/',     'feather-users',       'Shared with me', $all],
            ['groups',     '/groups/',     'feather-user-check',  'Groups',         'library.personal'],
            ['libraries',  '/libraries/',  'feather-hard-drive',  'Libraries',      'library.personal'],
            ['recent',     '/recent',      'feather-clock',       'Recent',         'library.personal'],
            ['favourites', '/favourites',  'feather-star',        'Favourites',     'library.personal'],
            ['tags',       '/tags/',       'feather-tag',         'Tags',           'tags.apply'],
            ['search',     '/search/',     'feather-search',      'Search',         'search.use'],
            ['graph',      '/graph/',      'feather-share-2',     'Graph',          'graph.use'],
            ['trash',      '/trash/',      'feather-trash-2',     'Trash',          'trash.own'],
        ],
        'Me' => [
            ['my-settings',   '/settings/',        'feather-settings', 'My settings',   $all],
            ['notifications', '/notifications',    'feather-bell',     'Notifications', $all],
            ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        $all],
            ['activity',      '/activity',         'feather-list',     'My activity',   $all],
        ],
        'Curate' => [
            ['curate', '/curate/', 'feather-edit-3', 'Curate', 'groups.manage|libraries.shared|tags.manage'],
        ],
        'Admin' => [
            ['admin', '/admin/', 'feather-sliders', 'Admin', 'settings.manage'],
        ],
    ];
}

/** The phone's tabs (design §9): Home · Files · Search · Me. A guest's Files tab reads Shared with me (a guest has no personal library). */
function nav_tabs(): array
{
    $all = 'library.personal|documents.guest';
    $files = has_right('library.personal') ? ['files', '/files/', 'feather-folder', 'Files', 'library.personal'] : ['shared', '/shared/', 'feather-folder', 'Files', $all];
    return [
        ['home',        '/',          'feather-home',   'Home',   $all],
        $files,
        ['search',      '/search/',   'feather-search', 'Search', 'search.use'],
        ['my-settings', '/settings/', 'feather-user',   'Me',     $all],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest DocCloud role the member holds, in words — the header's badge (db/004). */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $st = db()->prepare('SELECT roles FROM members WHERE id = :id');
    $st->execute(['id' => (int) $m['id']]);
    $held = pg_text_array((string) ($st->fetchColumn() ?: '{}'));
    if ($held === []) {
        return match ($m['capability'] ?? '') { 'admin' => 'DocCloud admin', 'read' => 'Guest', default => 'Member' };
    }
    foreach (['admin' => 'DocCloud admin', 'curator' => 'Curator', 'user' => 'Member', 'guest' => 'Guest'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return 'Member';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/files/' => 'My files', '/shared/' => 'Shared with me', '/groups/' => 'Groups', '/libraries/' => 'Libraries', '/recent' => 'Recent',
               '/favourites' => 'Favourites', '/tags/' => 'Tags', '/search/' => 'Search', '/graph/' => 'Graph', '/trash/' => 'Trash', '/notifications' => 'Notifications',
               '/activity' => 'My activity', '/settings/' => 'My settings', '/settings/tokens/' => 'Tokens', '/curate/' => 'Curate', '/admin/' => 'Admin'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/folders/\d+$#', $path)) {
        return [$back, 'the folder'];
    }
    if (preg_match('#^/documents/\d+$#', $path)) {
        return [$back, 'the document'];
    }
    if (preg_match('#^/libraries/\d+$#', $path)) {
        return [$back, 'the library'];
    }
    if (preg_match('#^/groups/\d+$#', $path)) {
        return [$back, 'the group'];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it. */
const NAV_SLICES = ['files' => 'slice 1', 'shared' => 'slice 3', 'groups' => 'slice 1', 'libraries' => 'slice 1', 'recent' => 'slice 1', 'favourites' => 'slice 1',
    'tags' => 'slice 4', 'search' => 'slice 4', 'graph' => 'slice 5', 'curate' => 'slice 9', 'admin' => 'slice 9'];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
