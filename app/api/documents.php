<?php
declare(strict_types=1);

/**
 * What the token API's reads answer (slice 7): whitelist presenters over the same functions the screens use — find_folder / folder_listing / find_document / document_versions / document_text_of
 * (all through the mcp_* views, so the walls judge every row as the key's person), never a raw row, never a storage path. The records MCP tools (Phase 4) reuse these shapes.
 */

/** GET /me: who the key acts as, its label and limits, today's count. */
function api_me(PDO $pdo, array $member): array
{
    $k = api_key();
    $calls = $k === null ? null : (int) one_value($pdo, 'SELECT dc_key_calls_today(:t)', ['t' => $k['token_id']]);
    return ['member' => ['member_id' => (int) $member['id'], 'display_name' => $member['display_name'], 'member_kind' => $member['member_kind']], 'label' => $k['label'] ?? null,
            'rate_per_minute' => $k['rate_per_minute'] ?? null, 'rate_per_day' => $k['rate_per_day'] ?? null, 'calls_today' => $calls];
}

/** GET /folders/{id}: the folder, its crumbs and one page of its listing — for the key's person, who must see the folder (a 404 for anything else). */
function api_folder(PDO $pdo, int $id, array $opts): array
{
    $folder = folder_at_level($pdo, $id, 'view');
    if ($folder['trashed_at'] !== null) {
        dc_not_found('Not found.');
    }
    $fv = find_folder($pdo, $id);
    $listing = folder_listing($pdo, $id, $opts);
    return ['folder' => present_folder($fv + ['my_level' => $folder['my_level']]), 'crumbs' => present_crumbs(folder_crumbs($pdo, $id)),
            'rows' => array_map('present_listing_row', $listing['rows']), 'total' => $listing['total'], 'page' => $listing['page'], 'pages' => $listing['pages'], 'per' => $listing['per']];
}

/** GET /documents/{id}: the document, its versions and tags. */
function api_document(PDO $pdo, int $id): array
{
    $doc = document_at_level($pdo, $id, 'view');
    $d = find_document($pdo, $id);
    $versions = array_map('present_version', document_versions($pdo, $id));
    return ['document' => present_document($d + ['my_level' => $doc['my_level']]), 'versions' => $versions, 'tags' => is_array($d['tags'] ?? null) ? $d['tags'] : pg_text_array((string) ($d['tags'] ?? '{}'))];
}

/** GET /documents/{id}/text: the current version's extracted text with its pages, at most 5 MB (cut and marked truncated). */
function api_document_text(PDO $pdo, int $id): array
{
    document_at_level($pdo, $id, 'view');
    $d = find_document($pdo, $id);
    $t = document_text_of($pdo, $id);
    $text = (string) ($t['text'] ?? '');
    $truncated = (bool) ($t['truncated'] ?? false);
    if (strlen($text) > 5 * 1048576) {
        $text = mb_strcut($text, 0, 5 * 1048576, 'UTF-8');
        $truncated = true;
    }
    $pages = $t === null || $t['pages'] === null ? [] : (json_decode((string) $t['pages'], true) ?: []);
    if ($truncated) {
        $pages = array_values(array_filter($pages, static fn (array $p): bool => (int) ($p['char_from'] ?? 0) < strlen($text)));
    }
    return ['document_id' => $id, 'version_id' => $t === null ? ($d['current_version_id'] === null ? null : (int) $d['current_version_id']) : (int) $t['version_id'], 'text' => $text, 'pages' => $pages,
            'char_count' => $t === null ? 0 : (int) $t['char_count'], 'status' => $d['extracted_status'] ?? null, 'truncated' => $truncated, 'extractor' => $t['extractor'] ?? null];
}

/** GET /documents/{id}/download[?version=n]: the bytes as an attachment (nosniff, ranges, ETag) — logged document.download with the door `api`. Exits. */
function api_document_download(PDO $pdo, int $id, ?int $versionNo): never
{
    $doc = document_at_level($pdo, $id, 'view');
    if ($doc['trashed_at'] !== null) {
        dc_not_found('Not found.');
    }
    if ($versionNo !== null) {
        $st = $pdo->prepare('SELECT v.*, b.storage_path FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.document_id = :d AND v.version_no = :n AND v.purged_at IS NULL');
        $st->execute(['d' => $id, 'n' => $versionNo]);
    } else {
        $st = $pdo->prepare('SELECT v.*, b.storage_path FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.id = :v AND v.purged_at IS NULL');
        $st->execute(['v' => (int) $doc['current_version_id']]);
    }
    $v = $st->fetch();
    if ($v === false) {
        dc_not_found('Not found.');
    }
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== '"' . $v['sha256'] . '"' && !isset($_SERVER['HTTP_RANGE'])) {
        log_activity($pdo, 'document.download', 'document', $id, ['document_id' => $id, 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'],
            'after' => ['version_no' => (int) $v['version_no'], 'bytes' => (int) $v['byte_size'], 'door' => 'api', 'key_label' => api_key_label()]]);
    }
    $GLOBALS['__json_sent'] = true;                 // bytes follow: JSON mode's buffer and shutdown answer must not touch them
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    store_stream((string) $v['storage_path'], ['filename' => $v['filename'], 'mime' => $v['mime_type'], 'inline' => false, 'sha256' => $v['sha256'], 'byte_size' => (int) $v['byte_size']]);
}
