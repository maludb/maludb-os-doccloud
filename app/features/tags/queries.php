<?php
declare(strict_types=1);

/**
 * The reads of tags (slice 4, docs/build-specs/tags-search.md): the vocabulary (mcp_tags), one tag, a document's tags with ids, the open
 * proposals (mcp_tag_proposals), what is untagged (dc_untagged), the picker's search, and a tag's documents — ALWAYS through dc_search with
 * the tag filter (the wall is inside it), never a listing that bypasses it.
 */

const TAG_KIND_BADGE = ['managed' => 'primary', 'free' => 'secondary', 'archived' => 'dark', 'alias' => 'light'];
const PROPOSAL_BADGE = ['proposed' => 'warning', 'accepted' => 'success', 'dismissed' => 'dark'];

/** The vocabulary: filters q (label or slug ILIKE), kind (managed|free), archived (bool: include archived). Managed first, then by usage. */
function find_tags(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT t.*, m.display_name AS created_by_name FROM mcp_tags t LEFT JOIN members m ON m.id = t.created_by WHERE 1 = 1';
    $args = [];
    if (empty($filters['archived'])) {
        $sql .= ' AND t.archived_at IS NULL';
    }
    if (!empty($filters['kind']) && in_array($filters['kind'], ['managed', 'free'], true)) {
        $sql .= ' AND t.kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    if (!empty($filters['q'])) {
        $sql .= ' AND (t.label ILIKE :q OR t.slug ILIKE :q)';
        $args['q'] = '%' . $filters['q'] . '%';
    }
    $sql .= " ORDER BY CASE t.kind WHEN 'managed' THEN 0 ELSE 1 END, t.usage_count DESC, t.label LIMIT " . (int) ($filters['limit'] ?? 500);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['aliases'] = is_array($r['aliases']) ? $r['aliases'] : pg_text_array((string) ($r['aliases'] ?? '{}'));
        $r['state'] = $r['archived_at'] !== null ? 'archived' : $r['kind'];
    }
    return $rows;
}

function find_tag(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.*, m.display_name AS created_by_name FROM mcp_tags t LEFT JOIN members m ON m.id = t.created_by WHERE t.tag_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['aliases'] = is_array($r['aliases']) ? $r['aliases'] : pg_text_array((string) ($r['aliases'] ?? '{}'));
    $r['state'] = $r['archived_at'] !== null ? 'archived' : $r['kind'];
    return $r;
}

/** A tag by a slug, a label or an alias — through dc_tag_resolve (never creating). */
function tag_by_word(PDO $pdo, string $word): ?array
{
    $id = one_value($pdo, 'SELECT dc_tag_resolve(:w)', ['w' => $word]);
    return $id === null || $id === false ? null : find_tag($pdo, (int) $id);
}

/** A document's tags with their ids, kinds and colours (mcp_document_tags joined to the vocabulary; the wall is the view's). */
function document_tags(PDO $pdo, int $documentId): array
{
    $st = $pdo->prepare('SELECT dt.tag_id, dt.slug, dt.label, dt.source, dt.added_by, dt.created_at, t.kind, t.colour, t.archived_at
                           FROM mcp_document_tags dt JOIN mcp_tags t ON t.tag_id = dt.tag_id
                          WHERE dt.document_id = :d ORDER BY CASE t.kind WHEN \'managed\' THEN 0 ELSE 1 END, t.usage_count DESC, t.label');
    $st->execute(['d' => $documentId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['state'] = $r['archived_at'] !== null ? 'archived' : $r['kind'];
    }
    return $rows;
}

/** A tag's documents: dc_search with the tag filter (the wall, the paging, the excerpt). */
function tag_documents(PDO $pdo, int $tagId, int $page = 1, int $per = 25): array
{
    $t = find_tag($pdo, $tagId);
    if ($t === null) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }
    $st = $pdo->prepare('SELECT * FROM dc_search(\'\', CAST(:f AS jsonb), :lim, :off)');
    $st->execute(['f' => json_encode(['tag' => $t['slug']]), 'lim' => $per, 'off' => (max(1, $page) - 1) * $per]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['tags'] = is_array($r['tags']) ? $r['tags'] : pg_text_array((string) ($r['tags'] ?? '{}'));
        $r['folder_path'] = (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => (int) $r['folder_id']]);
    }
    unset($r);
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['rows' => $rows, 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / $per))];
}

/** The open proposals (mcp_tag_proposals judges who sees which), with the names. */
function open_proposals(PDO $pdo, string $status = 'proposed', int $limit = 200): array
{
    $st = $pdo->prepare('SELECT p.*, t.label AS tag_label, t.slug AS tag_slug, t.kind AS tag_kind, i.label AS into_label, i.slug AS into_slug, d.name AS document_name,
                                m.display_name AS proposed_by_name, dd.display_name AS decided_by_name,
                                (SELECT count(DISTINCT dt.added_by) FROM document_tags dt WHERE dt.tag_id = p.tag_id AND dt.added_by IS NOT NULL) AS people
                           FROM mcp_tag_proposals p JOIN tags t ON t.id = p.tag_id LEFT JOIN tags i ON i.id = p.into_tag_id
                           LEFT JOIN documents d ON d.id = p.document_id LEFT JOIN members m ON m.id = p.proposed_by LEFT JOIN members dd ON dd.id = p.decided_by
                          WHERE p.status = :s ORDER BY p.created_at DESC LIMIT :lim');
    $st->bindValue('s', $status);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function find_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, t.label AS tag_label, t.slug AS tag_slug, i.label AS into_label FROM mcp_tag_proposals p JOIN tags t ON t.id = p.tag_id LEFT JOIN tags i ON i.id = p.into_tag_id WHERE p.tag_proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Untagged documents the caller may see (dc_untagged), in a folder's subtree or everywhere. */
function untagged(PDO $pdo, ?int $folderId = null, int $limit = 200): array
{
    $st = $pdo->prepare('SELECT u.*, dc_folder_path_text(u.folder_id) AS folder_path FROM dc_untagged(:f, :lim) u');
    $st->bindValue('f', $folderId, $folderId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The picker's search over the live vocabulary (managed first). */
function tags_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = "SELECT t.tag_id, t.slug, t.label, t.kind, t.usage_count, t.colour FROM mcp_tags t WHERE t.archived_at IS NULL"
        . ($q !== '' ? ' AND (t.label ILIKE :q OR t.slug ILIKE :q OR EXISTS (SELECT 1 FROM tag_aliases a WHERE a.tag_id = t.tag_id AND a.alias ILIKE :q))' : '')
        . " ORDER BY CASE t.kind WHEN 'managed' THEN 0 ELSE 1 END, t.usage_count DESC, t.label";
    return pick_page($pdo, $sql, $q !== '' ? ['q' => '%' . $q . '%'] : [], $page, $per, static fn (array $t): array => [
        'id' => (int) $t['tag_id'], 'label' => $t['label'], 'detail' => $t['slug'] . ' · ' . (int) $t['usage_count'] . ' document' . ((int) $t['usage_count'] === 1 ? '' : 's'),
        'badge' => $t['kind'], 'color' => TAG_KIND_BADGE[$t['kind']] ?? 'secondary']);
}

/** The vocabulary as a datalist (the editor): live tags' labels and slugs. */
function tag_datalist(PDO $pdo, int $limit = 300): array
{
    $st = $pdo->query("SELECT label, slug, kind FROM mcp_tags WHERE archived_at IS NULL ORDER BY CASE kind WHEN 'managed' THEN 0 ELSE 1 END, usage_count DESC, label LIMIT " . (int) $limit);
    return $st->fetchAll();
}

/** The auto-tag rules: every folder (the caller may see) whose auto_tags are set, with the slugs (tag-rules screen). */
function folders_with_rules(PDO $pdo, ?int $libraryId = null): array
{
    $sql = 'SELECT f.folder_id, f.name, f.library_id, f.auto_tags, f.my_level, dc_folder_path_text(f.folder_id) AS path_text, l.name AS library_name
              FROM mcp_folders f JOIN libraries l ON l.id = f.library_id
             WHERE f.trashed_at IS NULL AND cardinality(f.auto_tags) > 0' . ($libraryId !== null ? ' AND f.library_id = :lib' : '') . ' ORDER BY l.name, f.path';
    $st = $pdo->prepare($sql);
    $st->execute($libraryId !== null ? ['lib' => $libraryId] : []);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['auto_tags'] = is_array($r['auto_tags']) ? $r['auto_tags'] : pg_text_array((string) $r['auto_tags']);
    }
    return $rows;
}

/** The nearest rule upward of a folder (the one a new upload there follows): [folder_id, name, slugs] or null. */
function nearest_rule(PDO $pdo, int $folderId): ?array
{
    $st = $pdo->prepare('SELECT a.id AS folder_id, a.name, a.auto_tags FROM folders f JOIN folders a ON f.path LIKE a.path || \'%\' WHERE f.id = :id AND cardinality(a.auto_tags) > 0 ORDER BY a.depth DESC LIMIT 1');
    $st->execute(['id' => $folderId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['auto_tags'] = is_array($r['auto_tags']) ? $r['auto_tags'] : pg_text_array((string) $r['auto_tags']);
    return $r;
}
