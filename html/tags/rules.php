<?php
declare(strict_types=1);
/** The auto-tag rules (screen `tag-rules`, /tags/rules?library=): every folder (I may see) whose auto-tags are set; a form per folder I manage posts folder_auto_tags_set (slice 1's action). */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
require_right('tags.apply');
$pdo = db();
$lib = request_integer('library');
$rules = folders_with_rules($pdo, $lib);
$folderId = request_integer('folder');
$folder = null;
$nearest = null;
if ($folderId !== null) {
    $folder = folder_at_level($pdo, $folderId, 'view');
    $nearest = nearest_rule($pdo, $folderId);
}
log_activity($pdo, 'screen.view', 'folder', $folderId, ['screen' => 'tag-rules']);
if (wants_json()) {
    respond_screen(['rules' => array_map('present_rule', $rules), 'folder' => $folder === null ? null : ['folder_id' => (int) $folder['id'], 'name' => $folder['name'], 'auto_tags' => pg_text_array((string) $folder['auto_tags']), 'my_level' => $folder['my_level']],
        'nearest' => $nearest === null ? null : ['folder_id' => (int) $nearest['folder_id'], 'name' => $nearest['name'], 'auto_tags' => $nearest['auto_tags']]]);
}
$notices = ['updated' => ['success', 'The folder\'s auto-tags were saved.']];
render_screen('Auto-tag rules', view('tags/rules.php', ['rules' => $rules, 'folder' => $folder, 'nearest' => $nearest, 'datalist' => tag_datalist($pdo), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tags', 'screen' => 'tag-rules']);
