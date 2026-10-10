<?php
declare(strict_types=1);

/** Writes of comments: one INSERT, one DELETE. The controllers judge (the level on the thing) and log. */

/** Add a comment; returns its id. */
function note_add(PDO $pdo, string $kind, int $id, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:t, :id, :by, :b) RETURNING id');
    $st->execute(['t' => $kind, 'id' => $id, 'by' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

/** Delete a comment; true when it was there. */
function note_delete(PDO $pdo, int $noteId): bool
{
    $st = $pdo->prepare('DELETE FROM notes WHERE id = :id');
    $st->execute(['id' => $noteId]);
    return $st->rowCount() === 1;
}
