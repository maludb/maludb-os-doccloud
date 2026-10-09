<?php
declare(strict_types=1);

/**
 * THE UPLOAD IN PARTS and its one-request form (docs/build-specs/uploads-and-versions.md §2; uploads-versions.md "The flow of an upload").
 * upload_start → a session (the gates, the ceiling, the quota, the same-name rule); upload_part → one part on disk (resume = overwrite);
 * upload_finish → the parts assembled streaming into one file, hashed as they go, the type judged from the bytes, the blob claimed
 * (de-duplicated), the version added by the database (dc_version_add re-checks the ceiling, the quota and the lock); upload_one does the
 * three for a file that arrived whole (the API, an agent, a file request, a browser without JavaScript). Every function throws
 * UploadRefused with an HTTP status and a code; the controllers turn it into the answer. The caller has judged the level already
 * (require_folder_level / document_at_level); these re-check nothing of the caller but what the database refuses anyway.
 */

final class UploadRefused extends DomainException
{
    public function __construct(public readonly int $status, public readonly string $reason, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

/** The session row (as the writer) or null. */
function upload_session(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM upload_sessions WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A live document of this name in the folder (case-insensitive), or null. */
function document_named(PDO $pdo, int $folderId, string $name): ?array
{
    $st = $pdo->prepare('SELECT id, name, owner_member_id, locked_by, lock_expires_at, version_count FROM documents WHERE folder_id = :f AND lower(name) = lower(:n) AND trashed_at IS NULL');
    $st->execute(['f' => $folderId, 'n' => $name]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Is the document locked by someone other than $by (and the lock live)? The holder's name when so. */
function lock_held_by_another(PDO $pdo, int $documentId, int $by): ?string
{
    $st = $pdo->prepare('SELECT m.display_name FROM documents d JOIN members m ON m.id = d.locked_by WHERE d.id = :id AND d.locked_by IS NOT NULL AND d.locked_by <> :by AND d.lock_expires_at > now()');
    $st->execute(['id' => $documentId, 'by' => $by]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * Start a session. $f: filename, byte_size, mime_hint?, sha256?, document_id? (a new version of it), mode? (new|version|keep_both), name? (the
 * document's name; the file name by default), note?, source?. The folder's level and the document's level are the CALLER's to have checked;
 * here: the ceiling (413 too_large), the quota (422 quota), a zero-byte file (422 empty), the same-name rule (409 name_taken with
 * existing_document_id unless mode says), the lock (423 locked). A sha256 that is already a blob needs no parts: parts_total 0, deduplicated.
 * Returns the session's JSON shape.
 */
function upload_start(PDO $pdo, int $folderId, array $f, int $by): array
{
    $filename = dc_safe_filename((string) ($f['filename'] ?? ''));
    $name = isset($f['name']) && trim((string) $f['name']) !== '' ? dc_safe_filename((string) $f['name']) : $filename;
    $size = (int) ($f['byte_size'] ?? -1);
    if ($size < 0) {
        throw new UploadRefused(422, 'bad_size', 'Say how many bytes the file has.');
    }
    if ($size === 0) {
        throw new UploadRefused(422, 'empty', 'An empty file is not stored.');
    }
    $ceiling = (int) one_value($pdo, "SELECT dc_setting_int('max_document_bytes')");
    $envCeiling = (int) env('DC_MAX_DOCUMENT_BYTES', '0');
    if ($envCeiling > 0) {
        $ceiling = min($ceiling, $envCeiling);
    }
    if ($size > $ceiling) {
        throw new UploadRefused(413, 'too_large', 'A file is at most ' . format_bytes($ceiling) . '.');
    }
    $libraryId = (int) one_value($pdo, 'SELECT library_id FROM folders WHERE id = :f', ['f' => $folderId]);
    if (!(bool) one_value($pdo, 'SELECT dc_quota_allows(:l, :b)', ['l' => $libraryId, 'b' => $size])) {
        throw new UploadRefused(422, 'quota', 'The library is over its quota.');
    }
    $mode = (string) ($f['mode'] ?? 'new');
    $documentId = isset($f['document_id']) && (int) $f['document_id'] > 0 ? (int) $f['document_id'] : null;
    if ($documentId !== null) {
        $mode = 'version';
        $holder = lock_held_by_another($pdo, $documentId, $by);
        if ($holder !== null) {
            throw new UploadRefused(423, 'locked', 'Locked by ' . $holder . '.');
        }
    } else {
        $existing = document_named($pdo, $folderId, $name);
        if ($existing !== null) {
            if ($mode === 'keep_both') {
                $name = (string) one_value($pdo, 'SELECT dc_free_name(:f, :n)', ['f' => $folderId, 'n' => $name]);
            } elseif ($mode === 'version') {
                $documentId = (int) $existing['id'];
                $holder = lock_held_by_another($pdo, $documentId, $by);
                if ($holder !== null) {
                    throw new UploadRefused(423, 'locked', 'Locked by ' . $holder . '.');
                }
            } else {
                throw new UploadRefused(409, 'name_taken', 'A document named ' . $existing['name'] . ' is already here.', ['existing_document_id' => (int) $existing['id'], 'existing_version_count' => (int) $existing['version_count']]);
            }
        } else {
            $mode = 'new';
        }
    }
    $sha = isset($f['sha256']) && preg_match('/^[0-9a-f]{64}$/', (string) $f['sha256']) ? (string) $f['sha256'] : null;
    $partBytes = (int) one_value($pdo, "SELECT dc_setting_int('upload_part_bytes')");
    $partsTotal = max(1, (int) ceil($size / $partBytes));
    $dedup = false;
    if ($sha !== null) {
        $st = $pdo->prepare('SELECT b.id FROM blobs b WHERE b.sha256 = :s AND b.byte_size = :n AND b.deleted_at IS NULL');
        $st->execute(['s' => $sha, 'n' => $size]);
        if ($st->fetchColumn() !== false && is_file(dc_storage_path(dc_blob_relative($sha)))) {
            $dedup = true;
            $partsTotal = 0;
        }
    }
    $hours = (int) one_value($pdo, "SELECT dc_setting_int('upload_session_hours')");
    $st = $pdo->prepare('INSERT INTO upload_sessions (member_id, folder_id, document_id, filename, mime_hint, byte_size, part_bytes, parts_total, sha256_claimed, status, expires_at, document_name, mode, source, note)
                         VALUES (:m, :f, :d, :fn, :mh, :n, :pb, :pt, :sha, :st, now() + make_interval(hours => :h), :dn, :mode, :src, :note) RETURNING id, expires_at');
    $st->execute(['m' => $by, 'f' => $folderId, 'd' => $documentId, 'fn' => $filename, 'mh' => isset($f['mime_hint']) ? mb_substr((string) $f['mime_hint'], 0, 120) : null, 'n' => $size, 'pb' => $partBytes,
                  'pt' => max(1, $partsTotal), 'sha' => $sha, 'st' => $dedup ? 'assembling' : 'open', 'h' => max(1, $hours), 'dn' => $name, 'mode' => $mode,
                  'src' => in_array($f['source'] ?? 'web', ['web', 'api', 'agent', 'request'], true) ? $f['source'] : 'web', 'note' => isset($f['note']) ? mb_substr((string) $f['note'], 0, 500) : null]);
    $row = $st->fetch();
    $id = (int) $row['id'];
    if (!$dedup) {
        $dir = dirname(store_part_path($id, 1));
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new UploadRefused(503, 'storage', 'The store is not writable.');
        }
    }
    return ['session_id' => $id, 'part_bytes' => $partBytes, 'parts_total' => $partsTotal, 'deduplicated' => $dedup, 'expires_at' => $row['expires_at'], 'document_name' => $name, 'mode' => $mode, 'document_id' => $documentId];
}

/** One part: the bytes at $tmpPath (an uploaded file or a raw body already on disk) become uploads/<session>/<n>; a repeat overwrites. */
function upload_part(PDO $pdo, array $session, int $partNo, string $tmpPath): array
{
    $id = (int) $session['id'];
    if ($session['status'] !== 'open') {
        throw new UploadRefused(409, 'not_open', 'This upload is ' . $session['status'] . '.');
    }
    if (strtotime((string) $session['expires_at']) < time()) {
        throw new UploadRefused(410, 'expired', 'This upload expired.');
    }
    $total = (int) $session['parts_total'];
    if ($partNo < 1 || $partNo > $total) {
        throw new UploadRefused(422, 'bad_part', 'Parts are numbered 1 to ' . $total . '.');
    }
    $size = (int) (is_file($tmpPath) ? filesize($tmpPath) : -1);
    $expected = $partNo < $total ? (int) $session['part_bytes'] : (int) $session['byte_size'] - ($total - 1) * (int) $session['part_bytes'];
    if ($size !== $expected) {
        throw new UploadRefused(422, 'bad_part', 'Part ' . $partNo . ' is ' . $size . ' bytes; ' . $expected . ' expected.');
    }
    $dest = store_part_path($id, $partNo);
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new UploadRefused(503, 'storage', 'The store is not writable.');
    }
    if (!@rename($tmpPath, $dest)) {
        if (!@copy($tmpPath, $dest)) {
            throw new UploadRefused(503, 'storage', 'Cannot keep part ' . $partNo . '.');
        }
        @unlink($tmpPath);
    }
    @chmod($dest, 0640);
    $pdo->prepare('INSERT INTO upload_parts (session_id, part_no, byte_size) VALUES (:s, :n, :b) ON CONFLICT (session_id, part_no) DO UPDATE SET byte_size = EXCLUDED.byte_size, received_at = now()')
        ->execute(['s' => $id, 'n' => $partNo, 'b' => $size]);
    $pdo->prepare('UPDATE upload_sessions SET parts_done = (SELECT count(*) FROM upload_parts WHERE session_id = :s) WHERE id = :s')->execute(['s' => $id]);
    $done = (int) one_value($pdo, 'SELECT parts_done FROM upload_sessions WHERE id = :s', ['s' => $id]);
    return ['part_no' => $partNo, 'parts_done' => $done, 'parts_total' => $total];
}

/**
 * Finish: assemble, hash, judge, claim the blob, add the version. $conflict answers a 409 met at the finish ('version' | 'keep_both'; '' = refuse).
 * Returns [document_id, version_id, version_no, deduplicated, name, new_document].
 */
function upload_finish(PDO $pdo, array $session, string $conflict = ''): array
{
    $id = (int) $session['id'];
    if (!in_array($session['status'], ['open', 'assembling'], true)) {
        throw new UploadRefused(409, 'not_open', 'This upload is ' . $session['status'] . '.');
    }
    if (strtotime((string) $session['expires_at']) < time()) {
        throw new UploadRefused(410, 'expired', 'This upload expired.');
    }
    $folderId = (int) $session['folder_id'];
    $by = (int) $session['member_id'];
    $name = (string) ($session['document_name'] ?: $session['filename']);
    $documentId = $session['document_id'] === null ? null : (int) $session['document_id'];
    $mode = (string) $session['mode'];
    // the same-name rule, again (a conflict may have appeared since the start — or the caller answers it now)
    if ($documentId === null) {
        $existing = document_named($pdo, $folderId, $name);
        if ($existing !== null) {
            if ($conflict === 'version' || $mode === 'version') {
                $documentId = (int) $existing['id'];
            } elseif ($conflict === 'keep_both' || $mode === 'keep_both') {
                $name = (string) one_value($pdo, 'SELECT dc_free_name(:f, :n)', ['f' => $folderId, 'n' => $name]);
            } else {
                $pdo->prepare("UPDATE upload_sessions SET document_name = :n WHERE id = :s")->execute(['n' => $name, 's' => $id]);
                throw new UploadRefused(409, 'name_taken', 'A document named ' . $existing['name'] . ' is already here.', ['existing_document_id' => (int) $existing['id'], 'existing_version_count' => (int) $existing['version_count'], 'session_id' => $id]);
            }
        }
    }
    if ($documentId !== null) {
        $holder = lock_held_by_another($pdo, $documentId, $by);
        if ($holder !== null) {
            throw new UploadRefused(423, 'locked', 'Locked by ' . $holder . '.');
        }
    }
    $deduplicated = false;
    $sha = (string) ($session['sha256_claimed'] ?? '');
    $size = (int) $session['byte_size'];
    $assembled = store_assembled_path($id);
    if ((int) $session['parts_done'] === 0 && $session['status'] === 'assembling' && $sha !== '' && is_file(dc_storage_path(dc_blob_relative($sha)))) {
        $deduplicated = true;                                       // the start found the bytes already here: nothing arrived, nothing to assemble
    } else {
        $total = (int) $session['parts_total'];
        $st = $pdo->prepare('SELECT part_no FROM upload_parts WHERE session_id = :s');
        $st->execute(['s' => $id]);
        $have = array_map('intval', array_column($st->fetchAll(), 'part_no'));
        $missing = array_values(array_diff(range(1, $total), $have));
        foreach ($have as $n) {
            if (!is_file(store_part_path($id, $n))) {
                $missing[] = $n;
            }
        }
        if ($missing !== []) {
            sort($missing);
            throw new UploadRefused(422, 'missing_parts', 'Part ' . $missing[0] . ' missing' . (count($missing) > 1 ? ' (and ' . (count($missing) - 1) . ' more)' : '') . '.', ['missing' => $missing]);
        }
        $pdo->prepare("UPDATE upload_sessions SET status = 'assembling' WHERE id = :s")->execute(['s' => $id]);
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new UploadRefused(503, 'storage', 'Cannot assemble the file.');
        }
        $h = hash_init('sha256');
        $written = 0;
        for ($n = 1; $n <= $total; $n++) {
            $in = fopen(store_part_path($id, $n), 'rb');
            if ($in === false) {
                fclose($out);
                upload_fail($pdo, $id, 'part ' . $n . ' unreadable');
                throw new UploadRefused(422, 'missing_parts', 'Part ' . $n . ' could not be read.', ['missing' => [$n]]);
            }
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($h, $chunk);
                fwrite($out, $chunk);
                $written += strlen($chunk);
            }
            fclose($in);
        }
        fclose($out);
        $got = hash_final($h);
        if ($written !== $size) {
            @unlink($assembled);
            upload_fail($pdo, $id, 'size mismatch: ' . $written . ' of ' . $size);
            throw new UploadRefused(422, 'size_mismatch', 'The parts add up to ' . $written . ' bytes, not ' . $size . '.');
        }
        if ($sha !== '' && $sha !== $got) {
            @unlink($assembled);
            upload_fail($pdo, $id, 'hash mismatch');
            throw new UploadRefused(422, 'hash_mismatch', 'The file\'s sha256 is not the one the upload claimed.');
        }
        $sha = $got;
    }
    $judge = $deduplicated ? null : store_judge_type($assembled, (string) $session['filename']);
    $mime = $judge['mime'] ?? (string) (one_value($pdo, 'SELECT v.mime_type FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE b.sha256 = :s ORDER BY v.id DESC LIMIT 1', ['s' => $sha]) ?? 'application/octet-stream');
    if ($deduplicated && $judge === null) {
        $judge = store_judge_type(dc_storage_path(dc_blob_relative($sha)), (string) $session['filename']);
        $mime = $judge['mime'];
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT blob_id, storage_path, existed FROM dc_blob_claim(:s, :n)');
        $st->execute(['s' => $sha, 'n' => $size]);
        $claim = $st->fetch();
        if (!$deduplicated) {
            if ($claim['existed'] && is_file(dc_storage_path((string) $claim['storage_path']))) {
                @unlink($assembled);                                     // the same bytes are already here: de-duplicated
                $deduplicated = true;
            } else {
                store_move_into_blobs($assembled, $sha);                 // a new blob (or one whose file was freed and must come back)
            }
        }
        $st = $pdo->prepare('SELECT document_id, version_id, version_no FROM dc_version_add(:d, :f, :name, :b, :fn, :m, :n, :s, :by, :src, :note)');
        $st->execute(['d' => $documentId, 'f' => $folderId, 'name' => $name, 'b' => (int) $claim['blob_id'], 'fn' => $session['filename'], 'm' => $mime, 'n' => $size, 's' => $sha, 'by' => $by,
                      'src' => $session['source'] ?? 'web', 'note' => $session['note'] ?? null]);
        $v = $st->fetch();
        $pdo->prepare("UPDATE upload_sessions SET status = 'done', version_id = :v, document_id = :d, document_name = :n WHERE id = :s")
            ->execute(['v' => (int) $v['version_id'], 'd' => (int) $v['document_id'], 'n' => $name, 's' => $id]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((string) $e->getCode() === '23505') {
            throw new UploadRefused(409, 'name_taken', 'A document named ' . $name . ' is already here.', ['session_id' => $id]);
        }
        if (in_array((string) $e->getCode(), ['P0001', '23514'], true)) {
            upload_fail($pdo, $id, db_message($e, 'refused'));
            throw new UploadRefused(422, 'refused', db_message($e, 'That could not be stored.'));
        }
        throw $e;
    }
    store_unlink('uploads/' . $id);
    return ['document_id' => (int) $v['document_id'], 'version_id' => (int) $v['version_id'], 'version_no' => (int) $v['version_no'], 'deduplicated' => $deduplicated, 'name' => $name,
            'new_document' => $documentId === null, 'mime' => $mime, 'kind' => dc_mime_kind_of($mime), 'inline_ok' => (bool) ($judge['inline_ok'] ?? false), 'refused_reason' => $judge['refused_reason'] ?? null];
}

function upload_fail(PDO $pdo, int $sessionId, string $why): void
{
    $pdo->prepare("UPDATE upload_sessions SET status = 'failed', error = :e WHERE id = :s")->execute(['e' => mb_substr($why, 0, 500), 's' => $sessionId]);
}

/** Discard a session (the uploader gave up, or skipped a conflict): the parts removed, the row expired. */
function upload_discard(PDO $pdo, array $session): void
{
    $pdo->prepare("UPDATE upload_sessions SET status = 'expired' WHERE id = :s AND status IN ('open', 'assembling')")->execute(['s' => (int) $session['id']]);
    store_unlink('uploads/' . (int) $session['id']);
}

/**
 * A file that arrived whole ($file: name, tmp_name, size — a PHP upload or a temp file): start + one part + finish in one go.
 * $o: name?, document_id?, on_conflict (version|keep_both|refuse; version by default), note?, source. Returns upload_finish's answer.
 */
function upload_one(PDO $pdo, int $folderId, array $file, int $by, array $o = []): array
{
    $tmp = (string) $file['tmp_name'];
    $size = (int) ($file['size'] ?? (is_file($tmp) ? filesize($tmp) : 0));
    $onConflict = (string) ($o['on_conflict'] ?? 'version');
    $mode = $onConflict === 'keep_both' ? 'keep_both' : ($onConflict === 'refuse' ? 'new' : 'version');
    $start = upload_start($pdo, $folderId, ['filename' => (string) $file['name'], 'byte_size' => $size, 'name' => $o['name'] ?? null, 'document_id' => $o['document_id'] ?? null, 'mode' => $mode,
                                            'note' => $o['note'] ?? null, 'source' => $o['source'] ?? 'web', 'sha256' => null], $by);
    $session = upload_session($pdo, (int) $start['session_id']);
    // one part of the whole file (the session's part size may be smaller: the whole-file door writes it as parts of part_bytes)
    $partBytes = (int) $session['part_bytes'];
    $total = (int) $session['parts_total'];
    if ($total === 1) {
        upload_part($pdo, $session, 1, $tmp);
    } else {
        $in = fopen($tmp, 'rb');
        for ($n = 1; $n <= $total; $n++) {
            $p = $tmp . '.p' . $n;
            $out = fopen($p, 'wb');
            $left = $n < $total ? $partBytes : $size - ($total - 1) * $partBytes;
            while ($left > 0 && !feof($in)) {
                $chunk = fread($in, (int) min(1048576, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
                $left -= strlen($chunk);
            }
            fclose($out);
            upload_part($pdo, $session, $n, $p);
        }
        fclose($in);
        @unlink($tmp);
    }
    $session = upload_session($pdo, (int) $start['session_id']);
    return upload_finish($pdo, $session, $onConflict === 'keep_both' ? 'keep_both' : ($onConflict === 'refuse' ? '' : 'version'));
}
