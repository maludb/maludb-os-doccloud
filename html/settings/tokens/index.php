<?php
declare(strict_types=1);
/** /settings/tokens/ — a person's own access tokens for the two MCP servers, and the URLs to connect their AI to (screen `tokens`). People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/queries.php';
require_login();
require_human();
$pdo = db();
$rows = find_my_tokens($pdo, (int) current_member_id());
$keys = my_keys($pdo);
$defaults = key_defaults($pdo);
$once = $_SESSION['minted_key'] ?? null;
unset($_SESSION['minted_key']);
log_screen_view($pdo, 'tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $rows), 'keys' => array_map('present_key', $keys), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity')]);
}
$raw = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
render_screen('Tokens', view('settings/tokens.php', ['rows' => $rows, 'raw' => $raw, 'tz' => member_timezone(), 'keys' => $keys, 'defaults' => $defaults, 'once' => $once]),
    ['activeNav' => 'tokens', 'screen' => 'tokens', 'entity' => 'mcp_access_token']);
