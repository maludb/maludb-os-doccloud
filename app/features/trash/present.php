<?php
declare(strict_types=1);

/** The one JSON branch of the trash screen (a whitelist). */
function present_trash_row(array $r): array
{
    [$badge, $words] = trash_status($r['purge_at']);
    return ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'mime_kind' => $r['mime_kind'] ?? null, 'byte_size' => $r['byte_size'] === null ? null : (int) $r['byte_size'],
            'trashed_at' => json_ts($r['trashed_at']), 'trashed_by' => $r['trashed_by'] === null ? null : (int) $r['trashed_by'], 'trashed_by_name' => $r['trashed_by_name'] ?? null,
            'purge_at' => json_ts($r['purge_at']), 'purges' => $words, 'status' => $badge, 'due' => $badge === 'danger',
            'from_folder_id' => $r['from_folder_id'] === null ? null : (int) $r['from_folder_id'], 'from_folder_name' => $r['from_folder_name'] ?? null,
            'url' => $r['kind'] === 'document' ? '/documents/' . (int) $r['id'] : '/f/' . (int) $r['id']];
}
