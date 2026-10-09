<?php
declare(strict_types=1);
/** One tag (screen `tag-view`, /tags/{id}): the tag, its aliases, its documents through dc_search with the tag filter (the wall), Edit · Merge into · Archive/Restore · Delete. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
require_right('tags.apply');
$pdo = db();
$tag = tag_from_request($pdo, 'id');
$id = (int) $tag['tag_id'];
$page = request_integer('page') ?? 1;
$docs = tag_documents($pdo, $id, $page);
$manage = has_right('tags.manage');
log_activity($pdo, 'screen.view', 'tag', $id, ['screen' => 'tag-view']);
if (wants_json()) {
    respond_screen(['tag' => present_tag($tag), 'documents' => array_map('present_search_row', $docs['rows']), 'total' => $docs['total'], 'page' => $docs['page'], 'pages' => $docs['pages'], 'may' => ['manage' => $manage]]);
}
$notices = ['updated' => ['success', 'Saved.'], 'merged' => ['success', 'Merged.'], 'archived' => ['success', 'Archived.'], 'restored' => ['success', 'Restored.']];
render_screen($tag['label'], view('tags/view.php', ['tag' => $tag, 'docs' => $docs, 'manage' => $manage, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tags', 'screen' => 'tag-view', 'entity' => 'tag', 'recordId' => $id]);
