<?php
declare(strict_types=1);

/**
 * The prelude of slice 3's controllers (sharing, links, requests): the files prelude (the walls' readers), then the access and links
 * modules. thing_from_request() reads `folder` OR `document` (one of them) at the level a handler needs and answers ['kind', 'row'];
 * access_from_request() reads an access row (`access`) across the two tables with the thing it belongs to.
 */
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/present.php';
require_once dirname(__DIR__) . '/links/queries.php';
require_once dirname(__DIR__) . '/links/write.php';

const ACCESS_LEVELS_FOLDER = ['view', 'upload', 'edit', 'manage'];
const ACCESS_LEVELS_DOCUMENT = ['view', 'edit', 'manage'];

/** The folder or the document a request names (one of them), at the level needed. */
function thing_from_request(PDO $pdo, string $need = 'manage', ?string $kindParam = null): array
{
    $kind = $kindParam ?? ((request_integer('document') ?? 0) > 0 ? 'document' : 'folder');
    if ($kind === 'document') {
        $row = document_at_level($pdo, request_integer('document') ?? request_integer('id'), $need);
    } else {
        $row = folder_at_level($pdo, request_integer('folder') ?? request_integer('id'), $need);
    }
    return ['kind' => $kind, 'row' => $row, 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'library_id' => (int) $row['library_id'],
            'folder_id' => $kind === 'folder' ? (int) $row['id'] : (int) $row['folder_id'], 'document_id' => $kind === 'document' ? (int) $row['id'] : null];
}

/** An access row by its id (`access`): the row and the thing it is on, which the caller must manage. */
function access_from_request(PDO $pdo): array
{
    $id = request_integer('access') ?? request_integer('id');
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which share.');
    }
    $kind = req_val('kind') ?? ((request_integer('document') ?? 0) > 0 ? 'document' : ((request_integer('folder') ?? 0) > 0 ? 'folder' : null));
    $rows = [];
    foreach ($kind === null ? ['folder', 'document'] : [$kind] as $k) {
        $table = $k === 'folder' ? 'folder_access' : 'document_access';
        $st = $pdo->prepare("SELECT *, '$k' AS kind FROM $table WHERE id = :id");
        $st->execute(['id' => $id]);
        if (($r = $st->fetch()) !== false) {
            $rows[] = $r;
        }
    }
    if ($rows === []) {
        dc_not_found('Not found.');
    }
    if (count($rows) > 1 && $kind === null) {
        refuse(422, 'Say whether the share is on a folder or a document (kind).');
    }
    $r = $rows[0];
    $thingId = (int) ($r['kind'] === 'folder' ? $r['folder_id'] : $r['document_id']);
    $thing = $r['kind'] === 'folder' ? folder_at_level($pdo, $thingId, 'manage') : document_at_level($pdo, $thingId, 'manage');
    return ['row' => $r, 'kind' => $r['kind'], 'thing' => $thing, 'thing_id' => $thingId, 'name' => (string) $thing['name'], 'library_id' => (int) $thing['library_id'],
            'folder_id' => $r['kind'] === 'folder' ? $thingId : (int) $thing['folder_id'], 'document_id' => $r['kind'] === 'document' ? $thingId : null];
}

/** The level a share form posts, checked against the thing's kind. */
function level_from_request(string $kind, array &$errors, array $allowed = null): ?string
{
    $level = strtolower((string) (req_val('level') ?? ''));
    $allowed ??= $kind === 'folder' ? ACCESS_LEVELS_FOLDER : ACCESS_LEVELS_DOCUMENT;
    if (!in_array($level, $allowed, true)) {
        $errors['level'] = 'The level is one of ' . implode(', ', $allowed) . '.';
        return null;
    }
    return $level;
}

/** The share's log row, then the landing on the share screen. */
function share_done(PDO $pdo, array $thing, string $event, int $accessId, array $after, string $did, string $notice = 'shared'): never
{
    files_log($pdo, $event, 'access', $accessId, ['folder_id' => $thing['folder_id'], 'document_id' => $thing['document_id'], 'library_id' => $thing['library_id'], 'after' => $after + ['on' => $thing['kind'], 'on_id' => $thing['id']]]);
    dc_done($did, $accessId, dc_land(return_path(share_url($thing['kind'], $thing['id'])), $notice), 'accessChanged');
}

function share_url(string $kind, int $id): string
{
    return ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/share';
}

function links_url(string $kind, int $id): string
{
    return ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/links';
}

/** A share by one principal (share_member / share_group / share_department / share_agent / share_guest / share_application): the one handler body. */
function share_handler(string $principalKind): never
{
    dc_handler_begin();
    require_right('documents.share');
    $pdo = db();
    $me = (int) current_member_id();
    $thing = thing_from_request($pdo, 'manage', $principalKind === 'application' ? 'folder' : null);
    $errors = [];
    $principalId = null;
    $ref = null;
    $name = '';
    $event = 'access.share';
    switch ($principalKind) {
        case 'member':
        case 'agent':
        case 'guest':
            $param = ['member' => 'member', 'agent' => 'agent', 'guest' => 'guest'][$principalKind];
            $principalId = request_integer($param) ?? request_integer('member');
            $st = $pdo->prepare('SELECT display_name, member_kind, is_guest FROM mcp_members WHERE member_id = :id');
            $st->execute(['id' => $principalId ?? 0]);
            $m = $st->fetch();
            if ($m === false) {
                $errors[$param] = 'Choose a person from the list.';
                break;
            }
            $name = (string) $m['display_name'];
            if ($principalKind === 'agent' && $m['member_kind'] !== 'agent') {
                $errors['agent'] = $name . ' is not an agent.';
            } elseif ($principalKind === 'member' && $m['member_kind'] === 'agent') {
                $principalKind = 'agent';
            }
            // a share to a guest is share_guest whatever form posted it (divergence 1): the paused action
            if ($m['is_guest'] && $principalKind !== 'agent') {
                $principalKind = 'guest';
                $event = 'access.share_guest';
                if (!str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'share-guest.php')) {
                    // a person's form: the handler routes to the guest write; an AGENT's call must have named share_guest (the kernel's hook judges by action)
                    if (is_action_authed() && current_agent_run_id() !== null) {
                        refuse(422, $name . ' is a guest: share with share_guest, which waits for a person\'s approval.');
                    }
                }
            } elseif (!$m['is_guest'] && $principalKind === 'guest') {
                $errors['guest'] = $name . ' is not a guest; share with share_member.';
            }
            if ($principalId === $me) {
                $errors[$param] = 'You already hold this.';
            }
            break;
        case 'group':
            $principalId = request_integer('group');
            $g = $principalId === null ? null : find_group($pdo, $principalId);
            if ($g === null || $g['archived_at'] !== null) {
                $errors['group'] = 'Choose a live group from the list.';
            } else {
                $name = 'the group ' . $g['name'];
            }
            break;
        case 'department':
            $principalId = request_integer('department');
            $d = $principalId === null ? null : one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', ['id' => $principalId]);
            if ($d === null) {
                $errors['department'] = 'Choose a department from the list.';
            } else {
                $name = 'the department ' . $d;
            }
            break;
        case 'application':
            $ref = strtolower(trim((string) (req_val('application') ?? '')));
            if (ctype_digit($ref)) {
                $ref = application_key_of((int) $ref) ?? $ref;      // the picker posts the list's index
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $ref)) {
                $errors['application'] = 'An application is its catalog key (letters, digits and underscores).';
            } elseif ($ref === app_key()) {
                $errors['application'] = 'That is this application.';
            } else {
                $name = 'the application ' . (KNOWN_APPLICATIONS[$ref] ?? $ref);
            }
            $event = 'access.share_application';
            break;
    }
    $allowed = match ($principalKind) { 'guest' => ['view', 'edit'], 'application' => ['view'], default => null };
    $level = $principalKind === 'application' && !req_has('level') ? 'view' : level_from_request($thing['kind'], $errors, $allowed);
    if ($errors !== []) {
        dc_refuse_fields($errors);
    }
    [$accessId, $created] = dc_guard($pdo, static function () use ($pdo, $thing, $principalKind, $principalId, $ref, $level, $me, $name): array {
        $pdo->beginTransaction();
        $r = share_add($pdo, $thing['kind'], $thing['id'], $principalKind, $principalId, $ref, $level, $me);
        if ($principalKind !== 'application') {
            share_notify($pdo, $thing['kind'], $thing['id'], $thing['name'], $principalKind, $principalId, $level, $me);
        }
        $pdo->commit();
        return $r;
    });
    $did = ($created ? 'Shared ' : 'Set ') . $thing['name'] . ' with ' . $name . ' at ' . $level;
    share_done($pdo, $thing, $event, $accessId, ['principal_kind' => $principalKind, 'principal_id' => $principalId, 'principal_ref' => $ref, 'level' => $level, 'created' => $created], $did);
}

/** The link a request names (`link` or `request`), which the caller made or manages the thing of (or holds links.all). */
function link_from_request(PDO $pdo, string $param = 'link'): array
{
    $id = request_integer($param) ?? request_integer('link') ?? request_integer('request') ?? request_integer('id');
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which ' . $param . '.');
    }
    $st = $pdo->prepare('SELECT * FROM share_links WHERE id = :id');
    $st->execute(['id' => $id]);
    $l = $st->fetch();
    if ($l === false) {
        dc_not_found('Not found.');
    }
    $kind = $l['document_id'] !== null ? 'document' : 'folder';
    $thingId = (int) ($l['document_id'] ?? $l['folder_id']);
    $me = (int) current_member_id();
    $own = (int) $l['made_by'] === $me;
    if (!$own && !has_right('links.all')) {
        $thing = $kind === 'folder' ? folder_at_level($pdo, $thingId, 'manage') : document_at_level($pdo, $thingId, 'manage');
    } else {
        $st = $pdo->prepare($kind === 'folder' ? 'SELECT * FROM folders WHERE id = :id' : 'SELECT * FROM documents WHERE id = :id');
        $st->execute(['id' => $thingId]);
        $thing = $st->fetch();
        if ($thing === false) {
            dc_not_found('Not found.');
        }
        $thing['my_level'] = $kind === 'folder' ? dc_folder_level($pdo, $thingId) : dc_document_level($pdo, $thingId);
        if (!$own && $thing['my_level'] === 'none' && !has_right('links.all')) {
            dc_not_found('Not found.');
        }
    }
    return ['row' => $l, 'kind' => $kind, 'thing' => $thing, 'thing_id' => $thingId, 'name' => (string) $thing['name'], 'library_id' => (int) $thing['library_id'],
            'folder_id' => $kind === 'folder' ? $thingId : (int) $thing['folder_id'], 'document_id' => $kind === 'document' ? $thingId : null, 'own' => $own];
}

/** The link's days field: NULL = the default, 0 = never (only when the admin allows), else 1–3650. */
function link_days_from_request(PDO $pdo, array &$errors, ?int $keep = null): ?int
{
    if (!req_has('expires_in_days')) {
        return $keep;
    }
    $v = trim((string) req_val('expires_in_days'));
    if ($v === '' || strtolower($v) === 'default') {
        return null;
    }
    if (strtolower($v) === 'never') {
        $v = '0';
    }
    if (!ctype_digit($v) || (int) $v > 3650) {
        $errors['expires_in_days'] = 'Expires in a number of days (1 to 3650), or never.';
        return $keep;
    }
    if ((int) $v === 0 && !(bool) one_value($pdo, "SELECT dc_setting_bool('link_no_expiry_allowed')")) {
        $errors['expires_in_days'] = 'A link that never expires is not allowed here; the admin may change that.';
        return $keep;
    }
    return (int) $v;
}

/** The raw token shown once: kept in the session for one render of the links screen. */
function link_show_once(int $linkId, string $raw): void
{
    $_SESSION['link_once'][$linkId] = $raw;
}

function link_take_once(int $linkId): ?string
{
    $raw = $_SESSION['link_once'][$linkId] ?? null;
    unset($_SESSION['link_once'][$linkId]);
    return $raw;
}

function link_public_url(array $link, string $raw): string
{
    return app_url(($link['kind'] === 'request' ? '/r/' : '/s/') . $raw);
}
