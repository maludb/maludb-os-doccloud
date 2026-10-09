<?php
declare(strict_types=1);
/** Action `link_rotate` (link): a new token with the same label, note and switches; the old link dies now; shown once. Pauses for an agent. Log link.rotate. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
require_right('links.make');
$pdo = db();
$me = (int) current_member_id();
$l = link_from_request($pdo, 'link');
$old = $l['row'];
if ($old['revoked_at'] !== null || $old['rotated_at'] !== null) {
    refuse(422, 'Only a live link is rotated; make a new one.');
}
$r = dc_guard($pdo, static fn (): array => link_rotate($pdo, $old, $me));
files_log($pdo, 'link.rotate', 'link', $r['link_id'], ['folder_id' => $l['folder_id'], 'document_id' => $l['document_id'], 'library_id' => $l['library_id'], 'before' => ['link_id' => (int) $old['id']], 'after' => ['label' => $old['label'], 'kind' => $old['kind']]]);
link_show_once($r['link_id'], $r['raw']);
$url = link_public_url(['kind' => $old['kind']], $r['raw']);
dc_done('Rotated the link ' . $old['label'] . ': a new URL', $r['link_id'], dc_land(return_path(links_url($l['kind'], $l['thing_id'])) . (str_contains(return_path(''), '?') ? '&' : '?') . 'made=' . $r['link_id'], $old['kind'] === 'request' ? 'request_made' : 'rotated', 'link-url-once'), 'linksChanged', ['url' => $url]);
