<?php
declare(strict_types=1);
/**
 * Ownership (screen `curate-ownership`, GET /curate/ownership?member=&library=): who has left and still owns documents (the inactive members from dc_storage_by_owner), any person by the member picker,
 * and — for the one chosen — the form that moves their documents (all, or one library's) to a new owner after a confirm that names the count. ownership.transfer (curators). Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/curate/handler.php';
require_login();
require_right('ownership.transfer');
$pdo = db();
$leavers = inactive_owners($pdo);
$memberId = request_integer('member');
$libraryId = request_integer('library');
$owner = null;
$holdings = null;
$library = null;
if ($memberId !== null) {
    $owner = owner_candidate($pdo, $memberId);
    if ($owner === null) {
        dc_not_found('Not found.');
    }
    if ($libraryId !== null) {
        $st = $pdo->prepare('SELECT id AS library_id, name FROM libraries WHERE id = :id');          // a curator names any library to limit a transfer to it, though they may not open it
        $st->execute(['id' => $libraryId]);
        $library = $st->fetch() ?: null;
    }
    $holdings = owner_holdings($pdo, $memberId, $library === null ? null : (int) $library['library_id']);
}
log_activity($pdo, 'screen.view', 'member', $memberId, ['screen' => 'curate-ownership']);
if (wants_json()) {
    respond_screen(['leavers' => array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'], 'display_name' => $r['display_name'], 'status' => $r['status'], 'documents' => (int) $r['documents'], 'live_bytes' => (int) $r['live_bytes']], $leavers),
        'owner' => $owner === null ? null : ['member_id' => (int) $owner['id'], 'display_name' => $owner['display_name'], 'status' => $owner['status'], 'documents' => $holdings['documents'], 'bytes' => $holdings['bytes']],
        'library_id' => $library === null ? null : (int) $library['library_id']]);
}
$notices = ['transferred' => ['success', 'The documents were handed over; the new owner was told.']];
render_screen('Ownership', view('curate/ownership.php', ['leavers' => $leavers, 'owner' => $owner, 'holdings' => $holdings, 'library' => $library, 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'curate', 'screen' => 'curate-ownership', 'entity' => 'member', 'recordId' => $memberId]);
