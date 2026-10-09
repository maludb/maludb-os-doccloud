<?php
declare(strict_types=1);

/**
 * The writes of the trash — SQL only (the database keeps the rules: db/007 dc_document_trash / restore / purge, db/006 dc_folder_trash / restore).
 * The controllers parse, judge the level and the right, and log.
 */

function document_trash(PDO $pdo, int $id, int $by): bool
{
    return (bool) one_value($pdo, 'SELECT dc_document_trash(:id, :by)', ['id' => $id, 'by' => $by]);
}

/** Back from the trash. Returns ['restored', 'folder_id', 'name', 'to_root', 'renamed'] — to_root: the old folder was gone; renamed: the name was taken. */
function document_restore(PDO $pdo, int $id): array
{
    $before = trashed_document($pdo, $id);
    $ok = (bool) one_value($pdo, 'SELECT dc_document_restore(:id)', ['id' => $id]);
    $after = trashed_document($pdo, $id);
    if (!$ok || $before === null || $after === null) {
        return ['restored' => false];
    }
    return ['restored' => true, 'folder_id' => (int) $after['folder_id'], 'name' => $after['name'], 'to_root' => (int) $after['folder_id'] !== (int) $before['folder_id'], 'renamed' => $after['name'] !== $before['name']];
}

/** A trashed document, final: versions released, the row deleted, the engine's forget queued by db/012's trigger. */
function document_purge(PDO $pdo, int $id): bool
{
    return (bool) one_value($pdo, 'SELECT dc_document_purge(:id)', ['id' => $id]);
}

/** A trashed folder with its subtree, final and now: every document under it purged (trashed first when it was not), then the folders deleted. Returns [documents, folders]. */
function folder_purge(PDO $pdo, int $id): array
{
    $f = $pdo->prepare('SELECT path FROM folders WHERE id = :id');
    $f->execute(['id' => $id]);
    $path = (string) $f->fetchColumn();
    $docs = $pdo->prepare('SELECT id FROM documents WHERE folder_id IN (SELECT id FROM folders WHERE path LIKE :p) ORDER BY id');
    $docs->execute(['p' => $path . '%']);
    $n = 0;
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $did) {
        $pdo->prepare('UPDATE documents SET trashed_at = COALESCE(trashed_at, now()) WHERE id = :id')->execute(['id' => (int) $did]);
        if (document_purge($pdo, (int) $did)) {
            $n++;
        }
    }
    $del = $pdo->prepare('DELETE FROM folders WHERE path LIKE :p AND id <> (SELECT root_folder_id FROM libraries WHERE id = folders.library_id)');
    $del->execute(['p' => $path . '%']);
    return [$n, $del->rowCount()];
}

/**
 * Empty the trash: $dueOnly = what is past the window (or past $days when given); otherwise everything trashed. $libraryId narrows. Returns [documents, folders].
 * Documents go through dc_document_purge (the engine forgets them); a folder goes when it is old enough and holds nothing.
 */
function trash_purge(PDO $pdo, bool $dueOnly, ?int $days = null, ?int $libraryId = null): array
{
    $days ??= $dueOnly ? trash_window_days($pdo) : 0;
    $args = ['d' => $days, 'l' => $libraryId];
    $st = $pdo->prepare("SELECT id FROM documents WHERE trashed_at IS NOT NULL AND trashed_at < now() - make_interval(days => :d) AND (CAST(:l AS bigint) IS NULL OR library_id = :l) ORDER BY trashed_at");
    $st->execute($args);
    $docs = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $did) {
        if (document_purge($pdo, (int) $did)) {
            $docs++;
        }
    }
    $del = $pdo->prepare("DELETE FROM folders f WHERE f.trashed_at IS NOT NULL AND f.parent_id IS NOT NULL AND f.trashed_at < now() - make_interval(days => :d) AND (CAST(:l AS bigint) IS NULL OR f.library_id = :l)
                             AND NOT EXISTS (SELECT 1 FROM documents d JOIN folders c ON c.id = d.folder_id WHERE c.path LIKE f.path || '%')");
    $del->execute($args);
    return [$docs, $del->rowCount()];
}
