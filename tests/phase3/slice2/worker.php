<?php
/** Slice 2 proof — the worker's file passes (spec "Proof" 12): extraction states for the fixtures (PDF with text, PDF without, PNG, DOCX, XLSX, TXT, CSV, a binary), page anchors, document_page and document_excerpt, the index row carrying the text, a thumbnail for a PNG and a PDF, a zip of a folder holding the right files and skipping what the maker may not see, uploads_expire, exports_expire, locks_expire, the notifications. */
require __DIR__ . '/lib.php';
$seed = seed_store();
$contracts = $seed['contracts'];
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
pdo()->prepare("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = :c), :c, 'Worker', 27, 27) ON CONFLICT DO NOTHING")->execute(['c' => $contracts]);
$wf = (int) one("SELECT id FROM folders WHERE parent_id = :c AND name = 'Worker'", ['c' => $contracts]);
$png = fx_dir() . '/w.png'; $im = imagecreatetruecolor(500, 300); imagefill($im, 0, 0, imagecolorallocate($im, 90, 160, 60)); imagepng($im, $png); imagedestroy($im);
$files = [
    'pdf' => pdf_fixture('w-text.pdf'), 'pdf_img' => fx_write('w-scan.pdf', fx_pdf([[], []], true)), 'png' => $png,
    'docx' => fx_docx('w-memo.docx', [['h1', 'Worker memo'], ['h2', 'Leave policy'], ['p', fx_para(21, 1500)]]), 'xlsx' => fx_xlsx('w-sheet.xlsx', ['Budget' => [['item', 'cost'], ['travel', '120']], 'Notes' => [['a note']]]),
    'txt' => fx_write('w-plain.txt', "Plain words about termination clauses.\n" . fx_para(22, 900)), 'csv' => fx_write('w-rows.csv', "a,b\n1,2\n3,4\n"), 'bin' => tmpfile_of(300000, 'wbin', 'w-blob.bin'),
];
$ids = [];
foreach ($files as $k => $p) { $u = upload_file(27, $wf, $p); $ids[$k] = (int) ($u['finish']['json']['record_id'] ?? 0); if ($ids[$k] === 0) { echo "  upload of $k failed: " . substr($u['finish']['body'] ?? $u['start']['body'], 0, 200) . "\n"; } }
ok(min($ids) > 0, 'eight fixtures uploaded (' . implode(', ', array_keys($ids)) . ')');

echo "1. Extraction\n";
$rep = worker('extract_text');
$states = []; foreach ($ids as $k => $id) { $states[$k] = (string) one('SELECT v.extracted_status FROM document_versions v JOIN documents d ON d.current_version_id = v.id WHERE d.id = :d', ['d' => $id]); }
ok($states['pdf'] === 'ready' && $states['docx'] === 'ready' && $states['xlsx'] === 'ready' && $states['txt'] === 'ready' && $states['csv'] === 'ready', 'PDF, DOCX, XLSX, TXT and CSV are ready (' . json_encode($states) . ')');
ok($states['pdf_img'] === 'no_text' && $states['png'] === 'no_text', 'an image-only PDF and a PNG are no_text');
ok($states['bin'] === 'unsupported', 'a binary is unsupported');
ok(($rep['ran']['extract_text']['extracted'] ?? 0) >= 5 && !isset($rep['ran']['extract_text']['stub']), 'the pass reports what it did (no stub)');
$t = q('SELECT t.* FROM document_text t JOIN documents d ON d.current_version_id = t.version_id WHERE d.id = :d', ['d' => $ids['pdf']])[0] ?? null;
$pages = $t === null ? [] : json_decode($t['pages'], true);
ok($t !== null && (int) $t['char_count'] > 1000 && count($pages) === 2 && (int) $pages[1]['page_no'] === 2 && (int) $pages[1]['char_from'] > 0, 'the PDF\'s text with two page anchors');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$p2 = q('SELECT * FROM dc_document_page(:v, 2)', ['v' => (int) $t['version_id']])[0] ?? null;
ok($p2 !== null && (int) $p2['page_count'] === 2 && strlen($p2['text']) > 100, 'dc_document_page answers page 2 of 2');
$words = explode(' ', trim(substr($t['text'], 200, 60))); array_shift($words); array_pop($words); $quote = implode(' ', $words);
$ex = q('SELECT * FROM dc_document_excerpt(:v, :q, 200)', ['v' => (int) $t['version_id'], 'q' => $quote])[0] ?? null;
ok($ex !== null && $ex['found'] && str_contains($ex['excerpt'], $quote), 'dc_document_excerpt finds a quote and answers the text around it');
$dt = q('SELECT text FROM document_text t JOIN documents d ON d.current_version_id = t.version_id WHERE d.id = :d', ['d' => $ids['docx']])[0]['text'] ?? '';
ok(str_contains($dt, 'Worker memo') && str_contains($dt, 'Leave policy'), 'the DOCX\'s headings are in its text');
$xt = q('SELECT text FROM document_text t JOIN documents d ON d.current_version_id = t.version_id WHERE d.id = :d', ['d' => $ids['xlsx']])[0]['text'] ?? '';
ok(str_contains($xt, 'Budget') && str_contains($xt, 'travel'), 'the XLSX\'s sheet name and cells are in its text');
$si = q("SELECT tsv @@ to_tsquery('simple', 'termination') AS hit, version_id FROM search_index WHERE document_id = :d", ['d' => $ids['txt']])[0] ?? null;
ok($si !== null && $si['hit'] && (int) $si['version_id'] === (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $ids['txt']]), 'the index row carries the extracted words (the trigger)');
ok((int) one("SELECT count(*) FROM graph_jobs WHERE document_id = :d AND status = 'queued'", ['d' => $ids['txt']]) === 1, 'the graph job is queued for slice 5');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'document.text_extracted' AND document_id = :d AND source = 'cron'", ['d' => $ids['pdf']]) === 1 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'document.text_extracted' AND after::text LIKE '%Plain words%'") === 0, 'document.text_extracted logged (source cron), never the text');
$r = page(actor(26), '/documents/' . $ids['pdf_img']);
ok(str_contains($r['body'], 'No text to read') && str_contains($r['body'], 'image-only'), 'the page says why an image-only PDF has no text');
$r = page(actor(26), '/documents/' . $ids['bin']);
ok(str_contains($r['body'], 'Not a kind we read'), '...and that a binary is not a kind we read');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$r = post_j(27, '/documents/reextract.php', ['document' => $ids['txt']]);
ok($r['code'] === 200 && one('SELECT v.extracted_status FROM document_versions v JOIN documents d ON d.current_version_id = v.id WHERE d.id = :d', ['d' => $ids['txt']]) === 'pending', 'document_reextract queues the version again (manage)');
ok(post_j(26, '/documents/reextract.php', ['document' => $ids['txt']])['code'] === 403, '...a member with edit only: 403');
worker('extract_text');

echo "2. Thumbnails\n";
$rep = worker('thumbnails');
ok(is_file(store() . '/thumbs/' . $ids['png'] . '/' . one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $ids['png']]) . '.jpg'), 'a JPEG thumbnail for the PNG');
$pdfThumb = store() . '/thumbs/' . $ids['pdf'] . '/' . one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $ids['pdf']]) . '.jpg';
ok(is_file($pdfThumb) && max(getimagesize($pdfThumb)[0], getimagesize($pdfThumb)[1]) === 320, 'a 320 px thumbnail for the PDF\'s first page (pdftoppm)');
ok(!is_file(store() . '/thumbs/' . $ids['docx'] . '/' . one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $ids['docx']]) . '.jpg'), 'none for a DOCX');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'thumb.made' AND document_id = :d", ['d' => $ids['png']]) === 1, 'thumb.made logged');
$rep2 = worker('thumbnails');
ok(($rep2['ran']['thumbnails']['made'] ?? 1) === 0, 'a second pass makes nothing more (idempotent)');

echo "3. A zip of the folder\n";
pdo()->exec("INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES ($wf, 'member', 28, 'view', 27) ON CONFLICT DO NOTHING");
pdo()->prepare("INSERT INTO document_access (document_id, principal_kind, principal_id, level, granted_by) VALUES (:d, 'member', 27, 'manage', 27) ON CONFLICT DO NOTHING")->execute(['d' => $ids['bin']]);   // the bin gets rows of its own: Bea no longer sees it
$r = post_j(28, '/folders/export.php', ['folder' => $wf]);
$eid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $eid > 0 && ($r['json']['location'] ?? '') === '/exports?notice=queued', 'export_folder by a viewer: queued, lands on Exports');
$rep = worker('exports');
$e = q('SELECT * FROM exports WHERE id = :id', ['id' => $eid])[0];
ok($e['status'] === 'done' && (int) $e['item_count'] === 7 && is_file(store() . '/' . $e['storage_path']) && str_contains((string) $e['log'], '1 not included'), 'the worker built it: 7 files (the binary Bea may not see is absent), the log says so');
$z = new ZipArchive(); $z->open(store() . '/' . $e['storage_path']); $names = []; for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); } $z->close();
ok(in_array('w-text.pdf', $names, true) && in_array('w-memo.docx', $names, true) && !in_array('w-blob.bin', $names, true), 'the zip holds the right names');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 28 AND kind = 'export_ready'") === 1, 'the maker is told (export_ready)');
$r = page(actor(28), '/exports');
ok($r['code'] === 200 && str_contains($r['body'], 'id="export-row-' . $eid . '"') && str_contains($r['body'], 'id="export-download-' . $eid . '"'), 'the exports screen lists it with Download');
$r = page(actor(28), '/exports/' . $eid);
ok($r['code'] === 200 && preg_match('/^Content-Disposition: attachment; filename="Worker-' . $eid . '\.zip"/mi', $r['headers']) && strlen($r['body']) === (int) $e['byte_size'], 'the download streams the zip as an attachment');
ok(one('SELECT downloaded_at IS NOT NULL FROM exports WHERE id = :id', ['id' => $eid]) && last_event('export.download') !== null, 'downloaded_at set; logged export.download');
ok(page(actor(26), '/exports/' . $eid)['code'] === 404, 'another member cannot download my zip (404)');
$r = post_j(26, '/documents/export.php', ['documents' => $ids['pdf'] . ',' . $ids['txt']]);
$eid2 = (int) $r['json']['record_id'];
worker('exports');
ok((int) one('SELECT item_count FROM exports WHERE id = :id', ['id' => $eid2]) === 2, 'export_documents (a comma list, an agent\'s shape): a zip of two');
pdo()->exec("UPDATE exports SET expires_at = now() - interval '1 minute' WHERE id = $eid");
$rep = worker('exports');
ok(!is_file(store() . '/exports/' . $eid . '.zip') && one('SELECT storage_path IS NULL FROM exports WHERE id = :id', ['id' => $eid]) && ($rep['ran']['exports']['expired'] ?? 0) === 1, 'an expired zip is unlinked and its path cleared');
ok(page(actor(28), '/exports/' . $eid)['code'] === 404, '...and no longer downloads');

echo "4. Abandoned uploads, stale locks\n";
$s = post_j(27, '/upload/start', ['folder_id' => $wf, 'filename' => 'abandoned.bin', 'byte_size' => 10 * 1048576]);
$sid = (int) $s['json']['session_id'];
post_raw(27, '/upload/part?session=' . $sid . '&n=1', str_repeat('a', 8388608));
ok(is_file(store() . '/uploads/' . $sid . '/data') && filesize(store() . '/uploads/' . $sid . '/data') === 8388608, 'a part of an abandoned session is on disk (written at its offset in the session\'s one file)');
pdo()->exec("UPDATE upload_sessions SET expires_at = now() - interval '1 minute' WHERE id = $sid");
$rep = worker('upload_sessions_expire');
ok(($rep['ran']['upload_sessions_expire']['expired'] ?? 0) === 1 && one('SELECT status FROM upload_sessions WHERE id = :s', ['s' => $sid]) === 'expired' && !is_dir(store() . '/uploads/' . $sid), 'the pass expires it and removes the parts');
ok(post_j(27, '/upload/finish', ['session' => $sid])['code'] === 409, 'a finish on an expired session: 409');
post_j(26, '/documents/lock.php', ['document' => $ids['txt']]);
pdo()->exec("UPDATE documents SET lock_expires_at = now() - interval '1 minute' WHERE id = " . $ids['txt']);
$rep = worker('locks_expire');
ok(($rep['ran']['locks_expire']['released'] ?? 0) === 1 && doc_row($ids['txt'])['locked_by'] === null, 'a stale lock is released');
ok((int) one("SELECT count(*) FROM worker_passes") >= 1, 'every pass leaves a worker_passes row');
finish();
