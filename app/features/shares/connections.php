<?php
declare(strict_types=1);

/**
 * What the admin's connections screen shows (slice 7, screen `admin-connections`; read-only): which sibling applications hold an `application` access row, on which folders (dc_application_folders per
 * catalog key) and on which single documents; and the share reads logged (`share.read`, `share.refused` with direction `in`) per consumer and tool. Approving a connection is the
 * kernel's (bin/app_connection.php / the OS) — this application only shows that its own side is shared.
 */

/** Every application with an access row: [key, folders[{folder_id, path, level, document_count, library_id}], documents[{document_id, name, level}], reads[{tool, calls, refused, last_at}]]. */
function shares_connections(PDO $pdo, int $days = 30): array
{
    $keys = $pdo->query("SELECT principal_ref AS k FROM folder_access WHERE principal_kind = 'application' AND level <> 'none'
                         UNION SELECT principal_ref FROM document_access WHERE principal_kind = 'application' AND level <> 'none'
                         UNION SELECT after->>'consumer' FROM activity_log WHERE action IN ('share.read', 'share.refused') AND after->>'direction' = 'in' AND after->>'consumer' IS NOT NULL
                         ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($keys as $k) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $k)) {
            continue;
        }
        $f = $pdo->prepare('SELECT folder_id, path, library_id, level, document_count FROM dc_application_folders(:k)');
        $f->execute(['k' => $k]);
        $d = $pdo->prepare("SELECT d.id AS document_id, d.name, da.level FROM document_access da JOIN documents d ON d.id = da.document_id
                             WHERE da.principal_kind = 'application' AND da.principal_ref = :k AND da.level <> 'none' AND d.trashed_at IS NULL ORDER BY d.name LIMIT 100");
        $d->execute(['k' => $k]);
        $r = $pdo->prepare("SELECT after->>'tool' AS tool, count(*) FILTER (WHERE action = 'share.read') AS calls, count(*) FILTER (WHERE action = 'share.refused') AS refused, max(occurred_at) AS last_at
                              FROM activity_log WHERE action IN ('share.read', 'share.refused') AND after->>'direction' = 'in' AND after->>'consumer' = :k AND occurred_at > now() - make_interval(days => :d)
                             GROUP BY 1 ORDER BY 1");
        $r->execute(['k' => $k, 'd' => $days]);
        $out[] = ['application' => (string) $k, 'folders' => $f->fetchAll(), 'documents' => $d->fetchAll(), 'reads' => $r->fetchAll()];
    }
    return $out;
}

/** The most recent share reads and refusals (the reads log): [occurred_at, action, tool, consumer, rows, outcome, reason, ms]. */
function shares_recent_reads(PDO $pdo, int $limit = 30): array
{
    $st = $pdo->prepare("SELECT occurred_at, action, after->>'tool' AS tool, after->>'consumer' AS consumer, after->>'rows' AS rows, after->>'outcome' AS outcome, after->>'reason' AS reason, after->>'ms' AS ms
                           FROM activity_log WHERE action IN ('share.read', 'share.refused') AND after->>'direction' = 'in' ORDER BY id DESC LIMIT :n");
    $st->bindValue('n', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function present_connection(array $c): array
{
    return ['application' => $c['application'],
            'folders' => array_map(static fn (array $f): array => ['folder_id' => (int) $f['folder_id'], 'path' => $f['path'], 'library_id' => (int) $f['library_id'], 'level' => $f['level'], 'document_count' => (int) $f['document_count']], $c['folders']),
            'documents' => array_map(static fn (array $d): array => ['document_id' => (int) $d['document_id'], 'name' => $d['name'], 'level' => $d['level']], $c['documents']),
            'reads' => array_map(static fn (array $r): array => ['tool' => $r['tool'], 'calls' => (int) $r['calls'], 'refused' => (int) $r['refused'], 'last_at' => json_ts($r['last_at'])], $c['reads'])];
}
