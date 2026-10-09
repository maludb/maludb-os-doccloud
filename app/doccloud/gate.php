<?php
declare(strict_types=1);

/**
 * THE WALLS AS PHP USES THEM (docs/build-specs/visibility.md §1). The database decides (dc_document_level / dc_folder_level, db/008);
 * PHP asks once per record per request and shows the sentence: a record the caller may not see at the level asked is "Not found."
 * (404, never 403 — a guest learns nothing from the difference). The admin's reach is logged: the first time per session an admin opens
 * a library they hold no base level in, `library.admin_view` is logged (sso-shell.md "DocCloud is not Knowledge").
 */

const DC_LEVELS = ['none' => 0, 'view' => 1, 'upload' => 2, 'edit' => 3, 'manage' => 4];

function dc_level_at_least(string $have, string $need): bool
{
    return (DC_LEVELS[$have] ?? 0) >= (DC_LEVELS[$need] ?? 99);
}

/** 'none'|'view'|'upload'|'edit'|'manage' for the caller on a document (cached for the request). */
function dc_document_level(PDO $pdo, int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $st = $pdo->prepare('SELECT dc_document_level(:id)');
        $st->execute(['id' => $id]);
        $cache[$id] = (string) ($st->fetchColumn() ?: 'none');
    }
    return $cache[$id];
}

function dc_folder_level(PDO $pdo, int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $st = $pdo->prepare('SELECT dc_folder_level(:id)');
        $st->execute(['id' => $id]);
        $cache[$id] = (string) ($st->fetchColumn() ?: 'none');
    }
    return $cache[$id];
}

/** The caller's principals once per request: [[kind, id], …] (for screens that group by principal). */
function dc_my_principals(PDO $pdo): array
{
    static $rows = null;
    return $rows ??= array_map(static fn (array $r): array => [$r['kind'], $r['id'] === null ? null : (int) $r['id']], $pdo->query('SELECT kind, id FROM dc_my_principals()')->fetchAll());
}

/** The document row (as the writer) when the caller holds at least $need on it — else 404. Logs the admin's view of a foreign library once per session. */
function require_document_level(PDO $pdo, int $id, string $need = 'view'): array
{
    require_login();
    $st = $pdo->prepare('SELECT * FROM documents WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    $have = $row === false ? 'none' : dc_document_level($pdo, $id);
    if ($row === false || !dc_level_at_least($have, $need)) {
        dc_not_found('Not found.');
    }
    dc_note_admin_view($pdo, (int) $row['library_id']);
    $row['my_level'] = $have;
    return $row;
}

/** The folder row when the caller holds at least $need on it — else 404. */
function require_folder_level(PDO $pdo, int $id, string $need = 'view'): array
{
    require_login();
    $st = $pdo->prepare('SELECT * FROM folders WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    $have = $row === false ? 'none' : dc_folder_level($pdo, $id);
    if ($row === false || !dc_level_at_least($have, $need)) {
        dc_not_found('Not found.');
    }
    dc_note_admin_view($pdo, (int) $row['library_id']);
    $row['my_level'] = $have;
    return $row;
}

/** The library row when the caller reaches it (a base level, or the admin) — else 404. */
function require_library_reach(PDO $pdo, int $id): array
{
    require_login();
    $st = $pdo->prepare('SELECT l.*, dc_library_base_level(l.id, app_current_member_id()) AS base_rank FROM libraries l WHERE l.id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    if ($row === false || ((int) $row['base_rank'] === 0 && !is_dc_admin())) {
        dc_not_found('Not found.');
    }
    dc_note_admin_view($pdo, (int) $row['id']);
    return $row;
}

/** The admin opening a library they hold no base level in: logged library.admin_view once per session per library. */
function dc_note_admin_view(PDO $pdo, int $libraryId): void
{
    if ($libraryId <= 0 || !is_dc_admin() || !empty($_SESSION['admin_viewed'][$libraryId])) {
        return;
    }
    $st = $pdo->prepare('SELECT dc_library_base_level(:l, app_current_member_id())');
    $st->execute(['l' => $libraryId]);
    if ((int) $st->fetchColumn() > 0) {
        return;
    }
    $_SESSION['admin_viewed'][$libraryId] = true;
    $GLOBALS['__admin_view_library'] = $libraryId;
    log_activity($pdo, 'library.admin_view', 'library', $libraryId, ['library_id' => $libraryId]);
}

/** Is this screen shown as the admin's foreign view (the quiet line "You see this as the admin")? */
function dc_is_admin_view(int $libraryId): bool
{
    return !empty($_SESSION['admin_viewed'][$libraryId]);
}

/** The same nothing for every reason: 404 in words (JSON callers get the code). */
function dc_not_found(string $message = 'Not found.'): never
{
    if (function_exists('refuse')) {
        refuse(404, $message);
    }
    http_response_code(404);
    echo $message;
    exit;
}
