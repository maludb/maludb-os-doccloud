<?php
declare(strict_types=1);
/** The share screen's body, required by html/folders/share.php ($folder) and html/documents/share.php ($document). */
$kind = isset($document) ? 'document' : 'folder';
$thing = $kind === 'document' ? $document : $folder;
$level = $thing['my_level'];
$manage = dc_level_at_least($level, 'manage') && has_right('documents.share');
$me = (int) current_member_id();
$explainFor = request_integer('member');
$explainRows = null;
if ($explainFor !== null && $manage) {
    $explainRows = access_explain($pdo, $kind, $id, $explainFor);
}
$mine = access_explain($pdo, $kind, $id, $me);
$access = $manage ? access_rows($pdo, $kind, $id) : null;
$summary = $manage ? link_summary($pdo, $kind, $id) : null;
$departments = $manage ? find_live_departments($pdo) : [];
$name = (string) $thing['name'];
$back = $kind === 'folder' ? '/f/' . $id : '/documents/' . $id;
log_activity($pdo, 'screen.view', $kind, $id, ['screen' => $kind . '-share', $kind . '_id' => $id, 'library_id' => (int) $thing['library_id']]);
if (wants_json()) {
    respond_screen(['kind' => $kind, 'id' => $id, 'name' => $name, 'my_level' => $level, 'manage' => $manage, 'mine' => $mine, 'access' => $access === null ? null : present_access($access),
        'explain_for' => $explainFor, 'explain' => $explainRows, 'links' => $summary, 'levels' => $kind === 'folder' ? ACCESS_LEVELS_FOLDER : ACCESS_LEVELS_DOCUMENT, 'applications' => KNOWN_APPLICATIONS]);
}
$notices = ['shared' => ['success', 'Shared.'], 'level' => ['success', 'The level was changed.'], 'removed' => ['success', 'The share was removed.'], 'restricted' => ['success', 'Restricted: this folder now keeps only the rows below.']];
$explainName = $explainFor === null ? null : (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $explainFor]);
render_screen('Who can see ' . $name, view('access/share.php', ['kind' => $kind, 'id' => $id, 'name' => $name, 'thing' => $thing, 'manage' => $manage, 'mine' => $mine, 'access' => $access, 'summary' => $summary,
    'departments' => $departments, 'explainFor' => $explainFor, 'explainName' => $explainName, 'explainRows' => $explainRows, 'tz' => member_timezone(), 'back' => $back,
    'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'adminView' => dc_is_admin_view((int) $thing['library_id'])]),
    ['activeNav' => 'files', 'screen' => $kind . '-share', 'entity' => $kind, 'recordId' => $id]);
