<?php
/**
 * Slice 9's helpers on top of slice 8's (and so 7's … 1's): the worlds of slices 1–8 composed (world()), then THIS slice's seed (world9(), idempotent, made once per run): Lee (31, who has left) given a second library's
 * documents so a transfer can be limited to one; Priya's home given something shared this week (and something older), an open file request and a link ending in three days; empty and found searches in the search
 * log; failed graph jobs; a link opened from many addresses; the leaver's count asked of the database, not guessed. The fixture: 1 the admin, 26 Priya (Member), 27 Marco (curator, owner of the Board), 28 Bea,
 * 29 Ann (guest), 30 Dana (a new Member with nothing), 31 Lee (has left), 40 Seamus (agent, the expert), 41 the Watcher, 42 the Clerk (agent).
 */
require dirname(__DIR__) . '/slice8/lib.php';
/** The extra seed of slice 9 (idempotent: a second process reads world9.json). */
function world9(): array
{
    static $w9 = null;
    if ($w9 !== null) { return $w9; }
    $file = (string) getenv('DC_DEV_STATE') . '/world9.json';
    if (is_file($file)) { return $w9 = json_decode((string) file_get_contents($file), true); }
    $w = world();
    $p = pdo();
    // Lee has left (slice 8): give him documents in his own library and in the Board's, as he would have had them. (Active while the documents are made — a leaver makes none.)
    $p->exec("UPDATE members SET status = 'active' WHERE id = 31");
    $p->exec('SELECT dc_library_ensure_personal(31)');
    $leeRoot = root_of(31);
    $leeDocs = [];
    for ($i = 1; $i <= 4; $i++) { $leeDocs[] = seed_doc($leeRoot, "w9-lee-own-$i.pdf", 31, "Lee's own $i. " . WORLD_SECRET); }
    for ($i = 1; $i <= 7; $i++) { $leeDocs[] = seed_doc($w['inbox'], "w9-lee-board-$i.pdf", 31, "Lee's board $i. " . WORLD_SECRET); }
    $p->exec("UPDATE members SET status = 'inactive' WHERE id = 31");
    $leeLib = (int) one("SELECT library_id FROM folders WHERE id = :f", ['f' => $leeRoot]);
    // what is shared with Priya: one this week (Marco's), one 20 days old (not shown on her home)
    $sharedNew = seed_doc(root_of(27), 'w9-shared-new.pdf', 27, 'Shared new. ' . WORLD_SECRET);
    $sharedOld = seed_doc(root_of(27), 'w9-shared-old.pdf', 27, 'Shared old. ' . WORLD_SECRET);
    $p->exec("INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by, created_at) VALUES ($sharedNew, 'member', 26, 'view', 27, now() - interval '1 day'), ($sharedOld, 'member', 26, 'view', 27, now() - interval '20 days')");
    // Priya's open file request and her link ending in three days
    $req = make_request(26, ['folder' => $w['priya_root'], 'label' => 'w9 priya request']);
    $link = make_link(26, ['document' => $w['priya_doc'], 'label' => 'w9 priya ending link', 'expires_in_days' => 30]);
    $p->exec("UPDATE share_links SET expires_at = now() + interval '3 days' WHERE id = " . (int) $link['link_id']);
    // the search log: empty and found, keyword and graph and by name
    $p->exec("DELETE FROM search_log");
    $rows = [['budget forecast', 'keyword', 0, 3], ['budget forecast', 'keyword', 0, 0], ['Budget Forecast', 'graph', 0, 1], ['zzz nothing here', 'keyword', 0, 0], ['contract', 'keyword', 5, 0], ['contract', 'keyword', 5, 1], ['acme', 'name', 2, 0], ['supplier terms', 'graph', 4, 2]];
    foreach ($rows as [$qy, $k, $res, $daysAgo]) {
        $p->prepare("INSERT INTO search_log (member_id, kind, query, results, created_at) VALUES (26, :k, :q, :r, now() - make_interval(days => :d))")->execute(['k' => $k, 'q' => $qy, 'r' => $res, 'd' => $daysAgo]);
    }
    // graph jobs for the admin's home: one queued, one failed
    $p->exec("INSERT INTO graph_jobs (document_id, version_id, kind, chars, status, error) VALUES ({$w['u1']}, (SELECT current_version_id FROM documents WHERE id = {$w['u1']}), 'full', 1000, 'queued', NULL),
                                                                                              ({$w['u2']}, (SELECT current_version_id FROM documents WHERE id = {$w['u2']}), 'full', 1000, 'failed', 'the engine said no')");
    $w9 = $w + ['lee_docs' => $leeDocs, 'lee_root' => $leeRoot, 'lee_lib' => $leeLib, 'shared_new' => $sharedNew, 'shared_old' => $sharedOld, 'priya_request' => (int) $req['link_id'], 'priya_link' => (int) $link['link_id']];
    file_put_contents($file, json_encode($w9));
    return $w9;
}
/** Set the member of the database session (for SQL asked as a person). */
function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }
/** The HTML id's present on a page: a list of ids → the ones missing. */
function missing_ids(string $html, array $ids): array { return array_values(array_filter($ids, static fn (string $id): bool => !str_contains($html, 'id="' . $id . '"'))); }
/** The state of the settings row. */
function setting(string $col) { return one("SELECT $col FROM dc_settings WHERE id = 1"); }
/** The kernel's state: set the connections the fake route answers (null removes it). */
function kernel_connections_set(?array $rows): void { kernel_state(function (array $s) use ($rows) { if ($rows === null) { unset($s['connections']); } else { $s['connections'] = $rows; } return $s; }); }
