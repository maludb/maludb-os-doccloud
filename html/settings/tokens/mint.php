<?php
declare(strict_types=1);
/** Action `token_mint` (log `token.mint`: the label and scope, never the token): a personal token, shown once on the next screen. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_post();
require_login();
verify_csrf();
require_human();
$pdo = db();
$label = request_string('label');
if ($label === '' || mb_strlen($label) > 80) {
    refuse(422, 'A label is required (up to 80 characters) — what will use this token.');
}
$scope = request_string('scope', 'mcp') ?: 'mcp';
if ($scope !== 'mcp') {
    refuse(422, 'A person\'s own token reads with the scope mcp; an API key (acting as you) is minted on the Keys screen (slice 7).');
}
$minted = mint_token($pdo, (int) current_member_id(), $label, $scope);
log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'scope' => $scope]]);
emit_action_status(true, ['did' => 'Minted the token "' . $label . '" — shown once on the Tokens screen', 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['id' => $minted['id'], 'record_id' => $minted['id'], 'token' => $minted['raw'], 'location' => '/settings/tokens/#token-row-' . $minted['id']]);   // the value, once
}
$_SESSION['minted_token'] = $minted;
saved_go('/settings/tokens/', 'tokenChanged');
