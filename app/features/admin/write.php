<?php
declare(strict_types=1);

/** Writes of the admin screens of slice 6: a library's quota; running the worker now. SQL and one child process; the controllers judge and log. */

/** Set (or clear with null) a library's quota in bytes. Returns the quota it had. */
function quota_set(PDO $pdo, int $libraryId, ?int $bytes, int $by): ?int
{
    $before = $pdo->prepare('SELECT quota_bytes FROM libraries WHERE id = :id FOR UPDATE');
    $before->execute(['id' => $libraryId]);
    $was = $before->fetchColumn();
    $pdo->prepare('UPDATE libraries SET quota_bytes = :q WHERE id = :id')->execute(['q' => $bytes, 'id' => $libraryId]);
    return $was === false || $was === null ? null : (int) $was;
}

/**
 * "Run a pass now": bin/worker.php in this request, one step by name or all, with a 50 s budget (killed after it). Answers the worker's own report
 * (['at', 'ran', 'errors', 'seconds'] or ['skipped' => …] when another pass holds the lock) plus 'timed_out'.
 */
function worker_run(PDO $pdo, ?string $step = null): array
{
    $php = (string) env('DC_PHP_BIN', '') !== '' ? (string) env('DC_PHP_BIN', '') : (is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php');
    $cmd = [$php, dirname(__DIR__, 3) . '/bin/worker.php'];
    if ($step !== null && $step !== '') {
        $cmd[] = '--passes=' . $step;
    }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) {
        return ['errors' => ['The worker could not be started.'], 'ran' => []];
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $started = microtime(true);
    $timedOut = false;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) - $started > 50) {
            proc_terminate($p, 9);
            $timedOut = true;
            break;
        }
        usleep(100000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($p);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $report = json_decode((string) end($lines), true);
    $report = is_array($report) ? $report : ['ran' => [], 'errors' => ['The worker did not answer.']];
    $report['timed_out'] = $timedOut;
    return $report;
}

/** The retention windows (versions keep days and count, trash days, lock hours) — only the keys given are changed. Returns ['before' => …, 'after' => …] of what differs. */
function retention_set(PDO $pdo, array $f, int $by): array
{
    $keys = ['versions_keep_days', 'versions_keep_count', 'trash_days', 'lock_hours'];
    $before = retention_settings($pdo);
    $set = [];
    $args = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $f) && $f[$k] !== null) {
            $set[] = $k . ' = :' . $k;
            $args[$k] = (int) $f[$k];
        }
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE dc_settings SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($args);
    }
    $after = retention_settings($pdo);
    $b = [];
    $a = [];
    foreach ($keys as $k) {
        if ((int) $before[$k] !== (int) $after[$k]) {
            $b[$k] = (int) $before[$k];
            $a[$k] = (int) $after[$k];
        }
    }
    return ['before' => $b, 'after' => $a];
}
