<?php
declare(strict_types=1);
/** Recent (screen `recent`, /recent): dc_recent_documents(50) as the listing table. */
require_once dirname(__DIR__) . '/app/features/files/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$rows = recent_documents($pdo, 50);
log_screen_view($pdo, 'recent');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $r): array => present_listing_row($r) + ['last_opened_at' => json_ts($r['last_opened_at'] ?? null)], $rows)]);
}
render_screen('Recent', view('files/recent.php', ['rows' => $rows, 'tz' => member_timezone(), 'stars' => my_favourite_ids($pdo)]), ['activeNav' => 'recent', 'screen' => 'recent']);
