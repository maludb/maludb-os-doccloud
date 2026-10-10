<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/links/queries.php';
require_once dirname(__DIR__) . '/graph/queries.php';

/**
 * The home (screen `home`), design §9 and docs/build-specs/home-curate-admin.md — each role's own. EVERY block reads an existing SQL function or an mcp_* view, never a raw table, so the home
 * can show nothing a role may not see:
 *   - a new Member (nothing of their own yet): ONE button, "Upload your first file";
 *   - a Member: my libraries (dc_my_libraries), Recent (dc_recent_documents), Shared with me this week (dc_shared_with_me), my open file requests, my links expiring this week
 *     (dc_links_expiring), the Clerk's last note and my open proposals;
 *   - a curator adds: untagged this week (dc_untagged), duplicates (dc_duplicates), stale guest shares (dc_stale_guest_shares);
 *   - the admin adds: storage by library (dc_storage_by_library), the graph queue and the month's spend (mcp_graph_jobs, dc_graph_budget_state), failed extractions (dc_extraction_failures);
 *   - a guest: Shared with me.
 * A block the role does not hold is null, so the JSON and the template never show it.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $guest = is_guest();
    $libraries = $guest ? [] : $pdo->query('SELECT library_id, kind, name, group_id, root_folder_id, level, used_bytes, quota_bytes, graph_policy FROM dc_my_libraries()')->fetchAll();
    $recent = $guest ? [] : $pdo->query('SELECT document_id, name, folder_id, mime_kind, updated_at, last_opened_at FROM dc_recent_documents(8)')->fetchAll();
    $shared = $pdo->query('SELECT s.kind, s.id, s.name, s.level, s.shared_at, m.display_name AS shared_by_name FROM dc_shared_with_me(500, 0) s LEFT JOIN members m ON m.id = s.shared_by ORDER BY s.shared_at DESC')->fetchAll();
    $sharedCount = count($shared);
    $weekAgo = time() - 7 * 86400;
    $sharedWeek = array_values(array_filter($shared, static fn (array $r): bool => strtotime((string) $r['shared_at']) >= $weekAgo));
    $hasDocuments = (int) one_value($pdo, 'SELECT count(*) FROM mcp_documents WHERE trashed_at IS NULL LIMIT 1') > 0;
    $personal = null;
    foreach ($libraries as $l) {
        if ($l['kind'] === 'personal') {
            $personal = $l;
        }
    }
    $note = $pdo->query("SELECT notification_id, title, body, created_at, read_at FROM mcp_notifications WHERE kind = 'monday_note' ORDER BY created_at DESC, notification_id DESC LIMIT 1")->fetch();
    $firstRun = !$guest && !$hasDocuments;
    $member = null;
    $curator = null;
    $admin = null;
    if (!$guest && !$firstRun) {
        $expiring = $pdo->query('SELECT link_id, label, kind, folder_id, document_id, expires_at, view_count FROM dc_links_expiring(7) WHERE made_by = app_current_member_id() ORDER BY expires_at')->fetchAll();
        $member = ['shared_week' => $sharedWeek, 'requests_open' => my_requests($pdo, 'open'), 'links_expiring' => $expiring];
        if (has_right('groups.manage')) {
            $untagged = array_values(array_filter($pdo->query('SELECT document_id, name, folder_id, created_at FROM dc_untagged(NULL, 200)')->fetchAll(), static fn (array $r): bool => strtotime((string) $r['created_at']) >= $weekAgo));
            $curator = ['untagged_week' => $untagged,
                        'duplicates' => has_right('reports.storage') ? $pdo->query('SELECT sha256, byte_size, copies, document_ids, names FROM dc_duplicates(5)')->fetchAll() : [],
                        'stale_shares' => $pdo->query('SELECT kind, id, name, guest_member_id, guest_name, level, created_at FROM dc_stale_guest_shares()')->fetchAll()];
        }
        if (has_right('settings.manage')) {
            $jobs = ['queued' => 0, 'running' => 0, 'failed' => 0];
            if (has_right('graph.manage')) {
                foreach ($pdo->query("SELECT status, count(*) AS n FROM mcp_graph_jobs WHERE status IN ('queued', 'running', 'failed') GROUP BY status")->fetchAll() as $r) {
                    $jobs[$r['status']] = (int) $r['n'];
                }
            }
            $failures = $pdo->query('SELECT document_id, name, status, error, uploaded_at FROM dc_extraction_failures(200)')->fetchAll();
            $admin = ['storage' => has_right('reports.storage') ? array_slice($pdo->query('SELECT library_id, kind, name, used_bytes, quota_bytes, pct, documents FROM dc_storage_by_library()')->fetchAll(), 0, 5) : [],
                      'graph' => ['jobs' => $jobs, 'budget' => has_right('costs.read') ? graph_budget($pdo) : null],
                      'failures' => array_slice($failures, 0, 5), 'failures_total' => count($failures)];
        }
    }
    return [
        'clerk_note' => $note === false || $guest ? null : $note,
        'proposals_open' => $guest ? 0 : (int) one_value($pdo, "SELECT count(*) FROM mcp_clerk_proposals WHERE status = 'proposed' AND for_member_id = app_current_member_id()"),
        'libraries' => $libraries,
        'recent' => $recent,
        'shared_count' => $guest ? $sharedCount : 0,
        'shared_recent' => $guest ? array_slice($shared, 0, 5) : [],
        'first_run' => $firstRun,
        'personal_root' => $personal !== null ? (int) $personal['root_folder_id'] : null,
        'guest' => $guest,
        'member' => $member,
        'curator' => $curator,
        'admin' => $admin,
        'may' => ['upload' => has_right('documents.upload'), 'curate' => has_right('groups.manage'), 'admin' => has_right('settings.manage')],
    ];
}

/** The JSON shape of the home's role blocks — whitelists. */
function present_home_blocks(array $s): array
{
    $m = $s['member'];
    $c = $s['curator'];
    $a = $s['admin'];
    $linkRow = static fn (array $r): array => ['link_id' => (int) $r['link_id'], 'label' => $r['label'], 'kind' => $r['kind'], 'folder_id' => $r['folder_id'] === null ? null : (int) $r['folder_id'],
        'document_id' => $r['document_id'] === null ? null : (int) $r['document_id'], 'expires_at' => json_ts($r['expires_at'] ?? null)];
    return [
        'shared_recent' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'level' => $r['level'], 'shared_at' => json_ts($r['shared_at'])], $s['shared_recent']),
        'shared_week' => $m === null ? null : array_map(static fn (array $r): array => ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'level' => $r['level'], 'shared_by_name' => $r['shared_by_name'],
            'shared_at' => json_ts($r['shared_at'])], $m['shared_week']),
        'requests_open' => $m === null ? null : array_map(static fn (array $r): array => $linkRow($r) + ['folder_name' => $r['folder_name'], 'uploads' => (int) $r['uploads']], $m['requests_open']),
        'links_expiring' => $m === null ? null : array_map($linkRow, $m['links_expiring']),
        'curator' => $c === null ? null : [
            'untagged_week' => array_map(static fn (array $r): array => ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'folder_id' => (int) $r['folder_id'], 'created_at' => json_ts($r['created_at'])], $c['untagged_week']),
            'duplicates' => array_map(static fn (array $r): array => ['copies' => (int) $r['copies'], 'byte_size' => (int) $r['byte_size'], 'names' => pg_text_array((string) $r['names']), 'document_ids' => pg_int_array((string) $r['document_ids'])], $c['duplicates']),
            'stale_shares' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'id' => (int) $r['id'], 'name' => $r['name'], 'guest_member_id' => (int) $r['guest_member_id'], 'guest_name' => $r['guest_name'],
                'level' => $r['level'], 'since' => json_ts($r['created_at'])], $c['stale_shares'])],
        'admin' => $a === null ? null : [
            'storage' => array_map(static fn (array $r): array => ['library_id' => (int) $r['library_id'], 'kind' => $r['kind'], 'name' => $r['name'], 'used_bytes' => (int) $r['used_bytes'],
                'quota_bytes' => $r['quota_bytes'] === null ? null : (int) $r['quota_bytes'], 'pct' => $r['pct'] === null ? null : (float) $r['pct'], 'documents' => (int) $r['documents']], $a['storage']),
            'graph' => ['jobs' => $a['graph']['jobs'], 'budget' => $a['graph']['budget'] === null ? null : ['month' => $a['graph']['budget']['month'], 'used_chars' => $a['graph']['budget']['full_chars'],
                'budget_chars' => $a['graph']['budget']['budget_chars'], 'pct' => $a['graph']['budget']['pct']]],
            'failures_total' => $a['failures_total'],
            'failures' => array_map(static fn (array $r): array => ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'status' => $r['status'], 'uploaded_at' => json_ts($r['uploaded_at'])], $a['failures'])],
    ];
}
