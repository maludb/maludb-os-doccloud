<?php
declare(strict_types=1);
/** The proposals (screen `tag-proposals`, /tags/proposals?status=): the free tags proposed for the vocabulary, the merges, the applications — curators accept or dismiss. */
require_once dirname(__DIR__, 3) . '/app/features/tags/handler.php';
require_login();
require_right('tags.manage');
$pdo = db();
$status = in_array(request_string('status', 'proposed'), ['proposed', 'accepted', 'dismissed'], true) ? request_string('status', 'proposed') : 'proposed';
$proposals = open_proposals($pdo, $status);
log_activity($pdo, 'screen.view', 'tag_proposal', null, ['screen' => 'tag-proposals']);
if (wants_json()) {
    respond_screen(['status' => $status, 'proposals' => array_map('present_proposal', $proposals)]);
}
$notices = ['accepted' => ['success', 'Accepted.'], 'dismissed' => ['success', 'Dismissed.']];
render_screen('Tag proposals', view('tags/proposals.php', ['proposals' => $proposals, 'status' => $status, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tags', 'screen' => 'tag-proposals']);
