<?php
declare(strict_types=1);
/**
 * POST /internal/bridge.php — the records MCP server's door to what only PHP may touch (docs/doccloud-mcp-tool-surface.md "The bridge"): the document graph (app/doccloud/graph.php holds the engine's token), the signed
 * download URL of a share, the search log, the four K7 shares. INTERNAL PORT ONLY: the public vhost answers 404 for /internal/ (deploy/apache-doccloud.conf) and this file refuses a caller that is not loopback anyway.
 * Header X-DC-Bridge: {unix ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "dc-bridge:" + ts + "." + sha256_hex(body))} — within 30 seconds; a key no agent holds. Body {op, caller, door, run_id, request_id, is_eval, args}.
 * The answer is the tool's document; a refusal is {error:{code,message}} (401 bad signature, 403 consumer_unknown | not_shared | unknown_member | forbidden, 404 not_found | unknown_op, 422 invalid).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bridge/ops.php';
$GLOBALS['__public_door'] = 'mcp';
header_remove('Set-Cookie');
function bridge_reply(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bridge_reply(['error' => ['code' => 'method_not_allowed', 'message' => 'POST only.']], 405);
}
$body = (string) file_get_contents('php://input');
if (!dc_bridge_verify($body, (string) ($_SERVER['HTTP_X_DC_BRIDGE'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
    bridge_reply(['error' => ['code' => 'unauthorized', 'message' => 'unauthorized']], 401);
}
register_shutdown_function(static function (): void {            // no session outlives the call
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
});
$in = json_decode($body, true);
if (!is_array($in) || !is_string($in['op'] ?? null)) {
    bridge_reply(['error' => ['code' => 'invalid', 'message' => 'The request names an op and a caller.']], 422);
}
$_SESSION = ['csrf_token' => 'bridge'];                            // never a logged-in browser session: the caller is named in the envelope, verified by the signature
$pdo = null;
try {
    $pdo = db();
    $pdo->exec("SELECT set_config('app.member_id', '', false)");
    bridge_reply(bridge_dispatch($pdo, $in['op'], $in), 200);
} catch (ShareRefused $e) {
    bridge_reply(['error' => ['code' => $e->code_, 'message' => $e->getMessage()]], $e->status);
} catch (Throwable $e) {
    error_log('bridge ' . $in['op'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    bridge_reply(['error' => ['code' => 'server_error', 'message' => 'The operation could not be completed.']], 500);
}
