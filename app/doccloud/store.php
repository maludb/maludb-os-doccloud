<?php
declare(strict_types=1);

/**
 * THE BLOB STORE — the one module that touches the disk (design §6.1; docs/build-specs/uploads-and-versions.md §1, §3; sso-shell.md).
 * Every later slice calls it; nothing else opens a file under the store. Two name sets are served, because the two specs name them
 * differently: the uploads spec's (dc_storage_dir, dc_storage_path, dc_sniff_mime, dc_inline_safe, dc_refused_kind, dc_blob_store,
 * dc_blob_unlink, dc_make_thumbnail, dc_safe_filename) and the shell spec's (store_blob_path, store_part_path, store_thumb_path,
 * store_export_path, store_judge_type, store_move_into_blobs, store_stream, store_unlink). The directory: DC_STORAGE_DIR, else
 * dc_settings.storage_dir, else APP_ROOT/storage — holding blobs/ (content-addressed: blobs/aa/bb/<sha256>), uploads/<session>/<part>,
 * thumbs/<document>/<version>.jpg, exports/<export>.zip. Nothing is refused for storage but a zero-byte file; what must never be served
 * INLINE (an executable, a script, HTML, SVG, anything beginning <?php, #!, MZ or ELF) is stored and served as an attachment.
 */

function dc_storage_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $d = trim((string) env('DC_STORAGE_DIR', ''));
    if ($d === '') {
        try {
            $d = trim((string) (one_value(db(), 'SELECT storage_dir FROM dc_settings WHERE id = 1') ?? ''));
        } catch (Throwable) {
            $d = '';
        }
    }
    if ($d === '') {
        $d = APP_ROOT . '/storage';
    }
    return $dir = rtrim($d, '/');
}

/** The absolute path of a relative store path (blobs/…, uploads/…, thumbs/…, exports/…); a path that climbs out is refused. */
function dc_storage_path(string $rel): string
{
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
        throw new DomainException('A store path never climbs out of the store.');
    }
    return dc_storage_dir() . '/' . $rel;
}

/** Make the store's four directories when they are missing (0750). False when the store cannot be written. */
function dc_storage_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $base = dc_storage_dir();
    foreach (['', '/blobs', '/uploads', '/thumbs', '/exports'] as $sub) {
        $p = $base . $sub;
        if (!is_dir($p) && !@mkdir($p, 0750, true) && !is_dir($p)) {
            return $ready = false;
        }
    }
    return $ready = is_writable($base . '/blobs') && is_writable($base . '/uploads');
}

/** 'ok' when the store is writable, 'unwritable' otherwise (the health page). */
function dc_storage_state(): string
{
    return dc_storage_ready() ? 'ok' : 'unwritable';
}

function store_blob_path(string $relative): string { return dc_storage_path($relative); }
function store_part_path(int $session, int $part): string { return dc_storage_path('uploads/' . $session . '/' . $part); }
function store_assembled_path(int $session): string { return dc_storage_path('uploads/' . $session . '/assembled'); }
function store_thumb_path(int $documentId, int $versionId = 0): string { return dc_storage_path('thumbs/' . $documentId . '/' . ($versionId ?: 'current') . '.jpg'); }
function store_export_path(int $exportId): string { return dc_storage_path('exports/' . $exportId . '.zip'); }

/** The relative blob path for a sha256 (the database's dc_blob_claim answers the same; the two must agree). */
function dc_blob_relative(string $sha256): string
{
    if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
        throw new DomainException('A sha256 is 64 hex characters.');
    }
    return 'blobs/' . substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2) . '/' . $sha256;
}

/** A safe filename: the basename, backslashes as slashes first, control characters out, at most 255, never '.' or '..'. */
function dc_safe_filename(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
    $name = trim($name);
    if (mb_strlen($name) > 255) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $name = mb_substr($name, 0, 255 - ($ext !== '' ? mb_strlen($ext) + 1 : 0)) . ($ext !== '' ? '.' . $ext : '');
    }
    if ($name === '' || $name === '.' || $name === '..') {
        throw new DomainException('A file needs a name.');
    }
    return $name;
}

/** The sha256 of a file, streamed (never the whole file in memory). */
function dc_file_sha256(string $path): string
{
    $h = hash_init('sha256');
    $f = fopen($path, 'rb');
    if ($f === false) {
        throw new RuntimeException('Cannot read ' . basename($path));
    }
    while (!feof($f)) {
        $chunk = fread($f, 1048576);
        if ($chunk === false) {
            break;
        }
        hash_update($h, $chunk);
    }
    fclose($f);
    return hash_final($h);
}

/**
 * The type of a file, decided by its BYTES (finfo); a text kind refined by the extension among text kinds only (.md → text/markdown,
 * .csv → text/csv, .json → application/json). Returns the mime.
 */
function dc_sniff_mime(string $path, string $filename = ''): string
{
    $mime = 'application/octet-stream';
    if (is_file($path) && filesize($path) > 0) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($fi->file($path) ?: 'application/octet-stream');
    }
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($mime, ['text/plain', 'application/csv', 'text/x-c', 'text/x-php', 'application/json'], true) || str_starts_with($mime, 'text/')) {
        $refined = match ($ext) { 'md', 'markdown' => 'text/markdown', 'csv' => 'text/csv', 'json' => 'application/json', 'txt' => 'text/plain', 'html', 'htm' => 'text/html', 'svg' => 'image/svg+xml', default => null };
        if ($refined !== null) {
            $mime = $refined;
        }
    }
    return $mime;
}

/** The kinds that are stored but never served inline (the browser would run them). Returns the reason, or null. */
function dc_refused_kind(string $mime, string $filename, ?string $path = null): ?string
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'ps1', 'sh', 'js', 'mjs', 'vbs', 'jar', 'dll', 'html', 'htm', 'xhtml', 'svg', 'php', 'phtml', 'phar'], true)) {
        return 'extension';
    }
    if (in_array($mime, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-msdownload',
                         'application/x-php', 'text/x-php', 'application/javascript', 'text/javascript', 'application/x-sh', 'text/x-shellscript', 'application/x-mach-binary', 'application/java-archive'], true)) {
        return 'mime';
    }
    if ($path !== null && is_file($path)) {
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        if ($head !== '') {
            if (str_starts_with($head, "MZ") || str_starts_with($head, "\x7fELF") || str_starts_with($head, "#!")) {
                return 'bytes';
            }
            $lower = strtolower(ltrim($head, "\xEF\xBB\xBF \t\r\n"));
            if (str_starts_with($lower, '<?php') || str_starts_with($lower, '<script') || str_starts_with($lower, '<!doctype html') || str_starts_with($lower, '<html') || str_starts_with($lower, '<svg')
                || (str_starts_with($lower, '<?xml') && str_contains(substr($lower, 0, 512), '<svg'))) {
                return 'bytes';
            }
        }
    }
    return null;
}

/** May this kind be shown inline (images, PDF, plain text kinds, audio, video)? Everything refused is an attachment. */
function dc_inline_safe(string $mime, string $filename, ?string $path = null): bool
{
    if (dc_refused_kind($mime, $filename, $path) !== null) {
        return false;
    }
    return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml'
        || $mime === 'application/pdf'
        || in_array($mime, ['text/plain', 'text/markdown', 'text/csv', 'application/json'], true)
        || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
}

/** One judgement of a file: [mime, kind (dc_mime_kind's words), inline_ok, refused_reason?]. */
function store_judge_type(string $path, string $filename): array
{
    $mime = dc_sniff_mime($path, $filename);
    $reason = dc_refused_kind($mime, $filename, $path);
    return ['mime' => $mime, 'kind' => dc_mime_kind_of($mime), 'inline_ok' => $reason === null && dc_inline_safe($mime, $filename, $path), 'refused_reason' => $reason,
            'empty' => !is_file($path) || filesize($path) === 0];
}

/** The same words the database's dc_mime_kind() gives (db/007), in PHP. */
function dc_mime_kind_of(string $mime): string
{
    if (str_starts_with($mime, 'image/')) { return 'image'; }
    if ($mime === 'application/pdf') { return 'pdf'; }
    if (in_array($mime, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation', 'application/rtf'], true)) { return 'office'; }
    if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true)) { return 'text'; }
    if (str_starts_with($mime, 'audio/')) { return 'audio'; }
    if (str_starts_with($mime, 'video/')) { return 'video'; }
    if (in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-tar', 'application/x-7z-compressed', 'application/vnd.rar'], true)) { return 'archive'; }
    return 'other';
}

/**
 * Move an assembled (or uploaded) file into the blobs by its sha256: hard-links when it can, moves otherwise; 0640; an existing blob
 * file is kept (the bytes are the same) and the source removed. Returns the RELATIVE path (what blobs.storage_path holds).
 */
function store_move_into_blobs(string $tmp, string $sha256): string
{
    if (!dc_storage_ready()) {
        throw new RuntimeException('The store is not writable.');
    }
    $rel = dc_blob_relative($sha256);
    $dest = dc_storage_path($rel);
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot make ' . $dir);
    }
    if (is_file($dest)) {
        @unlink($tmp);
        return $rel;
    }
    if (!@link($tmp, $dest)) {
        if (!@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                throw new RuntimeException('Cannot store the file.');
            }
        }
    }
    @unlink($tmp);
    @chmod($dest, 0640);
    return $rel;
}
function dc_blob_store(string $tmp, string $sha256): string { return store_move_into_blobs($tmp, $sha256); }

/** Remove a stored file by its relative path (a freed blob, an expired part directory, a thumbnail). True when gone. */
function store_unlink(string $relative): bool
{
    try {
        $p = dc_storage_path($relative);
    } catch (DomainException) {
        return false;
    }
    if (is_dir($p)) {
        foreach (scandir($p) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($p . '/' . $f);
            }
        }
        return @rmdir($p);
    }
    return !is_file($p) || @unlink($p);
}
function dc_blob_unlink(string $relative): bool { return store_unlink($relative); }

/**
 * Stream a stored file to the browser: ranges (206/416), the disposition (inline only when inline_ok), nosniff, Cache-Control private,
 * an ETag of the sha256, a sandboxing CSP on inline kinds. $opts: filename, mime, inline (bool), sha256, byte_size. Exits.
 */
function store_stream(string $relative, array $opts): never
{
    $path = dc_storage_path($relative);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found.';
        exit;
    }
    $size = (int) ($opts['byte_size'] ?? filesize($path));
    $mime = (string) ($opts['mime'] ?? 'application/octet-stream');
    $name = dc_safe_filename((string) ($opts['filename'] ?? 'file'));
    $inline = !empty($opts['inline']);
    $etag = '"' . (string) ($opts['sha256'] ?? md5($relative)) . '"';
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
    if ($inline) {
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'");
    }
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    $start = 0;
    $end = $size - 1;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && $size > 0) {
        if ($m[1] === '' && $m[2] === '') {
            $bad = true;
        } elseif ($m[1] === '') {
            $n = (int) $m[2];
            $start = max(0, $size - $n);
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if (!empty($bad) || $start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    $f = fopen($path, 'rb');
    if ($f === false) {
        exit;
    }
    fseek($f, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($f)) {
        $chunk = fread($f, (int) min(1048576, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($f);
    exit;
}

/**
 * A thumbnail (320 px, JPEG): images through GD; a PDF's first page through pdftoppm when present. Returns the relative path or null
 * (no thumbnail for this kind, or the tool missing). Never throws.
 */
function dc_make_thumbnail(string $relative, string $mime, int $documentId, int $versionId): ?string
{
    try {
        $src = dc_storage_path($relative);
        $relOut = 'thumbs/' . $documentId . '/' . $versionId . '.jpg';
        $out = dc_storage_path($relOut);
        $dir = dirname($out);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && function_exists('imagecreatefromstring')) {
            $data = (string) @file_get_contents($src, false, null, 0, 50 * 1048576);
            $img = @imagecreatefromstring($data);
            if ($img === false) {
                return null;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, 320 / max(1, max($w, $h)));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $t = imagecreatetruecolor($tw, $th);
            $white = imagecolorallocate($t, 255, 255, 255);
            imagefill($t, 0, 0, $white);
            imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
            $okj = imagejpeg($t, $out, 82);
            imagedestroy($t);
            imagedestroy($img);
            return $okj ? $relOut : null;
        }
        if ($mime === 'application/pdf') {
            $tool = (string) (env('DC_PDFTOPPM', '') ?: trim((string) shell_exec('command -v pdftoppm 2>/dev/null')));
            if ($tool === '') {
                return null;
            }
            $prefix = sys_get_temp_dir() . '/dcthumb-' . getmypid() . '-' . $versionId;
            exec(escapeshellcmd($tool) . ' -f 1 -l 1 -scale-to 320 -jpeg -singlefile ' . escapeshellarg($src) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_file($prefix . '.jpg') && @rename($prefix . '.jpg', $out)) {
                return $relOut;
            }
            @unlink($prefix . '.jpg');
        }
    } catch (Throwable $e) {
        error_log('thumbnail: ' . $e->getMessage());
    }
    return null;
}
