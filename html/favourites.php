<?php
declare(strict_types=1);
/** Favourites (screen `favourites`, /favourites): folders then documents I starred, the listing table; Remove. */
require_once dirname(__DIR__) . '/app/features/files/handler.php';
require_login();
require_right('library.personal');
$pdo = db();
$rows = my_favourites($pdo);
log_screen_view($pdo, 'favourites');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $r): array => present_listing_row($r) + ['starred_at' => json_ts($r['starred_at'] ?? null)], $rows)]);
}
$notices = ['unstarred' => ['success', 'Removed from your favourites.'], 'starred' => ['success', 'Added to your favourites.']];
render_screen('Favourites', view('files/favourites.php', ['rows' => $rows, 'tz' => member_timezone(), 'stars' => my_favourite_ids($pdo), 'notice' => dc_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'favourites', 'screen' => 'favourites']);
