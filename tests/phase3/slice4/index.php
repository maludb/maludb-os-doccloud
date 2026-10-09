<?php
/** Slice 4 proof — the keyword index (spec "Proof" 5): a row per live document; rename, tag, a new version and the trash change it by trigger; a bulk load with app.dc_bulk and the catch-up repairs it; a trashed document leaves it. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$acme = $s['board_doc'];
$live = (int) one('SELECT count(*) FROM documents WHERE trashed_at IS NULL');
ok((int) one("SELECT count(*) FROM search_index WHERE entity_kind = 'document'") === $live && (int) one("SELECT count(*) FROM search_index si WHERE si.entity_kind = 'document' AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.id = si.document_id AND d.trashed_at IS NULL)") === 0, "every live document has one index row ($live)");
$row = q('SELECT * FROM search_index WHERE document_id = :d', ['d' => $acme])[0];
ok($row['title'] === 'acme-msa.pdf' && str_contains((string) $row['body'], 'Governing law Illinois') && (int) $row['version_id'] === (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $acme]), 'the row carries the name, the text and the version');
post_j(27, '/documents/save.php', ['document' => $acme, 'name' => 'acme-master.pdf']);
ok(one('SELECT title FROM search_index WHERE document_id = :d', ['d' => $acme]) === 'acme-master.pdf', 'a rename changes the row by trigger');
post_j(27, '/tags/apply.php', ['document' => $acme, 'tags' => ['indexed-tag']]);
$row = q('SELECT tags, tsv::text AS tsv FROM search_index WHERE document_id = :d', ['d' => $acme])[0];
ok(str_contains((string) $row['tags'], 'indexed-tag') && str_contains((string) $row['tsv'], 'indexed-tag'), 'a tag changes the row and the tsvector');
$up = upload_file(27, $s['contracts'], tmpfile_of(2500, 'v2', 'acme-master.pdf'), ['document_id' => $acme]);
$newV = (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $acme]);
ok(($up['finish']['code'] ?? 0) === 200 && (int) one('SELECT version_id FROM search_index WHERE document_id = :d', ['d' => $acme]) === $newV, 'a new version moves the row to it');
post_j(27, '/documents/save.php', ['document' => $acme, 'description' => 'Described for the index']);
ok(str_contains((string) one('SELECT tsv::text FROM search_index WHERE document_id = :d', ['d' => $acme]), 'describ'), 'a description enters the tsvector');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
pdo()->exec("SELECT dc_document_trash($acme, 27)");
ok((int) one('SELECT count(*) FROM search_index WHERE document_id = :d', ['d' => $acme]) === 0, 'a trashed document leaves the index');
pdo()->exec("SELECT dc_document_restore($acme)");
ok((int) one('SELECT count(*) FROM search_index WHERE document_id = :d', ['d' => $acme]) === 1, '…and returns on restore');

echo "2. The catch-up\n";
pdo()->exec("SELECT set_config('app.dc_bulk', '1', false)");
$bulk = seed_doc($s['board_root'], 'bulk-loaded.txt', 27, 'Bulk loaded text about the warehouse termination.', 'text/plain', 60);
pdo()->exec("SELECT set_config('app.dc_bulk', '', false)");
pdo()->exec("SELECT dc_recount_bulk()");                                   // a bulk load recounts the blobs and the libraries afterwards (db/007)
ok((int) one('SELECT count(*) FROM search_index WHERE document_id = :d', ['d' => $bulk]) === 0, 'a bulk load (app.dc_bulk) writes no index row');
pdo()->exec("DELETE FROM search_index WHERE document_id = " . $s['minutes']);
$w = worker('search_catch_up');
ok((int) ($w['ran']['search_catch_up']['indexed'] ?? -1) >= 2 && (int) one('SELECT count(*) FROM search_index WHERE document_id IN (:a, :b)', ['a' => $bulk, 'b' => $s['minutes']]) === 2, 'the worker\'s catch-up repairs the bulk-loaded and the missing rows: ' . json_encode($w['ran']['search_catch_up'] ?? null));
ok(last_event('index.catch_up') !== null && (int) json_decode(last_event('index.catch_up')['after'], true)['indexed'] >= 2, 'logged index.catch_up with the count');
$w = worker('search_catch_up');
ok((int) ($w['ran']['search_catch_up']['indexed'] ?? -1) === 0, 'a second pass finds nothing to repair');
$f = search_as(27, 'warehouse termination');
ok(in_array('bulk-loaded.txt', $f['names'], true), 'the repaired document is found');
finish();
