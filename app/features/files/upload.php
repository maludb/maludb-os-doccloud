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
 * document's name; the file name by default), note?, source?, resume_key? (the browser's fingerprint of the file). The folder's level and the
 * document's level are the CALLER's to have checked; here: the ceiling when an admin set one (413 too_large), the quota (422 quota), the store's
 * room less what open uploads still owe it (507 no_space), a zero-byte file (422 empty), the same-name rule (409 name_taken with
 * existing_document_id unless mode says), the lock (423 locked). The same person choosing the same file for the same place again (resume_key,
 * size, part size) gets the open session back with the parts it holds (`resumed`, `parts_received`). A sha256 that is already a blob needs no
 * parts: parts_total 0, deduplicated. Returns the session's JSON shape.
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
    $ceiling = dc_file_ceiling($pdo);
    if ($ceiling !== null && $size > $ceiling) {
        throw new UploadRefused(413, 'too_large', 'A file is at most ' . format_bytes($ceiling) . ' here (an admin\'s setting).');
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
    $resumeKey = isset($f['resume_key']) && preg_match('/^[0-9a-f]{64}$/', (string) $f['resume_key']) ? (string) $f['resume_key'] : null;
    $partBytes = (int) one_value($pdo, "SELECT dc_setting_int('upload_part_bytes')");
    $partsTotal = max(1, (int) ceil($size / $partBytes));
    $hours = max(1, (int) one_value($pdo, "SELECT dc_setting_int('upload_session_hours')"));
    $note = isset($f['note']) ? mb_substr((string) $f['note'], 0, 500) : null;
    if ($resumeKey !== null) {
        // the same file chosen again for the same place: carry on with the parts already here (never another person's, never another size)
        $st = $pdo->prepare("UPDATE upload_sessions SET mode = :mode, document_name = :dn, note = COALESCE(:note, note), expires_at = GREATEST(expires_at, now() + make_interval(hours => :h))
                             WHERE id = (SELECT id FROM upload_sessions WHERE member_id = :m AND folder_id = :f AND resume_key = :k AND byte_size = :n AND part_bytes = :pb AND status = 'open'
                                         AND writes_at_offset AND expires_at > now() AND document_id IS NOT DISTINCT FROM CAST(:d AS bigint) ORDER BY id DESC LIMIT 1)
                             RETURNING id, expires_at");
        $st->execute(['mode' => $mode, 'dn' => $name, 'note' => $note, 'h' => $hours, 'm' => $by, 'f' => $folderId, 'k' => $resumeKey, 'n' => $size, 'pb' => $partBytes, 'd' => $documentId]);
        $row = $st->fetch();
        if ($row !== false) {
            $id = (int) $row['id'];
            $st = $pdo->prepare('SELECT part_no FROM upload_parts WHERE session_id = :s ORDER BY part_no');
            $st->execute(['s' => $id]);
            return ['session_id' => $id, 'part_bytes' => $partBytes, 'parts_total' => $partsTotal, 'deduplicated' => false, 'expires_at' => $row['expires_at'], 'document_name' => $name, 'mode' => $mode,
                    'document_id' => $documentId, 'resumed' => true, 'parts_received' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
        }
    }
    $dedup = false;
    if ($sha !== null) {
        $st = $pdo->prepare('SELECT b.id FROM blobs b WHERE b.sha256 = :s AND b.byte_size = :n AND b.deleted_at IS NULL');
        $st->execute(['s' => $sha, 'n' => $size]);
        if ($st->fetchColumn() !== false && is_file(dc_storage_path(dc_blob_relative($sha)))) {
            $dedup = true;
            $partsTotal = 0;
        }
    }
    if (!$dedup) {
        $room = dc_store_room();
        $owed = (int) one_value($pdo, "SELECT COALESCE(sum(GREATEST(byte_size - parts_done::bigint * part_bytes, 0)), 0) FROM upload_sessions WHERE status = 'open' AND writes_at_offset AND expires_at > now()");
        if ($room !== null && $size > $room - $owed) {
            throw new UploadRefused(507, 'no_space', 'The store has room for ' . format_bytes(max(0, $room - $owed)) . ' more and this file is ' . format_bytes($size) . '. An admin can free space or add disk.');
        }
    }
    $st = $pdo->prepare('INSERT INTO upload_sessions (member_id, folder_id, document_id, filename, mime_hint, byte_size, part_bytes, parts_total, sha256_claimed, status, expires_at, document_name, mode, source, note, writes_at_offset, resume_key)
                         VALUES (:m, :f, :d, :fn, :mh, :n, :pb, :pt, :sha, :st, now() + make_interval(hours => :h), :dn, :mode, :src, :note, true, :rk) RETURNING id, expires_at');
    $st->execute(['m' => $by, 'f' => $folderId, 'd' => $documentId, 'fn' => $filename, 'mh' => isset($f['mime_hint']) ? mb_substr((string) $f['mime_hint'], 0, 120) : null, 'n' => $size, 'pb' => $partBytes,
                  'pt' => max(1, $partsTotal), 'sha' => $sha, 'st' => $dedup ? 'assembling' : 'open', 'h' => $hours, 'dn' => $name, 'mode' => $mode,
                  'src' => in_array($f['source'] ?? 'web', ['web', 'api', 'agent', 'request'], true) ? $f['source'] : 'web', 'note' => $note, 'rk' => $dedup ? null : $resumeKey]);
    $row = $st->fetch();
    $id = (int) $row['id'];
    if (!$dedup) {
        $dir = dirname(store_upload_path($id));
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new UploadRefused(503, 'storage', 'The store is not writable.');
        }
    }
    return ['session_id' => $id, 'part_bytes' => $partBytes, 'parts_total' => $partsTotal, 'deduplicated' => $dedup, 'expires_at' => $row['expires_at'], 'document_name' => $name, 'mode' => $mode, 'document_id' => $documentId,
            'resumed' => false, 'parts_received' => []];
}

/** A session this code can write: open (or assembling, for the finish), not expired, written at offsets (db/024). Throws UploadRefused. */
function upload_check_session(array $session, array $statuses): void
{
    if (!in_array($session['status'], $statuses, true)) {
        throw new UploadRefused(409, 'not_open', 'This upload is ' . $session['status'] . '.');
    }
    if (strtotime((string) $session['expires_at']) < time()) {
        throw new UploadRefused(410, 'expired', 'This upload expired.');
    }
    if (empty($session['writes_at_offset'])) {
        throw new UploadRefused(409, 'restart', 'This upload began before DocCloud was updated. Start it again.');
    }
}

/** The bytes part $n of a session must have. */
function upload_part_length(array $session, int $n): int
{
    $total = (int) $session['parts_total'];
    return $n < $total ? (int) $session['part_bytes'] : (int) $session['byte_size'] - ($total - 1) * (int) $session['part_bytes'];
}

/** Are the $length bytes of $file the same as the bytes of $data at $offset? */
function upload_range_equals(string $data, int $offset, string $file, int $length): bool
{
    $a = @fopen($data, 'rb');
    $b = @fopen($file, 'rb');
    if ($a === false || $b === false || fseek($a, $offset) !== 0) {
        return false;
    }
    $same = true;
    while ($length > 0) {
        $x = fread($a, (int) min(1048576, $length));
        $y = fread($b, (int) min(1048576, $length));
        if ($x === false || $y === false || $x === '' || $x !== $y) {
            $same = false;
            break;
        }
        $length -= strlen($x);
    }
    fclose($a);
    fclose($b);
    return $same;
}

/**
 * Write $file's $length bytes at $offset of $data (KineticSeas' chunk-at-offset upload: one file, each part at its place, in any order).
 * Throws UploadRefused: 507 no_space when the disk has no room for it, 503 storage otherwise.
 */
function upload_write_at(string $data, int $offset, string $file, int $length): void
{
    $room = dc_store_room();
    if ($room !== null && $room < $length) {
        throw new UploadRefused(507, 'no_space', 'The store is full. An admin can free space or add disk; the upload carries on after that.');
    }
    $in = @fopen($file, 'rb');
    $out = @fopen($data, 'c+b');
    if ($in === false || $out === false || fseek($out, $offset) !== 0) {
        throw new UploadRefused(503, 'storage', 'The store is not writable.');
    }
    $copied = @stream_copy_to_stream($in, $out, $length);
    $flushed = @fflush($out);
    fclose($in);
    fclose($out);
    @chmod($data, 0640);
    if ($copied !== $length || !$flushed) {
        $room = dc_store_room();
        throw ($room !== null && $room < $length) ? new UploadRefused(507, 'no_space', 'The store is full. An admin can free space or add disk; the upload carries on after that.')
                                                 : new UploadRefused(503, 'storage', 'Cannot keep this part.');
    }
}

/**
 * Feed the running sha256 every part that is here in order after the ones it has seen (the caller holds the session's row lock: parts are
 * written and hashed only under it, so a part's bytes never change once hashed). $s = the locked row. Saves the state when it moved.
 * Returns [hashed_parts, HashContext].
 */
function upload_hash_advance(PDO $pdo, array $s, string $data): array
{
    $id = (int) $s['id'];
    $total = (int) $s['parts_total'];
    $hashed = (int) $s['hashed_parts'];
    $ctx = null;
    if ($hashed > 0 && $s['hash_state'] !== null) {
        $ctx = @unserialize((string) base64_decode((string) $s['hash_state'], true), ['allowed_classes' => [HashContext::class]]);
    }
    if (!$ctx instanceof HashContext) {
        $ctx = hash_init('sha256');
        $hashed = 0;
    }
    $st = $pdo->prepare('SELECT part_no FROM upload_parts WHERE session_id = :s AND part_no > :h');
    $st->execute(['s' => $id, 'h' => $hashed]);
    $have = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $from = $hashed;
    $f = null;
    while ($hashed < $total && isset($have[$hashed + 1])) {
        $f ??= @fopen($data, 'rb');
        if ($f === false || fseek($f, $hashed * (int) $s['part_bytes']) !== 0) {
            break;
        }
        $c = hash_copy($ctx);
        $left = upload_part_length($s, $hashed + 1);
        while ($left > 0) {
            $chunk = fread($f, (int) min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($c, $chunk);
            $left -= strlen($chunk);
        }
        if ($left > 0) {
            break;                                                      // a short file: stop where the bytes stop
        }
        $ctx = $c;
        $hashed++;
    }
    if (is_resource($f)) {
        fclose($f);
    }
    if ($hashed !== $from || ((int) $s['hashed_parts'] > 0 && $hashed === 0)) {
        $pdo->prepare('UPDATE upload_sessions SET hashed_parts = :h, hash_state = :st WHERE id = :s')
            ->execute(['h' => $hashed, 'st' => $hashed === 0 ? null : base64_encode(serialize($ctx)), 's' => $id]);
    }
    return [$hashed, $ctx];
}

/**
 * One part: the bytes at $tmpPath (an uploaded file or a raw body already on disk) written at their offset in uploads/<session>/data. A part
 * may come in any order and again (a resume): the same bytes again change nothing; other bytes for a part not yet hashed replace it; other
 * bytes for a part already hashed are refused (409 part_differs — the running sha256 must always be the file's). The session's expiry moves
 * on with every part, so a long upload that keeps arriving never expires. Writing, recording and hashing happen under the session's row lock.
 */
function upload_part(PDO $pdo, array $session, int $partNo, string $tmpPath): array
{
    $id = (int) $session['id'];
    upload_check_session($session, ['open']);
    $total = (int) $session['parts_total'];
    if ($partNo < 1 || $partNo > $total) {
        throw new UploadRefused(422, 'bad_part', 'Parts are numbered 1 to ' . $total . '.');
    }
    $size = (int) (is_file($tmpPath) ? filesize($tmpPath) : -1);
    $expected = upload_part_length($session, $partNo);
    if ($size !== $expected) {
        throw new UploadRefused(422, 'bad_part', 'Part ' . $partNo . ' is ' . $size . ' bytes; ' . $expected . ' expected.');
    }
    $data = store_upload_path($id);
    $dir = dirname($data);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new UploadRefused(503, 'storage', 'The store is not writable.');
    }
    $offset = ($partNo - 1) * (int) $session['part_bytes'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM upload_sessions WHERE id = :s FOR UPDATE');
        $st->execute(['s' => $id]);
        $s = $st->fetch();
        upload_check_session($s, ['open']);
        $known = (bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM upload_parts WHERE session_id = :s AND part_no = :n)', ['s' => $id, 'n' => $partNo]);
        $same = $known && upload_range_equals($data, $offset, $tmpPath, $size);
        if (!$same) {
            if ($known && $partNo <= (int) $s['hashed_parts']) {
                throw new UploadRefused(409, 'part_differs', 'Part ' . $partNo . ' arrived before with other bytes. Start the upload again.');
            }
            try {
                upload_write_at($data, $offset, $tmpPath, $size);
            } catch (UploadRefused $e) {
                // a part half-written is a part missing: it is sent again, and never hashed as it is
                $pdo->prepare('DELETE FROM upload_parts WHERE session_id = :s AND part_no = :n')->execute(['s' => $id, 'n' => $partNo]);
                $pdo->prepare('UPDATE upload_sessions SET parts_done = (SELECT count(*) FROM upload_parts WHERE session_id = :s) WHERE id = :s')->execute(['s' => $id]);
                $pdo->commit();
                throw $e;
            }
        }
        $pdo->prepare('INSERT INTO upload_parts (session_id, part_no, byte_size) VALUES (:s, :n, :b) ON CONFLICT (session_id, part_no) DO UPDATE SET byte_size = EXCLUDED.byte_size, received_at = now()')
            ->execute(['s' => $id, 'n' => $partNo, 'b' => $size]);
        $pdo->prepare("UPDATE upload_sessions SET parts_done = (SELECT count(*) FROM upload_parts WHERE session_id = :s),
                       expires_at = GREATEST(expires_at, now() + make_interval(hours => GREATEST(1, dc_setting_int('upload_session_hours')::int))) WHERE id = :s")->execute(['s' => $id]);
        upload_hash_advance($pdo, $s, $data);
        $done = (int) one_value($pdo, 'SELECT parts_done FROM upload_sessions WHERE id = :s', ['s' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    @unlink($tmpPath);
    return ['part_no' => $partNo, 'parts_done' => $done, 'parts_total' => $total];
}

/**
 * Finish: every part here, the sha256 completed from the running state (the parts were hashed as they came — at most the last few are
 * read now), the type judged, the blob claimed, the version added. Nothing is copied: the one file becomes the blob by a hard link. $conflict
 * answers a 409 met at the finish ('version' | 'keep_both'; '' = refuse). Returns [document_id, version_id, version_no, deduplicated, name, new_document].
 */
function upload_finish(PDO $pdo, array $session, string $conflict = ''): array
{
    $id = (int) $session['id'];
    upload_check_session($session, ['open', 'assembling']);
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
    $data = store_upload_path($id);
    if ((int) $session['parts_done'] === 0 && $session['status'] === 'assembling' && $sha !== '' && is_file(dc_storage_path(dc_blob_relative($sha)))) {
        $deduplicated = true;                                       // the start found the bytes already here: nothing arrived, nothing to assemble
    } else {
        $total = (int) $session['parts_total'];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM upload_sessions WHERE id = :s FOR UPDATE');      // no part is written while the file is judged
            $st->execute(['s' => $id]);
            $s = $st->fetch();
            upload_check_session($s, ['open', 'assembling']);
            $st = $pdo->prepare('SELECT part_no FROM upload_parts WHERE session_id = :s');
            $st->execute(['s' => $id]);
            $missing = array_values(array_diff(range(1, $total), array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
            if ($missing === [] && !is_file($data)) {
                $missing = range(1, $total);
            }
            if ($missing !== []) {
                throw new UploadRefused(422, 'missing_parts', 'Part ' . $missing[0] . ' missing' . (count($missing) > 1 ? ' (and ' . (count($missing) - 1) . ' more)' : '') . '.', ['missing' => $missing]);
            }
            clearstatcache(true, $data);
            $written = (int) filesize($data);
            [$hashed, $ctx] = upload_hash_advance($pdo, $s, $data);
            if ($written !== $size || $hashed !== $total) {
                $pdo->commit();
                upload_fail($pdo, $id, 'size mismatch: ' . $written . ' of ' . $size);
                throw new UploadRefused(422, 'size_mismatch', 'The parts add up to ' . $written . ' bytes, not ' . $size . '.');
            }
            $pdo->prepare("UPDATE upload_sessions SET status = 'assembling' WHERE id = :s")->execute(['s' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $got = hash_final(hash_copy($ctx));
        if ($sha !== '' && $sha !== $got) {
            @unlink($data);
            upload_fail($pdo, $id, 'hash mismatch');
            throw new UploadRefused(422, 'hash_mismatch', 'The file\'s sha256 is not the one the upload claimed.');
        }
        $sha = $got;
    }
    $judge = $deduplicated ? null : store_judge_type($data, (string) $session['filename']);
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
                $deduplicated = true;                                    // the same bytes are already here: de-duplicated (the upload's file goes with its directory)
            } else {
                store_move_into_blobs($data, $sha, true);                // a new blob (or one whose file was freed and must come back): a hard link, the upload's file kept until the commit
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
