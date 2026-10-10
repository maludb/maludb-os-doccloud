<?php
/**
 * Slice 9 proof 1 — the home per role: a new Member's one button; a Member's sections with seeded data (shared this week, open requests, links ending, the Clerk, libraries, recent); a curator's three more;
 * the admin's three more; a guest's Shared with me; nothing a role may not see; every card's link resolves; the numbers equal the database's own.
 */
require __DIR__ . '/lib.php';
$p = pdo();
$data = static fn (int $m): array => get_json($m, '/')['json']['data'] ?? [];
$home = static fn (int $m): string => page(actor($m), '/')['body'];

echo "1. A new Member: the one button (asked before the world is seeded: later, Dana sees the shared Policies library)\n";
seed_store();
$d = $data(30);
$h = $home(30);
ok(($d['first_run'] ?? null) === true && ($d['may']['upload'] ?? false) === true, 'Dana (nothing yet): first_run, may.upload');
ok(str_contains($h, 'id="home-first-run"') && str_contains($h, 'id="home-upload-first-btn"') && str_contains($h, 'Upload your first file'), '...her home is the one button, Upload your first file');
ok(!str_contains($h, 'id="home-section-') && !str_contains($h, 'id="home-libraries-list"'), '...and no section at all');
ok(!isset($d['shared_week']) && !isset($d['requests_open']) && !isset($d['links_expiring']), '...the JSON carries none of the blocks (shared_week, requests_open, links_expiring null)');
ok(!isset($d['curator']) && !isset($d['admin']), '...nor a curator\'s or the admin\'s');

$w = world9();
$nul = static fn (array $d, string $k): bool => array_key_exists($k, $d) && $d[$k] === null;

echo "2. A Member: Priya\n";
$d = $data(26);
$h = $home(26);
ok(($d['first_run'] ?? null) === false && ($d['guest'] ?? null) === false && count($d['libraries'] ?? []) >= 2 && in_array('personal', array_column($d['libraries'], 'kind'), true) && in_array('group', array_column($d['libraries'], 'kind'), true), 'Priya: not first_run; her library, the Board and the shared ones as cards');
foreach (['home-section-shared', 'home-section-requests', 'home-section-links', 'home-libraries-list', 'home-recent'] as $id) { ok(str_contains($h, 'id="' . $id . '"'), "her home has #$id"); }
$names = array_column($d['shared_week'] ?? [], 'name');
ok(in_array('w9-shared-new.pdf', $names, true) && !in_array('w9-shared-old.pdf', $names, true), 'Shared with me this week: the one shared yesterday, not the one shared 20 days ago');
ok(str_contains($h, 'w9-shared-new.pdf') && !str_contains($h, 'w9-shared-old.pdf'), '...the page says the same');
$sqlShared = (int) (function () use ($p) { as_member(26); return one("SELECT count(*) FROM dc_shared_with_me(500, 0) WHERE shared_at >= now() - interval '7 days'"); })();
ok(count($d['shared_week']) === $sqlShared, "...the count ($sqlShared) is the database's own dc_shared_with_me");
$reqs = array_column($d['requests_open'] ?? [], 'link_id');
ok(in_array($w['priya_request'], $reqs, true) && count($reqs) === 1, 'My open file requests: hers, and only hers');
ok(in_array($w['priya_request'], $reqs, true) && str_contains($h, 'id="home-request-' . $w['priya_request'] . '"'), '...and a card for it');
$exp = array_column($d['links_expiring'] ?? [], 'link_id');
ok($exp === [$w['priya_link']], 'My links expiring this week: only her link ending in three days (not Marco\'s, not the wide one)');
ok(str_contains($h, 'id="home-link-' . $w['priya_link'] . '"') && str_contains($h, 'w9 priya ending link'), '...and its card');
ok(!str_contains($h, 'id="home-curator-title"') && !str_contains($h, 'id="home-section-untagged"') && !str_contains($h, 'id="home-section-duplicates"') && !str_contains($h, 'id="home-section-stale"'), 'no curator block on a Member\'s home');
ok(!str_contains($h, 'id="home-admin-title"') && !str_contains($h, 'id="home-section-storage"') && !str_contains($h, 'id="home-section-graph"') && !str_contains($h, 'id="home-section-failures"'), '...nor the admin\'s');
ok($nul($d, 'curator') && $nul($d, 'admin'), '...in the JSON either');
ok(!str_contains($h, 'w8-lees.pdf') && !str_contains($h, "Lee's") && !str_contains($h, WORLD_SECRET), 'nothing of a document she may not see (Lee\'s) and no document\'s words');
$clerk = (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'monday_note'");
$p->exec("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'monday_note', 'W9 Monday: your week', 'x')");
ok(str_contains($home(26), 'W9 Monday: your week') && ($data(26)['clerk_note']['title'] ?? '') === 'W9 Monday: your week', 'the Clerk\'s last note shows on the home (' . $clerk . ' before)');
ok(str_contains($h, 'id="home-clerk-btn"') || str_contains($home(26), 'id="home-clerk-btn"'), '...with its button');

echo "3. A curator: Marco\n";
$old = seed_doc(root_of(27), 'w9-old-untagged.pdf', 27, 'Old untagged. ' . WORLD_SECRET); age_doc($old, '10 days');
$new = seed_doc(root_of(27), 'w9-new-untagged.pdf', 27, 'New untagged. ' . WORLD_SECRET);
$d = $data(27);
$h = $home(27);
foreach (['home-curator-title', 'home-section-untagged', 'home-section-duplicates', 'home-section-stale', 'home-section-shared', 'home-section-requests', 'home-section-links'] as $id) { ok(str_contains($h, 'id="' . $id . '"'), "his home has #$id"); }
$un = array_column($d['curator']['untagged_week'] ?? [], 'document_id');
ok(in_array($new, $un, true) && !in_array($old, $un, true), 'Added this week, untagged: the new document, not the one made 10 days ago');
ok(in_array($w['u2'], $un, true), '...and the slice-8 world\'s loose notes');
$dup = $d['curator']['duplicates'][0] ?? [];
ok(($dup['copies'] ?? 0) === 3 && in_array($w['dups'][0], $dup['document_ids'] ?? [], true), 'Duplicates: the file kept three times');
ok(str_contains($h, 'w8-dup-a.pdf') && str_contains($h, 'copies'), '...named on the page');
$stale = $d['curator']['stale_shares'] ?? [];
ok(count($stale) >= 1 && $stale[0]['guest_name'] === 'SMOKE Ann' && $stale[0]['name'] === 'W8 Client', 'Stale guest shares: Ann on W8 Client (100 days)');
ok(str_contains($h, 'id="home-stale-folder-' . $w['client'] . '"'), '...with its card');
ok($nul($d, 'admin') && !str_contains($h, 'id="home-admin-title"') && !str_contains($h, 'id="home-section-storage"'), 'no admin block on the curator\'s home');
$mine = array_column($d['links_expiring'] ?? [], 'link_id');
ok(in_array($w['exp_link'], $mine, true) && !in_array($w['priya_link'], $mine, true), 'his own links ending soon are his (not Priya\'s)');
ok($nul($data(26), 'curator'), 'Priya still has no curator block');

echo "4. The admin\n";
$d = $data(1);
$h = $home(1);
foreach (['home-admin-title', 'home-section-storage', 'home-section-graph', 'home-section-failures', 'home-curator-title', 'home-section-untagged', 'home-section-duplicates', 'home-section-stale'] as $id) { ok(str_contains($h, 'id="' . $id . '"'), "the admin's home has #$id"); }
as_member(1);
$top = array_slice(array_column(q('SELECT library_id FROM dc_storage_by_library()'), 'library_id'), 0, 5);
$got = array_column($d['admin']['storage'] ?? [], 'library_id');
ok(count($got) === count($top) && array_map('intval', $top) === $got && count($got) <= 5, 'Storage by library: the top five, the database\'s own order (' . implode(',', $got) . ')');
ok(($d['admin']['graph']['jobs']['queued'] ?? 0) >= 1 && ($d['admin']['graph']['jobs']['failed'] ?? 0) >= 1, 'The graph queue: queued and failed jobs counted');
$b = $d['admin']['graph']['budget'] ?? [];
ok(($b['pct'] ?? 0) >= 84 && ($b['pct'] ?? 0) <= 86 && $b['budget_chars'] > 0, 'The month\'s spend: ' . ($b['pct'] ?? '?') . '% of the budget');
ok(str_contains($h, 'id="home-graph-spend"') && str_contains($h, 'id="home-graph-queue"'), '...on the page');
$f = $d['admin']['failures'] ?? [];
ok(($d['admin']['failures_total'] ?? 0) >= 1 && in_array($w['bin'], array_column($f, 'document_id'), true), 'Failed extractions: the unreadable file is listed (' . ($d['admin']['failures_total'] ?? 0) . ')');
ok(str_contains($h, 'id="home-failure-' . $w['bin'] . '"'), '...with its card');
ok(array_key_exists('curator', $d) && $d['curator'] !== null, 'the admin also holds the curator\'s blocks');

echo "5. A guest: Ann\n";
$d = $data(29);
$h = $home(29);
ok(($d['guest'] ?? null) === true && ($d['libraries'] ?? ['x']) === [] && ($d['first_run'] ?? null) === false, 'Ann: a guest, no library, never first_run');
ok(($d['shared_count'] ?? 0) >= 2 && count($d['shared_recent'] ?? []) >= 2, 'Shared with me: ' . ($d['shared_count'] ?? 0) . ' things');
ok(in_array('for-ann.pdf', array_column($d['shared_recent'], 'name'), true) && str_contains($h, 'id="home-section-shared"') && str_contains($h, 'for-ann.pdf'), '...Marco\'s document named, on the page too');
ok(str_contains($h, 'id="home-shared-btn"') && !str_contains($h, 'id="home-section-requests"') && !str_contains($h, 'id="home-section-links"') && !str_contains($h, 'id="home-curator-title"') && !str_contains($h, 'id="home-admin-title"'), 'a guest sees no member, curator or admin block');
ok($nul($d, 'curator') && $nul($d, 'admin') && $nul($d, 'shared_week') && $nul($d, 'requests_open') && $nul($d, 'links_expiring'), '...in the JSON either');
ok(!str_contains($h, 'w9-shared-new.pdf') && !str_contains($h, WORLD_SECRET), 'and nothing of what is shared with others');

echo "6. Every card's link resolves\n";
foreach ([1 => 'the admin', 27 => 'the curator', 26 => 'a member', 29 => 'a guest'] as $m => $who) {
    $html = home_content($m);
    preg_match_all('~href="(/[^"#]*)~', $html, $mm);
    $links = array_values(array_unique(array_filter($mm[1], fn ($u) => !str_starts_with($u, '/assets/'))));
    $bad = [];
    foreach ($links as $u) {
        $u = html_entity_decode($u);
        $c = page(actor($m), $u)['code'];
        if (!in_array($c, [200, 302], true)) { $bad[] = "$u → $c"; }
    }
    ok($bad === [] && count($links) >= 3, "$who: " . count($links) . ' links in the home content all resolve' . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 4)) : ''));
}

echo "7. The rest\n";
$x = req('GET', '/', ['headers' => ['Accept: application/json']]);
ok($x['code'] === 401, 'no session, JSON: 401');
$x = page(jar(), '/');
ok($x['code'] === 302 && $x['location'] === LAUNCHER, 'no session: the launcher');
$since = last_activity_id();
page(actor(27), '/');
ok(count(activity('screen.view', $since)) === 1 && activity('screen.view', $since)[0]['screen'] === 'home', 'opening the home logs screen.view with screen home');
$t = microtime(true); page(actor(1), '/'); $ms = (microtime(true) - $t) * 1000;
ok($ms < 3000, 'the admin\'s home answers in ' . (int) $ms . ' ms');
finish();
/** The main-content part of a member's home. */
function home_content(int $m): string
{
    $b = page(actor($m), '/')['body'];
    $i = strpos($b, 'id="home-content"');
    return $i === false ? '' : substr($b, $i);
}
