<?php
declare(strict_types=1);

/**
 * The reads of sharing (slice 3, docs/build-specs/access-sharing-links.md; the rules in visibility.md §2–3): the share screen's rows (the
 * library's base line, the inherited rows, this thing's own rows), why a member sees a thing, shared with me, my shares, who could see it
 * on a day. Every read is one of db/008's or db/014's functions or an mcp_* view — PHP decides nothing of the walls.
 */

const PRINCIPAL_KINDS = ['member' => 'person', 'agent' => 'agent', 'guest' => 'guest', 'group' => 'group', 'department' => 'department', 'application' => 'application', 'everyone' => 'everyone'];
const PRINCIPAL_BADGE = ['member' => 'primary', 'agent' => 'info', 'guest' => 'warning', 'group' => 'info', 'department' => 'secondary', 'application' => 'light text-dark', 'everyone' => 'light text-dark'];
/** The siblings a folder may be shared with (an application principal, visibility.md §2.2; K7's consumers). */
const KNOWN_APPLICATIONS = ['knowledge' => 'Knowledge', 'spaces' => 'Spaces', 'helpdesk' => 'Help Desk', 'projects' => 'Projects', 'hr' => 'HR'];

/** The share screen's data: the base line, where the inherited rows come from, the inherited rows, this thing's own rows. */
function access_rows(PDO $pdo, string $kind, int $id): array
{
    if ($kind === 'folder') {
        $st = $pdo->prepare('SELECT f.id, f.access_root_id, f.library_id, l.kind AS library_kind, l.name AS library_name, l.group_id, l.everyone_level, l.owner_member_id, g.member_level, g.name AS group_name
                               FROM folders f JOIN libraries l ON l.id = f.library_id LEFT JOIN groups g ON g.id = l.group_id WHERE f.id = :id');
        $st->execute(['id' => $id]);
        $t = $st->fetch();
        $ownRoot = $t !== false && (int) $t['access_root_id'] === $id;
        $inheritedFrom = $t !== false && $t['access_root_id'] !== null && !$ownRoot ? (int) $t['access_root_id'] : null;
        $own = $ownRoot ? access_rows_of($pdo, 'folder', $id) : [];
    } else {
        $st = $pdo->prepare('SELECT d.id, d.own_access, d.folder_id, d.library_id, d.owner_member_id AS document_owner_id, f.access_root_id, l.kind AS library_kind, l.name AS library_name, l.group_id, l.everyone_level, l.owner_member_id, g.member_level, g.name AS group_name
                               FROM documents d JOIN folders f ON f.id = d.folder_id JOIN libraries l ON l.id = d.library_id LEFT JOIN groups g ON g.id = l.group_id WHERE d.id = :id');
        $st->execute(['id' => $id]);
        $t = $st->fetch();
        $own = $t !== false && $t['own_access'] ? access_rows_of($pdo, 'document', $id) : [];
        $inheritedFrom = $t !== false && !$t['own_access'] && $t['access_root_id'] !== null ? (int) $t['access_root_id'] : null;
    }
    if ($t === false) {
        return ['base_line' => '', 'inherited_from' => null, 'inherited_from_name' => null, 'inherited' => [], 'explicit' => [], 'own_access' => false];
    }
    $inherited = $inheritedFrom === null ? [] : access_rows_of($pdo, 'folder', $inheritedFrom);
    $fromName = $inheritedFrom === null ? null : (string) one_value($pdo, 'SELECT name FROM folders WHERE id = :id', ['id' => $inheritedFrom]);
    return ['base_line' => access_base_line($t), 'inherited_from' => $inheritedFrom, 'inherited_from_name' => $fromName, 'inherited' => $inherited, 'explicit' => $own,
            'own_access' => $kind === 'document' && !empty($t['own_access']), 'library_kind' => $t['library_kind'], 'library_id' => (int) $t['library_id']];
}

/** The explicit rows of a folder or a document with their names (mcp_folder_access / mcp_document_access: the manager's view). */
function access_rows_of(PDO $pdo, string $kind, int $id): array
{
    $sql = $kind === 'folder'
        ? 'SELECT a.folder_access_id AS access_id, a.principal_kind, a.principal_id, a.principal_ref, a.principal_name, a.level, a.granted_by, a.created_at, m.display_name AS granted_by_name,
                  CASE WHEN a.principal_kind IN (\'member\', \'guest\') THEN dc_member_is_guest(a.principal_id) ELSE false END AS is_guest
             FROM mcp_folder_access a LEFT JOIN members m ON m.id = a.granted_by WHERE a.folder_id = :id'
        : 'SELECT a.document_access_id AS access_id, a.principal_kind, a.principal_id, a.principal_ref, a.principal_name, a.level, a.granted_by, a.created_at, m.display_name AS granted_by_name,
                  CASE WHEN a.principal_kind IN (\'member\', \'guest\') THEN dc_member_is_guest(a.principal_id) ELSE false END AS is_guest
             FROM mcp_document_access a LEFT JOIN members m ON m.id = a.granted_by WHERE a.document_id = :id';
    $st = $pdo->prepare($sql . " ORDER BY CASE a.principal_kind WHEN 'everyone' THEN 0 WHEN 'group' THEN 1 WHEN 'department' THEN 2 WHEN 'application' THEN 5 ELSE 3 END, a.principal_name");
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** "Everyone in the group Board edits (the library)" / "Everyone in the business views (the library)" / "Only you (your library)". */
function access_base_line(array $t): string
{
    return match ($t['library_kind']) {
        'personal' => (int) $t['owner_member_id'] === (int) current_member_id() ? 'Only you (your library)' : 'Only its owner (a personal library)',
        'group' => 'Everyone in the group ' . $t['group_name'] . ' ' . ($t['member_level'] === 'edit' ? 'edits' : 'views') . ' (the library); its owners manage',
        default => $t['everyone_level'] === 'none' ? 'Nobody by default (an invitation-only library); curators manage' : 'Everyone in the business ' . match ($t['everyone_level']) { 'edit' => 'edits', 'upload' => 'adds (and sees only their own)', default => 'views' } . ' (the library)',
    };
}

/** Why a member sees a folder or a document: dc_access_explain's rows as sentences. */
function access_explain(PDO $pdo, string $kind, int $id, int $memberId): array
{
    $st = $pdo->prepare('SELECT * FROM dc_access_explain(:d, :f, :m)');
    $st->execute(['d' => $kind === 'document' ? $id : null, 'f' => $kind === 'folder' ? $id : null, 'm' => $memberId]);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['reason' => $r['reason'], 'via_kind' => $r['via_kind'], 'via_id' => $r['via_id'] === null ? null : (int) $r['via_id'], 'via_name' => $r['via_name'], 'level' => $r['level'], 'sentence' => access_sentence($r)];
    }
    return $out;
}

function access_sentence(array $r): string
{
    return match ($r['reason']) {
        'owner' => ucfirst((string) $r['via_name']) . ' → manage',
        'admin' => 'DocCloud admin → manage (everything, logged)',
        'library' => 'member of the ' . $r['via_kind'] . ' library ' . $r['via_name'] . ' → ' . $r['level'],
        'document' => 'a row on this document: ' . $r['via_name'] . ' → ' . $r['level'],
        'folder' => 'a row on this folder: ' . $r['via_name'] . ' → ' . $r['level'],
        'inherited' => 'inherited: ' . $r['via_name'] . ' → ' . $r['level'],
        default => $r['via_name'] . ' → ' . $r['level'],
    };
}

/** Shared with me: explicit rows naming me, my groups or my departments (dc_shared_with_me), with the sharer's name. */
function shared_with_me(PDO $pdo, int $page = 1, int $per = 100): array
{
    $page = max(1, $page);
    $st = $pdo->prepare('SELECT s.*, m.display_name AS shared_by_name FROM dc_shared_with_me(:lim, :off) s LEFT JOIN members m ON m.id = s.shared_by');
    $st->execute(['lim' => $per + 1, 'off' => ($page - 1) * $per]);
    $rows = $st->fetchAll();
    $more = count($rows) > $per;
    return ['rows' => array_slice($rows, 0, $per), 'page' => $page, 'more' => $more];
}

/** What I granted (dc_my_shares), the stale guest shares flagged past the setting. */
function my_shares(PDO $pdo): array
{
    $days = (int) one_value($pdo, "SELECT dc_setting_int('stale_guest_share_days')");
    $rows = $pdo->query('SELECT * FROM dc_my_shares()')->fetchAll();
    foreach ($rows as &$r) {
        $r['stale'] = !empty($r['is_guest']) && strtotime((string) $r['created_at']) < time() - $days * 86400;
    }
    return $rows;
}

/** Who could see it on a day (the end of that day), from the trail (dc_access_history). */
function access_history(PDO $pdo, string $kind, int $id, string $at): array
{
    $st = $pdo->prepare("SELECT * FROM dc_access_history(:d, :f, (:at::date + 1)::timestamptz - interval '1 second')");
    $st->execute(['d' => $kind === 'document' ? $id : null, 'f' => $kind === 'folder' ? $id : null, 'at' => $at]);
    return $st->fetchAll();
}

/** The changes to a thing's access, newest first (mcp_access_changes). */
function access_changes(PDO $pdo, string $kind, int $id, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT c.*, dc_principal_name(c.principal_kind, c.principal_id, c.principal_ref) AS principal_name, m.display_name AS changed_by_name
                           FROM mcp_access_changes c LEFT JOIN members m ON m.id = c.changed_by WHERE ' . ($kind === 'folder' ? 'c.folder_id' : 'c.document_id') . ' = :id ORDER BY c.changed_at DESC, c.access_change_id DESC LIMIT :lim');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The guests (externals holding Guest) a share may name — the picker source `guest`. */
function guests_for_pick(PDO $pdo, string $q, array $params, int $page, int $per = 25): array
{
    return pick_page($pdo, "SELECT m.member_id AS id, m.display_name AS label, m.job_title AS detail FROM mcp_members m WHERE m.is_guest AND m.member_kind = 'human' AND (:q = '' OR m.display_name ILIKE :like) ORDER BY m.display_name",
        ['q' => $q, 'like' => '%' . $q . '%'], $page, $per, static fn (array $r): array => ['id' => (int) $r['id'], 'label' => $r['label'], 'detail' => $r['detail'], 'badge' => 'guest', 'color' => 'warning']);
}

/** The applications a folder may be shared with — the picker source `application` (a static list; the id is the catalog key's index). */
function applications_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $rows = [];
    $i = 0;
    foreach (KNOWN_APPLICATIONS as $key => $name) {
        $i++;
        if ($q === '' || stripos($name, $q) !== false || stripos($key, $q) !== false) {
            $rows[] = ['id' => $i, 'label' => $name, 'detail' => $key, 'badge' => 'application', 'color' => 'secondary'];
        }
    }
    return ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'page_size' => 25, 'pages' => 1];
}

function application_key_of(int $i): ?string
{
    $keys = array_keys(KNOWN_APPLICATIONS);
    return $keys[$i - 1] ?? null;
}

/** The document page's "who can see this" line: the owner, the library's base, the rows at its access root (or its own), in a sentence. */
function document_access_line(PDO $pdo, array $d): string
{
    $a = access_rows($pdo, 'document', (int) $d['document_id']);
    $rows = $a['own_access'] ? $a['explicit'] : $a['inherited'];
    $parts = [];
    $parts[] = 'its owner';
    $base = $a['base_line'] ?? '';
    if ($base !== '' && !$a['own_access']) {
        $parts[] = lcfirst(preg_replace('/ \(.*\)$/', '', $base) ?? $base);
    }
    $people = 0; $groups = 0; $departments = 0; $apps = 0;
    foreach ($rows as $r) {
        if ($r['level'] === 'none') { continue; }
        switch ($r['principal_kind']) { case 'member': case 'agent': case 'guest': $people++; break; case 'group': $groups++; break; case 'department': $departments++; break; case 'application': $apps++; break; case 'everyone': $parts[] = 'everyone'; break; }
    }
    $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    if ($people) { $parts[] = $plural($people, 'named person', 'named people'); }
    if ($groups) { $parts[] = $plural($groups, 'group', 'groups'); }
    if ($departments) { $parts[] = $plural($departments, 'department', 'departments'); }
    if ($apps) { $parts[] = $plural($apps, 'application', 'applications'); }
    $line = implode(' · ', $parts);
    if ($a['own_access']) { $line .= ' (this file only)'; } elseif ($a['inherited_from_name'] !== null) { $line .= ' (from ' . $a['inherited_from_name'] . ')'; }
    return ucfirst($line);
}
