<?php
declare(strict_types=1);

/** Reads for libraries — mcp_libraries, dc_my_libraries(), dc_quota_state(), mcp_group_members (db/014–016). */

/** The libraries the caller reaches, personal first (dc_my_libraries). */
function my_libraries(PDO $pdo): array
{
    return $pdo->query('SELECT library_id, kind, name, group_id, root_folder_id, level, used_bytes, quota_bytes, graph_policy FROM dc_my_libraries()')->fetchAll();
}

/** Every library the admin sees (mcp_libraries), with my_level; $filters: kind, archived. */
function all_libraries(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT * FROM mcp_libraries WHERE 1 = 1';
    $args = [];
    if (!empty($filters['kind'])) { $sql .= ' AND kind = :k'; $args['k'] = $filters['kind']; }
    $sql .= empty($filters['archived']) ? ' AND archived_at IS NULL' : ' AND archived_at IS NOT NULL';
    $sql .= " ORDER BY CASE kind WHEN 'personal' THEN 0 WHEN 'group' THEN 1 ELSE 2 END, lower(name)";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** The cards of /libraries: mine (dc_my_libraries) with counts, plus — for the admin — every other library marked `admin_view`. */
function libraries_for_list(PDO $pdo, array $filters = []): array
{
    $mine = [];
    $admin = is_dc_admin();
    foreach (my_libraries($pdo) as $l) {
        $mine[(int) $l['library_id']] = $l + ['admin_view' => false];
    }
    if ($admin && $mine !== []) {
        // the admin reaches every library through dc_my_libraries(): mark the ones they hold no base level in (the admin's view, logged when opened)
        $st = $pdo->prepare('SELECT id FROM libraries WHERE id = ANY (CAST(:ids AS bigint[])) AND dc_library_base_level(id, app_current_member_id()) = 0');
        $st->execute(['ids' => pg_array_literal(array_keys($mine))]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $mine[(int) $id]['admin_view'] = true;
        }
    }
    $rows = $mine;
    if ($admin) {
        foreach (all_libraries($pdo, $filters) as $l) {
            $id = (int) $l['library_id'];
            if (!isset($rows[$id])) {
                $rows[$id] = ['library_id' => $id, 'kind' => $l['kind'], 'name' => $l['name'], 'group_id' => $l['group_id'], 'root_folder_id' => $l['root_folder_id'], 'level' => $l['my_level'],
                              'used_bytes' => $l['used_bytes'], 'quota_bytes' => $l['quota_bytes'], 'graph_policy' => $l['graph_policy'], 'admin_view' => true];
            }
        }
    }
    if (!empty($filters['kind'])) {
        $rows = array_filter($rows, static fn (array $l): bool => $l['kind'] === $filters['kind']);
    }
    $ids = array_keys($rows);
    if ($ids !== []) {
        $st = $pdo->prepare('SELECT library_id, count(*) AS n FROM mcp_documents WHERE trashed_at IS NULL AND library_id = ANY (CAST(:ids AS bigint[])) GROUP BY library_id');
        $st->execute(['ids' => pg_array_literal($ids)]);
        $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $id => &$l) {
            $l['documents'] = (int) ($counts[$id] ?? 0);
        }
        unset($l);
    }
    return array_values($rows);
}

/** One library as the caller sees it (mcp_libraries), or null. */
function find_library(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_libraries WHERE library_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A group library's members (mcp_group_members, live). */
function library_members(PDO $pdo, int $libraryId): array
{
    $st = $pdo->prepare('SELECT gm.member_id, gm.role, gm.joined_at, gm.display_name FROM mcp_group_members gm JOIN mcp_libraries l ON l.group_id = gm.group_id WHERE l.library_id = :id AND gm.left_at IS NULL ORDER BY gm.role, lower(gm.display_name)');
    $st->execute(['id' => $libraryId]);
    return $st->fetchAll();
}

function library_quota_state(PDO $pdo, int $libraryId): ?array
{
    $st = $pdo->prepare('SELECT used_bytes, quota_bytes, pct, near FROM dc_quota_state(:id)');
    $st->execute(['id' => $libraryId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Live documents in a library (the delete rule). */
function library_document_count(PDO $pdo, int $libraryId): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM documents WHERE library_id = :id AND trashed_at IS NULL', ['id' => $libraryId]);
}

/** The libraries the caller reaches, for the picker source `library`. */
function libraries_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = "SELECT library_id, kind, name, level FROM dc_my_libraries()" . ($q !== '' ? ' WHERE name ILIKE :q' : '') . ' ORDER BY 1';
    $args = $q !== '' ? ['q' => '%' . $q . '%'] : [];
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $l): array => ['id' => (int) $l['library_id'], 'label' => $l['name'], 'detail' => $l['kind'] . ' library', 'badge' => $l['level'], 'color' => LEVEL_BADGE[$l['level']] ?? 'secondary']);
}

function present_library(array $l): array
{
    return ['library_id' => (int) $l['library_id'], 'kind' => $l['kind'], 'name' => $l['name'], 'description' => $l['description'] ?? null, 'owner_member_id' => isset($l['owner_member_id']) && $l['owner_member_id'] !== null ? (int) $l['owner_member_id'] : null,
            'group_id' => $l['group_id'] === null ? null : (int) $l['group_id'], 'everyone_level' => $l['everyone_level'] ?? null, 'graph_policy' => $l['graph_policy'], 'quota_bytes' => $l['quota_bytes'] === null ? null : (int) $l['quota_bytes'],
            'used_bytes' => (int) $l['used_bytes'], 'root_folder_id' => $l['root_folder_id'] === null ? null : (int) $l['root_folder_id'], 'my_level' => $l['my_level'] ?? $l['level'] ?? null,
            'archived' => ($l['archived_at'] ?? null) !== null, 'documents' => isset($l['documents']) ? (int) $l['documents'] : null, 'admin_view' => (bool) ($l['admin_view'] ?? false)];
}
