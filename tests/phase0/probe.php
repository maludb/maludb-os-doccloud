<?php
declare(strict_types=1);
/**
 * CLI probes the kit proof (tests/phase0/run.sh) drives — each prints plain lines the shell compares. Needs the proof's environment
 * (DC_DEV_ENV exported by run.sh). Never run against an installed database.
 *   php probe.php log                 log_activity with library_id/document_id/folder_id, then with values that would throw (it must not)
 *   php probe.php apikey <raw>        api_authenticate() as a key: who it acts as, whether it is a key, and the rate rule's answer
 *   php probe.php mail                malumail_send() with and without a key
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if ($mode === 'apikey') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . ($argv[2] ?? '');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__, 2) . '/app/api/bootstrap.php';
    $member = api_authenticate();
    echo 'authenticated:' . $member['id'] . ':key=' . ((api_key()['token_id'] ?? 0) > 0 ? 'yes' : 'no') . ':label=' . (api_key()['label'] ?? '') . "\n";
    log_activity(db(), 'probe.api', 'document', 5, ['after' => ['label' => api_key()['label'] ?? null]]);
    echo "logged\n";
    exit(0);
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db();
if ($mode === 'log') {
    log_activity($pdo, 'probe.event', 'document', 7, ['actor_member_id' => null, 'library_id' => 3, 'folder_id' => 11, 'after' => ['name' => 'a name', 'byte_size' => 2]]);
    log_activity($pdo, 'probe.folder', 'folder', 11, ['actor_member_id' => null]);
    echo "logged\n";
    try {
        log_activity($pdo, 'probe.bad_actor', 'document', 7, ['actor_member_id' => 'not-a-number']);          // a bigint column refuses it
        log_activity($pdo, 'probe.bad_json', 'document', 7, ['actor_member_id' => null, 'after' => ['x' => NAN]]);   // json_encode throws
        log_activity($pdo, 'probe.bad_keys', null, null, ['actor_member_id' => null, 'library_id' => 'abc', 'token_id' => []]);
        echo "survived\n";
    } catch (Throwable $e) {
        echo 'threw:' . get_class($e) . "\n";
    }
    exit(0);
}
if ($mode === 'mail') {
    try {
        $r = malumail_send(['from' => 'kit@example.invalid', 'to' => 'someone@example.invalid', 'subject' => 'Kit proof', 'text' => 'hello']);
        echo 'sent:' . $r['status'] . "\n";
    } catch (Throwable $e) {
        echo 'mail-threw:' . $e->getMessage() . "\n";
    }
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(2);
