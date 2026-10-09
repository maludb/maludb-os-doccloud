<?php
declare(strict_types=1);

/**
 * What a file IS, decided by its bytes (docs/build-specs/uploads-and-versions.md §3). The filename and the client's MIME type
 * are hints that only choose between text kinds the bytes cannot tell apart (CSV vs plain text vs Markdown); they never
 * make a PDF of an executable or a document of a zip.
 */

/** Executable and container signatures refused outright. */
const DC_EXECUTABLE_MIME = [
    'application/x-executable', 'application/x-dosexec', 'application/x-sharedlib', 'application/x-mach-binary',
    'application/x-pie-executable', 'application/vnd.microsoft.portable-executable', 'application/x-msdownload',
    'application/x-object', 'application/java-archive', 'application/x-elf',
];

/**
 * ['kind' => pdf|docx|pptx|xlsx|html|markdown|text|csv|json|null, 'error' => null|executable|svg|unsupported|legacy_office].
 * Reads the first 64 KB of $path (and, for a zip, its directory; for a would-be JSON file, the whole file).
 */
function dc_detect_kind(string $path, string $filename = '', ?string $mime = null): array
{
    $fh = @fopen($path, 'rb');
    $head = $fh === false ? '' : (string) fread($fh, 65536);
    if ($fh !== false) {
        fclose($fh);
    }
    if ($head === '') {
        return ['kind' => 'text', 'error' => null];
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    // executables first, whatever they are called
    if (str_starts_with($head, "MZ") || str_starts_with($head, "\x7FELF") || str_starts_with($head, "#!")
        || in_array(substr($head, 0, 4), ["\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", "\xCA\xFE\xBA\xBE", "\x00asm"], true)) {
        return ['kind' => null, 'error' => 'executable'];
    }
    $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($head);
    if (in_array($sniffed, DC_EXECUTABLE_MIME, true)) {
        return ['kind' => null, 'error' => 'executable'];
    }
    if (str_contains(substr($head, 0, 1024), '%PDF-')) {
        return ['kind' => 'pdf', 'error' => null];
    }
    if (str_starts_with($head, "PK\x03\x04")) {
        $kind = dc_detect_ooxml($path);
        return ['kind' => $kind, 'error' => $kind === null ? 'unsupported' : null];
    }
    if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        return ['kind' => null, 'error' => 'legacy_office'];
    }
    // text from here: a UTF-16 file is text; otherwise more than a stray NUL or two means binary
    $isUtf16 = str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF");
    if (!$isUtf16 && substr_count($head, "\0") > max(2, intdiv(strlen($head), 1000))) {
        return ['kind' => null, 'error' => 'unsupported'];
    }
    if (!$isUtf16 && preg_match('/^(image|audio|video|font)\//', $sniffed) && $sniffed !== 'image/svg+xml') {
        return ['kind' => null, 'error' => 'unsupported'];
    }
    $t = $isUtf16 ? dc_text_clean($head) : $head;
    $t = ltrim(str_starts_with($t, "\xEF\xBB\xBF") ? substr($t, 3) : $t);
    $lead = strtolower(substr($t, 0, 2048));
    // SVG (active content, never text) — by its root element, whatever precedes it
    if ($sniffed === 'image/svg+xml' || preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!doctype svg[^>]*>\s*)?<svg[\s>]/s', $lead)) {
        return ['kind' => null, 'error' => 'svg'];
    }
    if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<!doctype html/s', $lead) || preg_match('/^(<!--.*?-->\s*)*<(html|head|body)[\s>]/s', $lead)) {
        return ['kind' => 'html', 'error' => null];
    }
    $first = $t[0] ?? '';
    if ($first === '{' || $first === '[') {
        $all = (string) file_get_contents($path);
        if (json_decode($isUtf16 ? dc_text_clean($all) : dc_text_strip_bom($all), true, 512) !== null) {
            return ['kind' => 'json', 'error' => null];
        }
    }
    if (preg_match('/<(script|iframe|object|embed)[\s>]/i', $lead) && preg_match('/<\/(p|div|a|span|table)>/i', $lead) && !in_array($ext, ['md', 'markdown'], true)) {
        return ['kind' => 'html', 'error' => null];
    }
    if (dc_looks_like_csv($t)) {
        return ['kind' => 'csv', 'error' => null];
    }
    if (preg_match('/^ {0,3}#{1,6}[ \t]+\S/m', $t) || preg_match('/^\S.*\n {0,3}(=+|-{3,})[ \t]*$/m', $t) || preg_match('/^ {0,3}(```|~~~)/m', $t)) {
        return ['kind' => 'markdown', 'error' => null];
    }
    // the name and the client's type only choose between text kinds the bytes left open
    $firstLine = strtok($t, "\n") ?: '';
    if ((in_array($ext, ['csv', 'tsv'], true) || in_array($mime, ['text/csv', 'text/tab-separated-values'], true)) && preg_match('/[,\t;]/', $firstLine)) {
        return ['kind' => 'csv', 'error' => null];
    }
    if (in_array($ext, ['md', 'markdown', 'mdown'], true) || $mime === 'text/markdown') {
        return ['kind' => 'markdown', 'error' => null];
    }
    return ['kind' => 'text', 'error' => null];
}

/** Which Office Open XML document a zip is, by its inner parts; null when it is none of the three. */
function dc_detect_ooxml(string $path): ?string
{
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::RDONLY) !== true) {
        return null;
    }
    $kind = null;
    if ($z->locateName('[Content_Types].xml') !== false) {
        if ($z->locateName('word/document.xml') !== false) {
            $kind = 'docx';
        } elseif ($z->locateName('ppt/presentation.xml') !== false) {
            $kind = 'pptx';
        } elseif ($z->locateName('xl/workbook.xml') !== false) {
            $kind = 'xlsx';
        }
    }
    $z->close();
    return $kind;
}

/** Comma-, tab- or semicolon-separated: at least three lines, the same field count (≥ 2, quotes honoured) on nine in ten. */
function dc_looks_like_csv(string $t): bool
{
    $lines = array_slice(array_values(array_filter(explode("\n", str_replace("\r", '', substr($t, 0, 8192))), static fn($l) => trim($l) !== '')), 0, 20);
    if (count($lines) < 3) {
        return false;
    }
    array_pop($lines); // the last may be cut by the 8 KB window
    foreach ([',', "\t", ';'] as $d) {
        $counts = array_map(static fn($l) => count(str_getcsv($l, $d, '"', '')), $lines);
        $c = $counts[0];
        if ($c < 2) {
            continue;
        }
        $same = count(array_filter($counts, static fn($x) => $x === $c));
        if ($same / count($counts) >= 0.9 && max(array_map('strlen', $lines)) < 2000) {
            return true;
        }
    }
    return false;
}
