<?php
declare(strict_types=1);
/** Action `share_group` (folder or document, group, level): one access row for the principal (visibility.md §2.5); the first row on a folder carries the inherited set along. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
share_handler('group');
