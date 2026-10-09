<?php
declare(strict_types=1);
/** The exports screen (`exports`, /exports): my zips — what, status, size, made, expires, Download; "Build again". */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
$pdo = db();
if (is_guest()) {
    refuse(403, 'Exports are for members.');
}
$rows = my_exports($pdo);
log_screen_view($pdo, 'exports');
if (wants_json()) {
    respond_screen(['exports' => array_map('present_export', $rows)]);
}
render_screen('Exports', view('files/exports.php', ['rows' => $rows, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, ['queued' => ['success', 'The zip is being made; it appears here within the minute.']])]),
    ['activeNav' => 'files', 'screen' => 'exports', 'entity' => 'export']);
