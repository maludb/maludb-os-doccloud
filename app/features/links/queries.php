<?php
declare(strict_types=1);

/**
 * The reads of links and file requests (slice 3): the maker's lists (dc_find_links with the opens), one link, my requests, and the public
 * doors' reads — a token resolved (dc_link_resolve), a folder link's listing (the link's own gate: the subtree, trashed excluded — never the
 * member walls, there is no member), a document a link reaches (dc_link_reaches_document).
 */

/** A link's state word: live, expiring (< 7 days), expired, revoked, rotated. */
function link_state(array $l): string
{
    if ($l['revoked_at'] !== null) { return 'revoked'; }
    if ($l['rotated_at'] !== null) { return 'rotated'; }
    if ($l['expires_at'] !== null && strtotime((string) $l['expires_at']) <= time()) { return 'expired'; }
    if ($l['expires_at'] !== null && strtotime((string) $l['expires_at']) <= time() + 7 * 86400) { return 'expiring'; }
    return 'live';
}
const LINK_STATE_BADGE = ['live' => 'success', 'expiring' => 'warning', 'expired' => 'dark', 'revoked' => 'dark', 'rotated' => 'dark'];

/** The links (or requests) on a folder or a document the caller may see (dc_find_links judges), with the opens grouped and the maker's name. */
function find_links(PDO $pdo, string $kind, int $id, bool $liveOnly = false, ?string $linkKind = null): array
{
    $st = $pdo->prepare('SELECT l.*, m.display_name AS made_by_name,
                                (SELECT count(*) FROM link_opens o WHERE o.link_id = l.link_id AND o.what = \'view\') AS views,
                                (SELECT count(*) FROM link_opens o WHERE o.link_id = l.link_id AND o.what = \'download\') AS downloads,
                                (SELECT count(*) FROM link_opens o WHERE o.link_id = l.link_id AND o.what = \'upload\') AS uploads,
                                (SELECT count(DISTINCT o.address_hash) FROM link_opens o WHERE o.link_id = l.link_id) AS addresses
                           FROM dc_find_links(:f, :d, :k, :live) l LEFT JOIN members m ON m.id = l.made_by ORDER BY l.created_at DESC');
    $st->execute(['f' => $kind === 'folder' ? $id : null, 'd' => $kind === 'document' ? $id : null, 'k' => $linkKind, 'live' => $liveOnly ? 't' : 'f']);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['state'] = link_state($r);
        $r['opens'] = ['views' => (int) $r['views'], 'downloads' => (int) $r['downloads'], 'uploads' => (int) $r['uploads'], 'addresses' => (int) $r['addresses']];
    }
    return $rows;
}

/** One link as the caller may see it (mcp_share_links), or null. */
function find_link(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT l.*, m.display_name AS made_by_name FROM mcp_share_links l LEFT JOIN members m ON m.id = l.made_by WHERE l.link_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['state'] = link_state($r);
    return $r;
}

/** The opens of a link, newest first (mcp_link_opens: the address KEY, never the address). */
function link_opens_of(PDO $pdo, int $linkId, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT o.*, d.name AS document_name FROM mcp_link_opens o LEFT JOIN documents d ON d.id = o.document_id WHERE o.link_id = :id ORDER BY o.opened_at DESC LIMIT :lim');
    $st->bindValue('id', $linkId, PDO::PARAM_INT);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The file requests of a folder (kind request). */
function find_requests(PDO $pdo, int $folderId, bool $liveOnly = false): array
{
    return find_links($pdo, 'folder', $folderId, $liveOnly, 'request');
}

/** My file requests (made by me), open and closed, with their folders. */
function my_requests(PDO $pdo, ?string $status = null): array
{
    $st = $pdo->prepare("SELECT l.*, f.name AS folder_name,
                                (SELECT count(*) FROM link_opens o WHERE o.link_id = l.link_id AND o.what = 'upload') AS uploads
                           FROM mcp_share_links l JOIN folders f ON f.id = l.folder_id WHERE l.kind = 'request' AND l.made_by = app_current_member_id() ORDER BY l.created_at DESC LIMIT 200");
    $st->execute();
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['state'] = link_state($r);
        $open = $r['state'] === 'live' || $r['state'] === 'expiring';
        if ($status === 'open' && !$open) { continue; }
        if ($status === 'closed' && $open) { continue; }
        $rows[] = $r;
    }
    return $rows;
}

/** The link summaries the share screen shows: live links and requests on the thing. */
function link_summary(PDO $pdo, string $kind, int $id): array
{
    $links = find_links($pdo, $kind, $id, true, 'view');
    $requests = $kind === 'folder' ? find_requests($pdo, $id, true) : [];
    return ['links' => count($links), 'requests' => count($requests)];
}

// ---- the public doors' reads (no member: PHP as the writer, the link as the gate) ----------------------------------------------------

/** The link a raw token opens, or null — the same nothing for every reason (dc_link_resolve). With the thing's name and the maker's. */
function link_resolve(PDO $pdo, string $raw): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
        return null;
    }
    $st = $pdo->prepare('SELECT r.*, m.display_name AS made_by_name, COALESCE(d.name, f.name) AS target_name, l.folder_id AS link_folder_id
                           FROM dc_link_resolve(:raw) r JOIN members m ON m.id = r.made_by JOIN share_links l ON l.id = r.link_id
                           LEFT JOIN documents d ON d.id = r.document_id LEFT JOIN folders f ON f.id = r.folder_id');
    $st->execute(['raw' => $raw]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A folder link's read-only listing: the subfolders and documents of ONE folder within the link's subtree (crumbs inside the link's root only). */
function link_listing(PDO $pdo, int $linkId, ?int $folderId = null): ?array
{
    $st = $pdo->prepare('SELECT folder_id FROM share_links WHERE id = :id AND kind = \'view\' AND folder_id IS NOT NULL');
    $st->execute(['id' => $linkId]);
    $root = $st->fetchColumn();
    if ($root === false) {
        return null;
    }
    $root = (int) $root;
    $here = $folderId ?? $root;
    if ($here !== $root) {
        $st = $pdo->prepare('SELECT 1 FROM folders f WHERE f.id = :h AND f.trashed_at IS NULL AND f.path LIKE (SELECT path FROM folders WHERE id = :r) || \'%\'');
        $st->execute(['h' => $here, 'r' => $root]);
        if ($st->fetchColumn() === false) {
            return null;
        }
    }
    $crumbs = [];
    $st = $pdo->prepare('SELECT a.id, a.name FROM folders f JOIN folders a ON f.path LIKE a.path || \'%\' WHERE f.id = :h AND a.depth >= (SELECT depth FROM folders WHERE id = :r) ORDER BY a.depth');
    $st->execute(['h' => $here, 'r' => $root]);
    foreach ($st->fetchAll() as $c) {
        $crumbs[] = ['folder_id' => (int) $c['id'], 'name' => $c['name']];
    }
    $st = $pdo->prepare('SELECT id, name, updated_at FROM folders WHERE parent_id = :h AND trashed_at IS NULL ORDER BY lower(name)');
    $st->execute(['h' => $here]);
    $folders = $st->fetchAll();
    $st = $pdo->prepare('SELECT d.id, d.name, d.mime_kind, d.byte_size, d.updated_at FROM documents d WHERE d.folder_id = :h AND d.trashed_at IS NULL AND d.current_version_id IS NOT NULL ORDER BY lower(d.name)');
    $st->execute(['h' => $here]);
    $documents = $st->fetchAll();
    $st = $pdo->prepare('SELECT count(*) AS n, COALESCE(sum(d.byte_size), 0) AS bytes FROM documents d WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(:r)) AND d.trashed_at IS NULL');
    $st->execute(['r' => $root]);
    $size = $st->fetch();
    return ['root' => $root, 'here' => $here, 'crumbs' => $crumbs, 'folders' => $folders, 'documents' => $documents, 'documents_total' => (int) $size['n'], 'bytes' => (int) $size['bytes']];
}

/** A document a link reaches (dc_link_reaches_document), with its current version's facts, or null. */
function link_document(PDO $pdo, int $linkId, int $documentId): ?array
{
    $st = $pdo->prepare('SELECT d.*, v.filename, v.version_no AS current_version_no, v.extracted_status FROM documents d LEFT JOIN document_versions v ON v.id = d.current_version_id
                          WHERE d.id = :d AND d.trashed_at IS NULL AND dc_link_reaches_document(:l, :d)');
    $st->execute(['d' => $documentId, 'l' => $linkId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A link's export (the zip of its folder) that is done and not expired, or null. */
function link_export(PDO $pdo, int $linkId, int $exportId): ?array
{
    $st = $pdo->prepare("SELECT e.* FROM exports e WHERE e.id = :e AND e.link_id = :l");
    $st->execute(['e' => $exportId, 'l' => $linkId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
