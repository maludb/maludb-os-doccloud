<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md "Proof"). Run them through tests/phase2/run.sh, which builds the
 * scratch database, starts the application (:8501), a fake kernel (:8502) and a fake MaluDB + MaluMail (:8506) and puts the scratch
 * environment in the process environment. Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, admin), 26 Priya (user), 27 Marco (curator, user), 28 Bea (no roles sent
 * yet, capability write = a Member), 29 Ann (external, guest), 30 Dana and 31 Lee (Engineering), 40 Seamus (an agent, admitted by access[]),
 * 41 the Watcher (an agent, not admitted), 42 the Clerk (an agent).
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
const BASE = 'http://127.0.0.1:8701';
const APP = 'doccloud';
const LAUNCHER = 'https://app.example.invalid/launcher?app=doccloud';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }
/** dc_has_right() as the database answers it for a member. */
function right_of(int $member, string $right): bool { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (bool) one('SELECT dc_has_right(:r)', ['r' => $right]); }

/** One request. $o: jar, headers[], form[], json[], raw. Returns code, headers (string), body, and the Location. */
function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'dcjar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]);
}
/** Sign a member on with a fresh jar; returns [jar, response]. */
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
/** A person's action token and an agent's run token with its relay, as the kernel mints them. */
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }

/** The fake kernel's state file: read, change, write. */
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/**
 * The proofs' SMOKE store (idempotent): the group "SMOKE Board" (Marco owner, Priya member, Seamus the agent member) with its library and a
 * folder "Contracts" holding a document; a document in Priya's personal library; one document of Marco's shared with the guest Ann by a row
 * of its own. Returns ['group' => id, 'board_root' => folder id, 'contracts' => folder id, 'board_doc' => id, 'priya_doc' => id, 'guest_doc' => id].
 */
function seed_store(): array
{
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '27', false)");
    $g = one("SELECT id FROM groups WHERE key = 'p2-board'");
    if ($g === false) {
        $p->exec("INSERT INTO groups (key, name, kind, created_by) VALUES ('p2-board', 'SMOKE Board', 'custom', 27)");
        $g = one("SELECT id FROM groups WHERE key = 'p2-board'");
        $p->exec("INSERT INTO group_members (group_id, member_id, role, added_by) VALUES ($g, 26, 'member', 27), ($g, 40, 'member', 27) ON CONFLICT DO NOTHING");
    }
    $root = (int) one('SELECT root_folder_id FROM libraries WHERE group_id = :g', ['g' => $g]);
    $contracts = one('SELECT id FROM folders WHERE parent_id = :r AND name = :n', ['r' => $root, 'n' => 'Contracts']);
    if ($contracts === false) {
        $p->exec("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT id FROM libraries WHERE group_id = $g), $root, 'Contracts', 27, 27)");
        $contracts = one('SELECT id FROM folders WHERE parent_id = :r AND name = :n', ['r' => $root, 'n' => 'Contracts']);
    }
    $doc = static function (int $folder, string $name, int $by, int $bytes = 1200, string $mime = 'application/pdf') use ($p): int {
        $id = one('SELECT id FROM documents WHERE folder_id = :f AND lower(name) = lower(:n) AND trashed_at IS NULL', ['f' => $folder, 'n' => $name]);
        if ($id !== false) { return (int) $id; }
        $sha = hash('sha256', 'p2:' . $folder . ':' . $name);
        $b = q('SELECT blob_id FROM dc_blob_claim(:s, :n)', ['s' => $sha, 'n' => $bytes])[0]['blob_id'];
        $r = q('SELECT document_id FROM dc_version_add(NULL, :f, :name, :b, :name, :m, :n, :s, :by)', ['f' => $folder, 'name' => $name, 'b' => $b, 'm' => $mime, 'n' => $bytes, 's' => $sha, 'by' => $by]);
        return (int) $r[0]['document_id'];
    };
    $boardDoc = $doc((int) $contracts, 'acme-msa.pdf', 27);
    $priyaRoot = (int) one("SELECT root_folder_id FROM libraries WHERE kind = 'personal' AND owner_member_id = 26");
    $priyaDoc = $doc($priyaRoot, 'my-notes.md', 26, 300, 'text/markdown');
    $marcoRoot = (int) one("SELECT root_folder_id FROM libraries WHERE kind = 'personal' AND owner_member_id = 27");
    $guestDoc = $doc($marcoRoot, 'for-ann.pdf', 27, 800);
    $p->exec("INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by) VALUES ($guestDoc, 'guest', 29, 'view', 27) ON CONFLICT DO NOTHING");
    return ['group' => (int) $g, 'board_root' => $root, 'contracts' => (int) $contracts, 'board_doc' => $boardDoc, 'priya_doc' => $priyaDoc, 'guest_doc' => $guestDoc];
}
