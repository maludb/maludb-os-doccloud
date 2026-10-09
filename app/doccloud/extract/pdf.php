<?php
declare(strict_types=1);

/**
 * PDF through poppler's `pdftotext -layout` (DC_PDFTOTEXT, /usr/bin/pdftotext by default) under a timeout
 * (DC_PDFTOTEXT_TIMEOUT seconds, 60). One run for the whole file; its pages arrive separated by form feeds, so each
 * page's text and anchor is exact without one process per page. Lines that repeat at the top or bottom of more than
 * 60% of the pages (digits ignored, so "Page 3 of 9" repeats) are running headers and footers and are dropped before
 * the text is built — the offsets refer to the text WITHOUT them.
 */

/** Run a command with a timeout and an output cap: [exit code, stdout, stderr, timed out]. */
function dc_run(array $argv, int $timeout, int $maxOut): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [127, '', 'could not start', false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeout;
    $timedOut = false;
    while (true) {
        $r = [];
        if (!feof($pipes[1])) {
            $r[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $r[] = $pipes[2];
        }
        if ($r === []) {
            break;
        }
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            $timedOut = true;
            break;
        }
        $w = null;
        $e = null;
        if (@stream_select($r, $w, $e, (int) $left, (int) (($left - (int) $left) * 1e6)) === false) {
            break;
        }
        foreach ($r as $p) {
            $chunk = (string) fread($p, 65536);
            if ($p === $pipes[1]) {
                $out .= $chunk;
            } else {
                $err .= substr($chunk, 0, 4096);
            }
        }
        if (strlen($out) > $maxOut) {
            $timedOut = true; // treated as a failure: more text than any allowed file yields
            break;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ($timedOut) {
        proc_terminate($proc, 9);
        proc_close($proc);
        return [124, '', $err, true];
    }
    $code = proc_close($proc);
    return [$code, $out, $err, false];
}

/** Read a PDF: ['text', 'blocks' (none), 'pages', 'error' => null|pdf_unreadable|pdf_timeout]. */
function dc_read_pdf(string $path): array
{
    $bin = getenv('DC_PDFTOTEXT') ?: '/usr/bin/pdftotext';
    if (!is_file($bin) || !is_executable($bin)) {
        return ['text' => '', 'blocks' => [], 'pages' => [], 'error' => 'pdftotext_missing'];
    }
    [$code, $out, , $timedOut] = dc_run([$bin, '-layout', '-enc', 'UTF-8', '-eol', 'unix', '-q', $path, '-'], dc_env_int('DC_PDFTOTEXT_TIMEOUT', 60), dc_max_bytes() * 4);
    if ($timedOut) {
        return ['text' => '', 'blocks' => [], 'pages' => [], 'error' => 'pdf_timeout'];
    }
    if ($code !== 0) {
        return ['text' => '', 'blocks' => [], 'pages' => [], 'error' => 'pdf_unreadable'];
    }
    $raw = explode("\f", $out);
    if (count($raw) > 1 && trim((string) end($raw)) === '') {
        array_pop($raw); // pdftotext ends every page with a form feed
    }
    $pages = [];
    foreach ($raw as $p) {
        $pages[] = explode("\n", trim(dc_text_clean($p), "\n"));
    }
    $pages = dc_pdf_drop_running($pages);
    $tb = dc_tb_new();
    foreach ($pages as $i => $lines) {
        dc_tb_page_start($tb, $i + 1);
        dc_tb_para($tb, implode("\n", $lines));
        dc_tb_page_end($tb);
    }
    return dc_tb_finish($tb) + ['error' => null];
}

/** The key two running lines share: trimmed, inner spacing collapsed, every run of digits one '#'. */
function dc_pdf_line_key(string $line): string
{
    return (string) preg_replace(['/\s+/u', '/\d+/'], [' ', '#'], trim($line));
}

/** Drop lines among the first and last three non-blank lines of a page that repeat there on more than 60% of pages. */
function dc_pdf_drop_running(array $pages): array
{
    $n = count($pages);
    if ($n < 2) {
        return $pages;
    }
    $edgeIdx = [];
    $seen = [];
    foreach ($pages as $pi => $lines) {
        $nonBlank = array_keys(array_filter($lines, static fn($l) => trim($l) !== ''));
        $edge = array_unique(array_merge(array_slice($nonBlank, 0, 3), array_slice($nonBlank, -3)));
        $edgeIdx[$pi] = $edge;
        $keys = [];
        foreach ($edge as $li) {
            $keys[dc_pdf_line_key($lines[$li])] = true;
        }
        foreach (array_keys($keys) as $k) {
            $seen[$k] = ($seen[$k] ?? 0) + 1;
        }
    }
    $running = array_filter($seen, static fn($c, $k) => $k !== '' && $c / $n > 0.6, ARRAY_FILTER_USE_BOTH);
    if ($running === []) {
        return $pages;
    }
    foreach ($pages as $pi => $lines) {
        foreach ($edgeIdx[$pi] as $li) {
            if (isset($running[dc_pdf_line_key($lines[$li])])) {
                unset($lines[$li]);
            }
        }
        $pages[$pi] = explode("\n", trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)), "\n"));
    }
    return $pages;
}
