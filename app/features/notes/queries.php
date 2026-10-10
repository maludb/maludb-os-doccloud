<?php
declare(strict_types=1);

/** Reads of comments — `notes` (GL's canonical table) through mcp_notes, which already holds the wall (the thing's visibility). */

/** The comments on a document or a folder the caller may see, newest first: note_id, record_type, record_id, member_id, author, body, created_at. */
function find_notes(PDO $pdo, string $kind, int $id, int $limit = 50): array
{
    if (!in_array($kind, ['document', 'folder'], true)) {
        throw new InvalidArgumentException('A comment is on a document or a folder.');
    }
    $st = $pdo->prepare('SELECT n.note_id, n.record_type, n.record_id, n.member_id, m.display_name AS author, n.body, n.created_at
                           FROM mcp_notes n LEFT JOIN members m ON m.id = n.member_id WHERE n.record_type = :t AND n.record_id = :id ORDER BY n.created_at DESC, n.note_id DESC LIMIT :n');
    $st->bindValue('t', $kind);
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('n', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** One comment the caller may see, or null. */
function find_note(PDO $pdo, int $noteId): ?array
{
    $st = $pdo->prepare('SELECT n.note_id, n.record_type, n.record_id, n.member_id, m.display_name AS author, n.body, n.created_at
                           FROM mcp_notes n LEFT JOIN members m ON m.id = n.member_id WHERE n.note_id = :id');
    $st->execute(['id' => $noteId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The JSON shape of a comment. */
function present_note(array $n): array
{
    return ['note_id' => (int) $n['note_id'], 'record_type' => $n['record_type'], 'record_id' => (int) $n['record_id'], 'member_id' => $n['member_id'] === null ? null : (int) $n['member_id'],
            'author' => $n['author'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])];
}
