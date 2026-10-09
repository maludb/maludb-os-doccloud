<?php
declare(strict_types=1);
/** /settings/keys/ — my API keys (the keys part of screen `settings-tokens`): the key just minted or rotated (once), the table with Rotate and Revoke. People only. */
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
require_login();
require_human();
$pdo = db();
$keys = my_keys($pdo);
$defaults = key_defaults($pdo);
$once = $_SESSION['minted_key'] ?? null;
unset($_SESSION['minted_key']);
log_screen_view($pdo, 'settings-tokens');
if (wants_json()) {
    respond_screen(['keys' => array_map('present_key', $keys), 'defaults' => ['rate_per_minute' => $defaults[0], 'rate_per_day' => $defaults[1]], 'may' => ['mint' => has_right('search.use')]]);
}
$notices = ['revoked' => ['success', 'The key was revoked.']];
render_screen('API keys', view('settings/keys.php', ['keys' => $keys, 'once' => $once, 'defaults' => $defaults, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
