<?php
declare(strict_types=1);
/** Action `request_close` (request): the request no longer receives (= revoke); own or manage on the folder. Log request.close. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$l = link_from_request($pdo, 'request');
$old = $l['row'];
if ($old['kind'] !== 'request') {
    refuse(422, 'That is a link, not a request: revoke it.');
}
if (!$l['own'] && !dc_level_at_least((string) $l['thing']['my_level'], 'manage') && !has_right('links.all')) {
    refuse(403, 'Only its maker or a manager of the folder closes a request.');
}
if (!dc_guard($pdo, static fn (): bool => request_close($pdo, (int) $old['id'], $me))) {
    refuse(422, 'The request was already closed.');
}
files_log($pdo, 'request.close', 'link', (int) $old['id'], ['folder_id' => $l['folder_id'], 'library_id' => $l['library_id'], 'after' => ['label' => $old['label']]]);
dc_done('Closed the request ' . $old['label'], (int) $old['id'], dc_land(return_path('/requests'), 'closed'), 'linksChanged');
