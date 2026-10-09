<?php
declare(strict_types=1);
/** Libraries (screen `library-list`, /libraries?kind=): mine as cards (dc_my_libraries) — the admin sees every library, personal ones marked admin view; New shared library for libraries.shared. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$kind = request_string('kind');
$kind = in_array($kind, ['personal', 'group', 'shared'], true) ? $kind : null;
$rows = libraries_for_list($pdo, ['kind' => $kind, 'archived' => request_bool('archived')]);
log_screen_view($pdo, 'library-list');
if (wants_json()) {
    respond_screen(['libraries' => array_map('present_library', $rows), 'kind' => $kind, 'may' => ['create' => has_right('libraries.shared')]]);
}
$notices = ['created' => ['success', 'Library made.'], 'deleted' => ['success', 'Library deleted.'], 'archived' => ['success', 'Library archived.'], 'restored' => ['success', 'Library restored.']];
render_screen('Libraries', view('libraries/index.php', ['rows' => $rows, 'kind' => $kind, 'may' => ['create' => has_right('libraries.shared')], 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'archived' => request_bool('archived')]),
    ['activeNav' => 'libraries', 'screen' => 'library-list']);
