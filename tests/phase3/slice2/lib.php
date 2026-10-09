<?php
/**
 * Slice 2's helpers on top of slice 1's (tests/phase3/slice1/lib.php): the parts upload driven as a member (start → parts → finish), a raw-body
 * POST, a multipart POST, temp files of a size (deterministic bytes), the worker run with the scratch environment, the store's paths, the
 * fixtures of tests/extract (a PDF with text, one without, a DOCX, an XLSX, a PNG).
 */
require dirname(__DIR__) . '/slice1/lib.php';
require_once dirname(__DIR__, 2) . '/extract/fixtures.php';
function store(): string { return rtrim((string) getenv('DC_STORAGE_DIR'), '/'); }
function tmpfile_of(int $bytes, string $seed = 'x', string $name = 'file.bin'): string
{
    $dir = sys_get_temp_dir() . '/dc-s2-' . getmypid(); @mkdir($dir, 0700, true);
    $p = $dir . '/' . $name; $f = fopen($p, 'wb'); $left = $bytes; $i = 0;
    while ($left > 0) { $chunk = str_repeat(hash('sha256', $seed . $i++, true), 2048); if (strlen($chunk) > $left) { $chunk = substr($chunk, 0, $left); } fwrite($f, $chunk); $left -= strlen($chunk); }
    fclose($f); return $p;
}
function pdf_fixture(string $name, bool $imageOnly = false): string { return fx_write($name, fx_pdf([fx_wrap(fx_para(1, 900)), fx_wrap(fx_para(2, 900))], $imageOnly)); }
/** A raw-body POST as a member (the session's CSRF in the header). */
function post_raw(int $m, string $path, string $bytes, string $ct = 'application/octet-stream'): array
{
    $j = actor($m);
    $r = req('POST', $path, ['jar' => $j, 'raw' => $bytes, 'headers' => ['Content-Type: ' . $ct, 'X-CSRF-Token: ' . page_csrf($j), 'Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A multipart POST as a member with a file field. */
function post_file(int $m, string $path, array $fields, string $file, string $field = 'file', ?string $filename = null): array
{
    $j = actor($m);
    $fields['csrf_token'] = page_csrf($j);
    $fields[$field] = new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream', $filename ?? basename($file));
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $hs);
    return ['code' => $code, 'headers' => substr($r, 0, $hs), 'body' => $body, 'json' => json_decode($body, true) ?: []];
}
/** A JSON POST as a member through the session (form fields; Accept JSON). */
function post_j(int $m, string $path, array $form): array
{
    $j = actor($m); $form['csrf_token'] = page_csrf($j);
    $r = req('POST', $path, ['jar' => $j, 'form' => $form, 'headers' => ['Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** The parts upload as a member: start → every part → finish. Returns [start, finish] responses. $o: document_id, mode, name, skip_parts[], conflict. */
function upload_file(int $m, int $folder, string $path, array $o = []): array
{
    $size = filesize($path);
    $start = post_j($m, '/upload/start', ['folder_id' => $folder, 'filename' => $o['filename'] ?? basename($path), 'byte_size' => $size, 'mime_hint' => mime_content_type($path) ?: '', 'document_id' => $o['document_id'] ?? '', 'mode' => $o['mode'] ?? '', 'name' => $o['name'] ?? '', 'sha256' => $o['sha256'] ?? '']);
    if ($start['code'] !== 200) { return ['start' => $start, 'finish' => null]; }
    $s = $start['json']; $parts = [];
    for ($n = 1; $n <= (int) $s['parts_total']; $n++) {
        if (in_array($n, $o['skip_parts'] ?? [], true)) { continue; }
        $bytes = file_get_contents($path, false, null, ($n - 1) * (int) $s['part_bytes'], (int) $s['part_bytes']);
        $parts[$n] = post_raw($m, '/upload/part?session=' . $s['session_id'] . '&n=' . $n, $bytes);
    }
    $finish = post_j($m, '/upload/finish', ['session' => $s['session_id'], 'conflict' => $o['conflict'] ?? '']);
    return ['start' => $start, 'finish' => $finish, 'parts' => $parts, 'session' => $s];
}
function worker(string $passes, array $env = []): array
{
    $e = ''; foreach ($env as $k => $v) { $e .= $k . '=' . escapeshellarg($v) . ' '; }
    $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && ' . $e . 'php bin/worker.php --passes=' . escapeshellarg($passes) . ' 2>&1');
    $lines = array_values(array_filter(explode("\n", (string) $out)));
    return json_decode(end($lines) ?: '{}', true) ?: ['raw' => $out];
}
function doc_row(int $id): ?array { $r = q('SELECT * FROM documents WHERE id = :id', ['id' => $id]); return $r[0] ?? null; }
