<?php
/**
 * Slice 7's helpers on top of slice 6's: a key minted by a member through the action (key_mint, under the person's action token), an API call with a Bearer key (JSON in and out, a raw
 * body, a multipart upload), the K7 door called as the records server calls it (the bridge envelope signed with ACTIONS_RELAY_KEY), and the application's share rows.
 */
require dirname(__DIR__) . '/slice6/lib.php';
/** Mint a key as member $m; returns the answer's JSON (`token` once, `record_id`). $form: rate_per_minute, rate_per_day, expires_in_days. */
function mint_key(int $m, string $label, array $form = []): array
{
    $r = post_json($m, '/settings/tokens/key-mint.php', ['label' => $label] + $form);
    return ($r['json'] ?? []) + ['_code' => $r['code']];
}
/** One API call with a Bearer key. $o: json (body), raw, headers[], file (path) + field + fields (multipart), method. Returns code, headers, body, json. */
function api(string $method, string $path, ?string $key, array $o = []): array
{
    $h = $o['headers'] ?? [];
    if ($key !== null) { $h[] = 'Authorization: Bearer ' . $key; }
    $args = ['headers' => $h];
    if (isset($o['json'])) { $args['json'] = $o['json']; }
    if (isset($o['raw'])) { $args['raw'] = $o['raw']; }
    if (isset($o['file'])) {
        $fields = ($o['fields'] ?? []) + [$o['field'] ?? 'file' => new CURLFile($o['file'], mime_content_type($o['file']) ?: 'application/octet-stream', $o['filename'] ?? basename($o['file']))];
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h]);
        $r = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $out = ['code' => $code, 'headers' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
    } else {
        $out = req($method, $path, $args);
    }
    $out['json'] = json_decode($out['body'], true) ?: [];
    return $out;
}
function header_of(array $r, string $name): ?string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\s*$/mi', $r['headers'], $m) ? $m[1] : null; }
/** The door lives on the INTERNAL port: under php -S there is one server; under the real Apache (DC_APP=apache) it is the loopback vhost on 8707 and the public one answers 404. */
function door_base(): string { return getenv('DC_APP') === 'apache' ? 'http://127.0.0.1:8707' : BASE; }
/** The K7 door as the records server calls it: the bridge envelope, signed. $o: skew (seconds), key (override the signing key), sign (false = no header). */
function door(string $op, ?array $caller, array $args = [], array $o = []): array
{
    $body = json_encode(['op' => $op, 'caller' => $caller ?? new stdClass(), 'door' => 'share', 'request_id' => 'r-' . bin2hex(random_bytes(3)), 'args' => (object) $args]);
    $ts = (string) (time() + ($o['skew'] ?? 0));
    $sig = hash_hmac('sha256', 'dc-bridge:' . $ts . '.' . hash('sha256', $body), $o['key'] ?? need('ACTIONS_RELAY_KEY'));
    $h = ['Content-Type: application/json'];
    if (($o['sign'] ?? true) !== false) { $h[] = 'X-DC-Bridge: ' . $ts . '.' . $sig; }
    $r = req('POST', door_base() . '/api/v1/shares/read.php', ['raw' => $body, 'headers' => $h]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function app_caller(string $app, ?int $agent = 70): array { return ['kind' => 'application', 'application' => $app] + ($agent === null ? [] : ['agent_member_id' => $agent]); }
/** Share a folder with a sibling application (an access row, as the Share screen writes it). */
function share_folder_with(int $folder, string $app, string $level = 'view'): void
{
    pdo()->prepare("INSERT INTO folder_access (folder_id, principal_kind, principal_ref, level, granted_by) VALUES (:f, 'application', :a, :l, 27) ON CONFLICT DO NOTHING")->execute(['f' => $folder, 'a' => $app, 'l' => $level]);
}
function share_document_with(int $doc, string $app, string $level = 'view'): void
{
    pdo()->prepare("INSERT INTO document_access (document_id, principal_kind, principal_ref, level, granted_by) VALUES (:d, 'application', :a, :l, 27) ON CONFLICT DO NOTHING")->execute(['d' => $doc, 'a' => $app, 'l' => $level]);
}
function key_row(int $id): array { return q('SELECT * FROM mcp_access_tokens WHERE id = :id', ['id' => $id])[0]; }
function age_key(int $id, string $col, string $interval): void { pdo()->exec("UPDATE mcp_access_tokens SET $col = now() - interval '$interval' WHERE id = $id"); }
/** Clear a key's minute bucket (so a proof reuses a key without waiting for the next minute). */
function reset_usage(int $keyId): void { pdo()->exec("DELETE FROM key_usage WHERE token_id = $keyId"); }
