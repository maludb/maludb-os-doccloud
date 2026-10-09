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
