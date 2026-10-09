<?php
declare(strict_types=1);
/**
 * The proposals (screen `proposals`, /curate/proposals?status=&kind=&mine=; also /proposals): what the Filing Clerk leaves for people to decide — cards with the kind, the thing (a link), the
 * suggestion (a tags proposal's tags as boxes to accept together or one by one) and Accept · Dismiss. Mine to decide; a curator (groups.manage) sees every open one with "for X". Slice 8.
 */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$f = ['status' => request_string('status', 'proposed'), 'kind' => request_string('kind'), 'mine' => request_bool('mine')];
$status = isset(PROPOSAL_STATUS_WORDS[$f['status']]) ? $f['status'] : 'proposed';
$proposals = my_proposals($pdo, $f);
$counts = [];
foreach ($proposals as $p) {
    $counts[$p['kind']] = ($counts[$p['kind']] ?? 0) + 1;
}
$me = (int) current_member_id();
log_activity($pdo, 'screen.view', 'clerk_proposal', null, ['screen' => 'proposals']);
if (wants_json()) {
    respond_screen(['status' => $status, 'kind' => $f['kind'] ?: null, 'mine' => $f['mine'], 'proposals' => array_map('present_clerk_proposal', $proposals), 'counts' => $counts, 'curator' => has_right('groups.manage')]);
}
$notices = ['accepted' => ['success', 'Accepted.'], 'dismissed' => ['success', 'Dismissed.'], 'proposed' => ['success', 'Proposed.'], 'duplicate' => ['info', 'That was already proposed.'], 'noted' => ['success', 'Told.']];
render_screen('Proposals', view('proposals/index.php', ['proposals' => $proposals, 'status' => $status, 'kind' => $f['kind'], 'mine' => $f['mine'], 'counts' => $counts, 'me' => $me, 'curator' => has_right('groups.manage'),
    'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'proposals', 'screen' => 'proposals', 'entity' => 'clerk_proposal']);
