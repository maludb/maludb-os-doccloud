<?php
declare(strict_types=1);
/**
 * POST /api/v1/shares/read.php — the records MCP server's door to the four K7 shares (docs/build-specs/api-keys-k7.md; the shape of Spaces' html/api/v1/shares/read.php). INTERNAL PORT ONLY (the public
 * vhost answers 404 under /api/ but the health check, the documents API and the files named in its allow-list). The server has already verified the kernel's token; it signs the request with
 * ACTIONS_RELAY_KEY — the bridge's envelope (app/features/shares/gate.php): header X-DC-Bridge {ts}.{hmac}, body {op, caller:{kind:"application", application, agent_member_id}, door:"share", args}.
 * The answer is the `os.doccloud-*` document itself; a refusal is {error:{code,message}} (403 consumer_unknown | not_shared, 404 unknown_op, 422 invalid, 401 bad signature).
 */
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/features/shares/queries.php';
$GLOBALS['__public_door'] = 'application';
header_remove('Set-Cookie');
function share_reply(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    share_reply(['error' => ['code' => 'method_not_allowed', 'message' => 'POST only.']], 405);
}
$body = (string) file_get_contents('php://input');
if (!dc_bridge_verify($body, (string) ($_SERVER['HTTP_X_DC_BRIDGE'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
    share_reply(['error' => ['code' => 'unauthorized', 'message' => 'unauthorized']], 401);
}
$in = json_decode($body, true);
if (!is_array($in) || !is_string($in['op'] ?? null)) {
    share_reply(['error' => ['code' => 'invalid', 'message' => 'The request names an op and a caller.']], 422);
}
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '', false)");         // no member identity crosses: the application principal is judged by dc_application_* alone
try {
    $doc = shares_dispatch($pdo, $in['op'], is_array($in['caller'] ?? null) ? $in['caller'] : [], is_array($in['args'] ?? null) ? $in['args'] : []);
} catch (ShareRefused $e) {
    share_reply(['error' => ['code' => $e->code_, 'message' => $e->getMessage()]], $e->status);
} catch (Throwable $e) {
    error_log('shares/read: ' . $e->getMessage());
    share_reply(['error' => ['code' => 'server_error', 'message' => 'The document could not be built.']], 500);
}
share_reply($doc, 200);
