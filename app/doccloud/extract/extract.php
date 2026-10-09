<?php
declare(strict_types=1);

/**
 * The text extractor's front door (docs/doccloud-design.md §6.1; the build spec docs/build-specs/uploads-and-versions.md).
 * require_once this file and the whole library is loaded. Knowledge's extractor (/srv/apps/knowledge/app/knowledge/extract/,
 * 2026-10-05) copied as CODE and renamed kn_ → dc_ — code is shared by copying, schema by the canonical table — with its
 * sectioner and URL fetcher left behind: DocCloud cuts nothing into sections and fetches no page.
 *
 * Deterministic, never a model. ONE entry for the worker:
 *
 *   dc_extract_text(string $path, string $mime, string $filename = ''): array{
 *       status: 'ready'|'no_text'|'unsupported'|'failed',
 *       text: string,                                   -- clean UTF-8 (text.php), '' unless ready
 *       pages: list<array{page_no:int, char_from:int, char_to:int}>,   -- CHARACTER offsets, char_to exclusive (PDF only)
 *       extractor: string,                              -- pdftotext | ooxml | html | markdown | text | csv | json | image | none
 *       char_count: int,
 *       error: ?string,                                 -- the reason for no_text / unsupported / failed (never user-facing words)
 *       note: ?string,                                  -- 'truncated:<bytes>' when the text was cut at DC_MAX_TEXT_BYTES
 *       kind: ?string, title: ?string, language: ?string, blocks: list   -- what the reader learnt (headings, slides, sheets)
 *   }
 *
 * Status, by what the bytes are (the kind is decided by the bytes — detect.php — never by the name or the client's type):
 *   ready        text came out (PDF with a text layer per page; DOCX/PPTX/XLSX from the zip; HTML read; Markdown and plain text
 *                verbatim; CSV and JSON as rows of text)
 *   no_text      a PDF with no text layer; an image (its EXIF ImageDescription / title kept as the text when present, status
 *                still no_text — nothing to search); an empty file
 *   unsupported  audio, video, fonts, archives, legacy Office (.doc/.xls/.ppt), executables, SVG, unknown binaries: stored,
 *                never read
 *   failed       the extractor could not do its job: unreadable file, too_large (over DC_MAX_BYTES, 100 MB, for a kind read
 *                into memory), zip_bomb, unsafe_xml, pdf_unreadable, pdf_timeout, pdftotext_missing
 *
 * Caps: DC_MAX_BYTES (100 MB) — a text, HTML, JSON or Office file larger than this is `failed:too_large` (a PDF streams through
 * pdftotext and is not held in memory, so it has no such cap); DC_MAX_TEXT_BYTES (5 MB) — the text is cut there, at a character
 * boundary, and `note` says so; DC_PDFTOTEXT_TIMEOUT (60 s) — a PDF that takes longer is `failed:pdf_timeout`. Office and HTML
 * parsing is in-process and bounded by the file cap; the worker that calls this runs one file at a time under its own lock.
 */

require_once __DIR__ . '/text.php';
require_once __DIR__ . '/detect.php';
require_once __DIR__ . '/plain.php';
require_once __DIR__ . '/html.php';
require_once __DIR__ . '/ooxml.php';
require_once __DIR__ . '/pdf.php';

/** The largest text kept per version (DC_MAX_TEXT_BYTES, 5 MB by default); design §6.1 "capped at 5 MB of text per version". */
function dc_max_text_bytes(): int
{
    return dc_env_int('DC_MAX_TEXT_BYTES', 5242880);
}

/** The shape every extractor answers, refusals included. */
function dc_extract_result(string $status, string $extractor, array $read = [], ?string $error = null, ?string $kind = null): array
{
    $text = $status === 'ready' || $status === 'no_text' ? (string) ($read['text'] ?? '') : '';
    $note = null;
    $pages = [];
    foreach ($read['pages'] ?? [] as $p) {
        $pages[] = ['page_no' => (int) $p[0], 'char_from' => (int) $p[1], 'char_to' => (int) $p[2]];
    }
    $max = dc_max_text_bytes();
    if (strlen($text) > $max) {
        $cut = mb_strcut($text, 0, $max, 'UTF-8');
        $note = 'truncated:' . strlen($cut);
        $text = $cut;
        $chars = mb_strlen($text, 'UTF-8');
        $pages = array_values(array_filter(array_map(static function (array $p) use ($chars): ?array {
            if ($p['char_from'] >= $chars) {
                return null;
            }
            $p['char_to'] = min($p['char_to'], $chars);
            return $p;
        }, $pages)));
    }
    return [
        'status' => $status,
        'text' => $text,
        'pages' => $status === 'ready' ? $pages : [],
        'extractor' => $extractor,
        'char_count' => $status === 'ready' ? mb_strlen($text, 'UTF-8') : 0,
        'error' => $error,
        'note' => $note,
        'kind' => $kind,
        'title' => $read['title'] ?? null,
        'language' => $read['language'] ?? null,
        'blocks' => $status === 'ready' ? ($read['blocks'] ?? []) : [],
    ];
}

/** Which of the four statuses a detector refusal is. */
function dc_extract_refusal_status(string $error): string
{
    return match ($error) {
        'executable', 'svg', 'unsupported', 'legacy_office' => 'unsupported',
        default => 'failed',
    };
}

/** An image's EXIF title or description, when PHP's exif extension can read one; '' otherwise. Never throws. */
function dc_image_caption(string $path, string $sniffed): string
{
    if (!function_exists('exif_read_data') || !in_array($sniffed, ['image/jpeg', 'image/tiff'], true)) {
        return '';
    }
    try {
        $exif = @exif_read_data($path, 'IFD0', true);
    } catch (Throwable) {
        return '';
    }
    if (!is_array($exif)) {
        return '';
    }
    $parts = [];
    foreach (['Title', 'ImageDescription', 'UserComment'] as $k) {
        $v = $exif['IFD0'][$k] ?? $exif['COMPUTED'][$k] ?? null;
        if (is_string($v) && trim($v) !== '') {
            $parts[] = dc_one_line(dc_text_clean($v));
        }
    }
    return implode("\n", array_unique(array_filter($parts)));
}

/**
 * A file on disk → its text and page anchors. $mime is the type the store decided with finfo (a hint between text kinds only);
 * $filename the stored name (the same hint; defaults to the path's basename).
 */
function dc_extract_text(string $path, string $mime, string $filename = ''): array
{
    if ($filename === '') {
        $filename = basename($path);
    }
    if (!is_file($path) || !is_readable($path)) {
        return dc_extract_result('failed', 'none', [], 'unreadable');
    }
    $size = (int) filesize($path);
    if ($size === 0) {
        return dc_extract_result('no_text', 'none', ['text' => ''], 'empty', 'text');
    }
    // images, audio, video, fonts: decided by the bytes before the text detector sees them
    $fh = @fopen($path, 'rb');
    $head = $fh === false ? '' : (string) fread($fh, 65536);
    if ($fh !== false) {
        fclose($fh);
    }
    $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($head);
    if (str_starts_with($sniffed, 'image/') && $sniffed !== 'image/svg+xml') {
        return dc_extract_result('no_text', 'image', ['text' => dc_image_caption($path, $sniffed)], 'image', 'image');
    }
    if (preg_match('#^(audio|video|font)/#', $sniffed)) {
        return dc_extract_result('unsupported', 'none', [], explode('/', $sniffed)[0], explode('/', $sniffed)[0]);
    }
    $det = dc_detect_kind($path, $filename, $mime);
    if ($det['error'] !== null) {
        return dc_extract_result(dc_extract_refusal_status($det['error']), 'none', [], $det['error']);
    }
    $kind = $det['kind'];
    if ($kind !== 'pdf' && $size > dc_max_bytes()) {
        return dc_extract_result('failed', 'none', [], 'too_large', $kind);
    }
    $title = dc_title_from_filename($filename);
    try {
        switch ($kind) {
            case 'pdf':
                $read = dc_read_pdf($path);
                if ($read['error'] !== null) {
                    return dc_extract_result('failed', 'pdftotext', [], $read['error'], 'pdf');
                }
                $read['title'] = $title;
                return dc_extract_result(trim($read['text']) === '' ? 'no_text' : 'ready', 'pdftotext', $read, trim($read['text']) === '' ? 'no_text_layer' : null, 'pdf');
            case 'docx':
            case 'pptx':
            case 'xlsx':
                [$z, $err] = dc_zip_open($path);
                if ($z === null) {
                    return dc_extract_result('failed', 'ooxml', [], $err, $kind);
                }
                try {
                    $read = match ($kind) {
                        'docx' => dc_read_docx($z),
                        'pptx' => dc_read_pptx($z),
                        'xlsx' => dc_read_xlsx($z),
                    };
                } finally {
                    $z->close();
                }
                $read['title'] = $title;
                return dc_extract_result(trim($read['text']) === '' ? 'no_text' : 'ready', 'ooxml', $read, trim($read['text']) === '' ? 'empty' : null, $kind);
        }
        $raw = (string) file_get_contents($path);
        $read = match ($kind) {
            'html' => dc_read_html($raw),
            'markdown' => dc_read_markdown($raw),
            'csv' => dc_read_csv($raw, $filename),
            'json' => dc_read_json($raw),
            default => dc_read_plain($raw),
        };
        if ($kind !== 'html' || ($read['title'] ?? null) === null) {
            $read['title'] = $title;
        }
        $extractor = $kind === 'html' ? 'html' : ($kind === 'markdown' ? 'markdown' : ($kind === 'csv' ? 'csv' : ($kind === 'json' ? 'json' : 'text')));
        return dc_extract_result(trim($read['text']) === '' ? 'no_text' : 'ready', $extractor, $read, trim($read['text']) === '' ? 'empty' : null, $kind);
    } catch (RuntimeException $e) {
        $code = in_array($e->getMessage(), ['zip_bomb', 'unsafe_xml', 'unreadable'], true) ? $e->getMessage() : 'unreadable';
        return dc_extract_result('failed', 'none', [], $code, $kind);
    } catch (ValueError | DOMException) {
        return dc_extract_result('failed', 'none', [], 'unreadable', $kind);
    }
}

/** "Staff_Handbook-2026.docx" → "Staff Handbook 2026". */
function dc_title_from_filename(string $filename): ?string
{
    $base = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
    $t = dc_one_line(str_replace(['_', '-'], ' ', $base));
    return $t === '' ? null : $t;
}
