<?php
declare(strict_types=1);

/**
 * The extraction proof (docs/doccloud-design.md §10 Phase 0 "the extractor against fixtures"): app/doccloud/extract/ against
 * synthetic fixtures generated here (fixtures.php — Knowledge's generators, copied; no third-party text). No database, no
 * network. Run: php tests/extract/run.php   (exit 0 = every check passed; a check that needs pdftotext is SKIPPED, not failed,
 * when the binary is absent).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '1024M');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require_once __DIR__ . '/../../app/doccloud/extract/extract.php';
require_once __DIR__ . '/fixtures.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['skip'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['pass']++;
        echo "PASS  $name\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  $name" . ($detail === '' ? '' : "  — $detail") . "\n";
    }
}
function skip(string $name, string $why): void
{
    $GLOBALS['skip']++;
    echo "SKIP  $name  — $why\n";
}
function group(string $g): void
{
    echo "\n== $g\n";
}
function shape_ok(array $r): bool
{
    return in_array($r['status'], ['ready', 'no_text', 'unsupported', 'failed'], true) && is_string($r['text']) && is_array($r['pages'])
        && is_string($r['extractor']) && is_int($r['char_count']) && ($r['error'] === null || is_string($r['error']))
        && ($r['status'] !== 'ready' || $r['char_count'] === mb_strlen($r['text'], 'UTF-8'))
        && ($r['status'] === 'ready' || ($r['char_count'] === 0 && $r['pages'] === []));
}
$pdftotext = getenv('DC_PDFTOTEXT') ?: '/usr/bin/pdftotext';
$havePdf = is_executable($pdftotext);

try {
    group('plain text, Markdown, CSV, JSON, HTML');
    $txt = fx_write('notes.txt', fx_para(1, 1200) . "\n\n" . fx_para(2, 800) . "\n");
    $r = dc_extract_text($txt, 'text/plain');
    check('a text file is ready, extractor text, char_count > 0', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'text' && $r['char_count'] > 1500, json_encode([$r['status'], $r['extractor'], $r['char_count']]));
    check('...its title comes from the file name', $r['title'] === 'notes', (string) $r['title']);
    $md = fx_write('readme.md', "# Alpha Heading\n\n" . fx_para(3, 400) . "\n\n## Beta Heading\n\n" . fx_para(4, 400) . "\n");
    $r = dc_extract_text($md, 'text/markdown');
    check('Markdown is ready, extractor markdown, headings kept in the text', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'markdown' && str_contains($r['text'], 'Alpha Heading') && str_contains($r['text'], 'Beta Heading'), $r['status'] . '/' . $r['extractor']);
    check('...and the headings are reported as blocks', count($r['blocks']) >= 2 && $r['blocks'][0]['heading'] === 'Alpha Heading', json_encode($r['blocks']));
    $csvRows = ["name,department,balance"];
    for ($i = 0; $i < 12; $i++) {
        $csvRows[] = "Person $i,Dept " . ($i % 3) . "," . ($i * 7);
    }
    $csv = fx_write('balances.csv', implode("\n", $csvRows) . "\n");
    $r = dc_extract_text($csv, 'text/csv');
    check('CSV is ready as rows of text, extractor csv', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'csv' && str_contains($r['text'], 'Person 7') && str_contains($r['text'], 'department'), $r['status'] . '/' . $r['extractor']);
    $json = fx_write('config.json', json_encode(['title' => 'Quarterly plan', 'items' => [['name' => 'Travel', 'amount' => 120], ['name' => 'Training', 'amount' => 80]]]));
    $r = dc_extract_text($json, 'application/json');
    check('JSON is ready as rows of text, extractor json', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'json' && str_contains($r['text'], 'Quarterly plan') && str_contains($r['text'], 'Training'), $r['status'] . '/' . $r['extractor']);
    $html = fx_write('page.html', "<!DOCTYPE html><html lang=\"en\"><head><title>Travel Policy</title><script>var x = 'SCRIPTTEXT';</script><style>.a{}</style></head><body><nav>NAVTEXT</nav><main><h1>Travel Policy</h1><p>" . fx_para(5, 300) . "</p><h2>Claims</h2><p>" . fx_para(6, 300) . "</p><table><tr><td>Cell A</td><td>Cell B</td></tr></table></main><footer>FOOTERTEXT</footer></body></html>");
    $r = dc_extract_text($html, 'text/html');
    check('HTML is read: title from <title>, language, headings, table cells; scripts, nav and footer dropped', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'html' && $r['title'] === 'Travel Policy' && $r['language'] === 'en'
        && str_contains($r['text'], 'Claims') && str_contains($r['text'], 'Cell A') && !str_contains($r['text'], 'SCRIPTTEXT') && !str_contains($r['text'], 'NAVTEXT') && !str_contains($r['text'], 'FOOTERTEXT'), json_encode([$r['status'], $r['title'], $r['language']]));
    $empty = fx_write('empty.txt', '');
    $r = dc_extract_text($empty, 'text/plain');
    check('an empty file is no_text (error empty), never ready', shape_ok($r) && $r['status'] === 'no_text' && $r['error'] === 'empty', $r['status']);
    $utf16 = fx_write('utf16.txt', "\xFF\xFE" . mb_convert_encoding("Héllo wörld — café naïve\n" . fx_para(9, 200), 'UTF-16LE', 'UTF-8'));
    $r = dc_extract_text($utf16, 'text/plain');
    check('UTF-16 text is read as clean UTF-8 (NFC)', $r['status'] === 'ready' && str_contains($r['text'], 'Héllo wörld') && str_contains($r['text'], 'café'), mb_substr($r['text'], 0, 40));

    group('Office Open XML');
    $docxBody = [['h1', 'Staff Handbook'], ['p', fx_para(10, 500)], ['h2', 'Leave Carry Over'], ['p', fx_para(11, 600)], ['h2', 'Travel Claims'], ['p', fx_para(12, 400)]];
    $docx = fx_docx('Staff_Handbook-2026.docx', $docxBody);
    $r = dc_extract_text($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    check('DOCX is ready, extractor ooxml, the headings present in the text', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'ooxml' && $r['kind'] === 'docx'
        && str_contains($r['text'], 'Staff Handbook') && str_contains($r['text'], 'Leave Carry Over') && str_contains($r['text'], 'Travel Claims') && $r['char_count'] > 1400, json_encode([$r['status'], $r['extractor'], $r['kind'], $r['char_count']]));
    check('...heading styles become blocks with levels', count($r['blocks']) === 3 && $r['blocks'][0]['level'] === 1 && $r['blocks'][1]['level'] === 2, json_encode($r['blocks']));
    check('...the title is the file name, humanised', $r['title'] === 'Staff Handbook 2026', (string) $r['title']);
    $pptx = fx_pptx('deck.pptx', [['title' => 'Quarter Review', 'body' => [fx_sentence(20), fx_sentence(21)], 'notes' => 'Speaker note alpha'], ['title' => 'Budget Outlook', 'body' => [fx_sentence(22)], 'notes' => null], ['title' => null, 'body' => [fx_sentence(23)], 'notes' => null]]);
    $r = dc_extract_text($pptx, 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
    check('PPTX is ready, slide titles in the text', shape_ok($r) && $r['status'] === 'ready' && $r['kind'] === 'pptx' && str_contains($r['text'], 'Quarter Review') && str_contains($r['text'], 'Budget Outlook'), json_encode([$r['status'], $r['kind']]));
    $rows = [['Person', 'Days', 'Note']];
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = ["Person $i", $i * 2, 'ok'];
    }
    $xlsx = fx_xlsx('leave.xlsx', ['Balances' => $rows, 'Notes' => [['Topic', 'Text'], ['Policy', '=inline:' . fx_para(13, 120)], ['Gap', null]]]);
    $r = dc_extract_text($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    check('XLSX is ready, the sheet names in the text, cells as rows', shape_ok($r) && $r['status'] === 'ready' && $r['kind'] === 'xlsx' && str_contains($r['text'], 'Balances') && str_contains($r['text'], 'Notes') && str_contains($r['text'], 'Person 7'), json_encode([$r['status'], $r['kind']]));
    check('...sheets are reported as blocks', count(array_filter($r['blocks'], static fn($b) => $b['type'] === 'sheet')) === 2, json_encode($r['blocks']));
    $xxe = fx_docx('xxe.docx', [['p', 'x']]);
    $z = new ZipArchive();
    $z->open($xxe);
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>');
    $z->close();
    $r = dc_extract_text($xxe, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    check('a DOCX carrying an external entity is failed:unsafe_xml (or read with the entity ignored), never resolved', ($r['status'] === 'failed' && $r['error'] === 'unsafe_xml') || ($r['status'] !== 'ready' || !str_contains($r['text'], 'root:')), json_encode([$r['status'], $r['error']]));

    group('PDF');
    $pages = [fx_wrap('Handbook ' . fx_para(14, 900)), fx_wrap('Second page ' . fx_para(15, 900)), fx_wrap('Third page ' . fx_para(16, 500))];
    $pdf = fx_write('handbook.pdf', fx_pdf($pages));
    if ($havePdf) {
        $r = dc_extract_text($pdf, 'application/pdf');
        check('a PDF with a text layer is ready, extractor pdftotext', shape_ok($r) && $r['status'] === 'ready' && $r['extractor'] === 'pdftotext' && str_contains($r['text'], 'Second page'), json_encode([$r['status'], $r['extractor'], $r['error']]));
        check('...with three page anchors that tile the text in order', count($r['pages']) === 3 && $r['pages'][0]['page_no'] === 1 && $r['pages'][2]['page_no'] === 3 && $r['pages'][0]['char_from'] === 0
            && $r['pages'][0]['char_to'] <= $r['pages'][1]['char_from'] && $r['pages'][1]['char_to'] <= $r['pages'][2]['char_from'] && $r['pages'][2]['char_to'] <= $r['char_count'], json_encode($r['pages']));
        $p2 = mb_substr($r['text'], $r['pages'][1]['char_from'], $r['pages'][1]['char_to'] - $r['pages'][1]['char_from'], 'UTF-8');
        check('...and page 2\'s range holds page 2\'s words', str_contains($p2, 'Second page') && !str_contains($p2, 'Third page'), mb_substr($p2, 0, 60));
        $scan = fx_write('scan.pdf', fx_pdf([[], [], []], true));
        $r = dc_extract_text($scan, 'application/pdf');
        check('a PDF with no text layer is no_text (error no_text_layer)', shape_ok($r) && $r['status'] === 'no_text' && $r['error'] === 'no_text_layer', json_encode([$r['status'], $r['error']]));
        $r = dc_extract_text(fx_write('broken.pdf', "%PDF-1.4\ngarbage\n"), 'application/pdf');
        check('a broken PDF is failed or no_text, never ready', in_array($r['status'], ['failed', 'no_text'], true), $r['status']);
    } else {
        skip('a PDF with a text layer is ready', "pdftotext not found at $pdftotext (set DC_PDFTOTEXT)");
        skip('three page anchors', 'pdftotext absent');
        skip('page 2 words', 'pdftotext absent');
        skip('a PDF with no text layer is no_text', 'pdftotext absent');
        skip('a broken PDF', 'pdftotext absent');
    }
    $r = dc_extract_text($pdf, 'application/pdf', 'handbook.pdf');
    check('the extractor says pdftotext_missing (failed) rather than crashing when the binary is misnamed', (static function () use ($pdf): bool {
        putenv('DC_PDFTOTEXT=/nonexistent/pdftotext');
        $x = dc_extract_text($pdf, 'application/pdf');
        putenv('DC_PDFTOTEXT');
        return $x['status'] === 'failed' && $x['error'] === 'pdftotext_missing';
    })(), '');

    group('what is stored but never read');
    $png = fx_write('logo.png', "\x89PNG\r\n\x1A\n" . "\x00\x00\x00\rIHDR" . "\x00\x00\x00\x08\x00\x00\x00\x08\x08\x02\x00\x00\x00" . str_repeat("\x00", 64));
    $r = dc_extract_text($png, 'image/png');
    check('an image is no_text, extractor image', shape_ok($r) && $r['status'] === 'no_text' && $r['extractor'] === 'image' && $r['error'] === 'image', json_encode([$r['status'], $r['extractor']]));
    $exe = fx_write('setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\0", 300));
    $r = dc_extract_text($exe, 'application/octet-stream');
    check('an executable is unsupported:executable', $r['status'] === 'unsupported' && $r['error'] === 'executable', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_write('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\0", 300)), 'application/pdf');
    check('an executable named .pdf is still unsupported:executable (the bytes decide)', $r['status'] === 'unsupported' && $r['error'] === 'executable', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_write('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'image/svg+xml');
    check('an SVG is unsupported:svg', $r['status'] === 'unsupported' && $r['error'] === 'svg', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_write('old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 600)), 'application/msword');
    check('a legacy Office file is unsupported:legacy_office', $r['status'] === 'unsupported' && $r['error'] === 'legacy_office', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_write('song.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 200)), 'audio/mpeg');
    check('audio is unsupported:audio', $r['status'] === 'unsupported' && $r['error'] === 'audio', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_write('blob.bin', random_bytes(4000)), 'application/octet-stream');
    check('an unknown binary is unsupported', $r['status'] === 'unsupported', json_encode([$r['status'], $r['error']]));
    $r = dc_extract_text(fx_dir() . '/does-not-exist.txt', 'text/plain');
    check('a missing file is failed:unreadable', $r['status'] === 'failed' && $r['error'] === 'unreadable', json_encode([$r['status'], $r['error']]));

    group('the caps');
    putenv('DC_MAX_TEXT_BYTES');
    $full = dc_extract_text($txt, 'text/plain');
    putenv('DC_MAX_TEXT_BYTES=1000');
    $r = dc_extract_text($txt, 'text/plain');
    check('text over DC_MAX_TEXT_BYTES is cut at a character boundary with a note, status still ready', $r['status'] === 'ready' && strlen($r['text']) <= 1000 && strlen($r['text']) >= 990 && str_starts_with($full['text'], $r['text']) && str_starts_with((string) $r['note'], 'truncated:') && mb_check_encoding($r['text'], 'UTF-8') && $r['char_count'] === mb_strlen($r['text'], 'UTF-8'), json_encode([$r['note'], strlen($r['text'])]));
    if ($havePdf) {
        $r = dc_extract_text($pdf, 'application/pdf');
        check('...and the page anchors are trimmed to the kept text', str_starts_with((string) $r['note'], 'truncated:') && count($r['pages']) >= 1 && count($r['pages']) < 3 && end($r['pages'])['char_to'] <= $r['char_count'], json_encode($r['pages']));
    } else {
        skip('page anchors trimmed under the text cap', 'pdftotext absent');
    }
    putenv('DC_MAX_TEXT_BYTES');
    putenv('DC_MAX_BYTES=1000');
    $r = dc_extract_text($txt, 'text/plain');
    check('a text file over DC_MAX_BYTES is failed:too_large', $r['status'] === 'failed' && $r['error'] === 'too_large', json_encode([$r['status'], $r['error']]));
    putenv('DC_MAX_BYTES');
    $r = dc_extract_text($txt, 'text/plain');
    check('...and reads again when the cap is back', $r['status'] === 'ready', $r['status']);
} finally {
    fx_cleanup();
}

echo "\n== {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed, {$GLOBALS['skip']} skipped\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
