<?php
declare(strict_types=1);
/**
 * Action `key_rotate` (api_key; log key.rotate — the label and the new key's id, never a value): a new key for the same person, label and limits; the old one keeps working for
 * key_rotation_overlap_hours (24 by default) and then answers 401. The new raw key is shown ONCE. The key's owner only — another person's answers 404.
 */
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
dc_handler_begin();
require_key_maker();
$pdo = db();
$old = own_key_from_request($pdo);
if ($old['state'] === 'revoked' || $old['state'] === 'expired') {
    refuse(422, 'Only a live key is rotated; mint a new one.');
}
if ($old['state'] === 'rotating') {
    refuse(422, 'This key is already being rotated out; use its replacement.');
}
$k = dc_guard($pdo, static function () use ($pdo, $old): array {
    $pdo->beginTransaction();
    $k = key_rotate($pdo, (int) $old['token_id']);
    $pdo->commit();
    return $k;
});
$overlap = (int) one_value($pdo, "SELECT dc_setting_int('key_rotation_overlap_hours')");
files_log($pdo, 'key.rotate', 'mcp_access_token', (int) $old['token_id'], ['after' => ['label' => $old['label'], 'new_key_id' => $k['token_id'], 'overlap_hours' => $overlap]]);
emit_action_status(true, ['did' => 'Rotated the key "' . $old['label'] . '"', 'record_id' => $k['token_id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Rotated the key "' . $old['label'] . '": the old one works for ' . $overlap . ' more hours', 'record_id' => $k['token_id'], 'token' => $k['raw'], 'old_key_id' => (int) $old['token_id'],
                   'overlap_hours' => $overlap, 'location' => '/settings/keys/#key-row-' . $k['token_id'], 'refresh' => 'tokenChanged']);
}
key_show_once($k['token_id'], $k['raw'], (string) $old['label'], 'rotated');
saved_go('/settings/keys/', 'tokenChanged');
