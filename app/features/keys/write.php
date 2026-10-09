<?php
declare(strict_types=1);

/**
 * The writes of keys (slice 7): mint, rotate (dc_key_rotate — the old key keeps key_rotation_overlap_hours), revoke. The raw key is `dck_` + 48 hex,
 * answered ONCE to the caller; only its sha256 is stored (mcp_access_tokens.token_hash). A key is never logged: its label is.
 */

/** Mint a key for $memberId. $perMinute / $perDay null = the settings' defaults (the trigger fills them); $expiresDays null = never. Returns [token_id, raw]. */
function key_mint(PDO $pdo, int $memberId, string $label, ?int $perMinute = null, ?int $perDay = null, ?int $expiresDays = null): array
{
    $raw = 'dck_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, expires_at)
                         VALUES (:m, :l, :h, 'api', :pm, :pd, CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d AS integer)) END) RETURNING id");
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 'pm' => $perMinute, 'pd' => $perDay, 'd' => $expiresDays]);
    return ['token_id' => (int) $st->fetchColumn(), 'raw' => $raw];
}

/** Rotate a live key: a new one for the same person, label and limits; the old expires after the overlap. Returns [token_id, raw]. */
function key_rotate(PDO $pdo, int $tokenId): array
{
    $raw = 'dck_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('SELECT dc_key_rotate(:id, :h)');
    $st->execute(['id' => $tokenId, 'h' => hash('sha256', $raw)]);
    return ['token_id' => (int) $st->fetchColumn(), 'raw' => $raw];
}

/** Revoke a key now (idempotent: false when it was already revoked). A key that was being rotated out stays revoked; its successor is untouched. */
function key_revoke(PDO $pdo, int $tokenId, int $by): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'api' AND revoked_at IS NULL");
    $st->execute(['id' => $tokenId]);
    return $st->rowCount() === 1;
}
