<?php
declare(strict_types=1);
/** Action `link_revoke` (link): the link no longer opens; own, manage on the thing, or links.all. Pauses for an agent (deletion). Log link.revoke. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$l = link_from_request($pdo, 'link');
$old = $l['row'];
if (!$l['own'] && !has_right('links.all') && !dc_level_at_least((string) $l['thing']['my_level'], 'manage')) {
    refuse(403, 'Only its maker, a manager of the thing or the admin revokes a link.');
}
if (!dc_guard($pdo, static fn (): bool => link_revoke($pdo, (int) $old['id'], $me))) {
    refuse(422, 'The link was already revoked.');
}
files_log($pdo, $old['kind'] === 'request' ? 'request.close' : 'link.revoke', 'link', (int) $old['id'], ['folder_id' => $l['folder_id'], 'document_id' => $l['document_id'], 'library_id' => $l['library_id'], 'after' => ['label' => $old['label'], 'kind' => $old['kind']]]);
dc_done('Revoked the link ' . $old['label'], (int) $old['id'], dc_land(return_path(links_url($l['kind'], $l['thing_id'])), 'revoked'), 'linksChanged');
