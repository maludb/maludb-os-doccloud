<?php
declare(strict_types=1);

/**
 * Judge again every version stored as application/octet-stream, with the store's rules as they are now (store_judge_type(): the bytes decide)
 * — for a type the store has since learned (2026-10-11: a Sony camera's XAVC MP4, which PHP's own magic does not know). A version whose
 * judgement changes gets the new type and its thumbnail queued again (thumb_state NULL, the worker's next pass makes the poster); when it is
 * the document's current version, the document takes the type too (its kind follows by trigger — the grid, the player — and its Modified
 * time moves, the documents_touch trigger). One `document.retype` logged per version changed, source cron, with the type before and after.
 * Idempotent: a version judged the same is left alone. Run it as the store's owner (www-data), after a deploy that teaches the store a type.
 *   php bin/retype.php [--dry-run]
 * Prints one line per version changed (or that would be, with --dry-run) and a JSON summary; exits 1 when a file could not be read.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/files/handler.php';

$dry = in_array('--dry-run', array_slice($argv, 1), true);
$pdo = db();
$rows = $pdo->query("SELECT v.id AS version_id, v.document_id, v.filename, v.mime_type, b.storage_path, (d.current_version_id = v.id) AS is_current
                       FROM document_versions v JOIN blobs b ON b.id = v.blob_id JOIN documents d ON d.id = v.document_id
                      WHERE v.mime_type = 'application/octet-stream' AND v.purged_at IS NULL
                      ORDER BY v.id")->fetchAll();
$changed = 0;
$same = 0;
$unreadable = 0;
$setVersion = $pdo->prepare('UPDATE document_versions SET mime_type = :m, thumb_state = NULL WHERE id = :v AND mime_type = :old');
$setDocument = $pdo->prepare('UPDATE documents SET mime_type = :m WHERE id = :d AND current_version_id = :v');
foreach ($rows as $r) {
    $path = dc_storage_path((string) $r['storage_path']);
    if (!is_readable($path)) {
        $unreadable++;
        fwrite(STDERR, "version {$r['version_id']} (document {$r['document_id']}): the file cannot be read\n");
        continue;
    }
    $mime = store_judge_type($path, (string) $r['filename'])['mime'];
    if ($mime === $r['mime_type']) {
        $same++;
        continue;
    }
    echo ($dry ? 'would retype' : 'retyped') . " version {$r['version_id']} of document {$r['document_id']} ({$r['filename']}): {$r['mime_type']} → $mime\n";
    $changed++;
    if ($dry) {
        continue;
    }
    $pdo->beginTransaction();
    try {
        $setVersion->execute(['m' => $mime, 'v' => (int) $r['version_id'], 'old' => $r['mime_type']]);
        if ($r['is_current']) {
            $setDocument->execute(['m' => $mime, 'd' => (int) $r['document_id'], 'v' => (int) $r['version_id']]);
        }
        log_activity($pdo, 'document.retype', 'document', (int) $r['document_id'], ['source' => 'cron', 'actor_member_id' => null, 'document_id' => (int) $r['document_id'],
            'before' => ['mime_type' => $r['mime_type']], 'after' => ['mime_type' => $mime, 'version_id' => (int) $r['version_id']]]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
echo json_encode(['dry_run' => $dry, 'looked_at' => count($rows), ($dry ? 'would_change' : 'changed') => $changed, 'same' => $same, 'unreadable' => $unreadable]), "\n";
exit($unreadable > 0 ? 1 : 0);
