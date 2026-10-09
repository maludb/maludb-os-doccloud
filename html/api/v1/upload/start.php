<?php
declare(strict_types=1);
/** POST /api/v1/upload/start — the parts form's first call (slice 2's /upload/start under the key): {folder, filename, byte_size, …} → {session_id, part_bytes, parts_total, …}. */
require_once dirname(__DIR__, 4) . '/app/api/prelude.php';
require_once dirname(__DIR__, 4) . '/app/api/routes.php';
require_once dirname(__DIR__, 4) . '/app/api/upload.php';
api_route(['POST'], true);
api_upload_relay('upload/start.php', $_POST);
