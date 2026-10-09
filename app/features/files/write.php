<?php
declare(strict_types=1);

/**
 * The writes of folders and favourites. Each is one statement (or one database function) inside the caller's transaction; the
 * database keeps the rules (a name unique among live siblings, no move under itself or across libraries, a root never moved, the
 * depth) and raises — dc_guard() turns the sentence into a 422 on the field.
 */

/** A new folder under $parentId, owned and made by $by. $f: name, colour?, auto_tags? Returns the id. */
function folder_create(PDO $pdo, int $parentId, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO folders (library_id, parent_id, name, colour, auto_tags, owner_member_id, created_by)
                         VALUES ((SELECT library_id FROM folders WHERE id = :p), :p, :n, :c, CAST(:t AS text[]), :by, :by) RETURNING id');
    $st->execute(['p' => $parentId, 'n' => $f['name'], 'c' => $f['colour'] ?? null, 't' => pg_array_literal($f['auto_tags'] ?? []), 'by' => $by]);
    return (int) $st->fetchColumn();
}

function folder_rename(PDO $pdo, int $id, string $name): void
{
    $pdo->prepare('UPDATE folders SET name = :n WHERE id = :id')->execute(['n' => $name, 'id' => $id]);
}

function folder_colour_set(PDO $pdo, int $id, ?string $colour): void
{
    $pdo->prepare('UPDATE folders SET colour = :c WHERE id = :id')->execute(['c' => $colour, 'id' => $id]);
}

function folder_auto_tags_set(PDO $pdo, int $id, array $slugs): void
{
    $pdo->prepare('UPDATE folders SET auto_tags = CAST(:t AS text[]) WHERE id = :id')->execute(['t' => pg_array_literal($slugs), 'id' => $id]);
}

/** Move under another folder (the trigger refuses under-itself, across libraries, a root, too deep). */
function folder_move(PDO $pdo, int $id, int $targetId): void
{
    $pdo->prepare('UPDATE folders SET parent_id = :t WHERE id = :id')->execute(['t' => $targetId, 'id' => $id]);
}

/** To the trash with its subtree (dc_folder_trash): how many folders went. */
function folder_trash(PDO $pdo, int $id, int $by): int
{
    return (int) one_value($pdo, 'SELECT dc_folder_trash(:id, :by)', ['id' => $id, 'by' => $by]);
}

function folder_restore(PDO $pdo, int $id): int
{
    return (int) one_value($pdo, 'SELECT dc_folder_restore(:id)', ['id' => $id]);
}

/** Star a folder or a document for $by. True when it was not starred before. */
function favourite_add(PDO $pdo, int $by, ?int $documentId, ?int $folderId): bool
{
    $st = $pdo->prepare('INSERT INTO favourites (member_id, document_id, folder_id) VALUES (:m, :d, :f) ON CONFLICT DO NOTHING');
    $st->execute(['m' => $by, 'd' => $documentId, 'f' => $folderId]);
    return $st->rowCount() === 1;
}

function favourite_remove(PDO $pdo, int $by, ?int $documentId, ?int $folderId): bool
{
    $st = $pdo->prepare('DELETE FROM favourites WHERE member_id = :m AND document_id IS NOT DISTINCT FROM :d AND folder_id IS NOT DISTINCT FROM :f');
    $st->execute(['m' => $by, 'd' => $documentId, 'f' => $folderId]);
    return $st->rowCount() === 1;
}
