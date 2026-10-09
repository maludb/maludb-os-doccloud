<?php
declare(strict_types=1);

/**
 * The text kinds: Markdown (kept verbatim, ATX and setext headings found outside fenced code), plain text (paragraphs,
 * no structure), CSV (each row one line of "column: value" pairs, so every chunk carries its header) and JSON
 * (flattened to "path: value" lines, one paragraph per top-level member). Each returns dc_tb_finish()'s shape.
 */

function dc_read_markdown(string $raw): array
{
    $tb = dc_tb_new();
    $tb['text'] = rtrim(dc_text_clean($raw), "\n");
    $text = $tb['text'];
    $lines = explode("\n", $text);
    $offsets = [];
    $o = 0;
    foreach ($lines as $i => $l) {
        $offsets[$i] = $o;
        $o += strlen($l) + 1;
    }
    $fence = null;
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $l = $lines[$i];
        if ($fence !== null) {
            if (preg_match('/^ {0,3}(' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',})[ \t]*$/', $l)) {
                $fence = null;
            }
            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $l, $m)) {
            $fence = [$m[1][0], strlen($m[1])];
            continue;
        }
        if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $l, $m)) {
            $h = trim($m[2] ?? '');
            if ($h !== '') {
                $tb['blocks'][] = ['type' => 'heading', 'level' => strlen($m[1]), 'heading' => dc_one_line($h), 'char_from' => $offsets[$i], 'char_to' => $offsets[$i] + strlen($l)];
            }
            continue;
        }
        if ($i + 1 < $n && trim($l) !== '' && ($i === 0 || trim($lines[$i - 1]) === '')
            && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $lines[$i + 1], $m)
            && !preg_match('/^ {0,3}([>*+-]|\d+[.)])\s/', $l) && !preg_match('/^( {4}|\t)/', $l)) {
            $tb['blocks'][] = ['type' => 'heading', 'level' => $m[1][0] === '=' ? 1 : 2, 'heading' => dc_one_line($l), 'char_from' => $offsets[$i], 'char_to' => $offsets[$i + 1] + strlen($lines[$i + 1])];
            $i++;
        }
    }
    return dc_tb_finish($tb);
}

function dc_read_plain(string $raw): array
{
    $tb = dc_tb_new();
    $tb['text'] = rtrim(dc_text_clean($raw), "\n");
    return dc_tb_finish($tb);
}

function dc_read_csv(string $raw, string $filename = ''): array
{
    $text = dc_text_clean($raw);
    $first = (string) strtok($text, "\n");
    $delims = [',' => substr_count($first, ','), "\t" => substr_count($first, "\t"), ';' => substr_count($first, ';')];
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'tsv') {
        $delims["\t"] += 1000;
    }
    arsort($delims);
    $d = (string) array_key_first($delims);
    $fh = fopen('php://temp', 'w+b');
    fwrite($fh, $text);
    rewind($fh);
    $header = null;
    $lines = [];
    while (($row = fgetcsv($fh, null, $d, '"', '')) !== false) {
        if ($row === [null] || implode('', array_map('strval', $row)) === '') {
            continue;
        }
        if ($header === null) {
            $header = [];
            foreach ($row as $k => $h) {
                $h = dc_one_line((string) $h);
                $header[$k] = $h === '' ? 'Column ' . ($k + 1) : $h;
            }
            continue;
        }
        $pairs = [];
        foreach ($row as $k => $v) {
            $v = dc_one_line((string) $v);
            if ($v !== '') {
                $pairs[] = ($header[$k] ?? 'Column ' . ($k + 1)) . ': ' . $v;
            }
        }
        if ($pairs !== []) {
            $lines[] = implode(' | ', $pairs);
        }
    }
    fclose($fh);
    $tb = dc_tb_new();
    if ($lines === [] && $header !== null) {
        $lines[] = implode(' | ', $header);
    }
    dc_tb_lines($tb, $lines);
    return dc_tb_finish($tb);
}

function dc_read_json(string $raw): array
{
    $data = json_decode(dc_text_clean($raw), true, 512);
    $tb = dc_tb_new();
    if (is_array($data) && $data !== []) {
        $isList = array_is_list($data);
        foreach ($data as $k => $v) {
            $lines = [];
            dc_json_flatten($v, $isList ? '[' . $k . ']' : (string) $k, $lines);
            dc_tb_lines($tb, $lines);
        }
    } else {
        $lines = [];
        dc_json_flatten($data, '', $lines);
        dc_tb_lines($tb, $lines);
    }
    return dc_tb_finish($tb);
}

/** "a.b[0].c: value" lines, depth-first in document order. */
function dc_json_flatten(mixed $v, string $path, array &$lines): void
{
    if (is_array($v)) {
        if ($v === []) {
            $lines[] = ($path === '' ? '' : $path . ': ') . '(empty)';
            return;
        }
        $isList = array_is_list($v);
        foreach ($v as $k => $x) {
            dc_json_flatten($x, $isList ? $path . '[' . $k . ']' : ($path === '' ? (string) $k : $path . '.' . $k), $lines);
        }
        return;
    }
    $s = match (true) {
        $v === null => 'null',
        is_bool($v) => $v ? 'true' : 'false',
        default => (string) $v,
    };
    $lines[] = $path === '' ? $s : $path . ': ' . $s;
}
