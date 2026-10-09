<?php
declare(strict_types=1);
/**
 * The document (screen `document`, /documents/{id}; design §9): the preview per kind, name, description, tags (slice 4's chips), owner, the folder
 * crumb, size and kind, the lock state, the versions table with restore (and purge for the admin), download, the trail, comments (notes, read-only
 * until slice 8's composer), "who can see this" (slice 3) and related documents (slice 5) as placeholders. Read on a phone first.
 */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/present.php';
require_once dirname(__DIR__, 2) . '/app/doccloud/markdown.php';
require_login();
$pdo = db();
$doc = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
$id = (int) $doc['id'];
if ($doc['trashed_at'] !== null && !dc_level_at_least($doc['my_level'], 'manage')) {
    refuse(404, 'Not found.');
}
$d = find_document($pdo, $id);
if ($d === null) {
    refuse(404, 'Not found.');
}
$d['my_level'] = $doc['my_level'];
$text = document_text_of($pdo, $id);
$preview = document_preview($pdo, $d, $text);
$versions = document_versions($pdo, $id);
$crumbs = folder_crumbs($pdo, (int) $d['folder_id']);
$stars = my_favourite_ids($pdo);
$timeline = find_record_activity($pdo, 'document', $id, 20);
$st = $pdo->prepare('SELECT n.*, m.display_name AS author FROM mcp_notes n LEFT JOIN members m ON m.id = n.member_id WHERE n.record_type = :t AND n.record_id = :id ORDER BY n.created_at DESC LIMIT 50');
$st->execute(['t' => 'document', 'id' => $id]);
$notes = $st->fetchAll();
$me = (int) current_member_id();
$level = $doc['my_level'];
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
$accessLine = document_access_line($pdo, $d);
$lockedByOther = $d['locked_by'] !== null && (int) $d['locked_by'] !== $me && $d['lock_expires_at'] !== null && strtotime((string) $d['lock_expires_at']) > time();
$may = ['edit' => dc_level_at_least($level, 'edit') && has_right('documents.edit') && !$lockedByOther, 'manage' => dc_level_at_least($level, 'manage'),
        'restore' => dc_level_at_least($level, 'edit') && has_right('versions.restore') && !$lockedByOther, 'purge' => has_right('retention.manage') || has_right('trash.purge'),
        'lock' => dc_level_at_least($level, 'edit') && has_right('documents.edit') && $d['locked_by'] === null, 'unlock' => $d['locked_by'] !== null && (int) $d['locked_by'] === $me,
        'break' => $lockedByOther && dc_level_at_least($level, 'manage'), 'copy' => has_right('documents.upload') && !is_guest(), 'star' => !is_guest(), 'export' => has_right('export.own'),
        'tag' => dc_level_at_least($level, 'edit') && has_right('tags.apply') && !$lockedByOther,
        'reextract' => dc_level_at_least($level, 'manage') && in_array((string) ($d['extracted_status'] ?? ''), ['failed', 'no_text', 'unsupported', 'ready'], true), 'share' => dc_level_at_least($level, 'manage') && has_right('documents.share')];
log_activity($pdo, 'document.open', 'document', $id, ['screen' => 'document', 'document_id' => $id, 'folder_id' => (int) $d['folder_id'], 'library_id' => (int) $d['library_id']]);
$pdo->prepare('UPDATE documents SET last_opened_at = now() WHERE id = :id')->execute(['id' => $id]);
$documentTags = document_tags($pdo, $id);
$datalist = $may['tag'] ? tag_datalist($pdo) : [];
if (wants_json()) {
    respond_screen(['document' => present_document($d), 'tags' => array_map('present_document_tag', $documentTags), 'preview' => present_preview($preview), 'versions' => array_map('present_version', $versions), 'crumbs' => present_crumbs($crumbs), 'may' => $may,
        'extraction' => extraction_sentence($d, $text), 'starred' => isset($stars['document'][$id]), 'access_line' => $accessLine, 'notes' => array_map(static fn (array $n): array => ['note_id' => (int) $n['note_id'], 'author' => $n['author'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])], $notes),
        'timeline' => array_map('present_activity_row', $timeline)]);
}
$notices = ['tagged' => ['success', 'Tagged.'], 'untagged' => ['success', 'The tag was removed.'], 'proposed' => ['success', 'Proposed to the curators.'], 'uploaded' => ['success', 'Uploaded.'], 'version' => ['success', 'A new version was added.'], 'renamed' => ['success', 'Renamed.'], 'described' => ['success', 'Saved.'], 'moved' => ['success', 'Moved.'],
            'copied' => ['success', 'Copied — this is the copy.'], 'restored' => ['success', 'Restored as a new version.'], 'purged' => ['success', 'The version was purged.'], 'locked' => ['success', 'Locked for you.'],
            'unlocked' => ['success', 'Unlocked.'], 'lock_broken' => ['success', 'The lock was broken; its holder was told.'], 'reextract' => ['success', 'The text will be read again within the minute.'],
            'starred' => ['success', 'Added to your favourites.'], 'unstarred' => ['success', 'Removed from your favourites.'], 'export' => ['success', 'The zip is being made; find it under Exports.'], 'trashed' => ['success', 'Sent to the trash.']];
render_screen($d['name'], view('files/document.php', ['d' => $d, 'preview' => $preview, 'versions' => $versions, 'crumbs' => $crumbs, 'may' => $may, 'stars' => $stars, 'tz' => member_timezone(), 'text' => $text,
    'extraction' => extraction_sentence($d, $text), 'notes' => $notes, 'timeline' => $timeline, 'accessLine' => $accessLine, 'documentTags' => $documentTags, 'datalist' => $datalist, 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'adminView' => dc_is_admin_view((int) $d['library_id']), 'lockedByOther' => $lockedByOther]),
    ['activeNav' => 'files', 'screen' => 'document', 'entity' => 'document', 'recordId' => $id]);
