<?php
declare(strict_types=1);
/**
 * Slice 8's prelude: the files prelude (the level readers, files_log), the tag writes (an accepted tags proposal applies through them), the agents' settings and dispatch loop, the Clerk's
 * proposals and report, and the notifications wrapper.
 */
require_once dirname(__DIR__) . '/tags/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/dispatch.php';
require_once dirname(__DIR__) . '/clerk/queries.php';
require_once dirname(__DIR__) . '/clerk/write.php';
require_once dirname(__DIR__) . '/clerk/report.php';
require_once dirname(__DIR__) . '/notifications/write.php';

/** Is the caller the Filing Clerk (the agent named in the settings)? */
function caller_is_clerk(PDO $pdo): bool
{
    return db_bool($pdo, 'SELECT dc_is_clerk()');
}
