<?php
/**
 * Helpers for slice 1's proofs on top of Phase 2's (tests/phase2/lib.php): the actors signed on once, a POST as a member with the
 * session's CSRF token, a JSON POST under a person's action token or an agent's run token, and the ids of the SMOKE store
 * (tests/phase2/lib.php seed_store(): the group SMOKE Board — Marco 27 owner, Priya 26 and Seamus 40 members — its root, Contracts
 * with acme-msa.pdf; Priya's my-notes.md; Marco's for-ann.pdf shared with the guest Ann 29).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';
function actor(int $m): string { static $jars = []; if (!isset($jars[$m])) { [$jars[$m], ] = sign_on($m); } return $jars[$m]; }
/** A browser's POST (the session's CSRF token added). Returns the response; $o may carry headers (HX-Request …). */
function post(int $m, string $path, array $form, array $o = []): array
{
    $j = actor($m);
    $form['csrf_token'] = page_csrf($j);
    return req('POST', $path, ['jar' => $j, 'form' => $form] + $o);
}
/** A JSON POST under the person's action token (the kernel's actions server's shape). */
function post_json(int $m, string $path, array $form): array
{
    $r = req('POST', $path, ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token($m)], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A JSON POST under an agent's run token with its relay. */
function post_agent(int $agent, int $run, string $path, array $form): array
{
    $r = req('POST', $path, ['headers' => as_agent(run_token($agent, $run)), 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function get_json(int $m, string $path): array
{
    $r = page(actor($m), $path, ['headers' => ['Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function text_of(string $html): string { return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?? ''); }
function root_of(int $member): int { return (int) one("SELECT root_folder_id FROM libraries WHERE kind = 'personal' AND owner_member_id = :m", ['m' => $member]); }
function level_of(int $member, string $kind, int $id): string { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (string) one("SELECT dc_{$kind}_level(:id)", ['id' => $id]); }
function last_event(string $action): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a ORDER BY id DESC LIMIT 1', ['a' => $action]); return $r[0] ?? null; }
