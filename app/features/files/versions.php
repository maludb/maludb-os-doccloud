<?php
declare(strict_types=1);

/** Versions' writes: a restore is a NEW version from the old blob (dc_version_restore); a purge releases the bytes (never the current — the trigger refuses). */

function version_restore(PDO $pdo, int $versionId, int $by): int
{
    return (int) one_value($pdo, 'SELECT dc_version_restore(:v, :by)', ['v' => $versionId, 'by' => $by]);
}

function version_purge(PDO $pdo, int $versionId): bool
{
    $st = $pdo->prepare('UPDATE document_versions SET purged_at = now() WHERE id = :v AND purged_at IS NULL');
    $st->execute(['v' => $versionId]);
    return $st->rowCount() === 1;
}
