<?php
declare(strict_types=1);

/**
 * Reads for folders, the listing, recents and favourites — through the mcp_* views and the read functions only (db/014–016), so a
 * screen and the records MCP never disagree. Nothing here writes.
 */

/** One folder as the caller sees it (mcp_folders: my_level, document_count), or null when they may not. */
function find_folder(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_folders WHERE folder_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The crumbs from the library's root to this folder: [{folder_id, name, depth}] (dc_folder_path). */
function folder_crumbs(PDO $pdo, int $folderId): array
{
    $st = $pdo->prepare('SELECT folder_id, name, depth FROM dc_folder_path(:id)');
    $st->execute(['id' => $folderId]);
    return $st->fetchAll();
}

/** "SMOKE Board › Contracts › 2026" (dc_folder_path_text). */
function folder_path_text(PDO $pdo, int $folderId): string
{
    return (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => $folderId]);
}

/**
 * The listing (dc_folder_listing): folders first then documents the caller may see, sorted, filtered by kind, paged.
 * $opts: sort name|updated|size, dir asc|desc, kind, page, per (100). Answers rows, total, page, pages, per.
 */
function folder_listing(PDO $pdo, int $folderId, array $opts = []): array
{
    $sort = in_array($opts['sort'] ?? 'name', ['name', 'updated', 'size'], true) ? $opts['sort'] : 'name';
    $dir = ($opts['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $kind = $opts['kind'] ?? null;
    $kind = in_array($kind, ['folder', 'document', 'image', 'pdf', 'office', 'text', 'audio', 'video', 'archive', 'other'], true) ? $kind : null;
    $per = max(1, min(500, (int) ($opts['per'] ?? 100)));
    $page = max(1, (int) ($opts['page'] ?? 1));
    $st = $pdo->prepare('SELECT * FROM dc_folder_listing(:f, :s, :d, :n, :o, :k)');
    $st->execute(['f' => $folderId, 's' => $sort, 'd' => $dir, 'n' => $per, 'o' => ($page - 1) * $per, 'k' => $kind]);
    $rows = $st->fetchAll();
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'per' => $per, 'sort' => $sort, 'dir' => $dir, 'kind' => $kind];
}

/** The subtree's live bytes and documents. */
function folder_subtree_size(PDO $pdo, int $folderId): array
{
    $st = $pdo->prepare('SELECT COALESCE(sum(d.byte_size), 0) AS bytes, count(*) AS documents FROM documents d WHERE d.trashed_at IS NULL AND d.folder_id IN (SELECT dc_folder_subtree_ids(:f))');
    $st->execute(['f' => $folderId]);
    $r = $st->fetch();
    return ['bytes' => (int) $r['bytes'], 'documents' => (int) $r['documents']];
}

/**
 * The access summary line (spec "Screens", `folder`): "Only you" on a personal library with no rows; "SMOKE Board · 3 people · 1 group" on a
 * group root; "Everyone · view" on a shared root; with explicit rows at the access root, their counts — and "restricted" when the base
 * principal is no longer among them.
 */
function folder_access_line(PDO $pdo, int $folderId): string
{
    $st = $pdo->prepare('SELECT f.access_root_id, l.kind, l.name, l.group_id, l.everyone_level, g.member_level,
                                (SELECT count(*) FROM group_members gm WHERE gm.group_id = l.group_id AND gm.left_at IS NULL) AS group_people
                           FROM folders f JOIN libraries l ON l.id = f.library_id LEFT JOIN groups g ON g.id = l.group_id WHERE f.id = :id');
    $st->execute(['id' => $folderId]);
    $f = $st->fetch();
    if ($f === false) {
        return '';
    }
    $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    if ($f['access_root_id'] === null) {
        return match ($f['kind']) {
            'personal' => 'Only you',
            'group' => $f['name'] . ' · ' . $plural((int) $f['group_people'], 'person', 'people') . ' · 1 group',
            default => $f['everyone_level'] === 'none' ? 'Invitation only' : 'Everyone · ' . $f['everyone_level'],
        };
    }
    $rows = $pdo->prepare("SELECT principal_kind, principal_id, level FROM folder_access WHERE folder_id = :r");
    $rows->execute(['r' => (int) $f['access_root_id']]);
    $people = 0;
    $groups = 0;
    $departments = 0;
    $everyone = false;
    $baseKept = $f['kind'] === 'personal';
    foreach ($rows->fetchAll() as $r) {
        if ($r['level'] === 'none') {
            continue;
        }
        switch ($r['principal_kind']) {
            case 'member': case 'agent': case 'guest': $people++; break;
            case 'group': $groups++; if ($f['kind'] === 'group' && (int) $r['principal_id'] === (int) $f['group_id']) { $baseKept = true; } break;
            case 'department': $departments++; break;
            case 'everyone': $everyone = true; if ($f['kind'] === 'shared') { $baseKept = true; } break;
        }
    }
    $parts = [$f['name']];
    if ($everyone) { $parts[] = 'everyone'; }
    if ($people > 0) { $parts[] = $plural($people, 'person', 'people'); }
    if ($groups > 0) { $parts[] = $plural($groups, 'group', 'groups'); }
    if ($departments > 0) { $parts[] = $plural($departments, 'department', 'departments'); }
    if (!$baseKept && $f['kind'] !== 'personal') { $parts[] = 'restricted'; }
    return implode(' · ', $parts);
}

/** The folders the caller may ADD TO, by name or path (the picker source `folder`; param `library`). */
function folders_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $lib = isset($params['library']) && $params['library'] !== null ? (int) $params['library'] : null;
    $sql = "SELECT f.folder_id, f.name, f.library_id, f.depth, f.my_level, dc_folder_path_text(f.folder_id) AS path_text
              FROM mcp_folders f
             WHERE f.trashed_at IS NULL AND f.my_level IN ('upload', 'edit', 'manage')" . ($lib !== null ? ' AND f.library_id = :lib' : '')
        . ($q !== '' ? ' AND (f.name ILIKE :q OR dc_folder_path_text(f.folder_id) ILIKE :q)' : '')
        . ' ORDER BY f.library_id, f.path';
    $args = [];
    if ($lib !== null) { $args['lib'] = $lib; }
    if ($q !== '') { $args['q'] = '%' . $q . '%'; }
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $f): array => [
        'id' => (int) $f['folder_id'], 'label' => $f['path_text'] !== '' ? $f['path_text'] : $f['name'],
        'detail' => $f['depth'] === 0 || $f['depth'] === '0' ? 'the library\'s root' : null, 'badge' => $f['my_level'], 'color' => LEVEL_BADGE[$f['my_level']] ?? 'secondary']);
}

/** Runs a picker query as one page: rows mapped, total, page, page_size, pages. */
function pick_page(PDO $pdo, string $sql, array $args, int $page, int $per, callable $map): array
{
    $page = max(1, $page);
    $count = $pdo->prepare('SELECT count(*) FROM (' . $sql . ') x');
    $count->execute($args);
    $total = (int) $count->fetchColumn();
    $st = $pdo->prepare($sql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => array_map($map, $st->fetchAll()), 'total' => $total, 'page' => $page, 'page_size' => $per, 'pages' => max(1, (int) ceil($total / $per))];
}

/** Recent documents (dc_recent_documents) as listing rows. */
function recent_documents(PDO $pdo, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT r.document_id, r.name, r.folder_id, r.mime_kind, r.updated_at, r.last_opened_at, d.byte_size, d.owner_member_id, d.owner_name, d.tags, d.my_level, d.locked_by, d.version_count
                           FROM dc_recent_documents(:n) r JOIN mcp_documents d ON d.document_id = r.document_id');
    $st->execute(['n' => max(1, min(100, $limit))]);
    return array_map(static fn (array $d): array => listing_row_from_document($d) + ['last_opened_at' => $d['last_opened_at']], $st->fetchAll());
}

/** The caller's favourites: folders then documents, as listing rows. */
function my_favourites(PDO $pdo): array
{
    $rows = [];
    $st = $pdo->query('SELECT f.folder_id AS id, f.name, f.library_id, f.updated_at, f.owner_member_id, f.my_level, f.document_count, fv.created_at AS starred_at
                         FROM mcp_favourites fv JOIN mcp_folders f ON f.folder_id = fv.folder_id WHERE fv.folder_id IS NOT NULL AND f.trashed_at IS NULL ORDER BY lower(f.name)');
    foreach ($st->fetchAll() as $f) {
        $rows[] = ['kind' => 'folder', 'id' => (int) $f['id'], 'name' => $f['name'], 'mime_kind' => null, 'byte_size' => null, 'owner_member_id' => $f['owner_member_id'], 'owner_name' => null,
                   'updated_at' => $f['updated_at'], 'tags' => '{}', 'level' => $f['my_level'], 'locked_by' => null, 'version_count' => null, 'has_thumbnail' => false, 'starred_at' => $f['starred_at']];
    }
    $st = $pdo->query('SELECT d.*, fv.created_at AS starred_at FROM mcp_favourites fv JOIN mcp_documents d ON d.document_id = fv.document_id WHERE fv.document_id IS NOT NULL AND d.trashed_at IS NULL ORDER BY lower(d.name)');
    foreach ($st->fetchAll() as $d) {
        $rows[] = listing_row_from_document($d) + ['starred_at' => $d['starred_at']];
    }
    return $rows;
}

/** A mcp_documents row in the listing's shape (dc_folder_listing's columns). */
function listing_row_from_document(array $d): array
{
    return ['kind' => 'document', 'id' => (int) $d['document_id'], 'name' => $d['name'], 'mime_kind' => $d['mime_kind'], 'byte_size' => $d['byte_size'], 'owner_member_id' => $d['owner_member_id'],
            'owner_name' => $d['owner_name'] ?? null, 'updated_at' => $d['updated_at'], 'tags' => $d['tags'] ?? '{}', 'level' => $d['my_level'] ?? null, 'locked_by' => $d['locked_by'] ?? null,
            'version_count' => $d['version_count'] ?? null, 'has_thumbnail' => in_array($d['mime_kind'] ?? '', ['image', 'pdf'], true), 'folder_id' => $d['folder_id'] ?? null];
}

/** The folder and document ids the caller starred, for the listing's star state. */
function my_favourite_ids(PDO $pdo): array
{
    $out = ['folder' => [], 'document' => []];
    foreach ($pdo->query('SELECT folder_id, document_id FROM mcp_favourites')->fetchAll() as $r) {
        if ($r['folder_id'] !== null) { $out['folder'][(int) $r['folder_id']] = true; }
        if ($r['document_id'] !== null) { $out['document'][(int) $r['document_id']] = true; }
    }
    return $out;
}
