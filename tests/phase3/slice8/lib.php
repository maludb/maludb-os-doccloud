<?php
/**
 * Slice 8's helpers on top of slice 7's (and so slice 6's … slice 1's): the agents named (the expert Seamus 40, the Filing Clerk 42 — the fixture's two admitted agents), the fake kernel's chat endpoint
 * made to answer as a proof wants (a canned reply, an approval, a refusal, a 500) and its log of every turn it was asked, dispatches and proposals as the database holds them, a document made the
 * seed's way with a chosen sha256 (duplicates), the dc_clerk_report() as a given member, and the clock moved by SQL. The fixture: 1 the admin, 26 Priya, 27 Marco (curator, owner of the Board), 28 Bea,
 * 29 Ann (guest), 30 Dana, 31 Lee, 40 Seamus (agent), 41 the Watcher (agent, not admitted), 42 the Clerk (agent).
 */
require dirname(__DIR__) . '/slice7/lib.php';
const EXPERT = 40;
const CLERK = 42;
/** Name the expert and the Clerk as the admin does (the action, so the proof uses what the screen uses). */
function name_agents(int $expert = EXPERT, int $clerk = CLERK): array
{
    return post_json(1, '/admin/agents/save.php', ['expert' => $expert, 'clerk' => $clerk]);
}
/** What the fake kernel's chat endpoint answers next: a reply (finished), an approval, or a refusal status; null clears. */
function chat_answers(?array $chat, ?int $status = null, ?string $message = null): void
{
    kernel_state(function (array $s) use ($chat, $status, $message) {
        unset($s['chat'], $s['chat_status'], $s['chat_message']);
        if ($chat !== null) { $s['chat'] = $chat; }
        if ($status !== null) { $s['chat_status'] = $status; }
        if ($message !== null) { $s['chat_message'] = $message; }
        return $s;
    });
}
function chat_reply(string $reply, int $run = 11): array { return ['run_id' => $run, 'status' => 'succeeded', 'finished' => true, 'reply' => $reply, 'actions' => []]; }
/** Every turn the fake kernel was asked since the log was last cleared: [{agent, acting, utterance}]. */
function chat_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES) ?: []))) : [];
}
function chat_log_clear(): void { @unlink(need('FAKE_KERNEL_STATE') . '.chat'); }
function dispatches(string $where = 'true', array $args = []): array { return q("SELECT * FROM agent_dispatches WHERE $where ORDER BY id", $args); }
function dispatch_by_kind(string $kind, ?int $folder = null): ?array
{
    $r = q('SELECT * FROM agent_dispatches WHERE kind = :k AND folder_id IS NOT DISTINCT FROM :f ORDER BY id DESC LIMIT 1', ['k' => $kind, 'f' => $folder]);
    return $r[0] ?? null;
}
function proposals(string $where = 'true', array $args = []): array { return q("SELECT * FROM clerk_proposals WHERE $where ORDER BY id", $args); }
function payload_of(array $p): array { return json_decode((string) $p['payload'], true) ?: []; }
/** dc_clerk_report() as a member, keyed by kind: [kind => [count, ids[], detail]]. */
function report_as(int $member): array
{
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '$member', false)");
    $out = [];
    foreach (q('SELECT kind, count, ids, detail FROM dc_clerk_report(7)') as $r) {
        $out[$r['kind']] = ['count' => (int) $r['count'], 'ids' => array_values(array_filter(explode(',', trim((string) $r['ids'], '{}')))), 'detail' => json_decode((string) $r['detail'], true)];
    }
    $p->exec("SELECT set_config('app.member_id', '', false)");
    return $out;
}
/** A document with a chosen sha256 (so copies are copies), made as member $by in $folder; returns its id. */
function doc_with_sha(int $folder, string $name, int $by, string $sha, int $bytes = 900): int
{
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '$by', false)");
    $id = one('SELECT id FROM documents WHERE folder_id = :f AND lower(name) = lower(:n) AND trashed_at IS NULL', ['f' => $folder, 'n' => $name]);
    if ($id === false) {
        $b = q('SELECT blob_id FROM dc_blob_claim(:s, :n)', ['s' => $sha, 'n' => $bytes])[0]['blob_id'];
        $id = q('SELECT document_id FROM dc_version_add(NULL, :f, :name, :b, :name, :m, :n, :s, :by)', ['f' => $folder, 'name' => $name, 'b' => $b, 'm' => 'application/pdf', 'n' => $bytes, 's' => $sha, 'by' => $by])[0]['document_id'];
    }
    return (int) $id;
}
/** A document in the graph with the subjects given (policy full). */
function in_graph(int $doc, array $subjects, string $policy = 'full'): void
{
    $lit = '{' . implode(',', array_map(static fn ($s) => '"' . str_replace('"', '\\"', $s) . '"', $subjects)) . '}';
    pdo()->prepare("INSERT INTO graph_documents (document_id, version_id, maludb_document_id, policy, subjects, chars) VALUES (:d, (SELECT current_version_id FROM documents WHERE id = :d), :m, :p, CAST(:s AS text[]), 100)
                    ON CONFLICT (document_id) DO UPDATE SET subjects = EXCLUDED.subjects, policy = EXCLUDED.policy")->execute(['d' => $doc, 'm' => 'proof-' . $doc, 'p' => $policy, 's' => $lit]);
}
function age_doc(int $doc, string $interval): void { pdo()->exec("UPDATE documents SET created_at = now() - interval '$interval' WHERE id = $doc"); }
function member_name(int $id): string { return (string) one('SELECT display_name FROM members WHERE id = :id', ['id' => $id]); }
/** A folder made by SQL as its owner (owner = the member; created beneath $parent). */
function new_folder(int $parent, string $name, int $by = 27): int { return folder_in($parent, $name, $by); }
/** The ISO week label of now, as the code writes it. */
function week_label(?string $at = null): string { $d = new DateTimeImmutable($at ?? 'now'); return $d->format('o') . '-W' . $d->format('W'); }
/** Run the worker's passes with the clock set (DC_WORKER_NOW). */
function worker_at(string $passes, string $now): array { return worker($passes, ['DC_WORKER_NOW' => $now]); }
/** The most recent Monday at 08:00 UTC (a time the Monday sweep may run). */
function monday_8(): string { return (new DateTimeImmutable('monday this week 08:00:00 UTC'))->format('Y-m-d\TH:i:s\Z'); }

/**
 * The seeded world every slice-8 proof reads (idempotent): the Board's documents all tagged 'base' so only what the world adds is untagged; then
 *   untagged: u1 (Priya, in the graph with subjects → a `tags` proposal), u2 (Marco, not in the graph → `untagged`), six scans in "Scans" (a folder with more than five → ONE folder proposal);
 *   a file of three copies (dup a/b/c, one sha256); a guest share 100 days old on "Client"; a link expiring in 3 days; a link opened from 12 addresses; an open file request;
 *   a document in the trash with 2 days to go; a document locked 30 hours; an unsupported file; the Board's library 92% full; the graph budget at 85%; a document owned by Lee (31) who has left.
 * Texts carry the marker WORLD_SECRET so a proof can assert the Clerk's proposals never hold a document's words.
 */
const WORLD_SECRET = 'ZEBRA-QUARTZ-4471';
function world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $file = (string) getenv('DC_DEV_STATE') . '/world.json';                        // made once per run (run.sh removes it): a second proof process reads it, never re-seeds
    if (is_file($file)) { return $w = json_decode((string) file_get_contents($file), true); }
    $s = seed_texts();
    name_agents();
    $p = pdo();
    $root = $s['board_root']; $contracts = $s['contracts'];
    $base = tag_id_of('base');
    foreach ([$s['board_doc'], $s['minutes'], $s['priya_doc'], $s['guest_doc'], $s['handbook'], $s['design']] as $d) { tag_doc($d, $base); }
    $inbox = new_folder($root, 'W8 Inbox', 27);
    $scans = new_folder($root, 'W8 Scans', 27);
    $client = new_folder($root, 'W8 Client', 27);
    $priyaRoot = root_of(26);
    $u1 = seed_doc($priyaRoot, 'w8-invoice.pdf', 26, 'Invoice for the quarter. ' . WORLD_SECRET . ' payment terms net thirty.');
    in_graph($u1, ['doc:' . $u1, 'folder:Priya', 'library:Priya', 'owner:SMOKE Priya', 'kind:pdf', 'year:2026', 'Supplier Terms', 'Net Thirty', 'Quarterly Invoice']);
    $u2 = seed_doc($inbox, 'w8-notes.pdf', 27, 'Loose notes. ' . WORLD_SECRET);
    $scanIds = [];
    for ($i = 1; $i <= 6; $i++) { $scanIds[] = seed_doc($scans, "w8-scan-$i.pdf", 27, "Scan $i " . WORLD_SECRET); }
    $sha = hash('sha256', 'w8-dup');
    $dups = [doc_with_sha($inbox, 'w8-dup-a.pdf', 27, $sha), doc_with_sha($scans, 'w8-dup-b.pdf', 27, $sha), doc_with_sha($contracts, 'w8-dup-c.pdf', 26, $sha)];
    foreach ($dups as $d) { tag_doc($d, $base); }
    $p->exec("INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by, created_at) VALUES ($client, 'guest', 29, 'view', 27, now() - interval '100 days') ON CONFLICT DO NOTHING");
    $exp = make_link(27, ['document' => $s['board_doc'], 'label' => 'w8 expiring link', 'expires_in_days' => 30]);
    $p->exec("UPDATE share_links SET expires_at = now() + interval '3 days' WHERE id = " . (int) $exp['link_id']);
    $wide = make_link(26, ['document' => $s['priya_doc'], 'label' => 'w8 widely opened']);
    for ($i = 1; $i <= 12; $i++) { $p->exec("INSERT INTO link_opens (link_id, what, address_hash) VALUES (" . (int) $wide['link_id'] . ", 'view', 'w8addr$i')"); }
    $req = make_request(27, ['folder' => $inbox, 'label' => 'w8 open request']);
    $trash = seed_doc($contracts, 'w8-purging.pdf', 27, 'Old contract. ' . WORLD_SECRET); tag_doc($trash, $base);
    $p->exec("UPDATE documents SET trashed_at = now() - interval '28 days', trashed_by = 27, trashed_from_folder_id = folder_id WHERE id = $trash");
    $lock = seed_doc($contracts, 'w8-locked.pdf', 27, 'Locked draft. ' . WORLD_SECRET); tag_doc($lock, $base);
    $p->exec("UPDATE documents SET locked_by = 26, locked_at = now() - interval '30 hours', lock_expires_at = now() + interval '40 hours' WHERE id = $lock");
    $bin = seed_doc($contracts, 'w8-unreadable.bin', 27, 'x'); tag_doc($bin, $base);
    $p->exec("UPDATE document_versions SET extracted_status = 'unsupported' WHERE id = (SELECT current_version_id FROM documents WHERE id = $bin)");
    $lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $root]);
    $p->exec("UPDATE libraries SET quota_bytes = 1000000, used_bytes = 920000 WHERE id = $lib");
    $budget = (int) one("SELECT dc_setting_int('graph_monthly_text_mb')") * 1048576;
    $p->exec("INSERT INTO graph_usage (month, kind, calls, chars, documents) VALUES (date_trunc('month', now())::date, 'full', 5, " . (int) round($budget * 0.85) . ", 5) ON CONFLICT (month, kind) DO UPDATE SET chars = EXCLUDED.chars");
    $orph = seed_doc($inbox, 'w8-lees.pdf', 31, 'Lee\'s file. ' . WORLD_SECRET); tag_doc($orph, $base);
    $p->exec("UPDATE members SET status = 'inactive' WHERE id = 31");
    $w = $s + ['inbox' => $inbox, 'scans' => $scans, 'client' => $client, 'u1' => $u1, 'u2' => $u2, 'scan_ids' => $scanIds, 'dups' => $dups, 'exp_link' => (int) $exp['link_id'], 'wide_link' => (int) $wide['link_id'],
        'request' => (int) $req['link_id'], 'trash' => $trash, 'lock' => $lock, 'bin' => $bin, 'lib' => $lib, 'orph' => $orph, 'priya_root' => $priyaRoot];
    file_put_contents($file, json_encode($w));
    return $w;
}
function tag_id_of(string $slug): int
{
    $id = one('SELECT id FROM tags WHERE slug = :s', ['s' => $slug]);
    if ($id === false) { $id = one("SELECT dc_tag_ensure(:l, NULL, 'free')", ['l' => $slug]); }
    return (int) $id;
}
function tag_doc(int $doc, int $tag): void { pdo()->prepare("SELECT dc_tag_apply(:d, :t, NULL, 'rule')")->execute(['d' => $doc, 't' => $tag]); }
