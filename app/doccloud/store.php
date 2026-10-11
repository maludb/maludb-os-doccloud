<?php
declare(strict_types=1);

/**
 * THE BLOB STORE — the one module that touches the disk (design §6.1; docs/build-specs/uploads-and-versions.md §1, §3; sso-shell.md).
 * Every later slice calls it; nothing else opens a file under the store. Two name sets are served, because the two specs name them
 * differently: the uploads spec's (dc_storage_dir, dc_storage_path, dc_sniff_mime, dc_inline_safe, dc_refused_kind, dc_blob_store,
 * dc_blob_unlink, dc_make_thumbnail, dc_safe_filename) and the shell spec's (store_blob_path, store_upload_path, store_thumb_path,
 * store_export_path, store_judge_type, store_move_into_blobs, store_stream, store_unlink). The directory: DC_STORAGE_DIR, else
 * dc_settings.storage_dir, else APP_ROOT/storage — holding blobs/ (content-addressed: blobs/aa/bb/<sha256>), uploads/<session>/data (every
 * part written at its offset, db/024), thumbs/<document>/<version>.jpg, exports/<export>.zip. Nothing is refused for storage but a zero-byte
 * file and a file the disk has no room for (dc_store_room; no per-file ceiling unless an admin sets one, dc_file_ceiling); what must never be served
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

/**
 * Bytes the store can still take: the filesystem's free space (what an unprivileged writer may use) less the reserve always kept free —
 * DC_STORE_RESERVE_BYTES, 1 GB by default — so a large upload can never fill the disk the database and the logs share. Null when unknown.
 */
function dc_store_room(): ?int
{
    $free = @disk_free_space(dc_storage_dir());
    if ($free === false) {
        return null;
    }
    $reserve = trim((string) env('DC_STORE_RESERVE_BYTES', ''));               // an empty line in the environment keeps the default
    return (int) $free - ($reserve === '' ? 1073741824 : max(0, (int) $reserve));
}

/**
 * The per-file ceiling, or null for none (db/024, the owner's rule: a file store takes files of any size). The setting
 * dc_settings.max_document_bytes is NULL unless an admin chose a ceiling; DC_MAX_DOCUMENT_BYTES may only lower it. dc_store_room() limits always.
 */
function dc_file_ceiling(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT dc_setting_int('max_document_bytes')");
    $ceiling = $v === null ? null : (int) $v;
    $env = (int) env('DC_MAX_DOCUMENT_BYTES', '0');
    if ($env > 0) {
        $ceiling = $ceiling === null ? $env : min($ceiling, $env);
    }
    return $ceiling;
}

/** 'ok' when the store is writable, 'unwritable' otherwise (the health page). */
function dc_storage_state(): string
{
    return dc_storage_ready() ? 'ok' : 'unwritable';
}

function store_blob_path(string $relative): string { return dc_storage_path($relative); }
function store_upload_path(int $session): string { return dc_storage_path('uploads/' . $session . '/data'); }
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
 * The type of a file, decided by its BYTES (finfo, then dc_iso_media_mime() for an MP4-family file finfo does not know); a text kind refined
 * by the extension among text kinds only (.md → text/markdown, .csv → text/csv, .json → application/json). Returns the mime.
 */
function dc_sniff_mime(string $path, string $filename = ''): string
{
    $mime = 'application/octet-stream';
    if (is_file($path) && filesize($path) > 0) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($fi->file($path) ?: 'application/octet-stream');
        if ($mime === 'application/octet-stream') {
            $mime = dc_iso_media_mime((string) @file_get_contents($path, false, null, 0, 512)) ?? $mime;
        }
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

/**
 * An ISO base media file (MP4, QuickTime, 3GP, M4A) by its opening `ftyp` box, for the brands PHP's own magic does not know — a Sony
 * camera's XAVC (`ftypXAVC`, compatible mp42 iso6) is application/octet-stream to it. The major brand decides, then the compatible brands in
 * order; audio and video brands only (an image brand stays finfo's to judge). $head = the file's first bytes. Returns the mime, or null.
 */
function dc_iso_media_mime(string $head): ?string
{
    if (strlen($head) < 16 || substr($head, 4, 4) !== 'ftyp') {
        return null;
    }
    $size = unpack('N', substr($head, 0, 4))[1];
    if ($size < 16 || $size > 4096) {
        return null;
    }
    $brands = [substr($head, 8, 4)];
    for ($o = 16; $o + 4 <= min($size, strlen($head)); $o += 4) {
        $brands[] = substr($head, $o, 4);
    }
    foreach ($brands as $b) {
        $mime = match (true) {
            $b === 'qt  ' => 'video/quicktime',
            in_array($b, ['M4A ', 'M4B ', 'M4P ', 'F4A ', 'F4B '], true) => 'audio/mp4',
            str_starts_with($b, '3g2') => 'video/3gpp2',
            in_array(substr($b, 0, 3), ['3gp', '3gg', '3ge', '3gs'], true) => 'video/3gpp',
            (bool) preg_match('/^iso[m2-9]$/', $b), in_array($b, ['mp41', 'mp42', 'avc1', 'XAVC', 'MSNV', 'M4V ', 'M4VH', 'M4VP', 'f4v ', 'mmp4', 'dash', 'CAEP'], true) => 'video/mp4',
            default => null,
        };
        if ($mime !== null) {
            return $mime;
        }
    }
    return null;
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
 * file is kept (the bytes are the same) and the source removed — unless $keepSource (the finish of an upload keeps its file until the
 * version is committed, so a refusal at the commit can be answered again). Returns the RELATIVE path (what blobs.storage_path holds).
 */
function store_move_into_blobs(string $tmp, string $sha256, bool $keepSource = false): string
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
        if (!$keepSource) {
            @unlink($tmp);
        }
        return $rel;
    }
    if (!@link($tmp, $dest)) {
        if ($keepSource || !@rename($tmp, $dest)) {
            if (!@copy($tmp, $dest . '.part') || !@rename($dest . '.part', $dest)) {     // never a half-copied file under a sha256's name
                @unlink($dest . '.part');
                throw new RuntimeException('Cannot store the file.');
            }
        }
    }
    if (!$keepSource) {
        @unlink($tmp);
    }
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
 * an ETag of the sha256, a sandboxing CSP on inline kinds. The session is released first (a stream must not hold its lock). $opts: filename,
 * mime, inline (bool), sha256, byte_size. Exits.
 */
function store_stream(string $relative, array $opts): never
{
    $path = dc_storage_path($relative);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();      // a playing video or a long download must not hold the session's lock: the person's other pages would wait on it
    }
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
 * A thumbnail (320 px on the longer side, JPEG) and what the bytes told us (docs/build-specs/grid-and-media.md): images through GD, turned
 * upright by a JPEG's EXIF orientation (ffmpeg when GD cannot read the format, e.g. TIFF); a PDF's first page through pdftoppm; a video's frame
 * 10% in (at most 5 s) through ffmpeg, with its length from ffprobe. Returns ['state' => made|none|no_tool, 'path' => relative|null,
 * 'duration_ms' => int|null]: none = not possible for these bytes, no_tool = the tool is missing (the worker retries once it exists).
 * Never throws; the tools run under timeout, niced, one thread, the input an argument — the bytes are a person's upload.
 */
function dc_make_thumb(string $relative, string $mime, int $documentId, int $versionId): array
{
    $result = ['state' => 'none', 'path' => null, 'duration_ms' => null];
    try {
        $src = dc_storage_path($relative);
        $relOut = 'thumbs/' . $documentId . '/' . $versionId . '.jpg';
        $out = dc_storage_path($relOut);
        $dir = dirname($out);
        if (!is_file($src) || (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir))) {
            return $result;
        }
        $made = static function () use (&$result, $relOut): array { $result['state'] = 'made'; $result['path'] = $relOut; return $result; };
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            if (function_exists('imagecreatefromstring') && dc_thumb_gd($src, $out, $mime)) {
                return $made();
            }
            $ff = dc_tool('ffmpeg');
            if ($ff === null) {
                return ['state' => 'no_tool'] + $result;
            }
            return dc_thumb_ffmpeg($ff, $src, $out, 0.0) ? $made() : $result;
        }
        if ($mime === 'application/pdf') {
            $tool = dc_tool('pdftoppm');
            if ($tool === null) {
                return ['state' => 'no_tool'] + $result;
            }
            $prefix = sys_get_temp_dir() . '/dcthumb-' . getmypid() . '-' . $versionId;
            exec(dc_timeout_prefix(60) . escapeshellarg($tool) . ' -f 1 -l 1 -scale-to 320 -jpeg -singlefile ' . escapeshellarg($src) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_file($prefix . '.jpg') && @rename($prefix . '.jpg', $out)) {
                return $made();
            }
            @unlink($prefix . '.jpg');
            return $result;
        }
        if (str_starts_with($mime, 'video/')) {
            $ff = dc_tool('ffmpeg');
            $probe = dc_tool('ffprobe');
            if ($ff === null) {
                return ['state' => 'no_tool'] + $result;
            }
            $seconds = null;
            if ($probe !== null) {
                $line = trim((string) shell_exec(dc_timeout_prefix(20) . escapeshellarg($probe) . ' -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($src) . ' 2>/dev/null'));
                if (is_numeric($line) && (float) $line >= 0) {
                    $seconds = (float) $line;
                    $result['duration_ms'] = (int) round($seconds * 1000);
                }
            }
            $at = $seconds !== null && $seconds > 0 ? min(5.0, $seconds * 0.1) : 1.0;
            if (dc_thumb_ffmpeg($ff, $src, $out, $at) || ($at > 0 && dc_thumb_ffmpeg($ff, $src, $out, 0.0))) {
                return $made();
            }
            return $result;
        }
    } catch (Throwable $e) {
        error_log('thumbnail: ' . $e->getMessage());
    }
    return $result;
}

/** The old name (slice 2): the relative path of a made thumbnail, or null. */
function dc_make_thumbnail(string $relative, string $mime, int $documentId, int $versionId): ?string
{
    return dc_make_thumb($relative, $mime, $documentId, $versionId)['path'];
}

/** A tool's absolute path (DC_FFMPEG, DC_FFPROBE, DC_PDFTOPPM override), or null when it is not installed. */
function dc_tool(string $name): ?string
{
    static $found = [];
    if (!array_key_exists($name, $found)) {
        $p = trim((string) env('DC_' . strtoupper($name), ''));
        if ($p === '') {
            $p = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        }
        $found[$name] = $p !== '' && is_executable($p) ? $p : null;
    }
    return $found[$name];
}

/** "timeout N nice -n 10 " when coreutils has them (it does on Ubuntu); empty otherwise. */
function dc_timeout_prefix(int $seconds): string
{
    static $t = null;
    $t ??= is_executable('/usr/bin/timeout') && is_executable('/usr/bin/nice');
    return $t ? '/usr/bin/timeout ' . $seconds . ' /usr/bin/nice -n 10 ' : '';
}

/** One frame through ffmpeg (seek $at seconds, scale into 320 × 320), written atomically. */
function dc_thumb_ffmpeg(string $ffmpeg, string $src, string $out, float $at): bool
{
    $tmp = $out . '.tmp-' . getmypid() . '.jpg';
    exec(dc_timeout_prefix(60) . escapeshellarg($ffmpeg) . ' -nostdin -v error -threads 1 -ss ' . sprintf('%.3F', max(0, $at)) . ' -i ' . escapeshellarg($src)
        . ' -frames:v 1 -vf ' . escapeshellarg('scale=w=320:h=320:force_original_aspect_ratio=decrease') . ' -q:v 4 -y ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
    if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0 && @rename($tmp, $out)) {
        return true;
    }
    @unlink($tmp);
    return false;
}

/** The EXIF orientation of a JPEG (1 upright … 8), 1 when there is none or it cannot be read. */
function dc_exif_orientation(string $path, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return 1;
    }
    $exif = @exif_read_data($path);
    $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    return $o >= 1 && $o <= 8 ? $o : 1;
}

/** A GD thumbnail, upright, written atomically. False when GD cannot read the bytes. */
function dc_thumb_gd(string $src, string $out, string $mime): bool
{
    $data = (string) @file_get_contents($src, false, null, 0, 50 * 1048576);
    $img = $data === '' ? false : @imagecreatefromstring($data);
    unset($data);
    if ($img === false) {
        return false;
    }
    $o = dc_exif_orientation($src, $mime);
    if ($o !== 1) {
        if (in_array($o, [2, 4], true)) {
            imageflip($img, $o === 2 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        } else {
            $turned = imagerotate($img, match ($o) { 3 => 180, 8 => 90, default => -90 }, 0);   // 5, 6, 7: a quarter turn clockwise first
            if ($turned !== false) {
                imagedestroy($img);
                $img = $turned;
            }
            if ($o === 5 || $o === 7) {
                imageflip($img, $o === 5 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            }
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 320 / max(1, max($w, $h)));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $t = imagecreatetruecolor($tw, $th);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    $tmp = $out . '.tmp-' . getmypid() . '.jpg';
    $ok = imagejpeg($t, $tmp, 82) && @rename($tmp, $out);
    imagedestroy($t);
    imagedestroy($img);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}
