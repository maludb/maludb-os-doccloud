<?php
declare(strict_types=1);

/**
 * The K7 shares' gate (slice 7): WHO is asking and whether the call came from the records server. The kernel calls the provider's tool with the consumer's catalog key in
 * `X-OS-Consumer` and its expert's member id in `X-OS-Consumer-Agent` (K26; anything the consumer put in the arguments is stripped). The records server (Phase 4) relays both to PHP over
 * loopback in a signed envelope — the bridge of docs/doccloud-mcp-tool-surface.md:
 *   POST /api/v1/shares/read.php (internal port only)    X-DC-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "dc-bridge:" + ts + "." + sha256_hex(body))}
 *   {"op": "share_folders|share_documents|share_text|share_link", "caller": {"kind": "application", "application": "<catalog key>", "agent_member_id": 70}, "door": "share", "args": {...}}
 * No consumer → `consumer_unknown`, nothing shared.
 */

/** A refusal the door answers in words: the code, the sentence, the HTTP status. */
final class ShareRefused extends DomainException
{
    public function __construct(public readonly string $code_, string $message, public readonly int $status = 403)
    {
        parent::__construct($message);
    }
}

/** The tool each op is, for the log and the answer's schema. */
const SHARE_OPS = ['share_folders' => 'folders_index', 'share_documents' => 'documents_index', 'share_text' => 'document_text', 'share_link' => 'document_link'];

/** True when the envelope's signature is good: loopback only, within 30 seconds, the HMAC over the body with ACTIONS_RELAY_KEY (a key no agent holds). */
function dc_bridge_verify(string $body, string $header, string $remoteAddr): bool
{
    $key = (string) env('ACTIONS_RELAY_KEY', '');
    if (strlen($key) < 32 || !in_array($remoteAddr, ['127.0.0.1', '::1'], true) || !preg_match('/^([0-9]{9,12})\.([a-f0-9]{64})$/', $header, $m)) {
        return false;
    }
    return abs(time() - (int) $m[1]) <= 30 && hash_equals(hash_hmac('sha256', 'dc-bridge:' . $m[1] . '.' . hash('sha256', $body), $key), $m[2]);
}

/** The consumer (a catalog key) and its asking agent, from a caller object; ShareRefused('consumer_unknown') when there is none. */
function share_gate(array $caller): array
{
    $app = $caller['application'] ?? null;
    if (($caller['kind'] ?? '') !== 'application' || !is_string($app) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $app)) {
        throw new ShareRefused('consumer_unknown', 'The kernel named no requesting application, so nothing is shared.', 403);
    }
    $agent = $caller['agent_member_id'] ?? null;
    return ['consumer' => $app, 'consumer_agent' => is_int($agent) && $agent > 0 ? $agent : (is_string($agent) && ctype_digit($agent) && (int) $agent > 0 ? (int) $agent : null)];
}

/** share.read — one row per share call, logged under the consumer (source application, no actor): the tool, the consumer, its agent, the rows, the outcome, the milliseconds. */
function share_read_log(PDO $pdo, string $tool, string $consumer, ?int $agent, int $rows, string $outcome, int $ms, array $extra = []): void
{
    log_activity($pdo, 'share.read', 'share', null, ['actor_member_id' => null, 'source' => 'application',
        'after' => ['direction' => 'in', 'tool' => $tool, 'consumer' => $consumer, 'consumer_agent' => $agent, 'rows' => $rows, 'outcome' => $outcome, 'ms' => $ms] + $extra]);
}

/** share.refused — a call nothing was shared for (no consumer, a folder not shared, a bad signature is not logged: nobody to name). */
function share_refused_log(PDO $pdo, string $tool, ?string $consumer, ?int $agent, string $reason): void
{
    log_activity($pdo, 'share.refused', 'share', null, ['actor_member_id' => null, 'source' => 'application',
        'after' => ['direction' => 'in', 'tool' => $tool, 'consumer' => $consumer, 'consumer_agent' => $agent, 'reason' => $reason]]);
}
