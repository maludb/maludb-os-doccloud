<?php
declare(strict_types=1);

/**
 * Reads of the trash — dc_trash() / dc_trash_due() (db/014, which keep the wall: what the caller owns, trashed, or manages), with the names a table needs.
 * A document trashed WITH its folder (the same stamp) is left out: it comes back with the folder, and the folder is the row.
 */

/** The caller's trash, newest first; $folderId = a folder's trash (its managers). Rows: kind, id, name, trashed_at, trashed_by, trashed_by_name, purge_at, from_folder_id, from_folder_name, mime_kind, byte_size. */
function my_trash(PDO $pdo, ?int $folderId = null): array
{
    $st = $pdo->prepare("SELECT t.kind, t.id, t.name, t.trashed_at, t.trashed_by, m.display_name AS trashed_by_name, t.purge_at, t.from_folder_id, ff.name AS from_folder_name,
                                d.mime_kind, d.byte_size, COALESCE(d.library_id, f.library_id) AS library_id
                           FROM dc_trash(:f) t
                           LEFT JOIN members m ON m.id = t.trashed_by
                           LEFT JOIN folders ff ON ff.id = t.from_folder_id
                           LEFT JOIN documents d ON t.kind = 'document' AND d.id = t.id
                           LEFT JOIN folders f ON t.kind = 'folder' AND f.id = t.id
                          WHERE NOT (t.kind = 'document' AND EXISTS (SELECT 1 FROM folders fx WHERE fx.id = d.folder_id AND fx.trashed_at = d.trashed_at))
                          ORDER BY t.trashed_at DESC, t.kind, t.id");
    $st->execute(['f' => $folderId]);
    return $st->fetchAll();
}

/** What purges within $days (default 7): kind, id, name, purge_at, owner_member_id (dc_trash_due). */
function trash_due(PDO $pdo, int $days = 7): array
{
    $st = $pdo->prepare('SELECT * FROM dc_trash_due(:d)');
    $st->execute(['d' => $days]);
    return $st->fetchAll();
}

/** The trash window in days (retention). */
function trash_window_days(PDO $pdo): int
{
    return (int) one_value($pdo, "SELECT dc_setting_int('trash_days')");
}

/** A trashed document by id (any trashed state) with the fields a handler needs, or null. */
function trashed_document(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT d.*, f.name AS folder_name FROM documents d LEFT JOIN folders f ON f.id = d.folder_id WHERE d.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
