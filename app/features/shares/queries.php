<?php
declare(strict_types=1);

/**
 * The four K7 shares as PHP functions (slice 7): folders_index, documents_index, document_text, document_link. Each answers an `os.doccloud-*` document and reads ONLY through the
 * sibling-application wall — dc_application_folder_rank() / dc_application_can_see_document() / dc_application_folders() / dc_application_documents() (db/008, db/016): a folder whose
 * access root carries an `application` row naming the consumer, its subtree, and the documents under it that carry no rows of their own (a document with its own rows needs its own
 * application row). No member identity crosses; an owner is a display name. The door html/api/v1/shares/read.php calls these; Phase 4's records tools call the door.
 */
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/sign.php';

const SHARE_PAGE = 200;
const SHARE_TEXT_BYTES = 5 * 1048576;

/** os.doccloud-folders/1 — every live folder in the consumer's reach (the shared roots and their subtrees): path, library, level, the documents it holds and its subtree holds. $q filters the path. */
function shares_folders_index(PDO $pdo, string $consumer, ?string $q = null): array
{
    $st = $pdo->prepare("SELECT f.id AS folder_id, dc_folder_path_text(f.id) AS path_text, l.id AS library_id, l.kind AS library_kind, l.name AS library_name, dc_rank_level(dc_application_folder_rank(f.id, :a)) AS level,
                                (f.id IN (SELECT fa.folder_id FROM folder_access fa WHERE fa.principal_kind = 'application' AND fa.principal_ref = :a AND fa.level <> 'none')) AS shared_root,
                                (SELECT count(*) FROM documents d WHERE d.folder_id = f.id AND d.trashed_at IS NULL AND dc_application_can_see_document(d.id, :a)) AS document_count,
                                (SELECT count(*) FROM documents d WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(f.id)) AND d.trashed_at IS NULL AND dc_application_can_see_document(d.id, :a)) AS subtree_document_count,
                                f.updated_at
                           FROM folders f JOIN libraries l ON l.id = f.library_id
                          WHERE f.trashed_at IS NULL
                            AND f.access_root_id IN (SELECT fa.folder_id FROM folder_access fa WHERE fa.principal_kind = 'application' AND fa.principal_ref = :a AND fa.level <> 'none')
                            AND dc_application_folder_rank(f.id, :a) > 0
                          ORDER BY f.path");
    $st->execute(['a' => $consumer]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        if ($q !== null && $q !== '' && mb_stripos((string) $r['path_text'], $q) === false) {
            continue;
        }
        $rows[] = ['folder_id' => (int) $r['folder_id'], 'path_text' => (string) $r['path_text'], 'library' => ['library_id' => (int) $r['library_id'], 'kind' => $r['library_kind'], 'name' => $r['library_name']],
                   'level' => $r['level'], 'shared_root' => (bool) $r['shared_root'], 'document_count' => (int) $r['document_count'], 'subtree_document_count' => (int) $r['subtree_document_count'], 'updated_at' => json_ts($r['updated_at'])];
    }
    return ['schema' => 'os.doccloud-folders/1', 'application' => $consumer, 'folders' => $rows];
}

/** True when the consumer reaches the folder (it, or what it inherits from, carries the consumer's row). */
function share_folder_reached(PDO $pdo, string $consumer, int $folderId): bool
{
    return (int) one_value($pdo, 'SELECT dc_application_folder_rank(:f, :a)', ['f' => $folderId, 'a' => $consumer]) > 0;
}

/** os.doccloud-documents/1 — the live documents of a shared folder's subtree the consumer may see, 200 a page, `since` on updated_at. A folder not shared answers not_shared (the same words as a missing one). */
function shares_documents_index(PDO $pdo, string $consumer, int $folderId, ?string $since = null, int $page = 1, int $limit = SHARE_PAGE, ?int $offset = null): array
{
    if (!share_folder_reached($pdo, $consumer, $folderId)) {
        throw new ShareRefused('not_shared', 'That folder is not shared with this application.', 403);
    }
    if ($since !== null && $since !== '') {
        $t = strtotime($since);
        if ($t === false) {
            throw new ShareRefused('invalid', 'since is a time, like 2026-10-01T00:00:00Z.', 422);
        }
        $since = gmdate('Y-m-d H:i:sP', $t);
    } else {
        $since = null;
    }
    $limit = max(1, min(SHARE_PAGE, $limit));
    $offset = $offset ?? (max(1, $page) - 1) * $limit;
    $st = $pdo->prepare('SELECT a.document_id, a.name, a.folder_path AS path_text, a.mime_type, a.mime_kind, a.byte_size, a.sha256, a.tags, m.display_name AS owner_name, a.version_no, a.version_id,
                                v.extracted_status, a.updated_at, a.total
                           FROM dc_application_documents(:a, :f, CAST(:s AS timestamptz), :l, :o) a LEFT JOIN members m ON m.id = a.owner_member_id LEFT JOIN document_versions v ON v.id = a.version_id');
    $st->execute(['a' => $consumer, 'f' => $folderId, 's' => $since, 'l' => $limit, 'o' => $offset]);
    $rows = $st->fetchAll();
    $docs = array_map(static fn (array $r): array => ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'path_text' => $r['path_text'], 'mime_type' => $r['mime_type'], 'mime_kind' => $r['mime_kind'],
        'byte_size' => (int) $r['byte_size'], 'sha256' => $r['sha256'], 'tags' => is_array($r['tags']) ? $r['tags'] : pg_text_array((string) $r['tags']), 'owner_name' => $r['owner_name'], 'version_no' => (int) $r['version_no'],
        'version_id' => (int) $r['version_id'], 'extracted_status' => $r['extracted_status'], 'updated_at' => json_ts($r['updated_at'])], $rows);
    $total = $rows === [] ? 0 : (int) $rows[0]['total'];
    return ['schema' => 'os.doccloud-documents/1', 'application' => $consumer, 'folder_id' => $folderId, 'documents' => $docs, 'total' => $total, 'offset' => $offset, 'limit' => $limit,
            'has_more' => $offset + count($docs) < $total];
}

/** os.doccloud-text/1 — the current version's extracted text with its pages, at most 5 MB (cut and marked `truncated`; `max_chars` cuts shorter). not_shared for a document the consumer may not see. */
function shares_document_text(PDO $pdo, string $consumer, int $documentId, ?int $maxChars = null): array
{
    if (!(bool) one_value($pdo, 'SELECT dc_application_can_see_document(:d, :a)', ['d' => $documentId, 'a' => $consumer])) {
        throw new ShareRefused('not_shared', 'That document is not shared with this application.', 403);
    }
    $st = $pdo->prepare('SELECT d.name, v.id AS version_id, v.version_no, v.extracted_status, t.text, t.pages, t.char_count, t.truncated AS cut_at_extraction
                           FROM documents d JOIN document_versions v ON v.id = d.current_version_id LEFT JOIN document_text t ON t.version_id = v.id WHERE d.id = :d');
    $st->execute(['d' => $documentId]);
    $r = $st->fetch();
    if ($r === false) {
        throw new ShareRefused('not_shared', 'That document is not shared with this application.', 403);
    }
    $text = (string) ($r['text'] ?? '');
    $limit = $maxChars === null ? SHARE_TEXT_BYTES : max(1000, min(SHARE_TEXT_BYTES, $maxChars));
    $truncated = (bool) ($r['cut_at_extraction'] ?? false);
    if (strlen($text) > $limit) {
        $text = mb_strcut($text, 0, $limit, 'UTF-8');
        $truncated = true;
    }
    $pages = $r['pages'] === null ? [] : (json_decode((string) $r['pages'], true) ?: []);
    if ($truncated) {
        $len = strlen($text);
        $pages = array_values(array_filter($pages, static fn (array $p): bool => (int) ($p['char_from'] ?? 0) < $len));
    }
    return ['schema' => 'os.doccloud-text/1', 'application' => $consumer, 'document_id' => $documentId, 'version_id' => (int) $r['version_id'], 'name' => $r['name'], 'text' => $text, 'pages' => $pages,
            'char_count' => $r['char_count'] === null ? 0 : (int) $r['char_count'], 'status' => $r['extracted_status'], 'truncated' => $truncated];
}

/** os.doccloud-link/1 — a signed ten-minute download URL for the document's current version (bytes never cross K7's JSON). not_shared for a document the consumer may not see. */
function shares_document_link(PDO $pdo, string $consumer, int $documentId, string $disposition = 'attachment'): array
{
    if (!(bool) one_value($pdo, 'SELECT dc_application_can_see_document(:d, :a)', ['d' => $documentId, 'a' => $consumer])) {
        throw new ShareRefused('not_shared', 'That document is not shared with this application.', 403);
    }
    $st = $pdo->prepare('SELECT d.id, d.name, v.id AS version_id, v.mime_type, v.byte_size FROM documents d JOIN document_versions v ON v.id = d.current_version_id AND v.purged_at IS NULL WHERE d.id = :d');
    $st->execute(['d' => $documentId]);
    $r = $st->fetch();
    if ($r === false) {
        throw new ShareRefused('not_shared', 'That document has no bytes to give.', 403);
    }
    $url = signed_download_url(['id' => $r['id']], ['id' => $r['version_id']], $consumer, 600);
    if ($disposition === 'inline') {
        $url .= '?disposition=inline';
    }
    return ['schema' => 'os.doccloud-link/1', 'application' => $consumer, 'document_id' => (int) $r['id'], 'version_id' => (int) $r['version_id'], 'name' => $r['name'], 'mime_type' => $r['mime_type'],
            'byte_size' => (int) $r['byte_size'], 'url' => $url, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)];
}

/** One call of the door: the op, the caller object and the arguments → the answer document (logged share.read / share.refused). Throws ShareRefused. */
function shares_dispatch(PDO $pdo, string $op, array $caller, array $args): array
{
    $tool = SHARE_OPS[$op] ?? null;
    if ($tool === null) {
        throw new ShareRefused('unknown_op', 'That is not one of the four shared tools.', 404);
    }
    $t0 = microtime(true);
    $consumer = null;
    $agent = null;
    try {
        ['consumer' => $consumer, 'consumer_agent' => $agent] = share_gate($caller);
        $extra = [];
        switch ($op) {
            case 'share_folders':
                $doc = shares_folders_index($pdo, $consumer, isset($args['q']) && $args['q'] !== '' ? mb_substr((string) $args['q'], 0, 200) : null);
                $rows = count($doc['folders']);
                break;
            case 'share_documents':
                $folder = filter_var($args['folder'] ?? null, FILTER_VALIDATE_INT);
                if ($folder === false || $folder < 1) {
                    throw new ShareRefused('invalid', 'Say which folder.', 422);
                }
                $extra = ['folder_id' => $folder];
                $doc = shares_documents_index($pdo, $consumer, $folder, isset($args['since']) ? (string) $args['since'] : null, 1, (int) ($args['limit'] ?? SHARE_PAGE), isset($args['offset']) ? max(0, (int) $args['offset']) : null);
                $rows = count($doc['documents']);
                break;
            default:
                $document = filter_var($args['document'] ?? null, FILTER_VALIDATE_INT);
                if ($document === false || $document < 1) {
                    throw new ShareRefused('invalid', 'Say which document.', 422);
                }
                $extra = ['document_id' => $document];
                $doc = $op === 'share_text'
                    ? shares_document_text($pdo, $consumer, $document, isset($args['max_chars']) ? (int) $args['max_chars'] : null)
                    : shares_document_link($pdo, $consumer, $document, ($args['disposition'] ?? 'attachment') === 'inline' ? 'inline' : 'attachment');
                $rows = 1;
        }
    } catch (ShareRefused $e) {
        share_refused_log($pdo, $tool, $consumer, $agent, $e->code_);
        throw $e;
    }
    share_read_log($pdo, $tool, $consumer, $agent, $rows, 'ok', (int) round((microtime(true) - $t0) * 1000), $extra);
    return $doc;
}
