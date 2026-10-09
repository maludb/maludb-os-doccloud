<?php
declare(strict_types=1);

/** The JSON shapes of folders and listings — whitelists, never a raw row; never a storage path. */

function present_folder(array $f): array
{
    return ['folder_id' => (int) $f['folder_id'], 'library_id' => (int) $f['library_id'], 'parent_id' => $f['parent_id'] === null ? null : (int) $f['parent_id'], 'name' => $f['name'],
            'depth' => (int) $f['depth'], 'colour' => $f['colour'], 'auto_tags' => pg_text_array((string) ($f['auto_tags'] ?? '{}')), 'graph_policy' => $f['graph_policy'],
            'owner_member_id' => $f['owner_member_id'] === null ? null : (int) $f['owner_member_id'], 'my_level' => $f['my_level'], 'document_count' => (int) ($f['document_count'] ?? 0),
            'trashed' => ($f['trashed_at'] ?? null) !== null, 'created_at' => json_ts($f['created_at']), 'updated_at' => json_ts($f['updated_at'])];
}

function present_crumbs(array $crumbs): array
{
    return array_map(static fn (array $c): array => ['folder_id' => (int) $c['folder_id'], 'name' => $c['name'], 'depth' => (int) $c['depth'], 'url' => '/f/' . (int) $c['folder_id']], $crumbs);
}

function present_listing_row(array $r): array
{
    $tags = is_array($r['tags'] ?? null) ? $r['tags'] : pg_text_array((string) ($r['tags'] ?? '{}'));
    return ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'url' => ($r['kind'] === 'folder' ? '/f/' : '/documents/') . (int) $r['id'], 'mime_kind' => $r['mime_kind'],
            'byte_size' => $r['byte_size'] === null ? null : (int) $r['byte_size'], 'owner_member_id' => $r['owner_member_id'] === null ? null : (int) $r['owner_member_id'], 'owner_name' => $r['owner_name'] ?? null,
            'updated_at' => json_ts($r['updated_at']), 'tags' => $tags, 'my_level' => $r['level'], 'locked' => ($r['locked_by'] ?? null) !== null, 'version_count' => $r['version_count'] === null ? null : (int) $r['version_count']];
}

function present_listing(array $listing): array
{
    return ['rows' => array_map('present_listing_row', $listing['rows']), 'total' => $listing['total'], 'page' => $listing['page'], 'pages' => $listing['pages'], 'per' => $listing['per'],
            'sort' => $listing['sort'], 'dir' => $listing['dir'], 'kind' => $listing['kind']];
}

// ---- slice 2 -------------------------------------------------------------------------------------------------------------------------

/** A document's JSON (find_document's row): never a storage path. */
function present_document(array $d): array
{
    $tags = is_array($d['tags'] ?? null) ? $d['tags'] : pg_text_array((string) ($d['tags'] ?? '{}'));
    return ['document_id' => (int) $d['document_id'], 'library_id' => (int) $d['library_id'], 'folder_id' => (int) $d['folder_id'], 'folder_name' => $d['folder_name'] ?? null, 'name' => $d['name'],
            'description' => $d['description'], 'owner_member_id' => (int) $d['owner_member_id'], 'owner_name' => $d['owner_name'] ?? null, 'mime_type' => $d['mime_type'], 'mime_kind' => $d['mime_kind'],
            'byte_size' => (int) $d['byte_size'], 'sha256' => $d['sha256'], 'version_count' => (int) $d['version_count'], 'current_version_id' => $d['current_version_id'] === null ? null : (int) $d['current_version_id'],
            'current_version_no' => isset($d['current_version_no']) ? (int) $d['current_version_no'] : null, 'filename' => $d['filename'] ?? null, 'extracted_status' => $d['extracted_status'] ?? null,
            'locked_by' => $d['locked_by'] === null ? null : (int) $d['locked_by'], 'locked_by_name' => $d['locked_by_name'] ?? null, 'lock_expires_at' => json_ts($d['lock_expires_at'] ?? null),
            'trashed' => ($d['trashed_at'] ?? null) !== null, 'tags' => $tags, 'my_level' => $d['my_level'], 'in_graph' => (bool) ($d['in_graph'] ?? false), 'own_access' => (bool) ($d['own_access'] ?? false),
            'created_at' => json_ts($d['created_at']), 'updated_at' => json_ts($d['updated_at']), 'last_opened_at' => json_ts($d['last_opened_at'] ?? null),
            'url' => '/documents/' . (int) $d['document_id'], 'download_url' => '/d/' . (int) $d['document_id'] . '?download=1', 'thumb_url' => '/d/' . (int) $d['document_id'] . '/thumb'];
}

function present_version(array $v): array
{
    return ['version_id' => (int) $v['version_id'], 'document_id' => (int) $v['document_id'], 'version_no' => (int) $v['version_no'], 'filename' => $v['filename'], 'mime_type' => $v['mime_type'],
            'byte_size' => (int) $v['byte_size'], 'sha256' => $v['sha256'], 'uploaded_by' => $v['uploaded_by'] === null ? null : (int) $v['uploaded_by'], 'uploaded_by_name' => $v['uploaded_by_name'] ?? null,
            'uploaded_at' => json_ts($v['uploaded_at']), 'source' => $v['source'], 'note' => $v['note'], 'extracted_status' => $v['extracted_status'], 'purged' => $v['purged_at'] !== null,
            'is_current' => (bool) $v['is_current'], 'download_url' => $v['purged_at'] === null ? '/d/' . (int) $v['document_id'] . '/v/' . (int) $v['version_no'] . '?download=1' : null];
}

function present_export(array $e): array
{
    return ['export_id' => (int) $e['export_id'], 'kind' => $e['kind'], 'folder_id' => $e['folder_id'] === null ? null : (int) $e['folder_id'], 'folder_name' => $e['folder_name'] ?? null,
            'document_ids' => pg_int_array((string) ($e['document_ids'] ?? '{}')), 'status' => $e['status'], 'byte_size' => $e['byte_size'] === null ? null : (int) $e['byte_size'], 'item_count' => (int) $e['item_count'],
            'available' => (bool) $e['available'], 'created_at' => json_ts($e['created_at']), 'finished_at' => json_ts($e['finished_at']), 'expires_at' => json_ts($e['expires_at']), 'downloaded_at' => json_ts($e['downloaded_at']),
            'log' => $e['log'], 'download_url' => !empty($e['available']) ? '/exports/' . (int) $e['export_id'] : null];
}

function present_upload_session(array $u): array
{
    return ['session_id' => (int) $u['upload_session_id'], 'folder_id' => (int) $u['folder_id'], 'document_id' => $u['document_id'] === null ? null : (int) $u['document_id'], 'filename' => $u['filename'],
            'document_name' => $u['document_name'] ?? $u['filename'], 'byte_size' => (int) $u['byte_size'], 'parts_total' => (int) $u['parts_total'], 'parts_done' => (int) $u['parts_done'], 'status' => $u['status'],
            'mode' => $u['mode'] ?? 'new', 'version_id' => $u['version_id'] === null ? null : (int) $u['version_id'], 'error' => $u['error'], 'created_at' => json_ts($u['created_at']), 'expires_at' => json_ts($u['expires_at'])];
}

/** The preview's JSON: the mode and, for text kinds, the text (never the engine's; the extracted text is the document's own). */
function present_preview(array $p): array
{
    $out = ['kind' => $p['kind'], 'mode' => $p['mode']];
    if (isset($p['text'])) {
        $out['text'] = $p['text'];
    }
    if (isset($p['rows'])) {
        $out['rows'] = $p['rows'];
    }
    foreach (['truncated', 'status', 'error'] as $k) {
        if (array_key_exists($k, $p)) {
            $out[$k] = $p[$k];
        }
    }
    return $out;
}
