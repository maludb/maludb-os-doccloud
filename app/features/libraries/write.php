<?php
declare(strict_types=1);

/** The writes of shared libraries (a personal one is made by the mirror; a group's by its group). */

/** A shared library; its root by trigger. $f: name, description?, everyone_level (none|view|upload|edit), graph_policy? */
function library_create(PDO $pdo, array $f, int $by): int
{
    $st = $pdo->prepare("INSERT INTO libraries (kind, name, description, owner_member_id, everyone_level, graph_policy, created_by) VALUES ('shared', :n, :d, :by, :e, :g, :by) RETURNING id");
    $st->execute(['n' => $f['name'], 'd' => $f['description'] ?? null, 'by' => $by, 'e' => $f['everyone_level'] ?? 'view', 'g' => $f['graph_policy'] ?? 'index']);
    $id = (int) $st->fetchColumn();
    return $id;
}

/** Name, description (and the root folder's name follows the library's). */
function library_update(PDO $pdo, int $id, array $f): void
{
    $pdo->prepare('UPDATE libraries SET name = :n, description = :d WHERE id = :id')->execute(['n' => $f['name'], 'd' => $f['description'] ?? null, 'id' => $id]);
    $pdo->prepare('UPDATE folders SET name = :n WHERE id = (SELECT root_folder_id FROM libraries WHERE id = :id)')->execute(['n' => $f['name'], 'id' => $id]);
}

function library_everyone_set(PDO $pdo, int $id, string $level): void
{
    $pdo->prepare('UPDATE libraries SET everyone_level = :e WHERE id = :id')->execute(['e' => $level, 'id' => $id]);
}

function library_archive(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE libraries SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}

function library_restore(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE libraries SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

/** Delete an EMPTY shared library (the handler checked); its root folder goes with it. */
function library_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM libraries WHERE id = :id')->execute(['id' => $id]);
}
