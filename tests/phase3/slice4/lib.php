<?php
/**
 * Slice 4's helpers on top of slice 3's: documents with TEXT seeded through dc_version_add() + document_text (the index follows by trigger), the
 * tags of a document as the database holds them, a tag by slug, a search as a member through the page's JSON branch, the typed-modifier parse
 * through PHP (search_parse) against the scratch database. The store: the Board (Marco 27 owner, Priya 26 and Seamus 40 members), Contracts with
 * acme-msa.pdf (Marco); Priya's my-notes.md; Marco's for-ann.pdf shared with the guest Ann 29; Bea 28 a member outside the Board; Engineering (8)
 * with Dana 30 and Lee 31 (seed_people).
 */
require dirname(__DIR__) . '/slice3/lib.php';
/** A document made the seed's way, with text, in a folder as a member; returns its id. */
function seed_doc(int $folder, string $name, int $by, string $text, string $mime = 'application/pdf', int $bytes = 1200): int
{
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '$by', false)");
    $id = one('SELECT id FROM documents WHERE folder_id = :f AND lower(name) = lower(:n) AND trashed_at IS NULL', ['f' => $folder, 'n' => $name]);
    if ($id === false) {
        $sha = hash('sha256', 's4:' . $folder . ':' . $name);
        $b = q('SELECT blob_id FROM dc_blob_claim(:s, :n)', ['s' => $sha, 'n' => $bytes])[0]['blob_id'];
        $id = q('SELECT document_id FROM dc_version_add(NULL, :f, :name, :b, :name, :m, :n, :s, :by)', ['f' => $folder, 'name' => $name, 'b' => $b, 'm' => $mime, 'n' => $bytes, 's' => $sha, 'by' => $by])[0]['document_id'];
    }
    $id = (int) $id;
    $v = (int) one('SELECT current_version_id FROM documents WHERE id = :id', ['id' => $id]);
    $p->prepare("INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES (:v, :d, :t, :c, 'proof')
                 ON CONFLICT (version_id) DO UPDATE SET text = EXCLUDED.text, char_count = EXCLUDED.char_count")->execute(['v' => $v, 'd' => $id, 't' => $text, 'c' => strlen($text)]);
    $p->prepare("UPDATE document_versions SET extracted_status = 'ready' WHERE id = :v")->execute(['v' => $v]);
    return $id;
}
/** The store with texts: the seed's three plus a minutes file in the Board root, a handbook in a shared library (everyone view), an Engineering design. */
function seed_texts(): array
{
    $s = seed_store();
    seed_people();
    $p = pdo();
    $p->exec("SELECT set_config('app.member_id', '27', false)");
    $acme = $s['board_doc'];
    $v = (int) one('SELECT current_version_id FROM documents WHERE id = :id', ['id' => $acme]);
    $p->prepare("INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES (:v, :d, :t, :c, 'proof') ON CONFLICT (version_id) DO UPDATE SET text = EXCLUDED.text, char_count = EXCLUDED.char_count")
      ->execute(['v' => $v, 'd' => $acme, 't' => 'Master services agreement between Acme Corporation and the business. Termination clause: ninety days notice. Governing law Illinois.', 'c' => 130]);
    $minutes = seed_doc($s['board_root'], 'minutes-jan.docx', 27, 'Board minutes, January. Acme renewal discussed; termination risk noted; budget approved.', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $pv = (int) one('SELECT current_version_id FROM documents WHERE id = :id', ['id' => $s['priya_doc']]);
    $p->prepare("INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES (:v, :d, :t, :c, 'proof') ON CONFLICT (version_id) DO UPDATE SET text = EXCLUDED.text, char_count = EXCLUDED.char_count")
      ->execute(['v' => $pv, 'd' => $s['priya_doc'], 't' => 'My private notes about the acme negotiation and termination terms.', 'c' => 70]);
    $gv = (int) one('SELECT current_version_id FROM documents WHERE id = :id', ['id' => $s['guest_doc']]);
    $p->prepare("INSERT INTO document_text (version_id, document_id, text, char_count, extractor) VALUES (:v, :d, :t, :c, 'proof') ON CONFLICT (version_id) DO UPDATE SET text = EXCLUDED.text, char_count = EXCLUDED.char_count")
      ->execute(['v' => $gv, 'd' => $s['guest_doc'], 't' => 'For Ann: the termination schedule of the acme contract, shared with the guest.', 'c' => 80]);
    $shared = one("SELECT id FROM libraries WHERE kind = 'shared' AND name = 'SMOKE Policies'");
    if ($shared === false) {
        $p->exec("INSERT INTO libraries (kind, name, owner_member_id, everyone_level, created_by) VALUES ('shared', 'SMOKE Policies', 27, 'view', 27)");
        $shared = one("SELECT id FROM libraries WHERE kind = 'shared' AND name = 'SMOKE Policies'");
    }
    $polRoot = (int) one('SELECT root_folder_id FROM libraries WHERE id = :id', ['id' => (int) $shared]);
    $handbook = seed_doc($polRoot, 'handbook.pdf', 27, 'Employee handbook. Leave policy, expenses policy, termination of employment procedure. Nothing about Acme here.');
    $engRoot = (int) one("SELECT root_folder_id FROM libraries l JOIN groups g ON g.id = l.group_id WHERE g.key = 's4-eng'");
    if ($engRoot === 0) {
        $p->exec("INSERT INTO groups (key, name, kind, department_id, created_by) VALUES ('s4-eng', 'SMOKE Engineering', 'department', 8, 27)");
        $engRoot = (int) one("SELECT root_folder_id FROM libraries l JOIN groups g ON g.id = l.group_id WHERE g.key = 's4-eng'");
    }
    $design = seed_doc($engRoot, 'design.pdf', 30, 'Engineering design for the Chicago warehouse. Termination of the old lease.');
    return $s + ['minutes' => $minutes, 'handbook' => $handbook, 'design' => $design, 'shared_lib' => (int) $shared, 'pol_root' => $polRoot, 'eng_root' => $engRoot];
}
function tags_of(int $doc): array { return array_column(q('SELECT t.slug FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dt.document_id = :d ORDER BY t.slug', ['d' => $doc]), 'slug'); }
function tag_row(string $slug): ?array { $r = q('SELECT * FROM tags WHERE slug = :s', ['s' => $slug]); return $r[0] ?? null; }
function tag_id(string $slug): int { return (int) one('SELECT id FROM tags WHERE slug = :s', ['s' => $slug]); }
/** A search as a member through the page's JSON branch: [names[], total, json]. */
function search_as(int $m, string $q, array $get = []): array
{
    $r = get_json($m, '/search?' . http_build_query(['q' => $q] + $get));
    $rows = $r['json']['data']['search']['rows'] ?? [];
    $names = array_map(static fn (array $x): string => $x['name'], $rows);
    sort($names);
    return ['names' => $names, 'total' => (int) ($r['json']['data']['search']['total'] ?? -1), 'json' => $r['json'], 'code' => $r['code']];
}
/** search_parse() against the scratch database as a member (the PHP side of the proof). */
function parse_as(int $m, string $typed): array
{
    require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
    require_once dirname(__DIR__, 3) . '/app/features/search/parse.php';
    $pdo = db();
    $pdo->exec("SELECT set_config('app.member_id', '$m', false)");
    return search_parse($pdo, $typed);
}
