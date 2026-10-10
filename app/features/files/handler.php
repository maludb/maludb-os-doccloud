<?php
declare(strict_types=1);

/**
 * THE CRUD EXEMPLAR's prelude (slice 1, docs/build-specs/libraries-groups-folders.md): what every controller of files, folders,
 * libraries and groups requires first. The walls are the database's (db/008); here they become the record readers every handler
 * starts with — a thing the caller may not see at all is "Not found." (404, the same nothing for a thing that does not exist); a
 * thing they see but may not touch at the level asked is a 403 in words. files_log() stamps library_id / folder_id / document_id on
 * every row (design §6.4), so a library's, a folder's and a document's trail is one query.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/libraries/queries.php';
require_once dirname(__DIR__) . '/libraries/write.php';
require_once dirname(__DIR__) . '/groups/queries.php';
require_once dirname(__DIR__) . '/groups/write.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/versions.php';
require_once __DIR__ . '/exports.php';

/** The level's sentence when a person holds less than a handler needs. */
const DC_LEVEL_WORDS = ['view' => 'see', 'upload' => 'add to', 'edit' => 'change', 'manage' => 'manage'];

/** The folder a request names (`folder`, `parent`, `id` …) at the level needed: the row with my_level; 404 for none, 403 for less. */
function folder_at_level(PDO $pdo, ?int $id, string $need = 'view', string $what = 'folder'): array
{
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which ' . $what . '.');
    }
    $st = $pdo->prepare('SELECT * FROM folders WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    $have = $row === false ? 'none' : dc_folder_level($pdo, $id);
    if ($row === false || $have === 'none') {
        dc_not_found('Not found.');
    }
    if (!dc_level_at_least($have, $need)) {
        refuse(403, 'You may not ' . (DC_LEVEL_WORDS[$need] ?? $need) . ' this ' . $what . '.');
    }
    dc_note_admin_view($pdo, (int) $row['library_id']);
    $row['my_level'] = $have;
    return $row;
}

function folder_from_request(PDO $pdo, string $need = 'view', string $param = 'folder'): array
{
    return folder_at_level($pdo, request_integer($param) ?? request_integer('id'), $need);
}

/** The document a request names at the level needed (slice 2 builds its screens; the favourites and the listing need the gate now). */
function document_at_level(PDO $pdo, ?int $id, string $need = 'view'): array
{
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which document.');
    }
    $st = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    $have = $row === false ? 'none' : dc_document_level($pdo, $id);
    if ($row === false || $have === 'none') {
        dc_not_found('Not found.');
    }
    if (!dc_level_at_least($have, $need)) {
        refuse(403, 'You may not ' . (DC_LEVEL_WORDS[$need] ?? $need) . ' this document.');
    }
    dc_note_admin_view($pdo, (int) $row['library_id']);
    $row['my_level'] = $have;
    return $row;
}

function document_from_request(PDO $pdo, string $need = 'view', string $param = 'document'): array
{
    return document_at_level($pdo, request_integer($param) ?? request_integer('id'), $need);
}

/** The library a request names, reachable by the caller (a base level, or the admin — logged library.admin_view once per session). */
function library_from_request(PDO $pdo, string $param = 'library'): array
{
    $id = request_integer($param) ?? request_integer('id');
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which library.');
    }
    // a curator reaches every SHARED library (archived ones too — to restore them); everyone else by their base level, the admin everything
    $st = $pdo->prepare('SELECT l.*, dc_library_base_level(l.id, app_current_member_id()) AS base_rank FROM libraries l WHERE l.id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    if ($row !== false && $row['kind'] === 'shared' && has_right('libraries.shared')) {
        dc_note_admin_view($pdo, (int) $row['id']);
        return $row;
    }
    return require_library_reach($pdo, $id);
}

/** The group a request names: the row (mcp_groups) — any member of the business sees every group (404 for a guest or a missing one). */
function group_from_request(PDO $pdo, string $param = 'group'): array
{
    $id = request_integer($param) ?? request_integer('id');
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which group.');
    }
    $g = find_group($pdo, $id);
    if ($g === null) {
        dc_not_found('Not found.');
    }
    return $g;
}

/** May the caller run a group (groups.manage, or an owner of it)? */
function may_run_group(array $g): bool
{
    return has_right('groups.manage') || !empty($g['i_am_owner']);
}

function require_group_owner(array $g): void
{
    if (!may_run_group($g)) {
        refuse(403, 'You may not run this group: its owners and curators do.');
    }
}

/** One activity row with the audit keys of design §6.4 filled from what the handler knows. */
function files_log(PDO $pdo, string $event, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $event, $entityType, $entityId, $opts);
}

/** The eight colours a folder may wear (design §9: eight swatches + none). */
const FOLDER_COLOURS = ['#3454d1' => 'blue', '#0ea5e9' => 'sky', '#10b981' => 'green', '#f59e0b' => 'amber', '#ef4444' => 'red', '#8b5cf6' => 'violet', '#ec4899' => 'pink', '#6b7280' => 'grey'];

/** The level chips' colours (spec "Status vocabulary"). */
const LEVEL_BADGE = ['manage' => 'primary', 'edit' => 'success', 'upload' => 'info', 'view' => 'secondary', 'none' => 'light'];
const LIBRARY_KIND_BADGE = ['personal' => 'light text-dark', 'group' => 'info text-info', 'shared' => 'secondary text-secondary'];
const EVERYONE_LEVELS = ['none' => 'Nobody by default (invitation only)', 'view' => 'Everyone may view', 'upload' => 'Everyone may add (and sees only their own)', 'edit' => 'Everyone may edit'];
const MEMBER_LEVELS = ['view' => 'Members view', 'edit' => 'Members edit'];

/** Bytes in words: 1.5 MB. */
function format_bytes(?int $b): string
{
    if ($b === null) {
        return '';
    }
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < 4) {
        $v /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $b : number_format($v, 1)) . ' ' . $u[$i];
}

/** A kind's icon. */
function kind_icon(?string $kind): string
{
    return match ($kind) {
        'folder' => 'feather-folder', 'image' => 'feather-image', 'pdf' => 'feather-file-text', 'office' => 'feather-file-text', 'text' => 'feather-file-text',
        'audio' => 'feather-music', 'video' => 'feather-film', 'archive' => 'feather-archive', default => 'feather-file',
    };
}

/**
 * The listing's layout, list or grid (docs/build-specs/grid-and-media.md): ?view= switches it and is remembered per browser in the dc_view
 * cookie (a phone may keep the grid and a desk the list); the list by default.
 */
function listing_layout(): string
{
    $asked = (string) ($_GET['view'] ?? '');
    if (in_array($asked, ['list', 'grid'], true)) {
        if (!headers_sent() && ($_COOKIE['dc_view'] ?? '') !== $asked) {
            $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('dc_view', $asked, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $asked;
    }
    return ($_COOKIE['dc_view'] ?? '') === 'grid' ? 'grid' : 'list';
}

/** The kinds whose preview may be hidden (the owner: "an image or a video"). */
const PREVIEW_HIDEABLE = ['image', 'video'];

/** Video types no browser plays inline: the page says so and offers Download (G3 — no transcoding). */
function video_unplayable(string $mime): bool
{
    return in_array(strtolower($mime), ['video/x-msvideo', 'video/avi', 'video/msvideo', 'video/x-ms-asf', 'video/x-ms-wmv', 'video/x-ms-wm', 'video/x-flv', 'video/mpeg', 'video/mp2t', 'video/x-mpeg'], true);
}

/**
 * A file's family for its grid icon (docs/build-specs/grid-and-media.md): finer than mime_kind — a Word document, a spreadsheet and a deck are
 * all `office`. Answers [family, feather icon, colour class, extension label].
 */
function file_family(?string $mimeKind, ?string $mimeType = null, ?string $name = null): array
{
    $ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
    $mime = strtolower((string) $mimeType);
    $family = match (true) {
        $mimeKind === 'folder' => 'folder',
        $mimeKind === 'image' => 'image',
        $mimeKind === 'video' => 'video',
        $mimeKind === 'audio' => 'audio',
        $mimeKind === 'pdf' => 'pdf',
        $mimeKind === 'archive' => 'archive',
        in_array($ext, ['xls', 'xlsx', 'xlsm', 'ods', 'csv', 'tsv', 'numbers'], true) || str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || $mime === 'text/csv' => 'sheet',
        in_array($ext, ['ppt', 'pptx', 'odp', 'key'], true) || str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint') => 'slides',
        in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'pages'], true) || str_contains($mime, 'wordprocessing') || $mime === 'application/msword' || $mime === 'application/rtf' => 'word',
        in_array($ext, ['json', 'xml', 'yml', 'yaml', 'js', 'mjs', 'ts', 'py', 'php', 'rb', 'go', 'java', 'c', 'h', 'cpp', 'cs', 'sql', 'sh', 'html', 'htm', 'css', 'svg', 'toml', 'ini'], true) || $mime === 'application/json' || $mime === 'application/xml' => 'code',
        $mimeKind === 'text' || $mimeKind === 'office' => $mimeKind === 'text' ? 'text' : 'word',
        default => 'other',
    };
    [$icon, $colour] = match ($family) {
        'folder' => ['feather-folder', 'ft-folder'], 'image' => ['feather-image', 'ft-image'], 'video' => ['feather-film', 'ft-video'], 'audio' => ['feather-music', 'ft-audio'],
        'pdf' => ['feather-file-text', 'ft-pdf'], 'archive' => ['feather-archive', 'ft-archive'], 'sheet' => ['feather-grid', 'ft-sheet'], 'slides' => ['feather-monitor', 'ft-slides'],
        'word' => ['feather-file-text', 'ft-word'], 'code' => ['feather-code', 'ft-code'], 'text' => ['feather-align-left', 'ft-text'], default => ['feather-file', 'ft-other'],
    };
    $label = $family === 'folder' ? '' : strtoupper(mb_substr($ext !== '' && preg_match('/^[a-z0-9]{1,6}$/', $ext) ? $ext : ($family === 'other' ? 'file' : $family), 0, 5));
    return ['family' => $family, 'icon' => $icon, 'colour' => $colour, 'label' => $label];
}

/** A length in words for a badge: 0:42, 12:05, 1:02:09. */
function format_duration(?int $ms): string
{
    if ($ms === null) {
        return '';
    }
    $t = (int) round($ms / 1000);
    return $t >= 3600 ? sprintf('%d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60) : sprintf('%d:%02d', intdiv($t, 60), $t % 60);
}

/** The "Preview hidden" placeholder the thumbnail door serves while a document's preview is hidden (no image of the file at all). */
function preview_hidden_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 320 320"><rect width="320" height="320" rx="24" fill="#e9ecf2"/>'
        . '<g transform="translate(112 96) scale(4)" fill="none" stroke="#8a93a6" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></g>'
        . '<text x="160" y="236" text-anchor="middle" font-family="sans-serif" font-size="24" fill="#6b7280">Preview hidden</text></svg>';
}

/**
 * Actions `document_preview_hide` / `document_preview_show` (docs/build-specs/grid-and-media.md): the caller holds documents.edit and edit on the
 * document (the controller checked both); only an image or a video (422 on `document` otherwise). A lock does not stop it — the preview setting is
 * not the content. Logged once when it changed, with the name and the kind. HTMX with swap=tile answers the re-rendered grid tile; JSON the result
 * contract; a browser lands on return_to with a notice.
 */
function preview_hidden_action(PDO $pdo, array $doc, bool $hidden): never
{
    $id = (int) $doc['id'];
    if (!in_array((string) $doc['mime_kind'], PREVIEW_HIDEABLE, true)) {
        dc_refuse_fields(['document' => 'Only an image\'s or a video\'s preview can be hidden.']);
    }
    $changed = (bool) dc_guard($pdo, static fn (): bool => document_preview_hidden_set($pdo, $id, $hidden, (int) current_member_id()));
    if ($changed) {
        files_log($pdo, $hidden ? 'document.preview_hide' : 'document.preview_show', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'],
            'library_id' => (int) $doc['library_id'], 'after' => ['name' => $doc['name'], 'mime_kind' => $doc['mime_kind'], 'preview_hidden' => $hidden]]);
    }
    $did = ($changed ? ($hidden ? 'Hid the preview of ' : 'Showed the preview of ') : ($hidden ? 'The preview was already hidden: ' : 'The preview was already shown: ')) . $doc['name'];
    if (is_htmx_request() && !wants_json() && req_val('swap') === 'tile') {
        emit_action_status(true, ['did' => $did, 'record_id' => $id, 'refresh' => 'documentChanged']);
        $row = grid_row_for_document($pdo, $id);
        if ($row !== null) {
            hx_trigger('documentChanged');
            echo view('files/partials/tile.php', ['r' => $row, 'tz' => member_timezone(), 'select' => req_val('select') === '1', 'stars' => my_favourite_ids($pdo),
                'back' => safe_local_path($_POST['return_to'] ?? null) ?? '', 'mayEditRight' => true]);
            exit;
        }
    }
    dc_done($did, $id, dc_land(return_path('/documents/' . $id), $hidden ? 'preview_hidden' : 'preview_shown'), 'documentChanged');
}

/** An UploadRefused becomes the answer: JSON {error: {code, message, ...extra}} with its status; a browser the sentence (a 409 names the document). */
function upload_refused(UploadRefused $e): never
{
    emit_action_status(false, ['error' => $e->getMessage(), 'code' => $e->reason] + $e->extra);
    if (wants_json()) {
        json_response(['ok' => false, 'error' => ['code' => $e->reason, 'message' => $e->getMessage()] + $e->extra], $e->status);
    }
    refuse($e->status, $e->getMessage());
}

/** Where a raw-body request's bytes go: a temp file in the store's uploads/ (never memory), or the uploaded `part`/`file` field's tmp_name. */
function request_bytes_to_file(string $field = 'part'): ?string
{
    if (isset($_FILES[$field]) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return $_FILES[$field]['tmp_name'];
    }
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_starts_with($ct, 'multipart/form-data') || str_starts_with($ct, 'application/x-www-form-urlencoded')) {
        return null;
    }
    $dir = dc_storage_path('uploads/tmp');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }
    $tmp = $dir . '/' . bin2hex(random_bytes(8));
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    if ($in === false || $out === false) {
        return null;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $tmp;
}
