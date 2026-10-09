<?php
declare(strict_types=1);
/** My saved searches and the shared ones (screen `saved-searches`, /search/saved): name, query, filters as chips, Run · Delete. */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
require_right('search.use');
$pdo = db();
$saved = my_saved_searches($pdo);
log_activity($pdo, 'screen.view', 'saved_search', null, ['screen' => 'saved-searches']);
if (wants_json()) {
    respond_screen(['saved' => array_map('present_saved_search', $saved)]);
}
$notices = ['saved' => ['success', 'Saved.'], 'deleted' => ['success', 'Deleted.']];
render_screen('Saved searches', view('search/saved.php', ['saved' => $saved, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'search', 'screen' => 'saved-searches']);
