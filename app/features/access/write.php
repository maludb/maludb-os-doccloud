<?php
declare(strict_types=1);

/**
 * The writes of sharing (slice 3): one row per principal on a folder or a document, its level, its removal, the restrict. Each is one
 * statement inside the caller's transaction; the database carries the inherited set along on the first row (the trigger), keeps the
 * trail (access_changes), recomputes the access roots and refuses a bad principal shape (dc_guard → 422).
 */

/** A share: insert or raise the level. Returns [access_id, created (bool)]. */
function share_add(PDO $pdo, string $kind, int $id, string $principalKind, ?int $principalId, ?string $ref, string $level, int $by): array
{
    $table = $kind === 'folder' ? 'folder_access' : 'document_access';
    $col = $kind === 'folder' ? 'folder_id' : 'document_id';
    $st = $pdo->prepare("SELECT id, level FROM $table WHERE $col = :id AND principal_kind = :k AND principal_id IS NOT DISTINCT FROM :p AND principal_ref IS NOT DISTINCT FROM :r");
    $st->execute(['id' => $id, 'k' => $principalKind, 'p' => $principalId, 'r' => $ref]);
    $existing = $st->fetch();
    if ($existing !== false) {
        if ($existing['level'] !== $level) {
            $pdo->prepare("UPDATE $table SET level = :l, granted_by = :by WHERE id = :id")->execute(['l' => $level, 'by' => $by, 'id' => (int) $existing['id']]);
        }
        return [(int) $existing['id'], false];
    }
    $st = $pdo->prepare("INSERT INTO $table ($col, principal_kind, principal_id, principal_ref, level, granted_by) VALUES (:id, :k, :p, :r, :l, :by) RETURNING id");
    $st->execute(['id' => $id, 'k' => $principalKind, 'p' => $principalId, 'r' => $ref, 'l' => $level, 'by' => $by]);
    return [(int) $st->fetchColumn(), true];
}

function share_level_set(PDO $pdo, string $kind, int $accessId, string $level, int $by): void
{
    $table = $kind === 'folder' ? 'folder_access' : 'document_access';
    $pdo->prepare("UPDATE $table SET level = :l, granted_by = :by WHERE id = :id")->execute(['l' => $level, 'by' => $by, 'id' => $accessId]);
}

function share_remove(PDO $pdo, string $kind, int $accessId): void
{
    $table = $kind === 'folder' ? 'folder_access' : 'document_access';
    $pdo->prepare("DELETE FROM $table WHERE id = :id")->execute(['id' => $accessId]);
}

/** Restrict: keep only these rows ([{principal_kind, principal_id, principal_ref, level}]); the maker is kept at manage when the set is empty (dc_folder_restrict). */
function folder_restrict(PDO $pdo, int $folderId, array $rows, int $by): int
{
    $st = $pdo->prepare('SELECT dc_folder_restrict(:f, CAST(:rows AS jsonb), :by)');
    $st->execute(['f' => $folderId, 'rows' => json_encode(array_values($rows), JSON_THROW_ON_ERROR), 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** The members a principal reaches now (for the share_received notifications): a person, a group's or a department's live members. */
function principal_members(PDO $pdo, string $principalKind, ?int $principalId): array
{
    return match ($principalKind) {
        'member', 'agent', 'guest' => $principalId === null ? [] : [$principalId],
        'group' => array_map('intval', array_column($pdo->query("SELECT gm.member_id FROM group_members gm JOIN members m ON m.id = gm.member_id AND m.status = 'active' WHERE gm.group_id = " . (int) $principalId . ' AND gm.left_at IS NULL')->fetchAll(), 'member_id')),
        'department' => array_map('intval', array_column($pdo->query("SELECT dm.member_id FROM department_members dm JOIN members m ON m.id = dm.member_id AND m.status = 'active' WHERE dm.department_id = " . (int) $principalId . ' AND dm.left_at IS NULL')->fetchAll(), 'member_id')),
        default => [],
    };
}

/** The bell (and the outbox when the person asks) for a share: deduped per thing and member. */
function share_notify(PDO $pdo, string $kind, int $id, string $name, string $principalKind, ?int $principalId, string $level, int $by): int
{
    $n = 0;
    $byName = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $by]);
    foreach (principal_members($pdo, $principalKind, $principalId) as $memberId) {
        if ($memberId === $by) {
            continue;
        }
        $st = $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, :a, :dd)');
        $st->execute(['m' => $memberId, 'k' => 'share_received', 't' => $byName . ' shared ' . $name . ' with you', 'b' => 'You may ' . ($level === 'manage' ? 'manage' : ($level === 'edit' ? 'edit' : ($level === 'upload' ? 'add to' : 'view'))) . ' the ' . $kind . ' ' . $name . '.',
            'rt' => $kind, 'rid' => $id, 'd' => $kind === 'document' ? $id : null, 'f' => $kind === 'folder' ? $id : null, 'a' => $by, 'dd' => 'share:' . $kind . ':' . $id . ':' . $memberId]);
        if ($st->fetchColumn() !== null) {
            $n++;
        }
    }
    return $n;
}
