<?php
declare(strict_types=1);

/**
 * THE CLERK'S MONDAY STORE REPORT as the application can run it WITHOUT a model (slice 8, spec "The Clerk's Monday report"): dc_clerk_report() — names, counts and ids across the store, never a
 * document's text (db/016: a SECURITY DEFINER read only the Clerk's member id and the admin may call) — turned into `clerk_proposals` rows, one per finding, each FOR the one person whose decision it
 * is, then the Monday notes (one per group to its owners, one to the admin). The Clerk agent runs the same duty in the kernel through the report tools and `clerk_propose`; both are deduped
 * (kind + thing + week, and not again for 28 days after a dismissal), so the worker's pass at Monday 07:00 — after the Clerk's 06:50 — only fills what the agent did not reach.
 */
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/agents/dispatch.php';

/** Run $fn with the database acting as $memberId (the report's wall asks who is calling), then back to nobody. */
function acting_as(PDO $pdo, int $memberId, callable $fn): mixed
{
    $set = $pdo->prepare("SELECT set_config('app.member_id', ?, false)");
    $set->execute([(string) $memberId]);
    try {
        return $fn();
    } finally {
        $set->execute(['']);
    }
}

/** The tag slugs the graph's subjects suggest for a document: the subjects less the structural ones (doc: folder: library: owner: kind: year: tag:), as slugs, at most five. */
function graph_tag_suggestions(PDO $pdo, int $documentId): array
{
    $subjects = pg_text_array((string) one_value($pdo, 'SELECT subjects FROM graph_documents WHERE document_id = :id', ['id' => $documentId]));
    $out = [];
    foreach ($subjects as $s) {
        if (preg_match('/^(doc|folder|library|owner|kind|year|tag):/', $s)) {
            continue;
        }
        $slug = (string) one_value($pdo, 'SELECT dc_slugify(:s)', ['s' => $s]);
        if ($slug !== '' && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
        if (count($out) === 5) {
            break;
        }
    }
    return $out;
}

/** The documents by id: id => [name, owner_member_id, folder_id, library_id]. */
function documents_by_ids(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, name, owner_member_id, folder_id, library_id FROM documents WHERE id = ANY (CAST(:ids AS bigint[])) ORDER BY id');
    $st->execute(['ids' => pg_array_literal(array_map('intval', $ids))]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

/** The person a proposal is for: $preferred when an active person here, else the folder's accountable person, else the first admin. */
function proposal_person(PDO $pdo, ?int $preferred, ?int $folderId = null): ?int
{
    return accountable_member($pdo, $folderId, $preferred);
}

/** "12 Oct 2026" for a title. */
function title_date(?string $ts): string
{
    return $ts === null ? '' : (new DateTimeImmutable($ts))->format('j M Y');
}

/**
 * Turn a report (clerk_report()) into proposals. Returns per kind how many were made: ['tags' => 2, 'untagged' => 1, …] and 'total'. A kind with nothing to say makes nothing ("silence is also a report" —
 * the admin's note says how many libraries are in order).
 */
function clerk_build_proposals(PDO $pdo, array $report, int $clerkId): array
{
    $made = [];
    $add = static function (string $kind, array $f) use ($pdo, $clerkId, &$made): void {
        if ($f['for_member'] === null) {
            return;
        }
        if (clerk_propose($pdo, ['kind' => $kind] + $f, $clerkId, false) !== null) {
            $made[$kind] = ($made[$kind] ?? 0) + 1;
        }
    };
    $admin = admin_member_ids($pdo)[0] ?? null;

    // untagged → one `tags` proposal per document the graph can suggest for, one `untagged` per document it cannot; a folder with more than five becomes ONE `untagged` for the folder
    $docs = documents_by_ids($pdo, $report['untagged']['ids'] ?? []);
    $byFolder = [];
    foreach ($docs as $d) {
        $byFolder[(int) $d['folder_id']][] = $d;
    }
    foreach ($byFolder as $folderId => $list) {
        if (count($list) > 5) {
            $path = (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => $folderId]);
            $add('untagged', ['title' => count($list) . ' documents in ' . $path . ' have no tags', 'for_member' => proposal_person($pdo, null, $folderId), 'folder_id' => $folderId, 'key' => 'folder:' . $folderId,
                'payload' => ['count' => count($list), 'document_ids' => array_slice(array_map(static fn (array $d): int => (int) $d['id'], $list), 0, 40)]]);
            continue;
        }
        foreach ($list as $d) {
            $tags = graph_tag_suggestions($pdo, (int) $d['id']);
            $for = proposal_person($pdo, $d['owner_member_id'] === null ? null : (int) $d['owner_member_id'], $folderId);
            if ($tags !== []) {
                $add('tags', ['title' => 'Tag ' . $d['name'] . ': ' . implode(', ', $tags), 'for_member' => $for, 'document_id' => (int) $d['id'], 'folder_id' => $folderId, 'payload' => ['tags' => $tags, 'source' => 'graph']]);
            } else {
                $add('untagged', ['title' => $d['name'] . ' has no tags', 'for_member' => $for, 'document_id' => (int) $d['id'], 'folder_id' => $folderId, 'payload' => ['tags' => []]]);
            }
        }
    }
    // duplicates: the same bytes in several places — one proposal per file, for the first copy's owner
    foreach ($report['duplicate']['detail'] ?? [] as $g) {
        $ids = array_map('intval', (array) ($g['ids'] ?? []));
        $d = documents_by_ids($pdo, array_slice($ids, 0, 1))[$ids[0] ?? 0] ?? null;
        if ($d === null) {
            continue;
        }
        $add('duplicate', ['title' => (int) $g['copies'] . ' copies of ' . $d['name'], 'for_member' => proposal_person($pdo, $d['owner_member_id'] === null ? null : (int) $d['owner_member_id'], (int) $d['folder_id']),
            'document_id' => (int) $d['id'], 'folder_id' => (int) $d['folder_id'], 'payload' => ['copies' => (int) $g['copies'], 'document_ids' => array_slice($ids, 0, 20), 'sha256' => substr((string) $g['sha256'], 0, 12)]]);
    }
    // guest access older than the window
    foreach ($report['stale_share']['detail'] ?? [] as $s) {
        $folder = (int) ($s['folder_id'] ?? 0);
        $guest = mirrored_member($pdo, (int) $s['guest']);
        $path = $folder > 0 ? (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => $folder]) : '';
        if ($folder === 0 || $guest === null) {
            continue;
        }
        $days = (int) ((time() - strtotime((string) $s['since'])) / 86400);
        $add('stale_share', ['title' => $guest['display_name'] . ' has had access to ' . $path . ' for ' . $days . ' days', 'for_member' => proposal_person($pdo, isset($s['by']) ? (int) $s['by'] : null, $folder),
            'folder_id' => $folder, 'key' => 'guest:' . (int) $s['guest'], 'payload' => ['guest_member_id' => (int) $s['guest'], 'since' => substr((string) $s['since'], 0, 10), 'days' => $days]]);
    }
    // links and requests: the maker decides
    $linkRow = $pdo->prepare('SELECT id, label, kind, folder_id, document_id, made_by, expires_at FROM share_links WHERE id = :id');
    foreach (['link_expiring', 'link_suspicious', 'request_open'] as $kind) {
        foreach ($report[$kind]['detail'] ?? [] as $x) {
            $linkRow->execute(['id' => (int) $x['link_id']]);
            $l = $linkRow->fetch();
            if ($l === false) {
                continue;
            }
            $folder = $l['folder_id'] === null ? null : (int) $l['folder_id'];
            $title = match ($kind) {
                'link_expiring' => 'The link ' . $l['label'] . ' expires on ' . title_date($l['expires_at']),
                'link_suspicious' => 'The link ' . $l['label'] . ' was opened from ' . (int) $x['addresses'] . ' addresses this week',
                default => 'The file request ' . $l['label'] . ' is still open (' . (int) $x['uploads'] . ' file' . ((int) $x['uploads'] === 1 ? '' : 's') . ' so far)',
            };
            $add($kind, ['title' => $title, 'for_member' => proposal_person($pdo, (int) $l['made_by'], $folder), 'link_id' => (int) $l['id'], 'folder_id' => $folder, 'document_id' => $l['document_id'] === null ? null : (int) $l['document_id'],
                'payload' => $kind === 'link_expiring' ? ['expires_on' => substr((string) $l['expires_at'], 0, 10)] : ($kind === 'link_suspicious' ? ['addresses' => (int) $x['addresses'], 'opens' => (int) $x['opens']] : ['uploads' => (int) $x['uploads']])]);
        }
    }
    // the trash about to purge, long locks: the owner; more than five in a trash becomes one proposal per owner
    $trash = [];
    foreach ($report['trash_due']['detail'] ?? [] as $t) {
        $trash[(int) ($t['owner'] ?? 0)][] = $t;
    }
    foreach ($trash as $owner => $list) {
        $names = documents_by_ids($pdo, array_map(static fn (array $t): int => (int) $t['document_id'], $list));
        if (count($list) > 5) {
            $add('trash_due', ['title' => count($list) . ' documents of yours are about to be purged from the trash', 'for_member' => proposal_person($pdo, $owner ?: null), 'key' => 'owner:' . $owner,
                'payload' => ['count' => count($list), 'document_ids' => array_slice(array_keys($names), 0, 20)]]);
            continue;
        }
        foreach ($list as $t) {
            $d = $names[(int) $t['document_id']] ?? null;
            if ($d === null) {
                continue;
            }
            $add('trash_due', ['title' => $d['name'] . ' will be purged from the trash on ' . title_date((string) $t['purge_at']), 'for_member' => proposal_person($pdo, $owner ?: null, (int) $d['folder_id']),
                'document_id' => (int) $d['id'], 'payload' => ['purge_on' => substr((string) $t['purge_at'], 0, 10)]]);
        }
    }
    $locked = documents_by_ids($pdo, array_map(static fn (array $l): int => (int) $l['document_id'], $report['locked']['detail'] ?? []));
    foreach ($report['locked']['detail'] ?? [] as $l) {
        $d = $locked[(int) $l['document_id']] ?? null;
        if ($d === null) {
            continue;
        }
        $holder = mirrored_member($pdo, (int) $l['locked_by']);
        $add('locked', ['title' => $d['name'] . ' has been locked by ' . ($holder['display_name'] ?? 'someone') . ' since ' . title_date((string) $l['since']),
            'for_member' => proposal_person($pdo, $d['owner_member_id'] === null ? null : (int) $d['owner_member_id'], (int) $d['folder_id']), 'document_id' => (int) $d['id'], 'folder_id' => (int) $d['folder_id'],
            'payload' => ['locked_by' => (int) $l['locked_by'], 'since' => substr((string) $l['since'], 0, 10)]]);
    }
    // documents whose text could not be read: one proposal per owner
    $noText = [];
    foreach (documents_by_ids($pdo, $report['no_text']['ids'] ?? []) as $d) {
        $noText[(int) ($d['owner_member_id'] ?? 0)][] = $d;
    }
    foreach ($noText as $owner => $list) {
        $add('no_text', ['title' => count($list) . ' of your documents have no readable text', 'for_member' => proposal_person($pdo, $owner ?: null), 'key' => 'owner:' . $owner,
            'payload' => ['count' => count($list), 'document_ids' => array_slice(array_map(static fn (array $d): int => (int) $d['id'], $list), 0, 20), 'by_status' => $report['no_text']['detail']['by_status'] ?? []]]);
    }
    // libraries near their quota and the graph's budget: the admin raises them
    foreach ($report['quota']['detail'] ?? [] as $q) {
        $pct = (int) round(100 * (int) $q['used'] / max(1, (int) $q['quota']));
        $add('quota', ['title' => $q['name'] . ' is ' . $pct . '% full', 'for_member' => $admin, 'library_id' => (int) $q['library_id'], 'payload' => ['pct' => $pct, 'used_bytes' => (int) $q['used'], 'quota_bytes' => (int) $q['quota']]]);
    }
    if (($report['budget']['count'] ?? 0) > 0) {
        $b = $report['budget']['detail'];
        $add('budget', ['title' => 'The document graph has used ' . (int) $b['pct'] . '% of this month\'s text budget', 'for_member' => $admin, 'key' => 'budget:' . substr((string) $b['month'], 0, 7),
            'payload' => ['pct' => (float) $b['pct'], 'month' => substr((string) $b['month'], 0, 7), 'full_chars' => (int) $b['full_chars'], 'budget_chars' => (int) $b['budget_chars']]]);
    }
    // documents whose owner has left: the admin or a curator transfers them
    foreach ($report['orphaned']['detail']['owners'] ?? [] as $ownerId => $n) {
        $o = mirrored_member($pdo, (int) $ownerId);
        $ids = array_map('intval', $pdo->query("SELECT id FROM documents WHERE owner_member_id = " . (int) $ownerId . " AND trashed_at IS NULL ORDER BY id LIMIT 20")->fetchAll(PDO::FETCH_COLUMN));
        $add('orphaned', ['title' => ($o['display_name'] ?? 'A former member') . ' has left: ' . (int) $n . ' document' . ((int) $n === 1 ? '' : 's') . ' still have them as owner', 'for_member' => $admin, 'key' => 'owner:' . (int) $ownerId,
            'payload' => ['owner_member_id' => (int) $ownerId, 'count' => (int) $n, 'document_ids' => $ids]]);
    }
    $made['total'] = array_sum($made);
    return $made;
}

/** The Markdown body of a Monday note: a line per kind with its count, then where to decide. */
function clerk_note_body(array $byKind, string $intro): string
{
    $lines = [$intro, ''];
    foreach ($byKind as $kind => $n) {
        $lines[] = '- ' . (int) $n . ' × ' . (PROPOSAL_KIND_WORDS[$kind] ?? $kind);
    }
    $lines[] = '';
    $lines[] = 'Open Proposals (/curate/proposals) to accept or dismiss each one.';
    return implode("\n", $lines);
}

/**
 * The Monday notes: one per group to its owners (the open proposals about that group's library, by kind), one to every admin (everything open, and how many groups are in order). A bell row
 * and the person's preferences' mail, once a week each (record = the group and the ISO week). Returns ['groups' => n owners told, 'admins' => n].
 */
function clerk_monday_notes(PDO $pdo, DateTimeImmutable $now, int $clerkId): array
{
    $week = iso_week($now);
    $out = ['groups' => 0, 'admins' => 0, 'in_order' => 0];
    $perLib = $pdo->prepare("SELECT p.kind, count(*) AS n FROM clerk_proposals p
                               LEFT JOIN documents d ON d.id = p.document_id LEFT JOIN folders f ON f.id = p.folder_id LEFT JOIN share_links sl ON sl.id = p.link_id
                               LEFT JOIN folders slf ON slf.id = sl.folder_id LEFT JOIN documents sld ON sld.id = sl.document_id
                              WHERE p.status = 'proposed' AND COALESCE(p.library_id, d.library_id, f.library_id, slf.library_id, sld.library_id) = :lib GROUP BY p.kind ORDER BY count(*) DESC, p.kind");
    $groups = $pdo->query("SELECT g.id, g.name, l.id AS library_id FROM groups g JOIN libraries l ON l.group_id = g.id WHERE g.archived_at IS NULL AND l.archived_at IS NULL ORDER BY g.id")->fetchAll();
    $owners = $pdo->prepare("SELECT member_id FROM group_members WHERE group_id = :g AND role = 'owner' AND left_at IS NULL ORDER BY joined_at, member_id");
    foreach ($groups as $g) {
        $perLib->execute(['lib' => (int) $g['library_id']]);
        $byKind = array_column($perLib->fetchAll(), 'n', 'kind');
        if ($byKind === []) {
            $out['in_order']++;
            continue;
        }
        $owners->execute(['g' => (int) $g['id']]);
        foreach ($owners->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $id = notify($pdo, (int) $m, 'monday_note', 'Monday note: ' . $g['name'] . ' has ' . array_sum($byKind) . ' thing' . (array_sum($byKind) === 1 ? '' : 's') . ' for you to look at',
                clerk_note_body($byKind, 'The Clerk read the store this morning. In ' . $g['name'] . ':'), ['record_type' => 'group_week', 'record_id' => (int) $g['id'] * 1000000 + $week['number'],
                'actor' => $clerkId, 'once_since' => "'-infinity'", 'dedupe' => 'monday_note:' . $week['label'] . ':g' . (int) $g['id'] . ':m' . (int) $m]);
            $out['groups'] += $id === null ? 0 : 1;
        }
    }
    $all = array_column($pdo->query("SELECT kind, count(*) AS n FROM clerk_proposals WHERE status = 'proposed' GROUP BY kind ORDER BY count(*) DESC, kind")->fetchAll(), 'n', 'kind');
    foreach (admin_member_ids($pdo) as $m) {
        $intro = $all === [] ? 'The Clerk read the store this morning: nothing needs a hand.' : 'The Clerk read the store this morning. Open and waiting for a decision:';
        $intro .= ' ' . $out['in_order'] . ' group librar' . ($out['in_order'] === 1 ? 'y is' : 'ies are') . ' in order.';
        $id = notify($pdo, $m, 'monday_note', 'Monday note: ' . ($all === [] ? 'the store is in order' : array_sum($all) . ' open proposal' . (array_sum($all) === 1 ? '' : 's')), clerk_note_body($all, $intro),
            ['record_type' => 'week', 'record_id' => $week['number'], 'actor' => $clerkId, 'once_since' => "'-infinity'", 'dedupe' => 'monday_note:' . $week['label'] . ':admin:m' . $m]);
        $out['admins'] += $id === null ? 0 : 1;
    }
    return $out;
}

/**
 * The Monday sweep, once a week: the report read as the Clerk, the proposals made, the notes sent, `clerk.report` logged with the ISO week (the marker that makes a second run a no-op).
 * Skipped without a named Clerk or when the admin switched the report off. $force runs it though the week has its report (the proposals are deduped anyway).
 */
function clerk_sweep(PDO $pdo, DateTimeImmutable $now, bool $force = false): array
{
    $s = agent_settings($pdo);
    if ($s['clerk_member_id'] === null) {
        return ['proposed' => 0, 'skipped' => 'no_clerk_named'];
    }
    if (!$s['clerk_report_enabled']) {
        return ['proposed' => 0, 'skipped' => 'report_switched_off'];
    }
    $week = iso_week($now);
    if (!$force && (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'clerk.report' AND after->>'week' = :w", ['w' => $week['label']]) > 0) {
        return ['proposed' => 0, 'skipped' => 'already_this_week'];
    }
    $clerk = (int) $s['clerk_member_id'];
    $report = acting_as($pdo, $clerk, static fn (): array => clerk_report($pdo, 7));
    $made = clerk_build_proposals($pdo, $report, $clerk);
    $notes = clerk_monday_notes($pdo, $now, $clerk);
    log_activity($pdo, 'clerk.report', 'clerk', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['week' => $week['label'], 'proposed' => $made, 'notes' => $notes,
        'found' => array_map(static fn (array $r): int => $r['count'], $report)]]);
    return ['proposed' => (int) $made['total'], 'noted' => $notes['groups'] + $notes['admins']];
}

/** The worker's `proposals` pass: on Monday from 07:00 (the organization's time zone — the Clerk's own duty ran at 06:50) the sweep, once a week. */
function worker_proposals(PDO $pdo, DateTimeImmutable $now): array
{
    $tz = (string) one_value($pdo, 'SELECT timezone FROM dc_settings WHERE id = 1');
    try {
        $local = $now->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'UTC'));
    } catch (Throwable) {
        $local = $now;
    }
    if ($local->format('N') !== '1' || (int) $local->format('G') < 7) {
        return ['proposed' => 0, 'skipped' => 'not_monday_morning'];
    }
    return clerk_sweep($pdo, $local);
}
