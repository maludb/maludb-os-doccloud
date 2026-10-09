<?php
declare(strict_types=1);
/**
 * Action `key_revoke` (api_key; log key.revoke — the label and whose; confirm): a key stops working now. One's own, or any key for a holder of links.all (the admin); another
 * person's key answers 404 to everyone else. People only; never pauses (revoking only ever closes a door).
 */
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
dc_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id');
$k = $id === null ? null : find_key($pdo, $id);
if ($k === null || ((int) $k['member_id'] !== $me && !has_right('links.all'))) {
    dc_not_found('Not found.');
}
if ($k['state'] === 'revoked') {
    refuse(422, 'That key is already revoked.');
}
dc_guard($pdo, static function () use ($pdo, $k, $me): void {
    $pdo->beginTransaction();
    key_revoke($pdo, (int) $k['token_id'], $me);
    $pdo->commit();
});
files_log($pdo, 'key.revoke', 'mcp_access_token', (int) $k['token_id'], ['before' => ['label' => $k['label'], 'member_id' => (int) $k['member_id'], 'state' => $k['state']]]);
$own = (int) $k['member_id'] === $me;
dc_done('Revoked the key "' . $k['label'] . '"' . ($own ? '' : ' of ' . $k['member_name']), (int) $k['token_id'], dc_land(return_path($own ? '/settings/keys/' : '/admin/keys'), 'revoked', 'key-row-' . (int) $k['token_id']), 'tokenChanged');
