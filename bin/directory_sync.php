<?php
declare(strict_types=1);

/**
 * The mirror's timer (sign-on-and-directory.md §4): every minute, GET the change feed since the stored cursor and
 * apply it — members, departments, memberships, and each affected member's holding (access[]: capability and roles).
 * --full refreshes the whole directory. Advisory-locked; logs directory.sync with the counts (source cron).
 *   php bin/directory_sync.php [--full]
 *   php bin/directory_sync.php --from-file bin/dev_directory.json    a fixture in the kernel's format (testing-without-a-kernel.md)
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['full', 'from-file:']);
$full = isset($opts['full']);
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('doccloud_directory_sync'))")->fetchColumn()) {
    echo "another sync holds the lock — skipping.\n";
    exit(0);
}
$state = $pdo->query('SELECT next_cursor FROM directory_sync_state WHERE id = 1')->fetch();
$cursor = $full ? null : ($state['next_cursor'] ?? null);

$docs = [];
if (isset($opts['from-file'])) {
    $fixture = json_decode((string) file_get_contents((string) $opts['from-file']), true);
    if (!is_array($fixture) || !is_array($fixture['feed'] ?? null)) {
        fwrite(STDERR, "the fixture has no feed\n");
        exit(1);
    }
    $docs[] = $fixture['feed'];
} else {
    $feed = directory_read('changes.php' . ($cursor !== null ? '?since=' . rawurlencode((string) $cursor) : ''));
    if ($feed === null || ($feed['schema'] ?? '') !== 'os.directory-changes/1') {
        $pdo->prepare('UPDATE directory_sync_state SET last_run_at = now(), last_error = :e, updated_at = now() WHERE id = 1')
            ->execute(['e' => 'the kernel did not answer the change feed']);
        log_activity($pdo, 'directory.sync.failed', null, null, ['actor_member_id' => null, 'after' => ['cursor' => $cursor]]);
        fwrite(STDERR, "the kernel did not answer the change feed\n");
        exit(1);
    }
    $docs[] = $feed;
}

$pdo->beginTransaction();
try {
    $counts = ['members' => 0, 'departments' => 0, 'memberships' => 0, 'access' => 0];
    $next = null;
    $wasFull = false;
    foreach ($docs as $doc) {
        foreach (mirror_apply_feed($pdo, $doc) as $k => $v) {
            $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
        $next = $doc['next'] ?? $next;
        $wasFull = $wasFull || !empty($doc['full']);
    }
    // every synced active non-external human has a personal library before they look for it; department groups follow the directory (sso-shell.md)
    $made = $pdo->query("SELECT m.id, dc_library_ensure_personal(m.id) AS library_id FROM members m
                          WHERE m.member_kind = 'human' AND NOT m.is_external AND m.status = 'active' AND m.capability IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM libraries l WHERE l.kind = 'personal' AND l.owner_member_id = m.id)")->fetchAll();
    foreach ($made as $row) {
        if ($row['library_id'] !== null) {
            log_activity($pdo, 'library.create', 'library', (int) $row['library_id'], ['actor_member_id' => null, 'library_id' => (int) $row['library_id'], 'after' => ['kind' => 'personal', 'owner_member_id' => (int) $row['id']]]);
        }
    }
    $counts['libraries'] = count($made);
    $counts['group_members'] = (int) $pdo->query('SELECT dc_groups_sync_departments()')->fetchColumn();
    $pdo->prepare('UPDATE directory_sync_state SET next_cursor = COALESCE(:n, next_cursor), full_at = CASE WHEN :full THEN now() ELSE full_at END,
                          last_run_at = now(), last_error = NULL, updated_at = now() WHERE id = 1')
        ->execute(['n' => $next, 'full' => $wasFull ? 't' : 'f']);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('directory_sync: ' . $e->getMessage());
    $pdo->prepare('UPDATE directory_sync_state SET last_run_at = now(), last_error = :e, updated_at = now() WHERE id = 1')->execute(['e' => 'apply failed']);
    exit(1);
}
if (array_sum($counts) > 0 || $wasFull) {
    log_activity($pdo, 'directory.sync', null, null, ['actor_member_id' => null, 'after' => $counts + ['full' => $wasFull]]);
}
printf("applied %d members, %d departments, %d memberships, %d holdings, %d personal libraries made, %d group memberships followed%s; cursor %s\n", $counts['members'], $counts['departments'],
    $counts['memberships'], $counts['access'], $counts['libraries'], $counts['group_members'], $wasFull ? ' (full)' : '', $next ?? '(unchanged)');
