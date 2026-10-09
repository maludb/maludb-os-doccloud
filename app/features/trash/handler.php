<?php
declare(strict_types=1);

/**
 * Slice 6's prelude (docs/build-specs/trash-locks-quotas-worker.md): the files prelude (the record readers) plus the trash's reads, writes and presenters.
 * Every controller of the trash requires this first.
 */
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/present.php';

/** The trash's status chip: purges in more than 7 days secondary, 7 or fewer warning, due danger. [badge, words] */
function trash_status(?string $purgeAt, ?DateTimeImmutable $now = null): array
{
    if ($purgeAt === null) {
        return ['secondary', ''];
    }
    $now ??= new DateTimeImmutable();
    $at = new DateTimeImmutable($purgeAt);
    $secs = $at->getTimestamp() - $now->getTimestamp();
    if ($secs <= 0) {
        return ['danger', 'due'];
    }
    $days = (int) ceil($secs / 86400);
    return [$days > 7 ? 'secondary' : 'warning', 'in ' . $days . ' day' . ($days === 1 ? '' : 's')];
}
