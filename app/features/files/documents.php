<?php
declare(strict_types=1);

/** The writes of a document (docs/build-specs/uploads-and-versions.md §7) — each one statement or one database function; the referee is the database. */

function document_rename(PDO $pdo, int $id, string $name): void
{
    $pdo->prepare('UPDATE documents SET name = :n WHERE id = :id')->execute(['n' => $name, 'id' => $id]);
}

function document_describe(PDO $pdo, int $id, ?string $description): void
{
    $pdo->prepare('UPDATE documents SET description = :d WHERE id = :id')->execute(['d' => $description === '' ? null : $description, 'id' => $id]);
}

/** Move into another folder (the triggers keep library and bytes; the unique index refuses a taken name; a lock by another refuses). */
function document_move(PDO $pdo, int $id, int $folderId): void
{
    $pdo->prepare('UPDATE documents SET folder_id = :f WHERE id = :id')->execute(['f' => $folderId, 'id' => $id]);
}

/** A copy: a new document in $folderId sharing the current version's blob (ref_count + 1), owned by $by, the tags copied. Returns the new id. */
function document_copy(PDO $pdo, int $id, int $folderId, int $by, ?string $name = null): int
{
    $st = $pdo->prepare('SELECT d.name, d.description, v.blob_id, v.filename, v.mime_type, v.byte_size, v.sha256 FROM documents d JOIN document_versions v ON v.id = d.current_version_id WHERE d.id = :id');
    $st->execute(['id' => $id]);
    $src = $st->fetch();
    if ($src === false) {
        throw new DomainException('That document has no bytes to copy.');
    }
    $name = $name !== null && trim($name) !== '' ? dc_safe_filename($name) : (string) $src['name'];
    $name = (string) one_value($pdo, 'SELECT dc_free_name(:f, :n)', ['f' => $folderId, 'n' => $name]);
    $st = $pdo->prepare('SELECT document_id FROM dc_version_add(NULL, :f, :name, :b, :fn, :m, :n, :s, :by, :src, :note)');
    $st->execute(['f' => $folderId, 'name' => $name, 'b' => (int) $src['blob_id'], 'fn' => $src['filename'], 'm' => $src['mime_type'], 'n' => (int) $src['byte_size'], 's' => $src['sha256'], 'by' => $by,
                  'src' => 'copy', 'note' => 'copied from document ' . $id]);
    $newId = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE documents SET description = :d WHERE id = :id')->execute(['d' => $src['description'], 'id' => $newId]);
    $pdo->prepare('INSERT INTO document_tags (document_id, tag_id, added_by, source) SELECT :new, tag_id, :by, source FROM document_tags WHERE document_id = :old ON CONFLICT DO NOTHING')
        ->execute(['new' => $newId, 'by' => $by, 'old' => $id]);
    return $newId;
}

/** Lock for $by (lock_hours): false when another holds it. */
function document_lock(PDO $pdo, int $id, int $by): bool
{
    return (bool) one_value($pdo, 'SELECT dc_document_lock(:id, :by)', ['id' => $id, 'by' => $by]);
}

/** Unlock: the holder's own, or any lock with $force (a manager's break). */
function document_unlock(PDO $pdo, int $id, int $by, bool $force = false): bool
{
    return (bool) one_value($pdo, 'SELECT dc_document_unlock(:id, :by, :f)', ['id' => $id, 'by' => $by, 'f' => $force ? 'true' : 'false']);
}

/** Ask the worker to read the current version again. */
function document_reextract(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("UPDATE document_versions SET extracted_status = 'pending' WHERE id = (SELECT current_version_id FROM documents WHERE id = :id) AND purged_at IS NULL");
    $st->execute(['id' => $id]);
    return $st->rowCount() === 1;
}

/** Is this document's preview hidden (a row in document_preview_hidden — docs/build-specs/grid-and-media.md)? */
function document_preview_is_hidden(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM document_preview_hidden WHERE document_id = :id)');
    $st->execute(['id' => $id]);
    return (bool) $st->fetchColumn();
}

/** Hide or show a document's preview for everyone. True when it changed. Never touches documents (its updated_at is the "Modified" date). */
function document_preview_hidden_set(PDO $pdo, int $id, bool $hidden, int $by): bool
{
    $st = $hidden
        ? $pdo->prepare('INSERT INTO document_preview_hidden (document_id, hidden_by) VALUES (:id, :by) ON CONFLICT (document_id) DO NOTHING')
        : $pdo->prepare('DELETE FROM document_preview_hidden WHERE document_id = :id');
    $st->execute($hidden ? ['id' => $id, 'by' => $by] : ['id' => $id]);
    return $st->rowCount() > 0;
}

