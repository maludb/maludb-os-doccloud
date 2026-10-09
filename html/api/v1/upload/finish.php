<?php
declare(strict_types=1);
/** POST /api/v1/upload/{session}/finish {conflict?} — assemble and add the version (slice 2's /upload/finish under the key). */
require_once dirname(__DIR__, 4) . '/app/api/prelude.php';
require_once dirname(__DIR__, 4) . '/app/api/routes.php';
require_once dirname(__DIR__, 4) . '/app/api/upload.php';
api_route(['POST'], true);
api_upload_relay('upload/finish.php', $_POST);
