<?php
declare(strict_types=1);

/** The one JSON branch of the quotas and the worker screens (whitelists). */
function present_quota_row(array $r): array
{
    $state = ['quota_bytes' => $r['quota_bytes'], 'pct' => $r['pct']];
    return ['library_id' => (int) $r['library_id'], 'kind' => $r['kind'], 'name' => $r['name'], 'used_bytes' => (int) $r['used_bytes'], 'quota_bytes' => $r['quota_bytes'] === null ? null : (int) $r['quota_bytes'],
            'pct' => $r['pct'] === null ? null : (float) $r['pct'], 'status' => quota_bar_class($state), 'documents' => (int) $r['documents']];
}

function present_worker_pass(array $p): array
{
    $steps = [];
    foreach ($p['steps'] as $name => $s) {
        $steps[$name] = ['count' => (int) ($s['count'] ?? 0), 'ms' => (int) ($s['ms'] ?? 0), 'error' => $s['error'] ?? null, 'status' => isset($s['error']) ? 'danger' : 'success'];
    }
    return ['pass_id' => (int) $p['id'], 'started_at' => json_ts($p['started_at']), 'finished_at' => json_ts($p['finished_at']), 'ms' => $p['ms'] === null ? null : (int) $p['ms'], 'steps' => $steps, 'error' => $p['error'], 'status' => $p['error'] === null ? 'success' : 'danger'];
}
