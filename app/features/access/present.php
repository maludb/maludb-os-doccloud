<?php
declare(strict_types=1);

/** The one JSON branch of the share screens, shared with me, my shares and the history (whitelists; never a hash, never an address). */
function present_access_row(array $r): array
{
    return ['access_id' => (int) $r['access_id'], 'principal_kind' => $r['principal_kind'], 'principal_id' => $r['principal_id'] === null ? null : (int) $r['principal_id'], 'principal_ref' => $r['principal_ref'],
            'principal_name' => $r['principal_name'], 'level' => $r['level'], 'granted_by' => $r['granted_by'] === null ? null : (int) $r['granted_by'], 'granted_by_name' => $r['granted_by_name'] ?? null,
            'created_at' => json_ts($r['created_at']), 'is_guest' => !empty($r['is_guest'])];
}

function present_access(array $a): array
{
    return ['base_line' => $a['base_line'], 'inherited_from' => $a['inherited_from'], 'inherited_from_name' => $a['inherited_from_name'], 'own_access' => $a['own_access'] ?? false,
            'inherited' => array_map('present_access_row', $a['inherited']), 'explicit' => array_map('present_access_row', $a['explicit'])];
}

function present_shared_row(array $r): array
{
    return ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'level' => $r['level'], 'shared_by' => $r['shared_by'] === null ? null : (int) $r['shared_by'], 'shared_by_name' => $r['shared_by_name'] ?? null,
            'shared_at' => json_ts($r['shared_at']), 'via' => $r['via'], 'url' => $r['kind'] === 'folder' ? '/f/' . (int) $r['id'] : '/documents/' . (int) $r['id']];
}

function present_my_share(array $r): array
{
    return ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'principal_kind' => $r['principal_kind'], 'principal_id' => $r['principal_id'] === null ? null : (int) $r['principal_id'], 'principal_ref' => $r['principal_ref'],
            'principal_name' => $r['principal_name'], 'level' => $r['level'], 'created_at' => json_ts($r['created_at']), 'is_guest' => !empty($r['is_guest']), 'stale' => !empty($r['stale'])];
}

function present_history_row(array $r): array
{
    return ['principal_kind' => $r['principal_kind'], 'principal_id' => $r['principal_id'] === null ? null : (int) $r['principal_id'], 'principal_ref' => $r['principal_ref'], 'principal_name' => $r['principal_name'], 'level' => $r['level'], 'since' => json_ts($r['since'])];
}

function present_link(array $l): array
{
    return ['link_id' => (int) $l['link_id'], 'label' => $l['label'], 'kind' => $l['kind'], 'folder_id' => $l['folder_id'] === null ? null : (int) $l['folder_id'], 'document_id' => $l['document_id'] === null ? null : (int) $l['document_id'],
            'allow_download' => (bool) $l['allow_download'], 'has_password' => (bool) $l['has_password'], 'expires_at' => json_ts($l['expires_at']), 'view_count' => (int) $l['view_count'], 'upload_count' => (int) ($l['upload_count'] ?? 0),
            'last_used_at' => json_ts($l['last_used_at']), 'made_by' => (int) $l['made_by'], 'made_by_name' => $l['made_by_name'] ?? null, 'note' => $l['note'] ?? null, 'created_at' => json_ts($l['created_at']),
            'revoked_at' => json_ts($l['revoked_at']), 'rotated_at' => json_ts($l['rotated_at']), 'state' => $l['state'] ?? null, 'opens' => $l['opens'] ?? null];
}
