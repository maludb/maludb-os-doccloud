<?php
declare(strict_types=1);

/**
 * Reads of the Filing Clerk's proposals (slice 8, docs/build-specs/agents-clerk-notifications.md): the caller's open proposals (mcp_clerk_proposals — the person a proposal is for, the Clerk that
 * made it, a curator every one) with the names a card needs; the one proposal; the Clerk's report (dc_clerk_report — names, counts and ids across the store, never content).
 * The statuses: proposed (open), accepted, dismissed, expired (housekeeping, 31 days).
 */

/** Kind chips (the spec's status vocabulary) and the sentence each kind opens with on a card. */
const PROPOSAL_KIND_BADGE = ['untagged' => 'info', 'tags' => 'info', 'duplicate' => 'secondary', 'stale_share' => 'warning', 'link_expiring' => 'warning', 'link_suspicious' => 'danger',
    'request_open' => 'info', 'trash_due' => 'warning', 'locked' => 'secondary', 'no_text' => 'light', 'quota' => 'danger', 'budget' => 'danger', 'orphaned' => 'warning'];
const PROPOSAL_KIND_WORDS = ['untagged' => 'No tags', 'tags' => 'Suggested tags', 'duplicate' => 'Same file', 'stale_share' => 'Old guest access', 'link_expiring' => 'Link expiring',
    'link_suspicious' => 'Link opened widely', 'request_open' => 'Request open', 'trash_due' => 'Trash about to purge', 'locked' => 'Long lock', 'no_text' => 'Unreadable',
    'quota' => 'Library full', 'budget' => 'Graph budget', 'orphaned' => 'Owner has left'];
/** Statuses a person may filter by. */
const PROPOSAL_STATUS_WORDS = ['proposed' => 'Open', 'accepted' => 'Accepted', 'dismissed' => 'Dismissed', 'expired' => 'Expired'];

/**
 * The proposals the caller sees, newest first, with who they are for and the thing's name. $filters: status (default proposed), kind, mine (1 = only those for me, even for a curator).
 * Rows: clerk_proposal_id, kind, document_id, folder_id, library_id, link_id, for_member_id, for_name, title, payload (decoded), proposed_by, status, decided_by, decided_by_name, decided_at, created_at,
 * document_name, folder_name. 100 at most.
 */
function my_proposals(PDO $pdo, array $filters = []): array
{
    $status = isset(PROPOSAL_STATUS_WORDS[$filters['status'] ?? '']) ? $filters['status'] : 'proposed';
    $where = ['p.status = :status'];
    $args = ['status' => $status];
    if (!empty($filters['kind']) && isset(PROPOSAL_KIND_BADGE[$filters['kind']])) {
        $where[] = 'p.kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    if (!empty($filters['mine'])) {
        $where[] = 'p.for_member_id = app_current_member_id()';
    }
    if (!empty($filters['folder'])) {
        $where[] = 'p.folder_id = :folder';
        $args['folder'] = (int) $filters['folder'];
    }
    $st = $pdo->prepare('SELECT p.clerk_proposal_id, p.kind, p.document_id, p.folder_id, p.library_id, p.link_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.created_at,
                                fm.display_name AS for_name, dm.display_name AS decided_by_name, d.name AS document_name, f.name AS folder_name, l.name AS library_name
                           FROM mcp_clerk_proposals p
                           LEFT JOIN members fm ON fm.id = p.for_member_id LEFT JOIN members dm ON dm.id = p.decided_by
                           LEFT JOIN mcp_documents d ON d.document_id = p.document_id LEFT JOIN mcp_folders f ON f.folder_id = p.folder_id LEFT JOIN mcp_libraries l ON l.library_id = p.library_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY p.created_at DESC, p.clerk_proposal_id DESC LIMIT 100');
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
    }
    return $rows;
}

/** One proposal as the caller may see it (404 to the rest), or null. */
function find_clerk_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.clerk_proposal_id, p.kind, p.document_id, p.folder_id, p.library_id, p.link_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.created_at
                           FROM mcp_clerk_proposals p WHERE p.clerk_proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
    return $r;
}

/** How many open proposals are for me (the home's count). */
function my_open_proposal_count(PDO $pdo): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_clerk_proposals WHERE status = 'proposed' AND for_member_id = app_current_member_id()");
}

/**
 * The Clerk's report as the database answers it for the caller (the Clerk and the admin only; anyone else gets an empty report): kind => ['count', 'ids', 'detail'].
 * `ids` and `detail` hold ids, names and counts — never a document's text.
 */
function clerk_report(PDO $pdo, int $days = 7): array
{
    $st = $pdo->prepare('SELECT kind, count, ids, detail FROM dc_clerk_report(:d)');
    $st->execute(['d' => $days]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['kind']] = ['count' => (int) $r['count'], 'ids' => pg_int_array((string) ($r['ids'] ?? '{}')), 'detail' => json_decode((string) ($r['detail'] ?? 'null'), true)];
    }
    return $out;
}

/** The Clerk's last Monday note for me (the home card): the newest monday_note bell row, or null. */
function last_monday_note(PDO $pdo): ?array
{
    $r = $pdo->query("SELECT notification_id, title, body, created_at, read_at FROM mcp_notifications WHERE kind = 'monday_note' ORDER BY created_at DESC, notification_id DESC LIMIT 1")->fetch();
    return $r === false ? null : $r;
}

function present_clerk_proposal(array $p): array
{
    return ['proposal_id' => (int) $p['clerk_proposal_id'], 'kind' => $p['kind'], 'status' => $p['status'], 'title' => $p['title'], 'payload' => $p['payload'],
            'for_member_id' => $p['for_member_id'] === null ? null : (int) $p['for_member_id'], 'for_name' => $p['for_name'] ?? null,
            'document_id' => $p['document_id'] === null ? null : (int) $p['document_id'], 'document_name' => $p['document_name'] ?? null,
            'folder_id' => $p['folder_id'] === null ? null : (int) $p['folder_id'], 'folder_name' => $p['folder_name'] ?? null,
            'library_id' => $p['library_id'] === null ? null : (int) $p['library_id'], 'link_id' => $p['link_id'] === null ? null : (int) $p['link_id'],
            'proposed_by' => $p['proposed_by'] === null ? null : (int) $p['proposed_by'], 'decided_by' => $p['decided_by'] === null ? null : (int) $p['decided_by'],
            'decided_by_name' => $p['decided_by_name'] ?? null, 'decided_at' => json_ts($p['decided_at'] ?? null), 'created_at' => json_ts($p['created_at']), 'land' => proposal_land($p)];
}

/** Where a proposal's accept (and its card) points: the thing to act on. */
function proposal_land(array $p): string
{
    return match ($p['kind']) {
        'tags', 'untagged', 'duplicate', 'locked', 'trash_due' => $p['document_id'] !== null ? '/documents/' . (int) $p['document_id'] : ($p['kind'] === 'trash_due' ? '/trash/' : ($p['folder_id'] !== null ? '/folders/' . (int) $p['folder_id'] : '/curate/proposals')),
        'stale_share' => $p['folder_id'] !== null ? '/folders/' . (int) $p['folder_id'] . '/share' : '/shares/mine',
        'link_expiring', 'link_suspicious' => $p['folder_id'] !== null ? '/folders/' . (int) $p['folder_id'] . '/links' : ($p['document_id'] !== null ? '/documents/' . (int) $p['document_id'] . '/links' : '/shares/mine'),
        'request_open' => '/requests',
        'quota' => $p['library_id'] !== null ? '/admin/quotas?library=' . (int) $p['library_id'] : '/admin/quotas',
        'budget' => '/graph/admin',
        'orphaned' => '/curate/ownership' . (!empty($p['payload']['owner_member_id']) ? '?member=' . (int) $p['payload']['owner_member_id'] : ''),
        'no_text' => $p['document_id'] !== null ? '/documents/' . (int) $p['document_id'] : '/curate/proposals',
        default => '/curate/proposals',
    };
}
