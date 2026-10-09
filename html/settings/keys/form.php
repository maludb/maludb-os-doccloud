<?php
declare(strict_types=1);
/** /settings/keys/new — mint a key (screen `settings-tokens`; the action is key_mint): the label, the limits (the settings' by default), an expiry. People only; a guest may not. */
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
require_login();
require_key_maker();
$pdo = db();
$defaults = key_defaults($pdo);
log_screen_view($pdo, 'settings-tokens');
if (wants_json()) {
    respond_screen(['defaults' => ['rate_per_minute' => $defaults[0], 'rate_per_day' => $defaults[1]], 'admin' => is_dc_admin()]);
}
render_screen('Mint a key', view('settings/key-form.php', ['defaults' => $defaults, 'admin' => is_dc_admin(), 'errors' => []]), ['activeNav' => 'tokens', 'screen' => 'settings-tokens', 'entity' => 'mcp_access_token']);
