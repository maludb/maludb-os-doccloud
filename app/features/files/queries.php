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
            'version_count' => $d['version_count'] ?? null, 'has_thumbnail' => in_array($d['mime_kind'] ?? '', ['image', 'pdf', 'video'], true), 'folder_id' => $d['folder_id'] ?? null,
            'mime_type' => $d['mime_type'] ?? null, 'preview_hidden' => (bool) ($d['preview_hidden'] ?? false), 'duration_ms' => null];
}

/** One document as a listing row (dc_folder_listing's columns), for a grid tile re-rendered on its own (the hide-preview switch). */
function grid_row_for_document(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT 'document' AS kind, d.document_id AS id, d.name, d.mime_kind, d.byte_size, d.owner_member_id, d.owner_name, d.updated_at, d.tags, d.my_level AS level,
                                d.locked_by, d.version_count, COALESCE(v.thumb_state = 'made', false) AS has_thumbnail, d.mime_type, d.preview_hidden, v.duration_ms
                           FROM mcp_documents d LEFT JOIN document_versions v ON v.id = d.current_version_id WHERE d.document_id = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
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

// ---- slice 2: documents, versions, text, previews, upload sessions, the document picker ------------------------------------------------

/** One document as the caller sees it (mcp_documents + the current version's filename and extraction state), or null. */
function find_document(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT d.*, v.filename, v.version_no AS current_version_no, v.extracted_status, v.uploaded_at AS current_uploaded_at, v.thumb_state, v.duration_ms, f.name AS folder_name, f.parent_id AS folder_parent_id,
                                (SELECT m.display_name FROM members m WHERE m.id = d.locked_by) AS locked_by_name
                           FROM mcp_documents d LEFT JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN folders f ON f.id = d.folder_id WHERE d.document_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Every version of a document the caller may see, newest first, with the uploader's name. */
function document_versions(PDO $pdo, int $documentId): array
{
    $st = $pdo->prepare('SELECT v.*, m.display_name AS uploaded_by_name FROM mcp_document_versions v LEFT JOIN members m ON m.id = v.uploaded_by WHERE v.document_id = :d ORDER BY v.version_no DESC');
    $st->execute(['d' => $documentId]);
    return $st->fetchAll();
}

/** The current version's extracted text (mcp_document_text), or null. */
function document_text_of(PDO $pdo, int $documentId): ?array
{
    $st = $pdo->prepare('SELECT t.* FROM mcp_document_text t JOIN documents d ON d.current_version_id = t.version_id WHERE t.document_id = :d');
    $st->execute(['d' => $documentId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * What the document page shows for this kind: image inline, pdf in the viewer, text / markdown / csv from the extracted text, everything else
 * the extracted text ("extracted") or nothing ("none"). CSV: the first 200 rows.
 */
function document_preview(PDO $pdo, array $doc, ?array $text = null): array
{
    $mime = (string) $doc['mime_type'];
    $kind = (string) $doc['mime_kind'];
    $filename = (string) ($doc['filename'] ?? $doc['name']);
    $inlineOk = dc_inline_safe($mime, $filename);
    // a hidden preview (docs/build-specs/grid-and-media.md): the page shows the placeholder and keeps the preview behind Show
    $hidden = in_array($kind, PREVIEW_HIDEABLE, true) && (bool) ($doc['preview_hidden'] ?? false);
    if ($kind === 'image' && $inlineOk) {
        return ['kind' => $kind, 'mode' => 'image', 'hidden' => $hidden];
    }
    if ($kind === 'pdf' && $inlineOk) {
        return ['kind' => $kind, 'mode' => 'pdf'];
    }
    if ($kind === 'audio' && $inlineOk) {
        return ['kind' => $kind, 'mode' => 'audio'];
    }
    if ($kind === 'video' && $inlineOk) {
        // a MOV is offered to the player as MP4 (H.264 in a QuickTime box plays); the formats no browser plays say so with Download
        return ['kind' => $kind, 'mode' => 'video', 'hidden' => $hidden, 'playable' => !video_unplayable($mime), 'source_type' => match ($mime) { 'video/mp4', 'video/webm', 'video/ogg' => $mime, 'video/quicktime' => 'video/mp4', default => null },
                'poster' => ($doc['thumb_state'] ?? null) === 'made' && !$hidden, 'duration_ms' => isset($doc['duration_ms']) ? (int) $doc['duration_ms'] : null];
    }
    $text ??= document_text_of($pdo, (int) $doc['document_id']);
    $body = $text === null ? '' : (string) $text['text'];
    if ($mime === 'text/csv') {
        // the extractor flattens a CSV into "name: value | …" lines for the index; the table reads the raw bytes (the first 256 KB)
        $st = $pdo->prepare('SELECT b.storage_path FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.id = :v AND v.purged_at IS NULL');
        $st->execute(['v' => (int) $doc['current_version_id']]);
        $rel = (string) ($st->fetchColumn() ?: '');
        $raw = $rel !== '' && is_file(dc_storage_path($rel)) ? (string) @file_get_contents(dc_storage_path($rel), false, null, 0, 262144) : $body;
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
            if (count($rows) >= 200) {
                break;
            }
        }
        if ($rows !== []) {
            return ['kind' => $kind, 'mode' => 'csv', 'rows' => $rows, 'truncated' => substr_count($raw, "\n") > 200];
        }
    }
    if ($mime === 'text/markdown' && $body !== '') {
        return ['kind' => $kind, 'mode' => 'markdown', 'text' => $body];
    }
    if ($kind === 'text' && $body !== '') {
        return ['kind' => $kind, 'mode' => 'text', 'text' => mb_substr($body, 0, 200000)];
    }
    if ($body !== '') {
        return ['kind' => $kind, 'mode' => 'extracted', 'text' => mb_substr($body, 0, 20000), 'truncated' => mb_strlen($body) > 20000];
    }
    return ['kind' => $kind, 'mode' => 'none', 'status' => $doc['extracted_status'] ?? 'pending', 'error' => $text['error'] ?? null];
}

/** The sentence of a version's extraction state (the document page; spec §6.3). */
function extraction_sentence(array $doc, ?array $text): string
{
    return match ((string) ($doc['extracted_status'] ?? 'pending')) {
        'ready' => 'Text: ' . number_format((int) ($text['char_count'] ?? 0)) . ' characters' . (!empty($text['extractor']) ? ' (' . $text['extractor'] . ')' : '') . (!empty($text['truncated']) ? ', cut at the limit' : ''),
        'no_text' => 'No text to read' . ($doc['mime_kind'] === 'pdf' ? ' (an image-only PDF)' : ($doc['mime_kind'] === 'image' ? ' (an image)' : '')),
        'unsupported' => 'Not a kind we read (' . ($doc['mime_kind'] ?? 'other') . ')',
        'failed' => 'Reading the text failed' . (!empty($text['error']) ? ': ' . mb_substr((string) $text['error'], 0, 80) : ''),
        default => 'Text not read yet (within the minute)',
    };
}

/** My open and recent upload sessions (the upload screen's "still arriving" list). */
function my_upload_sessions(PDO $pdo, ?int $folderId = null): array
{
    $st = $pdo->prepare("SELECT * FROM mcp_upload_sessions WHERE member_id = app_current_member_id() AND (:f::bigint IS NULL OR folder_id = :f) AND created_at > now() - interval '1 day' ORDER BY created_at DESC LIMIT 100");
    $st->execute(['f' => $folderId]);
    return $st->fetchAll();
}

/** The document picker's search: documents I may see, by name; params folder (its subtree), library. */
function documents_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $args = ['q' => '%' . $q . '%'];
    $where = "d.trashed_at IS NULL AND (:q = '%%' OR d.name ILIKE :q)";
    if (!empty($params['folder'])) {
        $where .= ' AND d.folder_id IN (SELECT dc_folder_subtree_ids(:folder))';
        $args['folder'] = (int) $params['folder'];
    }
    if (!empty($params['library'])) {
        $where .= ' AND d.library_id = :library';
        $args['library'] = (int) $params['library'];
    }
    return pick_page($pdo, 'SELECT d.document_id AS id, d.name AS label, d.mime_kind, d.byte_size, d.folder_id FROM mcp_documents d WHERE ' . $where . ' ORDER BY lower(d.name)', $args, $page, $per,
        static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['label'], 'detail' => $r['mime_kind'] . ' · ' . format_bytes((int) $r['byte_size']), 'badge' => null, 'color' => null]);
}
