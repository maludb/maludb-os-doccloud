<?php
declare(strict_types=1);
/** The links screen's body (`folder-links` / `document-links`; required by html/folders/links.php and html/documents/links.php): the live links (and a folder's requests), the dead ones below, the one shown once. */
$level = $thing['my_level'];
if (!dc_level_at_least($level, 'manage') && !has_right('links.all')) {
    refuse(403, 'Only a manager of this ' . $kind . ' sees its links.');
}
$may = ['make' => dc_level_at_least($level, 'manage') && has_right('links.make'), 'request' => $kind === 'folder' && dc_level_at_least($level, 'manage') && has_right('requests.make')];
$links = find_links($pdo, $kind, $id, false, 'view');
$requests = $kind === 'folder' ? find_requests($pdo, $id, false) : [];
$made = request_integer('made');
$once = $made === null ? null : link_take_once($made);
$madeLink = $made === null ? null : find_link($pdo, $made);
$opensFor = request_integer('opens');
$opens = $opensFor === null ? [] : link_opens_of($pdo, $opensFor);
log_activity($pdo, 'screen.view', $kind, $id, ['screen' => $kind . '-links', $kind . '_id' => $id, 'library_id' => (int) $thing['library_id']]);
if (wants_json()) {
    respond_screen(['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'may' => $may, 'links' => array_map('present_link', $links), 'requests' => array_map('present_link', $requests),
        'made' => $madeLink === null ? null : present_link($madeLink) + ['url' => $once === null ? null : link_public_url($madeLink, $once)],
        'opens' => array_map(static fn (array $o): array => ['link_open_id' => (int) $o['link_open_id'], 'what' => $o['what'], 'opened_at' => json_ts($o['opened_at']), 'document_id' => $o['document_id'] === null ? null : (int) $o['document_id'], 'document_name' => $o['document_name'], 'bytes' => $o['byte_size'] === null ? null : (int) $o['byte_size'], 'address_key' => $o['address_key']], $opens)]);
}
$notices = ['made' => ['success', 'The link was made. Copy it now: it is shown once.'], 'rotated' => ['success', 'A new link; the old one no longer opens.'], 'revoked' => ['success', 'Revoked: the link no longer opens.'],
            'updated' => ['success', 'Saved.'], 'request_made' => ['success', 'The request was made. Copy its link now: it is shown once.'], 'closed' => ['success', 'The request is closed.']];
render_screen('Links of ' . $thing['name'], view('links/index.php', ['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'may' => $may, 'links' => $links, 'requests' => $requests, 'made' => $madeLink, 'once' => $once,
    'opensFor' => $opensFor, 'opens' => $opens, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'files', 'screen' => $kind . '-links', 'entity' => $kind, 'recordId' => $id]);
