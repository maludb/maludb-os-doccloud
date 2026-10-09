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
