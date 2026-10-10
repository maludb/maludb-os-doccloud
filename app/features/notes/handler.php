<?php
declare(strict_types=1);

/** Slice 9's comments prelude: the files prelude (the walls' readers), the comment reads and writes, the notifications wrapper. */
require_once dirname(__DIR__) . '/files/handler.php';
require_once dirname(__DIR__) . '/notifications/write.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

const NOTE_MAX_CHARS = 4000;
