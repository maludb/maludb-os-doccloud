<?php
declare(strict_types=1);
/**
 * Curate (screen `curate`, GET /curate/): the curator's index — Groups · Shared libraries · Tags and rules · Ownership · Storage report · Search report · Proposals, each with its count; a card shows
 * only when the person holds its right. Admitted by any of groups.manage, libraries.shared, tags.manage. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/curate/handler.php';
require_any_right('groups.manage|libraries.shared|tags.manage');
$pdo = db();
$counts = curate_counts($pdo);
log_screen_view($pdo, 'curate');
if (wants_json()) {
    respond_screen(['counts' => $counts]);
}
render_screen('Curate', view('curate/index.php', ['counts' => $counts]), ['activeNav' => 'curate', 'screen' => 'curate']);
