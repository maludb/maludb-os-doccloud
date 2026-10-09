<?php
declare(strict_types=1);

/** Exports (a zip the worker builds): make a row; the maker's list; one row. */

function export_make(PDO $pdo, string $kind, ?int $folderId, array $documentIds, int $by): int
{
    $st = $pdo->prepare("INSERT INTO exports (kind, format, folder_id, document_ids, created_by, params) VALUES (:k, 'zip', :f, CAST(:ids AS bigint[]), :by, '{}') RETURNING id");
    $st->execute(['k' => $kind, 'f' => $folderId, 'ids' => pg_array_literal($documentIds), 'by' => $by]);
    return (int) $st->fetchColumn();
}

function my_exports(PDO $pdo): array
{
    return $pdo->query('SELECT e.*, f.name AS folder_name FROM mcp_exports e LEFT JOIN folders f ON f.id = e.folder_id ORDER BY e.created_at DESC LIMIT 100')->fetchAll();
}

function find_export(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT e.*, f.name AS folder_name FROM mcp_exports e LEFT JOIN folders f ON f.id = e.folder_id WHERE e.export_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
