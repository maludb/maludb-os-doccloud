<?php
declare(strict_types=1);
/**
 * Action `storage_export` (report — storage or search —, format csv; log report.export): the report as a CSV. reports.storage for the storage report, reports.search for the search report. A browser gets the
 * file; JSON gets {file: {name, content_type, rows}, csv}. Names, numbers and dates only — never a document's words.
 */
require_once dirname(__DIR__, 3) . '/app/features/curate/handler.php';
dc_handler_begin();
$pdo = db();
$report = req_val('report') ?? 'storage';
$format = strtolower((string) (req_val('format') ?? 'csv'));
if (!in_array($report, ['storage', 'search'], true)) {
    dc_refuse_fields(['report' => 'The report is storage or search.']);
}
if ($format !== 'csv') {
    dc_refuse_fields(['format' => 'The format is csv.']);
}
require_right($report === 'storage' ? 'reports.storage' : 'reports.search');
if ($report === 'storage') {
    $r = storage_report($pdo);
    $csv = csv_text(['section', 'id', 'name', 'kind', 'documents', 'versions', 'used_bytes', 'quota_bytes', 'percent'],
        array_merge(
            array_map(static fn (array $l): array => ['library', $l['library_id'], $l['name'], $l['kind'], $l['documents'], $l['versions'], $l['used_bytes'], $l['quota_bytes'], $l['pct']], $r['libraries']),
            array_map(static fn (array $o): array => ['owner', $o['member_id'], $o['display_name'], '', $o['documents'], '', $o['live_bytes'], '', ''], $r['owners']),
            array_map(static fn (array $d): array => ['duplicate', '', (pg_text_array((string) $d['names'])[0] ?? ''), '', $d['copies'], '', $d['wasted'], '', ''], $r['duplicates'])));
    $rows = count($r['libraries']) + count($r['owners']) + count($r['duplicates']);
} else {
    $r = search_report($pdo, req_val('since') ?? '30d');
    $csv = csv_text(['query', 'times', 'last_at'], array_map(static fn (array $g): array => [$g['query'], $g['times'], $g['last_at']], $r['gaps']));
    $rows = count($r['gaps']);
}
$name = 'doccloud-' . $report . '-report-' . date('Y-m-d') . '.csv';
log_activity($pdo, 'report.export', 'report', null, ['after' => ['report' => $report, 'format' => 'csv', 'rows' => $rows]]);
emit_action_status(true, ['did' => 'Exported the ' . $report . ' report', 'record_id' => null, 'refresh' => '']);
if (wants_json()) {
    respond_saved(['did' => 'Exported the ' . $report . ' report', 'record_id' => null, 'file' => ['name' => $name, 'content_type' => 'text/csv', 'rows' => $rows], 'csv' => $csv]);
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo "\xEF\xBB\xBF" . $csv;
exit;
