<?php
declare(strict_types=1);
/**
 * The folder (screen `folder`, /folders/{id} and /f/{id}): crumbs, the access summary line, the actions by my level, the listing table
 * (dc_folder_listing: folders first, sort, kind filter, 100 a page, multi-select) — read at 375 px first. The upload drop zone is slice 2's
 * (?upload=1 lands here with the Upload button disabled until then); Share, Get link and Request files are slice 3's; Trash is slice 6's screen
 * (the action folder_trash is here).
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
$folder = folder_at_level($pdo, request_integer('id'), 'view');
$id = (int) $folder['id'];
if ($folder['trashed_at'] !== null) {
    refuse(404, 'Not found.');                                       // a trashed folder is reached from the trash (slice 6), never here
}
$listing = folder_listing($pdo, $id, ['sort' => request_string('sort', 'name'), 'dir' => request_string('dir', 'asc'), 'kind' => request_string('kind') ?: null, 'page' => request_integer('page') ?? 1]);
$crumbs = folder_crumbs($pdo, $id);
$library = find_library($pdo, (int) $folder['library_id']);
$accessLine = folder_access_line($pdo, $id);
$size = folder_subtree_size($pdo, $id);
$stars = my_favourite_ids($pdo);
$level = $folder['my_level'];
$may = ['add' => dc_level_at_least($level, 'upload') && has_right('documents.upload'), 'edit' => dc_level_at_least($level, 'edit'),          // edit: what is IN the folder (the select bar)
        'edit_self' => dc_level_at_least($level, 'edit') && $folder['parent_id'] !== null,                                                   // rename and move the folder itself: never a root
        'manage' => dc_level_at_least($level, 'manage'), 'share' => dc_level_at_least($level, 'manage') && has_right('documents.share'), 'star' => !is_guest(), 'export' => has_right('export.own')];
$uploadSettings = ['part_bytes' => (int) one_value($pdo, "SELECT dc_setting_int('upload_part_bytes')"), 'max_document_bytes' => (int) one_value($pdo, "SELECT dc_setting_int('max_document_bytes')")];
log_activity($pdo, 'screen.view', 'folder', $id, ['screen' => 'folder', 'folder_id' => $id, 'library_id' => (int) $folder['library_id']]);
$fv = find_folder($pdo, $id);
if (wants_json()) {
    respond_screen(['folder' => present_folder($fv + ['my_level' => $level]), 'crumbs' => present_crumbs($crumbs), 'access_line' => $accessLine, 'size' => $size,
        'library' => $library === null ? null : present_library($library), 'listing' => present_listing($listing), 'may' => $may, 'starred' => isset($stars['folder'][$id])]);
}
$notices = ['created' => ['success', 'Folder made.'], 'renamed' => ['success', 'Renamed.'], 'moved' => ['success', 'Moved.'], 'updated' => ['success', 'Saved.'],
            'trashed' => ['success', 'Sent to the trash. It can be restored for 30 days.'], 'restored' => ['success', 'Restored.'], 'starred' => ['success', 'Added to your favourites.'], 'queued' => ['success', 'The zip is being made; find it under Exports.'],
            'unstarred' => ['success', 'Removed from your favourites.'], 'moved_many' => ['success', 'Moved.']];
$activeNav = $library !== null && $library['kind'] === 'personal' && (int) ($library['owner_member_id'] ?? 0) === (int) current_member_id() ? 'files' : 'library-' . (int) $folder['library_id'];
render_screen($folder['name'], view('files/folder.php', ['folder' => $folder, 'fv' => $fv, 'library' => $library, 'crumbs' => $crumbs, 'listing' => $listing, 'accessLine' => $accessLine, 'size' => $size,
    'may' => $may, 'stars' => $stars, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'uploadWanted' => request_bool('upload'), 'uploadSettings' => $uploadSettings,
    'adminView' => dc_is_admin_view((int) $folder['library_id'])]),
    ['activeNav' => $activeNav, 'screen' => 'folder', 'entity' => 'folder', 'recordId' => $id]);
