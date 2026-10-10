<?php
declare(strict_types=1);

/**
 * Reads of the Curate area (slice 9): the index's counts, the leavers who still hold documents, the storage report and the search report. Every number comes from an existing SQL function
 * (dc_storage_by_library, dc_storage_by_owner, dc_duplicates, dc_search_gaps) or an mcp_* view, each already gated by its right (reports.storage, reports.search): without the right they answer
 * nothing, and so do these.
 */

/** The index's rows: [key => count or null]. A count is null when the caller's right does not reach it (the card is then not shown). */
function curate_counts(PDO $pdo): array
{
    $n = static fn (string $sql): int => (int) one_value($pdo, $sql);
    return [
        'groups' => has_right('groups.manage') ? $n('SELECT count(*) FROM mcp_groups WHERE archived_at IS NULL') : null,
        'shared_libraries' => has_right('libraries.shared') ? $n("SELECT count(*) FROM mcp_libraries WHERE kind = 'shared' AND archived_at IS NULL") : null,
        'tags' => has_right('tags.manage') ? $n('SELECT count(*) FROM mcp_tags WHERE archived_at IS NULL') : null,
        'rules' => has_right('tags.manage') ? $n("SELECT count(*) FROM mcp_folders WHERE trashed_at IS NULL AND auto_tags IS NOT NULL AND auto_tags <> '{}'") : null,
        'ownership' => has_right('ownership.transfer') ? count(inactive_owners($pdo)) : null,
        'storage_bytes' => has_right('reports.storage') ? (int) one_value($pdo, 'SELECT COALESCE(sum(used_bytes), 0) FROM dc_storage_by_library()') : null,
        'search_gaps' => has_right('reports.search') ? (int) one_value($pdo, 'SELECT count(*) FROM dc_search_gaps(now() - interval \'30 days\', 500)') : null,
        'proposals' => has_right('library.personal') ? $n("SELECT count(*) FROM mcp_clerk_proposals WHERE status = 'proposed'") : null,
    ];
}

/** Members who are no longer active here and still own live documents: member_id, display_name, status, documents, live_bytes (dc_storage_by_owner joined with members.status). */
function inactive_owners(PDO $pdo): array
{
    return $pdo->query("SELECT o.member_id, o.display_name, m.status, o.documents, o.live_bytes FROM dc_storage_by_owner(500) o JOIN members m ON m.id = o.member_id
                         WHERE m.status <> 'active' ORDER BY o.live_bytes DESC, o.member_id")->fetchAll();
}

/** A person as the ownership screen names them (any status): id, display_name, status, member_kind — or null. */
function owner_candidate(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, display_name, status, member_kind FROM members WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** What a transfer would move: every document the person owns (the trashed ones too — the SQL function moves them all), optionally in one library. [documents, bytes] */
function owner_holdings(PDO $pdo, int $memberId, ?int $libraryId = null): array
{
    $st = $pdo->prepare('SELECT count(*) AS n, COALESCE(sum(byte_size), 0) AS b FROM documents WHERE owner_member_id = :m AND (CAST(:l AS bigint) IS NULL OR library_id = :l)');
    $st->execute(['m' => $memberId, 'l' => $libraryId]);
    $r = $st->fetch();
    return ['documents' => (int) $r['n'], 'bytes' => (int) $r['b']];
}

/**
 * The storage report: libraries (dc_storage_by_library), owners (dc_storage_by_owner), duplicates with the bytes they waste (dc_duplicates), totals and the disk beside the sum of the blobs.
 */
function storage_report(PDO $pdo): array
{
    $libraries = $pdo->query('SELECT library_id, kind, name, used_bytes, quota_bytes, pct, documents, versions FROM dc_storage_by_library()')->fetchAll();
    $owners = $pdo->query('SELECT member_id, display_name, documents, live_bytes FROM dc_storage_by_owner(50)')->fetchAll();
    $dups = $pdo->query('SELECT sha256, byte_size, copies, document_ids, names FROM dc_duplicates(50)')->fetchAll();
    $wasted = 0;
    foreach ($dups as &$d) {
        $d['wasted'] = (int) $d['byte_size'] * ((int) $d['copies'] - 1);
        $wasted += $d['wasted'];
    }
    unset($d);
    return ['libraries' => $libraries, 'owners' => $owners, 'duplicates' => $dups, 'wasted_bytes' => $wasted,
            'totals' => ['used_bytes' => array_sum(array_map(static fn (array $l): int => (int) $l['used_bytes'], $libraries)), 'documents' => array_sum(array_map(static fn (array $l): int => (int) $l['documents'], $libraries)),
                         'versions' => array_sum(array_map(static fn (array $l): int => (int) $l['versions'], $libraries))],
            'disk' => storage_disk_truth($pdo)];
}

/**
 * The disk beside the database: the blobs the database holds (live: referenced; waiting: freed at the next pass; freed), the libraries' used bytes (a blob in two libraries counts in both),
 * what de-duplication saved, and the storage directory's state and free space (dc_storage_state, disk_free_space).
 */
function storage_disk_truth(PDO $pdo): array
{
    $b = $pdo->query('SELECT count(*) FILTER (WHERE ref_count > 0 AND deleted_at IS NULL) AS live_n, COALESCE(sum(byte_size) FILTER (WHERE ref_count > 0 AND deleted_at IS NULL), 0) AS live_b,
                             count(*) FILTER (WHERE ref_count = 0 AND deleted_at IS NULL) AS waiting_n, COALESCE(sum(byte_size) FILTER (WHERE ref_count = 0 AND deleted_at IS NULL), 0) AS waiting_b,
                             count(*) FILTER (WHERE deleted_at IS NOT NULL) AS freed_n, COALESCE(sum(byte_size) FILTER (WHERE deleted_at IS NOT NULL), 0) AS freed_b FROM blobs')->fetch();
    $used = (int) one_value($pdo, 'SELECT COALESCE(sum(used_bytes), 0) FROM libraries');
    $dir = dc_storage_dir();
    $free = @disk_free_space(is_dir($dir) ? $dir : dirname($dir));
    $total = @disk_total_space(is_dir($dir) ? $dir : dirname($dir));
    return ['state' => dc_storage_state(), 'directory' => $dir, 'free_bytes' => $free === false ? null : (int) $free, 'total_bytes' => $total === false ? null : (int) $total,
            'blobs_live' => (int) $b['live_n'], 'blobs_live_bytes' => (int) $b['live_b'], 'blobs_waiting' => (int) $b['waiting_n'], 'blobs_waiting_bytes' => (int) $b['waiting_b'],
            'blobs_freed' => (int) $b['freed_n'], 'blobs_freed_bytes' => (int) $b['freed_b'], 'libraries_used_bytes' => $used, 'deduplicated_bytes' => max(0, $used - (int) $b['live_b'])];
}

/** The days of "since": 7d, 30d, 90d, a number of days, or a date (YYYY-MM-DD) — 1 to 365 days back. Default 30. */
function search_since_days(string $since): int
{
    $since = strtolower(trim($since));
    if (preg_match('/^(\d{1,3})\s*(d|days?)?$/', $since, $m)) {
        return max(1, min(365, (int) $m[1]));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) && ($t = strtotime($since)) !== false) {
        return max(1, min(365, (int) ceil((time() - $t) / 86400)));
    }
    return 30;
}

/** The search report: the empty searches clustered (dc_search_gaps), the top queries, searches a day, keyword vs graph vs name. reports.search (mcp_search_log answers all of it to the holder only). */
function search_report(PDO $pdo, string $since = '30d'): array
{
    $days = search_since_days($since);
    $args = ['d' => $days];
    $gaps = $pdo->prepare('SELECT query, times, last_at FROM dc_search_gaps(now() - make_interval(days => :d), 50)');
    $gaps->execute($args);
    $top = $pdo->prepare("SELECT lower(query) AS query, count(*) AS times, round(avg(results), 1) AS avg_results FROM mcp_search_log WHERE created_at >= now() - make_interval(days => :d) GROUP BY lower(query) ORDER BY count(*) DESC, lower(query) LIMIT 20");
    $top->execute($args);
    $perDay = $pdo->prepare("SELECT created_at::date AS day, count(*) AS searches, count(*) FILTER (WHERE results = 0) AS empty FROM mcp_search_log WHERE created_at >= now() - make_interval(days => :d) GROUP BY created_at::date ORDER BY day DESC LIMIT 60");
    $perDay->execute($args);
    $kinds = $pdo->prepare('SELECT kind, count(*) AS n FROM mcp_search_log WHERE created_at >= now() - make_interval(days => :d) GROUP BY kind');
    $kinds->execute($args);
    $byKind = ['keyword' => 0, 'graph' => 0, 'name' => 0];
    foreach ($kinds->fetchAll() as $r) {
        $byKind[$r['kind']] = (int) $r['n'];
    }
    return ['days' => $days, 'gaps' => $gaps->fetchAll(), 'top' => $top->fetchAll(), 'per_day' => $perDay->fetchAll(), 'by_kind' => $byKind, 'searches' => array_sum($byKind)];
}
