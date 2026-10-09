<?php
declare(strict_types=1);
/** PUT /api/v1/upload/{session}/{n} — one part's bytes as the raw body (the rewrite puts ?session= and ?n=): slice 2's /upload/part under the key. */
require_once dirname(__DIR__, 4) . '/app/api/prelude.php';
require_once dirname(__DIR__, 4) . '/app/api/routes.php';
require_once dirname(__DIR__, 4) . '/app/api/upload.php';
api_route(['PUT', 'POST'], false);
api_upload_relay('upload/part.php', []);
