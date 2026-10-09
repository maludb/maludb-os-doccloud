<?php
declare(strict_types=1);
/**
 * GET /api/v1/folders/{id}?sort=&dir=&page=&kind=&per= — the folder, its crumbs and a page of its listing, as the key's person sees it (another's personal library: 404).
 * POST /api/v1/folders {parent, name} — a new folder (the action folder_create, 201 {folder_id}). Slice 7.
 */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_route(['GET', 'POST'], true);
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $parent = $_POST['parent'] ?? $_POST['parent_id'] ?? null;
    $form = ['parent' => (string) $parent, 'name' => (string) ($_POST['name'] ?? '')] + (isset($_POST['colour']) ? ['colour' => $_POST['colour']] : []);
    [$status, $out] = api_run_handler('folders/save.php', $form);
    if ($status !== 200) {
        api_relay($status, $out);
    }
    api_json(['folder_id' => (int) $out['record_id'], 'name' => (string) ($_POST['name'] ?? '')], 201);
}
$id = api_id();
$out = api_folder($pdo, $id, ['sort' => (string) ($_GET['sort'] ?? 'name'), 'dir' => (string) ($_GET['dir'] ?? 'asc'), 'kind' => ($_GET['kind'] ?? '') ?: null, 'page' => max(1, (int) ($_GET['page'] ?? 1)), 'per' => (int) ($_GET['per'] ?? 100)]);
log_activity($pdo, 'folder.list', 'folder', $id, ['folder_id' => $id, 'library_id' => $out['folder']['library_id'], 'after' => ['rows' => count($out['rows']), 'key_label' => api_key_label()]]);
api_json($out);
