<?php
declare(strict_types=1);

/**
 * THE WORKER'S FILE PASSES (docs/build-specs/uploads-versions.md "The flow" 5; uploads-and-versions.md §5, §6, §8): text extraction, thumbnails,
 * exports, abandoned upload sessions, expired exports, stale locks. Each idempotent, each its own transactions, each answering counts. The
 * versions and trash windows and the blob freeing are slice 6's passes (the data-owner rule). bin/worker.php calls these.
 */
require_once dirname(__DIR__, 2) . '/doccloud/extract/extract.php';
require_once dirname(__DIR__, 2) . '/doccloud/zip.php';

/** Pending versions → dc_extract_text → document_text; 50 a pass under a 50 s budget; the owner told once when it failed. */
function worker_extract(PDO $pdo, int $limit = 50, int $budgetSeconds = 50): array
{
    $out = ['extracted' => 0, 'no_text' => 0, 'unsupported' => 0, 'failed' => 0];
    $started = microtime(true);
    $maxText = (int) one_value($pdo, "SELECT dc_setting_int('max_text_bytes')");
    if ($maxText > 0) {
        putenv('DC_MAX_TEXT_BYTES=' . $maxText);
    }
    for ($i = 0; $i < $limit; $i++) {
        if (microtime(true) - $started > $budgetSeconds) {
            break;
        }
        $pdo->beginTransaction();
        try {
            $st = $pdo->query("SELECT v.id, v.document_id, v.mime_type, v.filename, b.storage_path, d.owner_member_id, d.name, d.folder_id, d.library_id
                                 FROM document_versions v JOIN blobs b ON b.id = v.blob_id JOIN documents d ON d.id = v.document_id
                                WHERE v.extracted_status = 'pending' AND v.purged_at IS NULL ORDER BY v.uploaded_at LIMIT 1 FOR UPDATE OF v SKIP LOCKED");
            $v = $st->fetch();
            if ($v === false) {
                $pdo->rollBack();
                break;
            }
            $path = dc_storage_path((string) $v['storage_path']);
            $kind = dc_mime_kind_of((string) $v['mime_type']);
            $readable = in_array($kind, ['pdf', 'office', 'text'], true) || in_array((string) $v['mime_type'], ['text/html', 'application/xml', 'application/xhtml+xml'], true);
            if (!is_file($path)) {
                $r = ['status' => 'failed', 'text' => '', 'pages' => [], 'extractor' => null, 'char_count' => 0, 'error' => 'file missing', 'note' => null];
            } elseif ($kind === 'image') {
                $r = ['status' => 'no_text', 'text' => '', 'pages' => [], 'extractor' => null, 'char_count' => 0, 'error' => null, 'note' => 'an image'];
            } elseif (!$readable) {
                $r = ['status' => 'unsupported', 'text' => '', 'pages' => [], 'extractor' => null, 'char_count' => 0, 'error' => null, 'note' => $kind];   // never the extractor on bytes we do not read (a 20 MB binary cost 14 s as "text")
            } else {
                $r = dc_extract_text($path, (string) $v['mime_type'], (string) $v['filename']);
            }
            $status = in_array($r['status'] ?? '', ['ready', 'no_text', 'unsupported', 'failed'], true) ? $r['status'] : 'failed';
            $pdo->prepare('INSERT INTO document_text (version_id, document_id, text, pages, extractor, char_count, truncated, error)
                           VALUES (:v, :d, :t, CAST(:p AS jsonb), :x, :c, :tr, :e)
                           ON CONFLICT (version_id) DO UPDATE SET text = EXCLUDED.text, pages = EXCLUDED.pages, extractor = EXCLUDED.extractor, char_count = EXCLUDED.char_count, truncated = EXCLUDED.truncated, error = EXCLUDED.error, extracted_at = now()')
                ->execute(['v' => (int) $v['id'], 'd' => (int) $v['document_id'], 't' => $status === 'ready' ? (string) $r['text'] : '', 'p' => json_encode($status === 'ready' ? ($r['pages'] ?? []) : []),
                           'x' => $r['extractor'] ?? null, 'c' => $status === 'ready' ? (int) ($r['char_count'] ?? strlen((string) $r['text'])) : 0,
                           'tr' => str_starts_with((string) ($r['note'] ?? ''), 'truncated') ? 'true' : 'false', 'e' => $status === 'ready' ? null : mb_substr((string) ($r['error'] ?? $r['note'] ?? $status), 0, 500)]);
            $pdo->prepare('UPDATE document_versions SET extracted_status = :s WHERE id = :v')->execute(['s' => $status, 'v' => (int) $v['id']]);
            log_activity($pdo, 'document.text_extracted', 'document', (int) $v['document_id'], ['source' => 'cron', 'actor_member_id' => null, 'document_id' => (int) $v['document_id'], 'folder_id' => (int) $v['folder_id'], 'library_id' => (int) $v['library_id'],
                'after' => ['version_id' => (int) $v['id'], 'status' => $status, 'extractor' => $r['extractor'] ?? null, 'char_count' => $status === 'ready' ? (int) ($r['char_count'] ?? 0) : 0, 'truncated' => str_starts_with((string) ($r['note'] ?? ''), 'truncated')]]);
            if ($status === 'failed') {
                $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, NULL, :dd)')->execute(['m' => (int) $v['owner_member_id'], 'k' => 'extraction_failed', 't' => 'The text of ' . $v['name'] . ' could not be read',
                    'b' => mb_substr((string) ($r['error'] ?? ''), 0, 200), 'rt' => 'document', 'rid' => (int) $v['document_id'], 'd' => (int) $v['document_id'], 'f' => (int) $v['folder_id'], 'dd' => 'extract_failed:' . (int) $v['id']]);
            }
            $pdo->commit();
            $out[$status === 'ready' ? 'extracted' : $status]++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('extract: ' . $e->getMessage());
            if (isset($v['id'])) {
                $pdo->prepare("UPDATE document_versions SET extracted_status = 'failed' WHERE id = :v")->execute(['v' => (int) $v['id']]);
            }
            $out['failed']++;
        }
    }
    return $out;
}

/**
 * Thumbnails (docs/build-specs/grid-and-media.md): the current version of each live image, PDF or video whose thumb_state is still NULL — or
 * no_tool when the tool it waits on now exists — newest first. A thumbnail already on disk from before db/023 is recorded `made` without being
 * remade (unless it is a JPEG photo whose EXIF orientation is not upright: remade once); the old `.none` marker is recorded `none`. At most
 * $limit tool runs a pass. A video's length is kept on its version.
 */
function worker_thumbs(PDO $pdo, int $limit = 50): array
{
    $made = 0;
    $skipped = 0;
    $waiting = 0;
    $recorded = 0;
    $retry = [];
    if (dc_tool('ffmpeg') !== null) {
        $retry[] = "v.mime_type LIKE 'video/%'";
        $retry[] = "v.mime_type LIKE 'image/%'";
    }
    if (dc_tool('pdftoppm') !== null) {
        $retry[] = "v.mime_type = 'application/pdf'";
    }
    $retrySql = $retry === [] ? 'false' : '(' . implode(' OR ', $retry) . ')';
    $st = $pdo->prepare("SELECT d.id, d.current_version_id, v.mime_type, v.thumb_state, b.storage_path
                           FROM documents d JOIN document_versions v ON v.id = d.current_version_id JOIN blobs b ON b.id = v.blob_id
                          WHERE d.trashed_at IS NULL AND d.mime_kind IN ('image', 'pdf', 'video') AND v.purged_at IS NULL
                            AND (v.thumb_state IS NULL OR (v.thumb_state = 'no_tool' AND $retrySql))
                            AND (v.extracted_status <> 'pending' OR v.uploaded_at < now() - interval '1 minute')
                          ORDER BY v.uploaded_at DESC LIMIT :n");
    $st->bindValue('n', $limit * 4, PDO::PARAM_INT);
    $st->execute();
    $set = $pdo->prepare('UPDATE document_versions SET thumb_state = :s, duration_ms = COALESCE(:ms, duration_ms) WHERE id = :v');
    foreach ($st->fetchAll() as $d) {
        if ($made + $skipped + $waiting >= $limit) {
            break;
        }
        $id = (int) $d['id'];
        $vid = (int) $d['current_version_id'];
        $mime = (string) $d['mime_type'];
        $thumb = store_thumb_path($id, $vid);
        if ($d['thumb_state'] === null) {
            if (is_file($thumb . '.none')) {                         // slice 2's marker: tried once, not possible
                $set->execute(['s' => 'none', 'ms' => null, 'v' => $vid]);
                @unlink($thumb . '.none');
                $recorded++;
                continue;
            }
            if (is_file($thumb) && !str_starts_with($mime, 'video/') && dc_exif_orientation(dc_storage_path((string) $d['storage_path']), $mime) === 1) {
                $set->execute(['s' => 'made', 'ms' => null, 'v' => $vid]);
                $recorded++;
                continue;
            }
        }
        $r = dc_make_thumb((string) $d['storage_path'], $mime, $id, $vid);
        $set->execute(['s' => $r['state'], 'ms' => $r['duration_ms'], 'v' => $vid]);
        if ($r['state'] === 'made') {
            $made++;
            log_activity($pdo, 'thumb.made', 'document', $id, ['source' => 'cron', 'actor_member_id' => null, 'document_id' => $id, 'after' => ['version_id' => $vid]]);
        } elseif ($r['state'] === 'no_tool') {
            $waiting++;
        } else {
            $skipped++;
        }
    }
    return ['made' => $made, 'skipped' => $skipped, 'waiting' => $waiting, 'recorded' => $recorded];
}

/** Queued exports, one at a time: the zip of what the maker may see; the maker told when it is ready. */
function worker_exports(PDO $pdo, int $limit = 5): array
{
    $built = 0;
    $failed = 0;
    for ($i = 0; $i < $limit; $i++) {
        $pdo->beginTransaction();
        $st = $pdo->query("SELECT * FROM exports WHERE status = 'queued' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED");
        $e = $st->fetch();
        if ($e === false) {
            $pdo->rollBack();
            break;
        }
        $pdo->prepare("UPDATE exports SET status = 'running' WHERE id = :id")->execute(['id' => (int) $e['id']]);
        $pdo->commit();
        try {
            $r = dc_zip_build($pdo, $e);
            $pdo->exec("SELECT set_config('app.member_id', '', false)");
            $pdo->prepare("UPDATE exports SET status = 'done', storage_path = :p, byte_size = :b, item_count = :n, finished_at = now(), log = :l WHERE id = :id")
                ->execute(['p' => 'exports/' . (int) $e['id'] . '.zip', 'b' => $r['byte_size'], 'n' => $r['item_count'], 'l' => $r['skipped'] > 0 ? $r['skipped'] . ' not included (not yours to see, or gone)' : null, 'id' => (int) $e['id']]);
            log_activity($pdo, 'export.ready', 'export', (int) $e['id'], ['source' => 'cron', 'actor_member_id' => null, 'folder_id' => $e['folder_id'] === null ? null : (int) $e['folder_id'], 'after' => ['items' => $r['item_count'], 'bytes' => $r['byte_size'], 'skipped' => $r['skipped']]]);
            $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, NULL, :f, NULL, :dd)')->execute(['m' => (int) $e['created_by'], 'k' => 'export_ready', 't' => 'Your zip is ready', 'b' => $r['item_count'] . ' files, ' . format_bytes($r['byte_size']) . '. It is kept for 7 days.',
                'rt' => 'export', 'rid' => (int) $e['id'], 'f' => $e['folder_id'] === null ? null : (int) $e['folder_id'], 'dd' => 'export_ready:' . (int) $e['id']]);
            $built++;
        } catch (Throwable $x) {
            $pdo->exec("SELECT set_config('app.member_id', '', false)");
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('export ' . $e['id'] . ': ' . $x->getMessage());
            $pdo->prepare("UPDATE exports SET status = 'failed', finished_at = now(), log = :l WHERE id = :id")->execute(['l' => mb_substr($x->getMessage(), 0, 500), 'id' => (int) $e['id']]);
            $failed++;
        }
    }
    return ['built' => $built, 'failed' => $failed];
}

/** Abandoned upload sessions: expired by the database, their parts removed; a failed session's directory after an hour. */
function worker_uploads_expire(PDO $pdo): array
{
    $n = 0;
    foreach ($pdo->query('SELECT dc_pass_uploads_expire()')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        store_unlink('uploads/' . (int) $id);
        log_activity($pdo, 'upload.expire', 'upload_session', (int) $id, ['source' => 'cron', 'actor_member_id' => null]);
        $n++;
    }
    foreach ($pdo->query("SELECT id FROM upload_sessions WHERE status IN ('failed', 'expired', 'done') AND updated_at < now() - interval '1 hour'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (is_dir(dc_storage_path('uploads/' . (int) $id))) {
            store_unlink('uploads/' . (int) $id);
        }
    }
    return ['expired' => $n];
}

/** Exports past their date: the rows' paths cleared by the database, the files unlinked. */
function worker_exports_expire(PDO $pdo): array
{
    $n = 0;
    foreach ($pdo->query('SELECT dc_pass_exports_expire()')->fetchAll(PDO::FETCH_COLUMN) as $path) {
        store_unlink((string) $path);
        $n++;
    }
    if ($n > 0) {
        log_activity($pdo, 'export.expire', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['count' => $n]]);
    }
    return ['expired' => $n];
}

/** Locks past their hours, released (dc_pass_locks_expire); each holder told once that theirs expired (lock_broken). Returns ['released' => n, 'told' => n]. */
function worker_locks_expire(PDO $pdo): array
{
    $due = $pdo->query('SELECT id, name, folder_id, library_id, locked_by, locked_at FROM documents WHERE locked_by IS NOT NULL AND lock_expires_at <= now() AND trashed_at IS NULL')->fetchAll();
    $n = (int) one_value($pdo, 'SELECT dc_pass_locks_expire()');
    $told = 0;
    foreach ($due as $d) {
        log_activity($pdo, 'document.lock_expire', 'document', (int) $d['id'], ['source' => 'cron', 'actor_member_id' => null, 'document_id' => (int) $d['id'], 'folder_id' => (int) $d['folder_id'], 'library_id' => (int) $d['library_id'], 'after' => ['holder_member_id' => (int) $d['locked_by']]]);
        $r = $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, NULL, :dd)');
        $r->execute(['m' => (int) $d['locked_by'], 'k' => 'lock_broken', 't' => 'Your lock on ' . $d['name'] . ' expired', 'b' => 'It was released after its time, so others can edit it. Lock it again if you still need it.',
                     'rt' => 'document', 'rid' => (int) $d['id'], 'd' => (int) $d['id'], 'f' => (int) $d['folder_id'], 'dd' => 'lock_expired:' . (int) $d['id'] . ':' . strtotime((string) $d['locked_at'])]);
        $told++;
    }
    return ['released' => $n, 'told' => $told];
}

/** Versions past the window (versions_keep_days, then the last versions_keep_count; the current always kept) purged; logged version.expire with the count. */
function worker_versions_expire(PDO $pdo, int $limit = 1000): array
{
    $st = $pdo->prepare('SELECT dc_pass_versions_expire(:l)');
    $st->execute(['l' => $limit]);
    $n = (int) $st->fetchColumn();
    if ($n > 0) {
        log_activity($pdo, 'version.expire', 'document', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['versions' => $n]]);
    }
    return ['purged' => $n];
}

/** Documents (and then empty folders) past the trash window purged through the database (the graph's forget is queued by its trigger); one document.purge row each, cron. */
function worker_trash_purge(PDO $pdo, int $limit = 500): array
{
    $due = $pdo->prepare("SELECT id, name, folder_id, library_id, byte_size FROM documents WHERE trashed_at IS NOT NULL AND trashed_at < now() - make_interval(days => dc_setting_int('trash_days')::integer) ORDER BY trashed_at LIMIT :l");
    $due->bindValue('l', $limit, PDO::PARAM_INT);
    $due->execute();
    $docs = $due->fetchAll();
    $foldersBefore = (int) one_value($pdo, "SELECT count(*) FROM folders WHERE trashed_at IS NOT NULL");
    $st = $pdo->prepare('SELECT dc_pass_trash_purge(:l)');
    $st->execute(['l' => $limit]);
    $n = (int) $st->fetchColumn();
    foreach ($docs as $d) {
        log_activity($pdo, 'document.purge', 'document', (int) $d['id'], ['source' => 'cron', 'actor_member_id' => null, 'document_id' => (int) $d['id'], 'folder_id' => (int) $d['folder_id'], 'library_id' => (int) $d['library_id'],
            'after' => ['name' => $d['name'], 'byte_size' => (int) $d['byte_size'], 'reason' => 'window']]);
    }
    $folders = max(0, $foldersBefore - (int) one_value($pdo, "SELECT count(*) FROM folders WHERE trashed_at IS NOT NULL"));
    if ($folders > 0) {
        log_activity($pdo, 'folder.purge', 'folder', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['folders' => $folders, 'reason' => 'window']]);
    }
    return ['purged' => $n, 'folders' => $folders];
}

/** Blobs nobody holds (ref_count 0, an hour old): marked deleted by the database, the files unlinked here (a file already gone is fine); logged blob.free with the count. */
function worker_blobs_free(PDO $pdo, int $limit = 500): array
{
    $st = $pdo->prepare('SELECT dc_pass_blobs_free(:l)');
    $st->execute(['l' => $limit]);
    $freed = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $path) {
        store_unlink((string) $path);
        $freed++;
    }
    if ($freed > 0) {
        log_activity($pdo, 'blob.free', 'blob', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['blobs' => $freed]]);
    }
    return ['freed' => $freed];
}

/** Libraries at 90% of their quota: their owners told once a month (quota_near; the bell row of the month is the dedupe), logged quota.near once a month per library. */
function worker_quota_watch(PDO $pdo): array
{
    $month = (string) one_value($pdo, "SELECT to_char(date_trunc('month', now()), 'YYYY-MM-DD')");
    $near = $pdo->query("SELECT l.id, l.kind, l.name, l.owner_member_id, l.created_by, l.group_id, l.used_bytes, l.quota_bytes FROM libraries l
                          WHERE l.archived_at IS NULL AND l.quota_bytes IS NOT NULL AND l.used_bytes >= 0.9 * l.quota_bytes ORDER BY l.id")->fetchAll();
    $told = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'quota_near' AND record_type = 'library' AND record_id = :l AND created_at >= CAST(:month AS date)");
    $out = ['near' => count($near), 'noticed' => 0];
    foreach ($near as $l) {
        $owners = [];
        if ($l['kind'] === 'group') {
            $g = $pdo->prepare("SELECT gm.member_id FROM group_members gm JOIN members m ON m.id = gm.member_id WHERE gm.group_id = :g AND gm.role = 'owner' AND gm.left_at IS NULL AND m.status = 'active'");
            $g->execute(['g' => (int) $l['group_id']]);
            $owners = array_map('intval', $g->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($l['owner_member_id'] !== null) {
            $owners = [(int) $l['owner_member_id']];
        } elseif ($l['created_by'] !== null) {
            $owners = [(int) $l['created_by']];
        }
        $pct = (int) round(100 * (int) $l['used_bytes'] / max(1, (int) $l['quota_bytes']));
        $any = false;
        foreach ($owners as $m) {
            $told->execute(['m' => $m, 'l' => (int) $l['id'], 'month' => $month]);
            if ($told->fetchColumn()) {
                continue;
            }
            $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, NULL, NULL, NULL, :dd)')->execute(['m' => $m, 'k' => 'quota_near', 't' => $l['name'] . ' is ' . $pct . '% full',
                'b' => format_bytes((int) $l['used_bytes']) . ' of ' . format_bytes((int) $l['quota_bytes']) . ' used. Clear out what you no longer need, or ask the admin for more room.',
                'rt' => 'library', 'rid' => (int) $l['id'], 'dd' => 'quota_near:' . (int) $l['id'] . ':' . substr($month, 0, 7) . ':' . $m]);
            $any = true;
            $out['noticed']++;
        }
        if ($any) {
            log_activity($pdo, 'quota.near', 'library', (int) $l['id'], ['source' => 'cron', 'actor_member_id' => null, 'library_id' => (int) $l['id'], 'after' => ['pct' => $pct, 'used_bytes' => (int) $l['used_bytes'], 'quota_bytes' => (int) $l['quota_bytes']]]);
        }
    }
    return $out;
}

/** Proposals nobody decided, the search log, link opens and sent mail past their age (dc_pass_housekeeping): how many rows went. */
function worker_housekeeping(PDO $pdo): array
{
    return ['pruned' => (int) one_value($pdo, 'SELECT dc_pass_housekeeping()')];
}

/** SLICE 4 — the keyword index repaired: dc_search_catch_up(limit); logged index.catch_up with the count when it did anything. */
function worker_search_catch_up(PDO $pdo, int $limit = 500): array
{
    $st = $pdo->prepare('SELECT dc_search_catch_up(:lim)');
    $st->execute(['lim' => $limit]);
    $n = (int) $st->fetchColumn();
    if ($n > 0) {
        log_activity($pdo, 'index.catch_up', 'search', null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['indexed' => $n]]);
    }
    return ['indexed' => $n];
}
