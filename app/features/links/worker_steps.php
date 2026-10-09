<?php
declare(strict_types=1);

/**
 * The worker's link passes (slice 3; visibility.md §6.5): requests past their expiry revoked with the maker told once; links past their expiry
 * logged link.expire once (the database refuses an expired link by itself — the log row is for the trail and the Clerk).
 */

/** Requests past expires_at: revoked (revoked_by NULL = the worker), the maker told once (deduped by the link). */
function worker_requests_close(PDO $pdo, int $limit = 200): array
{
    $out = ['closed' => 0];
    $st = $pdo->prepare("SELECT l.id, l.label, l.made_by, l.folder_id, f.name AS folder_name FROM share_links l JOIN folders f ON f.id = l.folder_id
                          WHERE l.kind = 'request' AND l.revoked_at IS NULL AND l.rotated_at IS NULL AND l.expires_at IS NOT NULL AND l.expires_at <= now() ORDER BY l.expires_at LIMIT :lim");
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE share_links SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL')->execute(['id' => (int) $r['id']]);
            log_activity($pdo, 'request.close', 'link', (int) $r['id'], ['actor_member_id' => null, 'folder_id' => (int) $r['folder_id'], 'after' => ['label' => $r['label'], 'reason' => 'expired']]);
            $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, NULL, :f, NULL, :dd)')->execute(['m' => (int) $r['made_by'], 'k' => 'request_received', 't' => 'The file request ' . $r['label'] . ' expired',
                'b' => 'It no longer receives files into ' . $r['folder_name'] . '. Make a new one when you need more.', 'rt' => 'link', 'rid' => (int) $r['id'], 'f' => (int) $r['folder_id'], 'dd' => 'request:expired:' . (int) $r['id']]);
            $pdo->commit();
            $out['closed']++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('requests_close: ' . $e->getMessage());
        }
    }
    return $out;
}

/** Links (kind view) past expires_at not yet logged as expired: one link.expire row each. */
function worker_links_expire(PDO $pdo, int $limit = 200): array
{
    $out = ['expired' => 0];
    $st = $pdo->prepare("SELECT l.id, l.label, l.folder_id, l.document_id FROM share_links l
                          WHERE l.kind = 'view' AND l.revoked_at IS NULL AND l.rotated_at IS NULL AND l.expires_at IS NOT NULL AND l.expires_at <= now()
                            AND NOT EXISTS (SELECT 1 FROM activity_log a WHERE a.action = 'link.expire' AND a.entity_type = 'link' AND a.entity_id = l.id)
                          ORDER BY l.expires_at LIMIT :lim");
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        log_activity($pdo, 'link.expire', 'link', (int) $r['id'], ['actor_member_id' => null, 'folder_id' => $r['folder_id'] === null ? null : (int) $r['folder_id'], 'document_id' => $r['document_id'] === null ? null : (int) $r['document_id'], 'after' => ['label' => $r['label']]]);
        $out['expired']++;
    }
    return $out;
}
