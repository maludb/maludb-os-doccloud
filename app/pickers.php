<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [DocCloud rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reuses the feature's own list query (find_*() with ILIKE and paging), never a second one; `label` names the chosen record.
 * Slice 1 adds folder (folders the caller may add to; param `library`), library, group, member (people and agents, never a guest; param
 * `group` hides its live members) and department. Slice 2 adds document; slice 3 guest and application; slice 4 adds tag.
 * A short enumeration (a kind, a level, a colour) stays a <select>.
 */
$files = static function (): void { require_once __DIR__ . '/features/files/handler.php'; };
$access = static function (): void { require_once __DIR__ . '/features/access/handler.php'; };
$tags = static function (): void { require_once __DIR__ . '/features/tags/handler.php'; };
return [
    'folder' => [
        'title' => 'Folders', 'noun' => 'folder', 'roles' => ['library.personal', 'documents.guest'], 'params' => ['library'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return folders_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($files): ?string { $files(); $f = find_folder($pdo, $id); return $f === null ? null : (folder_path_text($pdo, $id) ?: $f['name']); },
    ],
    'library' => [
        'title' => 'Libraries', 'noun' => 'library', 'roles' => ['library.personal', 'documents.guest'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return libraries_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($files): ?string { $files(); return find_library($pdo, $id)['name'] ?? null; },
    ],
    'group' => [
        'title' => 'Groups', 'noun' => 'group', 'roles' => ['library.personal'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return groups_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($files): ?string { $files(); return find_group($pdo, $id)['name'] ?? null; },
    ],
    'member' => [
        'title' => 'People and agents', 'noun' => 'person', 'roles' => ['library.personal'], 'params' => ['group'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return members_for_pick_search($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id): ?string { $st = $pdo->prepare('SELECT display_name FROM mcp_members WHERE member_id = :id'); $st->execute(['id' => $id]); $v = $st->fetchColumn(); return $v === false ? null : (string) $v; },
    ],
    'document' => [
        'title' => 'Documents', 'noun' => 'document', 'roles' => ['library.personal', 'documents.guest'], 'params' => ['folder', 'library'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return documents_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($files): ?string { $files(); return find_document($pdo, $id)['name'] ?? null; },
    ],
    'guest' => [
        'title' => 'Guests', 'noun' => 'guest', 'roles' => ['documents.share'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($access): array { $access(); return guests_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id): ?string { $st = $pdo->prepare('SELECT display_name FROM mcp_members WHERE member_id = :id AND is_guest'); $st->execute(['id' => $id]); $v = $st->fetchColumn(); return $v === false ? null : (string) $v; },
    ],
    'application' => [
        'title' => 'Applications', 'noun' => 'application', 'roles' => ['documents.share'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($access): array { $access(); return applications_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($access): ?string { $access(); $k = application_key_of($id); return $k === null ? null : KNOWN_APPLICATIONS[$k]; },
    ],
    'department' => [
        'title' => 'Departments', 'noun' => 'department', 'roles' => ['groups.manage'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($files): array { $files(); return departments_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id): ?string { $st = $pdo->prepare('SELECT name FROM mcp_departments WHERE department_id = :id'); $st->execute(['id' => $id]); $v = $st->fetchColumn(); return $v === false ? null : (string) $v; },
    ],
    'tag' => [
        'title' => 'Tags', 'noun' => 'tag', 'roles' => ['tags.apply'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($tags): array { $tags(); return tags_for_pick($pdo, $q, $params, $page); },
        'label' => static function (PDO $pdo, int $id) use ($tags): ?string { $tags(); return find_tag($pdo, $id)['label'] ?? null; },
    ],
];
