<?php
declare(strict_types=1);
/**
 * Action `trash_purge` (older_than_days?, library?, due_only?, confirm=yes; log trash.purge): empties the trash — what is past the window by default (due_only yes), or
 * everything trashed (due_only no), or what is older than older_than_days (1 to 3650), in one library when named. trash.purge (the admin); pauses for an agent (deletion).
 */
require_once dirname(__DIR__, 2) . '/app/features/trash/handler.php';
dc_handler_begin();
require_right('trash.purge');
$pdo = db();
if (!dc_yes('confirm')) {
    dc_refuse_fields(['confirm' => 'Say yes to empty the trash: it cannot be undone.']);
}
$errors = [];
$days = dc_int('older_than_days', null, 1, 3650, 'Older than days', $errors, true);
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$dueOnly = dc_yes('due_only', true);
$libraryId = null;
if (req_has('library') && req_val('library') !== '') {
    $libraryId = (int) library_from_request($pdo, 'library')['id'];
}
[$docs, $folders] = dc_guard($pdo, static function () use ($pdo, $dueOnly, $days, $libraryId): array {
    $pdo->beginTransaction();
    $r = trash_purge($pdo, $dueOnly, $days, $libraryId);
    log_activity($pdo, 'trash.purge', 'settings', null, ['library_id' => $libraryId, 'after' => ['documents' => $r[0], 'folders' => $r[1], 'due_only' => $dueOnly, 'older_than_days' => $days]]);
    $pdo->commit();
    return $r;
});
dc_done($docs + $folders === 0 ? 'Nothing was due' : 'Purged ' . $docs . ' document' . ($docs === 1 ? '' : 's') . ' and ' . $folders . ' folder' . ($folders === 1 ? '' : 's'), null, dc_land(return_path('/trash/'), 'emptied'), 'documentChanged', ['documents' => $docs, 'folders' => $folders]);
