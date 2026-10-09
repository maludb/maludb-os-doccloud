<?php
declare(strict_types=1);

/**
 * Reads of the API keys (slice 7, docs/build-specs/api-keys-k7.md): a key is an mcp_access_tokens row of scope 'api' that acts as its person
 * (db/003, db/005). The reads go through mcp_tokens (db/015: one's own, the admin's every one) — never the table, and never the hash.
 * State chips: live `success`, rotating (a successor exists, the old one expires soon) `warning`, expired `dark`, revoked `dark`.
 */
const KEY_STATE_BADGE = ['live' => 'success', 'rotating' => 'warning', 'expired' => 'dark', 'revoked' => 'dark'];

/** The state of a key row (mcp_tokens + `successor`). */
function key_state(array $k): string
{
    if ($k['revoked_at'] !== null) {
        return 'revoked';
    }
    if ($k['expires_at'] !== null && strtotime((string) $k['expires_at']) <= time()) {
        return 'expired';
    }
    if ($k['expires_at'] !== null && !empty($k['successor'])) {
        return 'rotating';
    }
    return 'live';
}

const KEY_SELECT = "SELECT t.token_id, t.member_id, t.label, t.last_used_at, t.expires_at, t.revoked_at, t.created_at, t.rate_per_minute, t.rate_per_day, t.rotated_from, t.calls_today,
                           (SELECT min(n.token_id) FROM mcp_tokens n WHERE n.rotated_from = t.token_id AND n.scope = 'api') AS successor,
                           COALESCE((SELECT sum(u.calls) FROM key_usage u WHERE u.token_id = t.token_id AND u.bucket_kind = 'minute' AND u.bucket_start = date_trunc('minute', now())), 0) AS calls_this_minute,
                           COALESCE((SELECT sum(u.refused) FROM key_usage u WHERE u.token_id = t.token_id AND u.bucket_kind = 'day' AND u.bucket_start = date_trunc('day', now())), 0) AS refused_today,
                           m.display_name AS member_name
                      FROM mcp_tokens t JOIN members m ON m.id = t.member_id WHERE t.scope = 'api'";

/** The caller's own keys, newest first. */
function my_keys(PDO $pdo): array
{
    $st = $pdo->prepare(KEY_SELECT . ' AND t.member_id = app_current_member_id() ORDER BY t.created_at DESC, t.token_id DESC');
    $st->execute();
    return array_map(static fn (array $k): array => $k + ['state' => key_state($k)], $st->fetchAll());
}

/** One key the caller may see (their own, or the admin's), or null. */
function find_key(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(KEY_SELECT . ' AND t.token_id = :id');
    $st->execute(['id' => $id]);
    $k = $st->fetch();
    return $k === false ? null : $k + ['state' => key_state($k)];
}

/** Every key of every person (the admin's screen; mcp_tokens answers all of them only to the admin). Filters: member, status (live, rotating, expired, revoked). */
function all_keys(PDO $pdo, array $filters = []): array
{
    $sql = KEY_SELECT;
    $args = [];
    if (!empty($filters['member'])) {
        $sql .= ' AND t.member_id = :member';
        $args['member'] = (int) $filters['member'];
    }
    $st = $pdo->prepare($sql . ' ORDER BY t.created_at DESC, t.token_id DESC LIMIT 500');
    $st->execute($args);
    $rows = array_map(static fn (array $k): array => $k + ['state' => key_state($k)], $st->fetchAll());
    $status = (string) ($filters['status'] ?? '');
    return in_array($status, array_keys(KEY_STATE_BADGE), true) ? array_values(array_filter($rows, static fn (array $k): bool => $k['state'] === $status)) : $rows;
}

/** The JSON shape of a key: never the hash, never the raw value. */
function present_key(array $k): array
{
    return ['key_id' => (int) $k['token_id'], 'member_id' => (int) $k['member_id'], 'member_name' => $k['member_name'] ?? null, 'label' => $k['label'], 'state' => $k['state'],
            'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'], 'calls_today' => (int) $k['calls_today'], 'refused_today' => (int) ($k['refused_today'] ?? 0),
            'last_used_at' => json_ts($k['last_used_at']), 'expires_at' => json_ts($k['expires_at']), 'revoked_at' => json_ts($k['revoked_at']), 'created_at' => json_ts($k['created_at']),
            'rotated_from' => $k['rotated_from'] === null ? null : (int) $k['rotated_from'], 'successor' => empty($k['successor']) ? null : (int) $k['successor']];
}

/** The settings' defaults for a new key: [per minute, per day]. */
function key_defaults(PDO $pdo): array
{
    return [(int) one_value($pdo, "SELECT dc_setting_int('key_rate_per_minute')"), (int) one_value($pdo, "SELECT dc_setting_int('key_rate_per_day')")];
}
