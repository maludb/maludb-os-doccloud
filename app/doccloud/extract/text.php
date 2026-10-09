<?php
declare(strict_types=1);

/**
 * Text hygiene and the text builder every reader shares (docs/build-specs/uploads-and-versions.md §2).
 *
 * Every piece of text a reader emits goes through dc_text_clean() BEFORE it is placed, so the final text is never
 * rewritten afterwards and every offset stays honest. Offsets are tracked in BYTES while building and turned into
 * CHARACTER (code point) offsets once, by dc_tb_finish(): the extracted array a caller sees speaks characters only.
 */

/** An integer from the environment, or the default when unset, empty or not a positive integer. */
function dc_env_int(string $name, int $default): int
{
    $v = getenv($name);
    if ($v === false || $v === '' || !ctype_digit($v) || (int) $v <= 0) {
        return $default;
    }
    return (int) $v;
}

/** The largest file or page the extractor reads (DC_MAX_BYTES, 100 MB by default). */
function dc_max_bytes(): int
{
    return dc_env_int('DC_MAX_BYTES', 104857600);
}

/**
 * Any byte string → clean UTF-8: a BOM dropped (UTF-16 by its BOM converted), invalid UTF-8 read as Windows-1252,
 * line endings \n (U+2028/2029 too), NUL, C0 (except \t \n) and C1 controls and stray BOMs removed, trailing blanks of
 * each line trimmed, three or more line breaks collapsed to one blank line, NFC.
 */
function dc_text_clean(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
        $s = (string) mb_convert_encoding(substr($s, 2), 'UTF-8', str_starts_with($s, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    } elseif (str_starts_with($s, "\xEF\xBB\xBF")) {
        $s = substr($s, 3);
    }
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    $s = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $s);
    $s = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|[\x{80}-\x{9F}]|\x{FEFF}/u', '', $s);
    $s = (string) preg_replace('/[ \t]+$/m', '', $s);
    $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
    return dc_nfc($s);
}

/** A UTF-8 byte-order mark removed, nothing else touched. */
function dc_text_strip_bom(string $s): string
{
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

/** Collapse a cleaned string onto one line (a heading, a cell, a title). */
function dc_one_line(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', dc_text_clean($s)));
}

/** Unicode NFC — PHP's Normalizer when intl is loaded, else the generated tables (nfc_data.php). */
function dc_nfc(string $s): string
{
    if (!preg_match('/[^\x00-\x7F]/', $s)) {
        return $s;
    }
    if (class_exists('Normalizer')) {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        return is_string($n) ? $n : $s;
    }
    // ASCII followed by ASCII is always stable, so only a run of non-ASCII and the character before it can change.
    return (string) preg_replace_callback('/.?[^\x00-\x7F]+/su', static fn(array $m): string => dc_nfc_run($m[0]), $s);
}

/** NFC of one run, by the book: full canonical decomposition, canonical ordering, canonical composition. */
function dc_nfc_run(string $run): string
{
    static $t = null;
    $t ??= require __DIR__ . '/nfc_data.php';
    $SB = 0xAC00; $LB = 0x1100; $VB = 0x1161; $TB = 0x11A7; $LC = 19; $VC = 21; $TC = 28; $NC = $VC * $TC; $SC = $LC * $NC;
    $cps = [];
    foreach (mb_str_split($run, 1, 'UTF-8') as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp >= $SB && $cp < $SB + $SC) {
            $si = $cp - $SB;
            $cps[] = $LB + intdiv($si, $NC);
            $cps[] = $VB + intdiv($si % $NC, $TC);
            if ($si % $TC !== 0) {
                $cps[] = $TB + $si % $TC;
            }
        } elseif (isset($t['d'][$cp])) {
            array_push($cps, ...$t['d'][$cp]);
        } else {
            $cps[] = $cp;
        }
    }
    $n = count($cps);
    // canonical ordering: a stable sort of each run of non-starters by combining class
    for ($i = 0; $i < $n; $i++) {
        if (($t['c'][$cps[$i]] ?? 0) === 0) {
            continue;
        }
        $j = $i;
        while ($j < $n && ($t['c'][$cps[$j]] ?? 0) !== 0) {
            $j++;
        }
        if ($j - $i > 1) {
            $seg = array_slice($cps, $i, $j - $i);
            $keyed = [];
            foreach ($seg as $k => $c) {
                $keyed[] = [$t['c'][$c], $k, $c];
            }
            usort($keyed, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
            foreach ($keyed as $k => $row) {
                $cps[$i + $k] = $row[2];
            }
        }
        $i = $j - 1;
    }
    // canonical composition
    $out = [];
    $starter = -1;
    $lastClass = -1;
    foreach ($cps as $cp) {
        $cc = $t['c'][$cp] ?? 0;
        if ($starter >= 0) {
            $s = $out[$starter];
            $composite = null;
            if ($s >= $LB && $s < $LB + $LC && $cp >= $VB && $cp < $VB + $VC && $lastClass === -1) {
                $composite = $SB + (($s - $LB) * $VC + ($cp - $VB)) * $TC;
            } elseif ($s >= $SB && $s < $SB + $SC && ($s - $SB) % $TC === 0 && $cp > $TB && $cp < $TB + $TC && $lastClass === -1) {
                $composite = $s + ($cp - $TB);
            } elseif ($lastClass === -1 || ($lastClass !== 0 && $lastClass < $cc)) {
                $composite = $t['p'][$s * 0x110000 + $cp] ?? null;
            }
            if ($composite !== null) {
                $out[$starter] = $composite;
                continue;
            }
        }
        if ($cc === 0) {
            $starter = count($out);
            $lastClass = -1;
        } else {
            $lastClass = $cc;
        }
        $out[] = $cp;
    }
    $r = '';
    foreach ($out as $cp) {
        $r .= mb_chr($cp, 'UTF-8');
    }
    return $r;
}

// ---- the builder -------------------------------------------------------------------------------------------------

/** A fresh builder: the text, the structure hints and the pages, all in bytes until dc_tb_finish(). */
function dc_tb_new(): array
{
    return ['text' => '', 'blocks' => [], 'pages' => [], 'page_open' => null];
}

/** Start a new paragraph-level piece: separate it from what came before by one blank line. */
function dc_tb_sep(array &$tb): void
{
    if ($tb['text'] !== '' && !str_ends_with($tb['text'], "\n\n")) {
        $tb['text'] .= str_ends_with($tb['text'], "\n") ? "\n" : "\n\n";
    }
}

/** Append a paragraph (cleaned here; an empty one is skipped). Lines inside it are kept. */
function dc_tb_para(array &$tb, string $text): void
{
    $text = trim(dc_text_clean($text), "\n");
    if (trim($text) === '') {
        return;
    }
    dc_tb_sep($tb);
    $tb['text'] .= $text;
}

/** Append consecutive lines (table rows, sheet rows) as one paragraph, one line each. */
function dc_tb_lines(array &$tb, array $lines): void
{
    $clean = [];
    foreach ($lines as $l) {
        $l = dc_one_line((string) $l);
        if ($l !== '') {
            $clean[] = $l;
        }
    }
    if ($clean !== []) {
        dc_tb_sep($tb);
        $tb['text'] .= implode("\n", $clean);
    }
}

/** Append a heading line and record it as a structure hint (kind heading|slide|sheet; level 1–9). */
function dc_tb_heading(array &$tb, string $heading, int $level, string $kind = 'heading'): void
{
    $heading = dc_one_line($heading);
    if ($heading === '') {
        return;
    }
    dc_tb_sep($tb);
    $from = strlen($tb['text']);
    $tb['text'] .= $heading;
    $tb['blocks'][] = ['type' => $kind, 'level' => max(1, min(9, $level)), 'heading' => $heading, 'char_from' => $from, 'char_to' => strlen($tb['text'])];
}

/** Open page $pageNo at the current end of the text. */
function dc_tb_page_start(array &$tb, int $pageNo): void
{
    dc_tb_sep($tb);
    $tb['page_open'] = [$pageNo, strlen($tb['text'])];
}

function dc_tb_page_end(array &$tb): void
{
    if ($tb['page_open'] !== null) {
        $tb['pages'][] = [$tb['page_open'][0], $tb['page_open'][1], strlen($tb['text'])];
        $tb['page_open'] = null;
    }
}

/** Close the builder: every byte offset becomes a character offset; the trailing blank line goes. */
function dc_tb_finish(array $tb): array
{
    dc_tb_page_end($tb);
    $text = rtrim($tb['text'], "\n");
    $len = strlen($text);
    $offs = [];
    foreach ($tb['blocks'] as $b) {
        $offs[] = min($b['char_from'], $len);
        $offs[] = min($b['char_to'], $len);
    }
    foreach ($tb['pages'] as $p) {
        $offs[] = min($p[1], $len);
        $offs[] = min($p[2], $len);
    }
    $map = dc_bytes_to_chars($text, $offs);
    $blocks = [];
    foreach ($tb['blocks'] as $b) {
        $b['char_from'] = $map[min($b['char_from'], $len)];
        $b['char_to'] = $map[min($b['char_to'], $len)];
        $blocks[] = $b;
    }
    $pages = [];
    foreach ($tb['pages'] as $p) {
        $pages[] = [$p[0], $map[min($p[1], $len)], $map[min($p[2], $len)]];
    }
    return ['text' => $text, 'blocks' => $blocks, 'pages' => $pages];
}

/** Byte offsets → character offsets, as a map byte => char, in one forward pass. */
function dc_bytes_to_chars(string $text, array $byteOffsets): array
{
    $u = array_values(array_unique(array_map('intval', $byteOffsets)));
    sort($u);
    $map = [];
    $b = 0;
    $c = 0;
    foreach ($u as $o) {
        $c += mb_strlen(substr($text, $b, $o - $b), 'UTF-8');
        $b = $o;
        $map[$o] = $c;
    }
    return $map;
}

/** Character offsets → byte offsets, as a map char => byte, in one forward pass. */
function dc_chars_to_bytes(string $text, array $charOffsets): array
{
    $u = array_values(array_unique(array_map('intval', $charOffsets)));
    sort($u);
    $map = [];
    $b = 0;
    $c = 0;
    $len = strlen($text);
    foreach ($u as $o) {
        $d = $o - $c;
        if ($d > 0) {
            $piece = mb_substr(substr($text, $b, $d * 4), 0, $d, 'UTF-8');
            $b = min($len, $b + strlen($piece));
        }
        $c = $o;
        $map[$o] = $b;
    }
    return $map;
}
