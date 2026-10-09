<?php
declare(strict_types=1);
/**
 * Tags (screen `tag-list`, /tags?q=&kind=&archived=): the vocabulary as chips with counts — managed first, then free by usage; a chip opens the tag;
 * New tag (curators); the open proposals for curators (promote, merge, apply — accept or dismiss). Read on a phone first.
 */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
require_right('tags.apply');
$pdo = db();
$filters = ['q' => request_string('q'), 'kind' => request_string('kind') ?: null, 'archived' => request_bool('archived')];
$tags = find_tags($pdo, $filters);
$manage = has_right('tags.manage');
$proposals = $manage ? open_proposals($pdo) : [];
log_activity($pdo, 'screen.view', 'tag', null, ['screen' => 'tag-list']);
if (wants_json()) {
    respond_screen(['tags' => array_map('present_tag', $tags), 'filters' => $filters, 'proposals' => array_map('present_proposal', $proposals), 'may' => ['manage' => $manage]]);
}
$notices = ['created' => ['success', 'Tag made.'], 'updated' => ['success', 'Saved.'], 'merged' => ['success', 'Merged.'], 'archived' => ['success', 'Archived: it stays on its documents, hidden from the pickers.'],
            'restored' => ['success', 'Restored.'], 'deleted' => ['success', 'Deleted.'], 'accepted' => ['success', 'Accepted.'], 'dismissed' => ['success', 'Dismissed.']];
render_screen('Tags', view('tags/index.php', ['tags' => $tags, 'filters' => $filters, 'proposals' => $proposals, 'manage' => $manage, 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tags', 'screen' => 'tag-list']);
