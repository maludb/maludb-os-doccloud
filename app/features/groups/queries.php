<?php
declare(strict_types=1);

/** Reads for groups — mcp_groups, mcp_group_members, mcp_departments, mcp_members (db/015). */

/** The groups as the caller sees them; $filters: q, mine, kind, archived. */
function find_groups(PDO $pdo, array $filters = []): array
{
    // the group's library is read from the base table: an ARCHIVED group's library is hidden by mcp_libraries, and its id must still be known (the delete rule, the card)
    $sql = 'SELECT g.*, l.id AS library_id, l.root_folder_id FROM mcp_groups g LEFT JOIN libraries l ON l.group_id = g.group_id WHERE 1 = 1';
    $args = [];
    if (!empty($filters['q'])) { $sql .= ' AND (g.name ILIKE :q OR g.key ILIKE :q)'; $args['q'] = '%' . $filters['q'] . '%'; }
    if (!empty($filters['mine'])) { $sql .= ' AND g.i_am_member'; }
    if (!empty($filters['kind']) && in_array($filters['kind'], ['custom', 'department'], true)) { $sql .= ' AND g.kind = :k'; $args['k'] = $filters['kind']; }
    $sql .= empty($filters['archived']) ? ' AND g.archived_at IS NULL' : ' AND g.archived_at IS NOT NULL';
    $sql .= ' ORDER BY lower(g.name)';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function find_group(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT g.*, l.id AS library_id, l.root_folder_id, l.used_bytes, l.quota_bytes, d.name AS department_name
                           FROM mcp_groups g LEFT JOIN libraries l ON l.group_id = g.group_id LEFT JOIN mcp_departments d ON d.department_id = g.department_id WHERE g.group_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The live members of a group: name, role, joined; agents and guests marked. */
function group_members(PDO $pdo, int $groupId): array
{
    $st = $pdo->prepare('SELECT gm.member_id, gm.role, gm.joined_at, gm.added_by, gm.display_name, m.member_kind, m.is_guest
                           FROM mcp_group_members gm LEFT JOIN mcp_members m ON m.member_id = gm.member_id
                          WHERE gm.group_id = :g AND gm.left_at IS NULL ORDER BY (gm.role = \'owner\') DESC, lower(gm.display_name)');
    $st->execute(['g' => $groupId]);
    return $st->fetchAll();
}

/** The live departments with no live group (the group form's kind select). */
function departments_without_group(PDO $pdo): array
{
    return $pdo->query('SELECT d.department_id, d.name FROM mcp_departments d WHERE d.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM mcp_groups g WHERE g.department_id = d.department_id AND g.archived_at IS NULL) ORDER BY d.name')->fetchAll();
}

/** The groups for the picker source `group` (live ones). */
function groups_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = 'SELECT group_id, name, kind, member_count, i_am_owner FROM mcp_groups WHERE archived_at IS NULL' . ($q !== '' ? ' AND name ILIKE :q' : '') . ' ORDER BY lower(name)';
    $args = $q !== '' ? ['q' => '%' . $q . '%'] : [];
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $g): array => ['id' => (int) $g['group_id'], 'label' => $g['name'],
        'detail' => $g['member_count'] . ' member' . ((int) $g['member_count'] === 1 ? '' : 's') . ($g['kind'] === 'department' ? ' · follows a department' : ''), 'badge' => $g['i_am_owner'] ? 'you own' : null, 'color' => 'primary']);
}

/** People and agents for the picker source `member` — never a guest (a guest is named on a share, slice 3). Param `group` hides its live members. */
function members_for_pick_search(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = 'SELECT member_id, display_name, member_kind, job_title FROM mcp_members WHERE NOT is_guest' . ($q !== '' ? ' AND display_name ILIKE :q' : '');
    $args = $q !== '' ? ['q' => '%' . $q . '%'] : [];
    if (!empty($params['group'])) { $sql .= ' AND member_id NOT IN (SELECT member_id FROM mcp_group_members WHERE group_id = :g AND left_at IS NULL)'; $args['g'] = (int) $params['group']; }
    $sql .= ' ORDER BY member_kind, lower(display_name)';
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => $m['display_name'],
        'detail' => $m['member_kind'] === 'agent' ? 'an agent' : ($m['job_title'] ?: 'a person'), 'badge' => $m['member_kind'] === 'agent' ? 'agent' : null, 'color' => 'info']);
}

/** The live departments for the picker source `department`. */
function departments_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = 'SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL' . ($q !== '' ? ' AND name ILIKE :q' : '') . ' ORDER BY name';
    $args = $q !== '' ? ['q' => '%' . $q . '%'] : [];
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $d): array => ['id' => (int) $d['department_id'], 'label' => $d['name']]);
}

function present_group(array $g): array
{
    return ['group_id' => (int) $g['group_id'], 'key' => $g['key'], 'name' => $g['name'], 'description' => $g['description'], 'kind' => $g['kind'],
            'department_id' => $g['department_id'] === null ? null : (int) $g['department_id'], 'member_level' => $g['member_level'], 'member_count' => (int) $g['member_count'],
            'i_am_member' => (bool) $g['i_am_member'], 'i_am_owner' => (bool) $g['i_am_owner'], 'library_id' => isset($g['library_id']) && $g['library_id'] !== null ? (int) $g['library_id'] : null,
            'root_folder_id' => isset($g['root_folder_id']) && $g['root_folder_id'] !== null ? (int) $g['root_folder_id'] : null, 'archived' => $g['archived_at'] !== null, 'created_at' => json_ts($g['created_at'])];
}

function present_group_member(array $m): array
{
    return ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name'], 'role' => $m['role'], 'is_agent' => ($m['member_kind'] ?? '') === 'agent', 'joined_at' => json_ts($m['joined_at'])];
}
