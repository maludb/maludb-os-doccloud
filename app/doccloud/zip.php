<?php
declare(strict_types=1);

/**
 * A zip of documents (docs/build-specs/uploads-and-versions.md §8, uploads-versions.md divergence 4): built by the worker for an export —
 * the documents the MAKER may see at build time (dc_visible_among as the maker), each blob added by path (ZipArchive streams it; nothing read
 * into memory), paths = the folder path below the exported folder, names de-duplicated with " (2)". ≤ 10,000 entries, and no more bytes than the store has room for (dc_store_room — no fixed size cap since
 * db/024; ZipArchive writes Zip64 past 4 GB); a file of 64 MB or more is stored as it is (deflating gigabytes of video costs minutes and gains
 * nothing). Returns [item_count, byte_size, skipped] or throws.
 */
function dc_zip_build(PDO $pdo, array $export): array
{
    $maker = (int) $export['created_by'];
    $pdo->exec("SELECT set_config('app.member_id', '" . $maker . "', false)");
    if ($export['kind'] === 'folder') {
        $folderId = (int) $export['folder_id'];
        $st = $pdo->prepare('SELECT d.id, d.name, d.folder_id, d.current_version_id, f.path AS folder_path FROM documents d JOIN folders f ON f.id = d.folder_id
                             WHERE d.folder_id IN (SELECT dc_folder_subtree_ids(:f)) AND d.trashed_at IS NULL AND d.current_version_id IS NOT NULL ORDER BY f.path, lower(d.name)');
        $st->execute(['f' => $folderId]);
        $rootPath = (string) one_value($pdo, 'SELECT path FROM folders WHERE id = :f', ['f' => $folderId]);
    } else {
        $ids = array_values(array_filter(array_map('intval', pg_int_array((string) ($export['document_ids'] ?? '{}'))), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return ['item_count' => 0, 'byte_size' => 0, 'skipped' => 0];
        }
        $st = $pdo->prepare('SELECT d.id, d.name, d.folder_id, d.current_version_id, NULL::text AS folder_path FROM documents d WHERE d.id = ANY (CAST(:ids AS bigint[])) AND d.trashed_at IS NULL AND d.current_version_id IS NOT NULL ORDER BY lower(d.name)');
        $st->execute(['ids' => pg_array_literal($ids)]);
        $rootPath = null;
    }
    $rows = $st->fetchAll();
    $visible = [];
    if ($rows !== []) {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $v = $pdo->prepare('SELECT document_id FROM dc_visible_among(CAST(:ids AS bigint[]))');
        $v->execute(['ids' => pg_array_literal($ids)]);
        foreach ($v->fetchAll() as $r) {
            $visible[(int) $r['document_id']] = true;
        }
    }
    $names = [];
    $folderNames = [];
    if ($rootPath !== null) {
        foreach ($pdo->query('SELECT id, name, parent_id FROM folders')->fetchAll() as $f) {
            $folderNames[(int) $f['id']] = ['name' => $f['name'], 'parent' => $f['parent_id'] === null ? null : (int) $f['parent_id']];
        }
    }
    $dirOf = static function (?string $path) use ($rootPath, $folderNames): string {
        if ($path === null || $rootPath === null) {
            return '';
        }
        $below = substr($path, strlen($rootPath));
        $parts = [];
        foreach (array_filter(explode('/', $below)) as $id) {
            $parts[] = dc_safe_filename($folderNames[(int) $id]['name'] ?? (string) $id);
        }
        return $parts === [] ? '' : implode('/', $parts) . '/';
    };
    $zipPath = store_export_path((int) $export['id']);
    $dir = dirname($zipPath);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot make the exports directory.');
    }
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open the zip for writing.');
    }
    $count = 0;
    $skipped = 0;
    $bytes = 0;
    $room = dc_store_room() ?? PHP_INT_MAX;
    $blob = $pdo->prepare('SELECT b.storage_path, b.byte_size FROM document_versions v JOIN blobs b ON b.id = v.blob_id WHERE v.id = :v AND v.purged_at IS NULL');
    foreach ($rows as $r) {
        if (empty($visible[(int) $r['id']])) {
            $skipped++;
            continue;
        }
        $blob->execute(['v' => (int) $r['current_version_id']]);
        $b = $blob->fetch();
        if ($b === false) {
            $skipped++;
            continue;
        }
        $abs = dc_storage_path((string) $b['storage_path']);
        if (!is_file($abs)) {
            $skipped++;
            continue;
        }
        if ($bytes + (int) $b['byte_size'] > $room || $count >= 10000) {
            $skipped++;
            continue;
        }
        $entry = $dirOf($r['folder_path']) . dc_safe_filename((string) $r['name']);
        $base = $entry;
        $i = 1;
        while (isset($names[strtolower($entry)])) {
            $i++;
            $entry = preg_replace('/(\.[^.\/]{1,12})?$/', ' (' . $i . ')$1', $base, 1) ?? $base . ' (' . $i . ')';
        }
        $names[strtolower($entry)] = true;
        $zip->addFile($abs, $entry);
        if ((int) $b['byte_size'] >= 67108864) {
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
        $count++;
        $bytes += (int) $b['byte_size'];
    }
    $zip->close();
    @chmod($zipPath, 0640);
    return ['item_count' => $count, 'byte_size' => (int) (filesize($zipPath) ?: 0), 'skipped' => $skipped];
}
