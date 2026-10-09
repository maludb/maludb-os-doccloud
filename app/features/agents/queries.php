<?php
declare(strict_types=1);

/**
 * Reads of slice 8's agents (docs/build-specs/agents-clerk-notifications.md): the thresholds and the two agents' member ids (dc_settings), the two shipped agents as cards (from
 * maludb-os.json and the directory mirror), the dispatches (mcp_agent_dispatches — the admin sees every one, anyone else the ones they are the acting person of), the admins to tell.
 * An "agent" is always an OS AI agent. The Clerk and the expert are NAMED in the settings (db/016 clerk_member_id, db/022 expert_member_id), never guessed.
 */

/** The dispatch statuses with their badge colours (the spec's status vocabulary) and the kinds with their sentences. */
const DISPATCH_BADGE = ['sent' => 'info', 'answered' => 'success', 'awaiting_approval' => 'warning', 'refused' => 'dark', 'failed' => 'danger'];
const DISPATCH_KINDS = ['untagged_batch' => 'Tags for a batch of untagged documents', 'request_received' => 'Files received on a request', 'duplicates_found' => 'The same file kept many times',
    'ask' => 'A question about a folder', 'duty_proposal' => 'A duty\'s proposal'];
/** A dispatch makes up to this many attempts (the first plus two retries, `dispatch_retry_minutes` apart); a person's Retry starts again. */
const DISPATCH_MAX_ATTEMPTS = 3;
/** The expert is woken for a file kept at least this many times (and never below the report's own threshold, `duplicate_copies`). */
const DISPATCH_DUPLICATE_COPIES = 5;

/** The settings the agents screen edits and the worker reads: clerk_member_id, expert_member_id, untagged_batch_size, duplicate_copies, dispatch_retry_minutes, clerk_report_enabled. */
function agent_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT clerk_member_id, expert_member_id, untagged_batch_size, duplicate_copies, dispatch_retry_minutes, clerk_report_enabled FROM dc_settings WHERE id = 1')->fetch();
    return ['clerk_member_id' => $r['clerk_member_id'] === null ? null : (int) $r['clerk_member_id'], 'expert_member_id' => $r['expert_member_id'] === null ? null : (int) $r['expert_member_id'],
            'untagged_batch_size' => (int) $r['untagged_batch_size'], 'duplicate_copies' => (int) $r['duplicate_copies'], 'dispatch_retry_minutes' => (int) $r['dispatch_retry_minutes'],
            'clerk_report_enabled' => (bool) $r['clerk_report_enabled']];
}

/** The agents this application declares (maludb-os.json agents[]): key => ['job_description', 'duty' => ['name', 'schedule_cron'] | null, 'skills' => n, 'tools' => n]. */
function declared_agents(): array
{
    $json = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
    $out = [];
    foreach (is_array($json['agents'] ?? null) ? $json['agents'] : [] as $a) {
        $tools = 0;
        foreach ($a['tool_grants'] ?? [] as $list) {
            $tools += is_array($list) ? count($list) : 0;
        }
        $out[(string) $a['key']] = ['job_description' => (string) ($a['job_description'] ?? ''), 'duty' => $a['duty'] ?? null, 'skills' => count($a['skills'] ?? []), 'tools' => $tools];
    }
    return $out;
}

/** One mirrored member for a card: id, display_name, member_kind, status, capability — or null. */
function mirrored_member(PDO $pdo, ?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, display_name, member_kind, status, capability FROM members WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * The two shipped agents as cards: [expert, clerk], each key, role (words), member (mirror row or null), state ('hired' — named, active and admitted —, 'inactive', 'not_named'), the declared duty,
 * the last dispatch (expert) or the last Monday report and the open proposals (clerk).
 */
function agents_summary(PDO $pdo): array
{
    $s = agent_settings($pdo);
    $declared = declared_agents();
    $cards = [];
    foreach (['expert' => ['The expert', 'expert_member_id', 'Answers in the command bar: finds, explains access, files, tags and shares inside the business, and pauses on anything that reaches outside or deletes.'],
              'clerk' => ['The Filing Clerk', 'clerk_member_id', 'Every Monday reads the store report and leaves proposals people accept or dismiss; never deletes, links out or shares.']] as $key => [$name, $col, $about]) {
        $m = mirrored_member($pdo, $s[$col]);
        $state = $m === null ? 'not_named' : (($m['status'] === 'active' && $m['capability'] !== null) ? 'hired' : 'inactive');
        $card = ['key' => $key, 'name' => $name, 'about' => $about, 'member' => $m, 'state' => $state, 'duty' => $declared[$key]['duty'] ?? null, 'skills' => $declared[$key]['skills'] ?? 0, 'tools' => $declared[$key]['tools'] ?? 0];
        if ($key === 'expert') {
            $d = $s[$col] === null ? false : $pdo->query('SELECT id, kind, status, created_at FROM agent_dispatches WHERE agent_member_id = ' . (int) $s[$col] . ' ORDER BY id DESC LIMIT 1')->fetch();
            $card['last_dispatch'] = $d === false ? null : $d;
            $card['dispatches_open'] = (int) one_value($pdo, "SELECT count(*) FROM agent_dispatches WHERE status IN ('sent', 'awaiting_approval', 'failed')");
        } else {
            $card['last_report'] = one_value($pdo, "SELECT max(occurred_at) FROM activity_log WHERE action = 'clerk.report'");
            $card['proposals_open'] = (int) one_value($pdo, "SELECT count(*) FROM clerk_proposals WHERE status = 'proposed'");
            $card['report_enabled'] = $s['clerk_report_enabled'];
        }
        $cards[] = $card;
    }
    return $cards;
}

/**
 * The dispatches the caller may see (mcp_agent_dispatches), newest first, with the acting person's and the folder's names (the folder only when the caller may see it).
 * $filters: status, kind. 25 a page. Returns ['rows', 'total', 'page', 'pages'].
 */
function find_dispatches(PDO $pdo, array $filters, int $page = 1): array
{
    $per = 25;
    $where = [];
    $args = [];
    if (!empty($filters['status']) && isset(DISPATCH_BADGE[$filters['status']])) {
        $where[] = 'a.status = :status';
        $args['status'] = $filters['status'];
    }
    if (!empty($filters['kind']) && isset(DISPATCH_KINDS[$filters['kind']])) {
        $where[] = 'a.kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $total = (int) one_value($pdo, 'SELECT count(*) FROM mcp_agent_dispatches a' . $w, $args);
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($pages, $page));
    $st = $pdo->prepare('SELECT a.agent_dispatch_id, a.record_type, a.record_id, a.agent_member_id, a.kind, a.via, a.acting_member_id, a.run_id, a.request_id, a.status, a.reply_excerpt, a.detail,
                                a.attempts, a.created_at, a.answered_at, a.document_id, a.folder_id, m.display_name AS acting_name, f.name AS folder_name
                           FROM mcp_agent_dispatches a LEFT JOIN members m ON m.id = a.acting_member_id LEFT JOIN mcp_folders f ON f.folder_id = a.folder_id' . $w . '
                          ORDER BY a.agent_dispatch_id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function find_dispatch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT a.* FROM agent_dispatches a WHERE a.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The active people who administer DocCloud (the super-admin, a holder of the admin role): the ones told about the store as a whole. Ids, lowest first. */
function admin_member_ids(PDO $pdo): array
{
    return array_map('intval', $pdo->query("SELECT id FROM members WHERE status = 'active' AND capability IS NOT NULL AND member_kind = 'human' AND NOT is_external
                                             AND (business_role = 'super_admin' OR 'admin' = ANY (roles)) ORDER BY (business_role = 'super_admin') DESC, id")->fetchAll(PDO::FETCH_COLUMN));
}

/** The picker source `agent`: the active OS AI agents admitted to DocCloud, by name. */
function agents_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    $sql = "SELECT member_id, display_name, job_title FROM mcp_members WHERE member_kind = 'agent'" . ($q !== '' ? ' AND display_name ILIKE :q' : '') . ' ORDER BY lower(display_name)';
    $args = $q !== '' ? ['q' => '%' . $q . '%'] : [];
    return pick_page($pdo, $sql, $args, $page, $per, static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => $m['display_name'], 'detail' => $m['job_title'] ?: 'an agent', 'badge' => 'agent', 'color' => 'info']);
}

function present_dispatch(array $d): array
{
    return ['dispatch_id' => (int) $d['agent_dispatch_id'], 'kind' => $d['kind'], 'via' => $d['via'], 'status' => $d['status'], 'attempts' => (int) $d['attempts'],
            'agent_member_id' => (int) $d['agent_member_id'], 'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id'], 'acting_name' => $d['acting_name'] ?? null,
            'folder_id' => $d['folder_id'] === null ? null : (int) $d['folder_id'], 'folder_name' => $d['folder_name'] ?? null, 'document_id' => $d['document_id'] === null ? null : (int) $d['document_id'],
            'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'], 'run_id' => $d['run_id'] === null ? null : (int) $d['run_id'],
            'request_id' => $d['request_id'], 'reply_excerpt' => $d['reply_excerpt'], 'detail' => $d['detail'], 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])];
}

function present_agent_card(array $c): array
{
    $out = ['key' => $c['key'], 'name' => $c['name'], 'state' => $c['state'], 'member_id' => $c['member'] === null ? null : (int) $c['member']['id'], 'member_name' => $c['member']['display_name'] ?? null,
            'duty' => $c['duty'], 'skills' => (int) $c['skills'], 'tools' => (int) $c['tools']];
    if ($c['key'] === 'expert') {
        $out['last_dispatch'] = $c['last_dispatch'] === null ? null : ['dispatch_id' => (int) $c['last_dispatch']['id'], 'kind' => $c['last_dispatch']['kind'], 'status' => $c['last_dispatch']['status'], 'created_at' => json_ts($c['last_dispatch']['created_at'])];
        $out['dispatches_open'] = (int) $c['dispatches_open'];
    } else {
        $out['last_report'] = json_ts($c['last_report']);
        $out['proposals_open'] = (int) $c['proposals_open'];
        $out['report_enabled'] = (bool) $c['report_enabled'];
    }
    return $out;
}
