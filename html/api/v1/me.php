<?php
declare(strict_types=1);
/** GET /api/v1/me — who the key acts as, its label and limits, today's count (slice 7). */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
$member = api_route(['GET']);
$pdo = db();
log_activity($pdo, 'key.whoami', 'mcp_access_token', api_key()['token_id'] ?? null, ['after' => ['key_label' => api_key_label()]]);
api_json(api_me($pdo, $member));
