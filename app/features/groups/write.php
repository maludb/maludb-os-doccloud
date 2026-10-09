<?php
declare(strict_types=1);

/**
 * The writes of groups. The database makes the group's library with it, copies its name and archiving, seeds a department group's
 * members and the maker as a custom group's owner (db/006).
 */

/** $f: key, name, description?, kind custom|department, department_id?, member_level view|edit. Returns the id. */
function group_create(PDO $pdo, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO groups (key, name, description, kind, department_id, member_level, created_by) VALUES (:k, :n, :d, :kind, :dept, :lvl, :by) RETURNING id');
    $st->execute(['k' => $f['key'], 'n' => $f['name'], 'd' => $f['description'] ?? null, 'kind' => $f['kind'], 'dept' => $f['department_id'] ?? null, 'lvl' => $f['member_level'], 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** Name, description, member level (the library's name follows by trigger). */
function group_update(PDO $pdo, int $id, array $f): void
{
    $pdo->prepare('UPDATE groups SET name = :n, description = :d, member_level = :lvl WHERE id = :id')->execute(['n' => $f['name'], 'd' => $f['description'] ?? null, 'lvl' => $f['member_level'], 'id' => $id]);
}

function group_level_set(PDO $pdo, int $id, string $level): void
{
    $pdo->prepare('UPDATE groups SET member_level = :lvl WHERE id = :id')->execute(['lvl' => $level, 'id' => $id]);
}

/** Add members (people and agents) as $role; one who left before rejoins. Returns how many were added or rejoined. */
function group_member_add(PDO $pdo, int $groupId, array $memberIds, int $by, string $role = 'member'): int
{
    $n = 0;
    $st = $pdo->prepare('INSERT INTO group_members (group_id, member_id, role, added_by) VALUES (:g, :m, :r, :by)
                         ON CONFLICT (group_id, member_id) DO UPDATE SET left_at = NULL, role = EXCLUDED.role, added_by = EXCLUDED.added_by, joined_at = CASE WHEN group_members.left_at IS NULL THEN group_members.joined_at ELSE now() END
                         WHERE group_members.left_at IS NOT NULL');
    foreach ($memberIds as $m) {
        $st->execute(['g' => $groupId, 'm' => $m, 'r' => $role, 'by' => $by]);
        $n += $st->rowCount();
    }
    return $n;
}

/** Date a member out (never deleted). True when they were live. */
function group_member_remove(PDO $pdo, int $groupId, int $memberId): bool
{
    $st = $pdo->prepare('UPDATE group_members SET left_at = now() WHERE group_id = :g AND member_id = :m AND left_at IS NULL');
    $st->execute(['g' => $groupId, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

function group_owner_set(PDO $pdo, int $groupId, int $memberId, bool $owner): bool
{
    $st = $pdo->prepare('UPDATE group_members SET role = :r WHERE group_id = :g AND member_id = :m AND left_at IS NULL');
    $st->execute(['r' => $owner ? 'owner' : 'member', 'g' => $groupId, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

function group_archive(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE groups SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}

function group_restore(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE groups SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a group whose library holds no live document (the handler checked): the library and its folders go with it. */
function group_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM libraries WHERE group_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM groups WHERE id = :id')->execute(['id' => $id]);
}

/** A key from a name: lower-case letters, digits and dashes, unique by suffix. */
function group_key_from(PDO $pdo, string $name, ?string $wanted = null): string
{
    $base = $wanted !== null && $wanted !== '' ? $wanted : trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name))) ?? '', '-');
    $base = substr($base !== '' ? $base : 'group', 0, 50);
    $key = $base;
    $i = 1;
    while (one_value($pdo, 'SELECT 1 FROM groups WHERE key = :k', ['k' => $key]) !== null) {
        $i++;
        $key = substr($base, 0, 46) . '-' . $i;
    }
    return $key;
}
