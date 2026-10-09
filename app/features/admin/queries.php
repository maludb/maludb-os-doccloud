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
