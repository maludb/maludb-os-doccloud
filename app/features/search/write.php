<?php
declare(strict_types=1);

/** The writes of search (slice 4): the log row every search leaves (never the full text of anything), saved searches saved and deleted. */

/** The search log: the query cut at 120, the filters, the count, the ms, the source (web, api, mcp). */
function search_log_write(PDO $pdo, string $kind, string $q, array $filters, int $results, int $ms, string $source = 'web'): void
{
    $pdo->prepare('INSERT INTO search_log (member_id, kind, query, filters, results, took_ms, source) VALUES (:m, :k, :q, CAST(:f AS jsonb), :r, :ms, :s)')
        ->execute(['m' => current_member_id(), 'k' => $kind, 'q' => mb_substr($q, 0, 120), 'f' => json_encode((object) $filters), 'r' => $results, 'ms' => $ms, 's' => $source]);
}

/** Save (or change, when $f['saved_search'] names mine): name, query, filters, kind, is_shared. */
function saved_search_save(PDO $pdo, array $f, int $by): int
{
    $name = trim((string) ($f['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        throw new DomainException('Name the search (up to 80 characters).');
    }
    $kind = in_array($f['kind'] ?? 'keyword', ['keyword', 'graph'], true) ? $f['kind'] : 'keyword';
    $filters = json_encode((object) ($f['filters'] ?? []));
    if (!empty($f['saved_search'])) {
        $st = $pdo->prepare('UPDATE saved_searches SET name = :n, query = :q, filters = CAST(:f AS jsonb), kind = :k, is_shared = :s WHERE id = :id AND owner_id = :by RETURNING id');
        $st->execute(['n' => $name, 'q' => (string) ($f['query'] ?? ''), 'f' => $filters, 'k' => $kind, 's' => !empty($f['is_shared']) ? 't' : 'f', 'id' => (int) $f['saved_search'], 'by' => $by]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new DomainException('Not found.');
        }
        return (int) $id;
    }
    $st = $pdo->prepare('INSERT INTO saved_searches (owner_id, name, query, filters, kind, is_shared) VALUES (:by, :n, :q, CAST(:f AS jsonb), :k, :s)
                         ON CONFLICT (owner_id, name) DO UPDATE SET query = EXCLUDED.query, filters = EXCLUDED.filters, kind = EXCLUDED.kind, is_shared = EXCLUDED.is_shared RETURNING id');
    $st->execute(['by' => $by, 'n' => $name, 'q' => (string) ($f['query'] ?? ''), 'f' => $filters, 'k' => $kind, 's' => !empty($f['is_shared']) ? 't' : 'f']);
    return (int) $st->fetchColumn();
}

function saved_search_delete(PDO $pdo, int $id, int $by): bool
{
    $st = $pdo->prepare('DELETE FROM saved_searches WHERE id = :id AND owner_id = :by');
    $st->execute(['id' => $id, 'by' => $by]);
    return $st->rowCount() === 1;
}
