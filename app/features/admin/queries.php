<?php
declare(strict_types=1);

/** Reads of the admin screens of slice 6: the quotas and the worker. */
require_once __DIR__ . '/passes.php';

/** Every live library with its use and quota, the fullest first. Rows: library_id, kind, name, used_bytes, quota_bytes, pct, documents. */
function quotas_overview(PDO $pdo, bool $nearOnly = false): array
{
    $rows = $pdo->query("SELECT l.id AS library_id, l.kind, l.name, l.used_bytes, l.quota_bytes, round(100.0 * l.used_bytes / NULLIF(l.quota_bytes, 0), 1) AS pct,
                                (SELECT count(*) FROM documents d WHERE d.library_id = l.id AND d.trashed_at IS NULL) AS documents
                           FROM libraries l WHERE l.archived_at IS NULL ORDER BY (l.quota_bytes IS NOT NULL AND l.used_bytes >= 0.9 * l.quota_bytes) DESC, l.used_bytes DESC, l.id")->fetchAll();
    return $nearOnly ? array_values(array_filter($rows, static fn (array $r): bool => $r['quota_bytes'] !== null && (int) $r['used_bytes'] >= 0.9 * (int) $r['quota_bytes'])) : $rows;
}

/** One library's quota state (dc_quota_state): used_bytes, quota_bytes, pct, near; null when the caller has no reach. */
function quota_state(PDO $pdo, int $libraryId): ?array
{
    $st = $pdo->prepare('SELECT used_bytes, quota_bytes, pct, near FROM dc_quota_state(:id)');
    $st->execute(['id' => $libraryId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The bar's class: under 90% primary, 90% and over warning, full (100%) danger. */
function quota_bar_class(?array $q): string
{
    if ($q === null || $q['quota_bytes'] === null) {
        return 'primary';
    }
    $pct = (float) $q['pct'];
    return $pct >= 100 ? 'danger' : ($pct >= 90 ? 'warning' : 'primary');
}

/** The owners with the most live bytes in one library. Rows: member_id, display_name, documents, live_bytes. */
function library_top_owners(PDO $pdo, int $libraryId, int $limit = 10): array
{
    $st = $pdo->prepare('SELECT d.owner_member_id AS member_id, m.display_name, count(*) AS documents, COALESCE(sum(d.byte_size), 0) AS live_bytes
                           FROM documents d LEFT JOIN members m ON m.id = d.owner_member_id WHERE d.library_id = :l AND d.trashed_at IS NULL
                          GROUP BY d.owner_member_id, m.display_name ORDER BY sum(d.byte_size) DESC NULLS LAST LIMIT :n');
    $st->bindValue('l', $libraryId, PDO::PARAM_INT);
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The retention windows as the settings hold them (the passes read these). */
function retention_settings(PDO $pdo): array
{
    return $pdo->query('SELECT versions_keep_days, versions_keep_count, trash_days, lock_hours, upload_session_hours, link_default_days, link_no_expiry_allowed FROM dc_settings WHERE id = 1')->fetch();
}

/** The last passes, newest first: id, started_at, finished_at, ms, steps (decoded), error. */
function worker_passes(PDO $pdo, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT id, started_at, finished_at, steps, error, round(extract(epoch FROM (finished_at - started_at)) * 1000) AS ms FROM worker_passes ORDER BY id DESC LIMIT :n');
    $st->bindValue('n', max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $steps = json_decode((string) $r['steps'], true) ?: [];                 // jsonb keeps keys by length: show them in the order the passes ran
        $ordered = [];
        foreach (WORKER_PASSES as $name) {
            if (isset($steps[$name])) {
                $ordered[$name] = $steps[$name];
                unset($steps[$name]);
            }
        }
        $r['steps'] = $ordered + $steps;
    }
    return $rows;
}

/** The queues at a glance: what waits for the worker. */
function worker_queues(PDO $pdo): array
{
    $q = static fn (string $sql): int => (int) one_value($pdo, $sql);
    return [
        'outbox_queued' => $q("SELECT count(*) FROM notification_outbox WHERE status = 'queued'"),
        'outbox_failed' => $q("SELECT count(*) FROM notification_outbox WHERE status = 'failed'"),
        'graph_queued' => $q("SELECT count(*) FROM graph_jobs WHERE status = 'queued'"),
        'uploads_open' => $q("SELECT count(*) FROM upload_sessions WHERE status IN ('open', 'assembling')"),
        'exports_queued' => $q("SELECT count(*) FROM exports WHERE status IN ('queued', 'running')"),
        'blobs_to_free' => $q('SELECT count(*) FROM blobs WHERE ref_count = 0 AND deleted_at IS NULL'),
        'trash_due' => $q("SELECT count(*) FROM documents WHERE trashed_at IS NOT NULL AND trashed_at < now() - make_interval(days => dc_setting_int('trash_days')::integer)"),
        'locks_stale' => $q('SELECT count(*) FROM documents WHERE locked_by IS NOT NULL AND lock_expires_at <= now()'),
        'key_buckets_old' => $q("SELECT count(*) FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days')"),
    ];
}

/** A pass's count from its result: the sum of its integer fields (a stub counts nothing). */
function worker_step_count(array $result): int
{
    if (!empty($result['stub'])) {
        return 0;
    }
    $n = 0;
    foreach ($result as $k => $v) {
        if (is_int($v) && !in_array($k, ['near'], true)) {
            $n += $v;
        }
    }
    return $n;
}

// ---- slice 9: the admin's index, every link, every library, the settings ------------------------------------------------------------------------------------------------------

/** The settings the admin form shows (mcp_settings: the view the records server reads too). */
function settings_current(PDO $pdo): array
{
    $r = $pdo->query('SELECT * FROM mcp_settings')->fetch();
    return $r === false ? [] : $r;
}

/**
 * The admin's index: counts for each card and the health line. Every number reads a function or an mcp_* view the admin's rights open. health: storage (the store writable), graph (the engine
 * answers; unconfigured without its token), worker (the last pass and how long ago), directory (the last sync).
 */
function admin_index(PDO $pdo): array
{
    require_once dirname(__DIR__, 2) . '/doccloud/graph.php';
    $n = static fn (string $sql): int => (int) one_value($pdo, $sql);
    $counts = [
        'libraries' => $n('SELECT count(*) FROM mcp_libraries WHERE archived_at IS NULL'),
        'links_live' => $n('SELECT count(*) FROM dc_find_links(NULL, NULL, NULL, true)'),
        'links_suspicious' => $n('SELECT count(*) FROM dc_links_suspicious(10, 7)'),
        'keys_live' => $n("SELECT count(*) FROM mcp_tokens WHERE scope = 'api' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())"),
        'quotas_near' => count(quotas_overview($pdo, true)),
        'dispatches_open' => $n("SELECT count(*) FROM mcp_agent_dispatches WHERE status IN ('failed', 'refused', 'awaiting_approval')"),
        'graph_failed' => $n("SELECT count(*) FROM mcp_graph_jobs WHERE status = 'failed'"),
        'outbox_failed' => $n("SELECT count(*) FROM notification_outbox WHERE status = 'failed'"),
    ];
    $pass = $pdo->query('SELECT id, started_at, finished_at, error, extract(epoch FROM (now() - COALESCE(finished_at, started_at))) AS age FROM worker_passes ORDER BY id DESC LIMIT 1')->fetch();
    $sync = $pdo->query('SELECT last_run_at, last_error FROM directory_sync_state WHERE id = 1')->fetch();
    $graph = dc_graph_config() === null ? 'unconfigured' : (dc_graph_available() ? 'ok' : 'unavailable');
    $worker = $pass === false ? ['state' => 'never', 'last_at' => null, 'error' => null] : ['state' => $pass['error'] !== null ? 'error' : ((float) $pass['age'] > 600 ? 'stale' : 'ok'), 'last_at' => $pass['finished_at'] ?? $pass['started_at'], 'error' => $pass['error']];
    return ['counts' => $counts, 'health' => ['storage' => dc_storage_state(), 'graph' => $graph, 'worker' => $worker,
        'directory' => ['last_run_at' => $sync['last_run_at'] ?? null, 'error' => $sync['last_error'] ?? null]]];
}

/**
 * Every link and file request (dc_find_links with links.all), each with its maker, what it opens and whether dc_links_suspicious flags it (many addresses in a week).
 * $filters: status live|expiring|expired|revoked|suspicious (empty = every one), kind view|request.
 */
function all_links(PDO $pdo, array $filters = []): array
{
    $kind = in_array($filters['kind'] ?? '', ['view', 'request'], true) ? $filters['kind'] : null;
    $st = $pdo->prepare('SELECT l.*, m.display_name AS made_by_name, f.name AS folder_name, d.name AS document_name, s.addresses AS suspicious_addresses
                           FROM dc_find_links(NULL, NULL, :k, false) l LEFT JOIN members m ON m.id = l.made_by LEFT JOIN folders f ON f.id = l.folder_id LEFT JOIN documents d ON d.id = l.document_id
                           LEFT JOIN dc_links_suspicious(10, 7) s ON s.link_id = l.link_id ORDER BY l.created_at DESC, l.link_id DESC LIMIT 500');
    $st->execute(['k' => $kind]);
    $rows = [];
    $status = (string) ($filters['status'] ?? '');
    foreach ($st->fetchAll() as $r) {
        $r['state'] = link_state($r);
        $r['suspicious'] = $r['suspicious_addresses'] !== null;
        $keep = match ($status) {
            'live' => in_array($r['state'], ['live', 'expiring'], true),
            'expiring' => $r['state'] === 'expiring',
            'expired' => $r['state'] === 'expired',
            'revoked' => in_array($r['state'], ['revoked', 'rotated'], true),
            'suspicious' => $r['suspicious'],
            default => true,
        };
        if ($keep) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/** The JSON shape of a link row of the admin's list. */
function present_admin_link(array $l): array
{
    return ['link_id' => (int) $l['link_id'], 'kind' => $l['kind'], 'label' => $l['label'], 'state' => $l['state'], 'folder_id' => $l['folder_id'] === null ? null : (int) $l['folder_id'], 'document_id' => $l['document_id'] === null ? null : (int) $l['document_id'],
            'thing' => $l['document_name'] ?? $l['folder_name'], 'made_by' => $l['made_by'] === null ? null : (int) $l['made_by'], 'made_by_name' => $l['made_by_name'], 'has_password' => (bool) $l['has_password'], 'allow_download' => (bool) $l['allow_download'],
            'expires_at' => json_ts($l['expires_at']), 'view_count' => (int) $l['view_count'], 'upload_count' => (int) $l['upload_count'], 'last_used_at' => json_ts($l['last_used_at']), 'created_at' => json_ts($l['created_at']),
            'revoked_at' => json_ts($l['revoked_at']), 'suspicious' => (bool) $l['suspicious'], 'addresses' => $l['suspicious_addresses'] === null ? null : (int) $l['suspicious_addresses']];
}

/**
 * Every library the admin sees, personal ones included (mcp_libraries): kind, name, owner or group, used, quota, documents (dc_storage_by_library, live libraries). $filters: kind, archived.
 * Opening one goes through its own screen (/libraries/{id}), which logs `library.admin_view` once per session through dc_note_admin_view().
 */
function admin_libraries(PDO $pdo, array $filters = []): array
{
    $rows = all_libraries($pdo, $filters);
    $docs = [];
    foreach ($pdo->query('SELECT library_id, documents FROM dc_storage_by_library()')->fetchAll() as $r) {
        $docs[(int) $r['library_id']] = (int) $r['documents'];
    }
    $owners = [];
    foreach ($pdo->query('SELECT id, display_name FROM members')->fetchAll() as $m) {
        $owners[(int) $m['id']] = $m['display_name'];
    }
    $groups = [];
    foreach ($pdo->query('SELECT group_id, name FROM mcp_groups')->fetchAll() as $g) {
        $groups[(int) $g['group_id']] = $g['name'];
    }
    $mine = array_flip(array_map('intval', $pdo->query('SELECT id FROM libraries WHERE dc_library_base_level(id, app_current_member_id()) > 0')->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($rows as &$r) {
        $id = (int) $r['library_id'];
        $r['mine'] = isset($mine[$id]);
        $r['documents'] = $docs[$id] ?? null;
        $r['owner_name'] = $r['owner_member_id'] === null ? null : ($owners[(int) $r['owner_member_id']] ?? null);
        $r['group_name'] = $r['group_id'] === null ? null : ($groups[(int) $r['group_id']] ?? null);
        $r['pct'] = $r['quota_bytes'] === null || (int) $r['quota_bytes'] === 0 ? null : round(100 * (int) $r['used_bytes'] / (int) $r['quota_bytes'], 1);
    }
    unset($r);
    return $rows;
}

/** The kernel's connections for this application (GET /api/v1/apps/connections.php with the application token), or null when the kernel has no such route / does not answer. Rows: consumer, provider, tool, status. */
function kernel_connections(): ?array
{
    $answer = kernel_call('GET', '/api/v1/apps/connections.php', null, [], 5);
    if ($answer === null || ($answer['status'] ?? 0) !== 200 || !is_array($answer['body'] ?? null)) {
        return null;
    }
    $doc = $answer['body']['data'] ?? $answer['body'];
    $rows = $doc['connections'] ?? null;
    if (!is_array($rows)) {
        return null;
    }
    $out = [];
    foreach ($rows as $r) {
        if (is_array($r)) {
            $out[] = ['consumer' => (string) ($r['consumer'] ?? ''), 'provider' => (string) ($r['provider'] ?? ''), 'tool' => (string) ($r['tool'] ?? ''), 'status' => (string) ($r['status'] ?? '')];
        }
    }
    return $out;
}

/** The shares this application declares (maludb-os.json shares[]): [name, tool, ...] — what a consumer can be approved for. */
function declared_shares(): array
{
    $json = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
    $out = [];
    foreach (is_array($json['shares'] ?? null) ? $json['shares'] : [] as $s) {
        $out[] = ['name' => (string) ($s['name'] ?? $s['tool'] ?? ''), 'tool' => (string) ($s['tool'] ?? $s['name'] ?? ''), 'description' => (string) ($s['description'] ?? '')];
    }
    return $out;
}
