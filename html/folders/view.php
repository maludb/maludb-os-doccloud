<?php
declare(strict_types=1);
/**
 * The folder (screen `folder`, /folders/{id} and /f/{id}): crumbs, the access summary line, the actions by my level, the listing as a table
 * or a grid of tiles (dc_folder_listing: folders first, sort, kind filter, 100 a page, multi-select; ?view=list|grid, remembered per browser —
 * docs/build-specs/grid-and-media.md) — read at 375 px first. The upload drop zone is slice 2's
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
$layout = listing_layout();
$crumbs = folder_crumbs($pdo, $id);
$library = find_library($pdo, (int) $folder['library_id']);
$accessLine = folder_access_line($pdo, $id);
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
$graphPolicy = effective_policy($pdo, $id);
$mayPolicy = dc_level_at_least((string) $folder['my_level'], 'manage') && may_set_policy($pdo, (int) $folder['library_id']);
$size = folder_subtree_size($pdo, $id);
$stars = my_favourite_ids($pdo);
$level = $folder['my_level'];
$may = ['add' => dc_level_at_least($level, 'upload') && has_right('documents.upload'), 'edit' => dc_level_at_least($level, 'edit'),          // edit: what is IN the folder (the select bar)
        'edit_self' => dc_level_at_least($level, 'edit') && $folder['parent_id'] !== null,                                                   // rename and move the folder itself: never a root
        'manage' => dc_level_at_least($level, 'manage'), 'share' => dc_level_at_least($level, 'manage') && has_right('documents.share'), 'star' => !is_guest(), 'export' => has_right('export.own')];
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
$tagDatalist = $may['edit'] && has_right('tags.apply') ? tag_datalist($pdo) : [];
$uploadSettings = ['part_bytes' => (int) one_value($pdo, "SELECT dc_setting_int('upload_part_bytes')"), 'max_document_bytes' => dc_file_ceiling($pdo)];
$strip = null;
if (!is_guest()) {
    $stripProposals = $pdo->prepare("SELECT clerk_proposal_id FROM mcp_clerk_proposals WHERE folder_id = :f AND status = 'proposed' AND (for_member_id = app_current_member_id() OR (SELECT dc_has_right('groups.manage')))");
    $stripProposals->execute(['f' => $id]);
    $stripRows = $stripProposals->fetchAll();
    $mayAsk = $may['edit'] && has_right('tags.apply') && (current_member()['member_kind'] ?? '') === 'human' && one_value($pdo, 'SELECT expert_member_id FROM dc_settings WHERE id = 1') !== null;
    $strip = ['proposals' => $stripRows, 'mayAsk' => $mayAsk, 'untagged' => $mayAsk ? (int) one_value($pdo, "SELECT count(*) FROM documents d WHERE d.folder_id = :f AND d.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = d.id)", ['f' => $id]) : 0];
}
log_activity($pdo, 'screen.view', 'folder', $id, ['screen' => 'folder', 'folder_id' => $id, 'library_id' => (int) $folder['library_id']]);
$fv = find_folder($pdo, $id);
if (wants_json()) {
    respond_screen(['folder' => present_folder($fv + ['my_level' => $level]), 'crumbs' => present_crumbs($crumbs), 'access_line' => $accessLine, 'size' => $size, 'graph_policy' => $graphPolicy, 'may_policy' => $mayPolicy,
        'library' => $library === null ? null : present_library($library), 'listing' => present_listing($listing), 'may' => $may, 'starred' => isset($stars['folder'][$id]), 'proposals_open' => $strip === null ? 0 : count($strip['proposals']),
        'layout' => $layout]);
}
$notices = ['tagged' => ['success', 'Tagged.'], 'created' => ['success', 'Folder made.'], 'renamed' => ['success', 'Renamed.'], 'moved' => ['success', 'Moved.'], 'updated' => ['success', 'Saved.'],
            'trashed' => ['success', 'Sent to the trash. It can be restored for 30 days.'], 'restored' => ['success', 'Restored.'], 'starred' => ['success', 'Added to your favourites.'], 'queued' => ['success', 'The zip is being made; find it under Exports.'],
            'unstarred' => ['success', 'Removed from your favourites.'], 'moved_many' => ['success', 'Moved.']];
$activeNav = $library !== null && $library['kind'] === 'personal' && (int) ($library['owner_member_id'] ?? 0) === (int) current_member_id() ? 'files' : 'library-' . (int) $folder['library_id'];
render_screen($folder['name'], view('files/folder.php', ['folder' => $folder, 'fv' => $fv, 'library' => $library, 'crumbs' => $crumbs, 'listing' => $listing, 'accessLine' => $accessLine, 'size' => $size, 'graphPolicy' => $graphPolicy, 'mayPolicy' => $mayPolicy,
    'may' => $may, 'stars' => $stars, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'uploadWanted' => request_bool('upload'), 'uploadSettings' => $uploadSettings, 'tagDatalist' => $tagDatalist, 'layout' => $layout,
    'adminView' => dc_is_admin_view((int) $folder['library_id'])] + ($strip === null ? [] : ['strip' => $strip])),
    ['activeNav' => $activeNav, 'screen' => 'folder', 'entity' => 'folder', 'recordId' => $id]);
