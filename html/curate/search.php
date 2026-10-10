<?php
declare(strict_types=1);
/**
 * Search report (screen `curate-search`, GET /curate/search?since=7d|30d|90d|YYYY-MM-DD): the searches that found nothing (dc_search_gaps), the top queries, searches a day, keyword against graph. reports.search
 * (curators). Logged report.search. Slice 9.
 */
require_once dirname(__DIR__, 2) . '/app/features/curate/handler.php';
require_login();
require_right('reports.search');
$pdo = db();
$since = request_string('since') ?: '30d';
$r = search_report($pdo, $since);
log_activity($pdo, 'report.search', 'report', null, ['screen' => 'curate-search', 'after' => ['days' => $r['days'], 'gaps' => count($r['gaps'])]]);
if (wants_json()) {
    respond_screen(['days' => $r['days'], 'searches' => $r['searches'], 'by_kind' => $r['by_kind'],
        'gaps' => array_map(static fn (array $g): array => ['query' => $g['query'], 'times' => (int) $g['times'], 'last_at' => json_ts($g['last_at'])], $r['gaps']),
        'top' => array_map(static fn (array $t): array => ['query' => $t['query'], 'times' => (int) $t['times'], 'avg_results' => (float) $t['avg_results']], $r['top']),
        'per_day' => array_map(static fn (array $d): array => ['day' => $d['day'], 'searches' => (int) $d['searches'], 'empty' => (int) $d['empty']], $r['per_day'])]);
}
render_screen('Search report', view('curate/search.php', ['r' => $r, 'tz' => member_timezone()]), ['activeNav' => 'curate', 'screen' => 'curate-search']);
