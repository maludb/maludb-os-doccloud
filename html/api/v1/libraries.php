<?php
declare(strict_types=1);
/** GET /api/v1/libraries — the libraries the key's person reaches (dc_my_libraries), with their level (slice 7). */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_route(['GET']);
$pdo = db();
$libs = my_libraries($pdo);
log_activity($pdo, 'library.list', 'library', null, ['after' => ['count' => count($libs), 'key_label' => api_key_label()]]);
api_json(['libraries' => array_map(static fn (array $l): array => ['library_id' => (int) $l['library_id'], 'kind' => $l['kind'], 'name' => $l['name'], 'group_id' => $l['group_id'] === null ? null : (int) $l['group_id'],
    'root_folder_id' => $l['root_folder_id'] === null ? null : (int) $l['root_folder_id'], 'my_level' => $l['level'], 'used_bytes' => (int) $l['used_bytes'], 'quota_bytes' => $l['quota_bytes'] === null ? null : (int) $l['quota_bytes'],
    'graph_policy' => $l['graph_policy']], $libs)]);
