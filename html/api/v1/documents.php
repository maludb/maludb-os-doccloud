<?php
declare(strict_types=1);
/**
 * The documents of the token API (slice 7; the vhost rewrites /documents/{id}[/download|/text|/tags] here with ?id= and ?do=):
 *   GET    /documents/{id}                  the document, its versions and tags
 *   GET    /documents/{id}/download[?version=n]   the bytes (attachment, nosniff, ranges)
 *   GET    /documents/{id}/text             the extracted text with its pages (≤ 5 MB)
 *   PATCH  /documents/{id} {name?, description?, folder?}   rename / describe (document_rename, document_describe) and move (document_move; the target needs `upload`)
 *   PUT    /documents/{id}/tags {tags: [slugs]}   set the tags (document_tags apply the missing, remove the rest — tag_apply / tag_remove)
 *   DELETE /documents/{id}                  to the trash (document_trash), 204
 * Every write is the slice's own action handler under the key's person: its rights, its level, its lock, its log row (source api, the key's token_id).
 */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
require_once dirname(__DIR__, 3) . '/app/features/tags/queries.php';
$do = (string) ($_GET['do'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowed = match ($do) { 'download', 'text' => ['GET'], 'tags' => ['PUT'], default => ['GET', 'PATCH', 'DELETE'] };
api_route($allowed, true);
$pdo = db();
$id = api_id();
if ($method === 'GET') {
    if ($do === 'download') {
        $v = filter_var($_GET['version'] ?? null, FILTER_VALIDATE_INT);
        api_document_download($pdo, $id, $v === false || $v === null ? null : $v);
    }
    if ($do === 'text') {
        $out = api_document_text($pdo, $id);
        log_activity($pdo, 'document.text', 'document', $id, ['document_id' => $id, 'after' => ['chars' => $out['char_count'], 'key_label' => api_key_label()]]);
        api_json($out);
    }
    $out = api_document($pdo, $id);
    log_activity($pdo, 'document.read', 'document', $id, ['document_id' => $id, 'folder_id' => $out['document']['folder_id'], 'library_id' => $out['document']['library_id'], 'after' => ['key_label' => api_key_label()]]);
    api_json($out);
}
if ($method === 'DELETE') {
    document_at_level($pdo, $id, 'view');                                  // not seen at all: 404 before the handler's own words
    [$status, $out] = api_run_handler('documents/trash.php', ['document' => (string) $id]);
    if ($status !== 200) {
        api_relay($status, $out);
    }
    $GLOBALS['__json_sent'] = true;                 // JSON mode's shutdown answer is not wanted: a 204 has no body
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(204);
    exit;
}
if ($method === 'PATCH') {
    document_at_level($pdo, $id, 'view');
    $given = array_intersect_key($_POST, array_flip(['name', 'description', 'folder']));
    if ($given === []) {
        api_error('invalid', 'Send name, description or folder.', 422);
    }
    if (isset($given['name']) || isset($given['description'])) {
        [$status, $out] = api_run_handler('documents/save.php', ['document' => (string) $id] + array_intersect_key($given, array_flip(['name', 'description'])));
        if ($status !== 200) {
            api_relay($status, $out);
        }
    }
    if (isset($given['folder'])) {
        [$status, $out] = api_run_handler('documents/move.php', ['document' => (string) $id, 'target_folder' => (string) $given['folder']]);
        if ($status !== 200) {
            api_relay($status, $out);
        }
    }
    api_json(api_document($pdo, $id) + ['did' => 'Updated the document']);
}
// PUT /documents/{id}/tags
$doc = document_at_level($pdo, $id, 'view');
$want = $_POST['tags'] ?? null;
if (!is_array($want)) {
    api_error('invalid', 'Send tags: a list of tag slugs (or labels).', 422);
}
$slug = static fn (string $w): string => (string) (tag_by_word($pdo, $w)['slug'] ?? one_value($pdo, 'SELECT dc_slugify(:t)', ['t' => $w]));     // an alias or a label resolves to its tag
$want = array_values(array_unique(array_filter(array_map(static fn ($w): string => trim((string) $w), $want), static fn (string $w): bool => $w !== '')));
$wantSlugs = array_map($slug, $want);
$have = array_map(static fn (array $t): string => (string) $t['slug'], document_tags($pdo, $id));
$add = [];
foreach ($want as $i => $w) {
    if (!in_array($wantSlugs[$i], $have, true)) {
        $add[] = $w;
    }
}
$drop = array_values(array_diff($have, $wantSlugs));
if ($add !== []) {
    [$status, $out] = api_run_handler('tags/apply.php', ['document' => (string) $id, 'tags' => $add]);
    if ($status !== 200) {
        api_relay($status, $out);
    }
}
if ($drop !== []) {
    [$status, $out] = api_run_handler('tags/remove.php', ['document' => (string) $id, 'tags' => $drop]);
    if ($status !== 200) {
        api_relay($status, $out);
    }
}
api_json(['document_id' => $id, 'tags' => array_map(static fn (array $t): string => (string) $t['slug'], document_tags($pdo, $id)), 'added' => $add === [] ? [] : array_map($slug, $add), 'removed' => $drop]);
