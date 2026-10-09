<?php
declare(strict_types=1);
/** Shared with me (`shared-with-me`, /shared): the explicit rows naming me, my groups or my departments (dc_shared_with_me) — a guest's home. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
require_any_right('library.personal|documents.guest');
$pdo = db();
$page = max(1, request_integer('page') ?? 1);
$kind = request_string('kind') ?: null;
$data = shared_with_me($pdo, $page);
$rows = $kind === null ? $data['rows'] : array_values(array_filter($data['rows'], static fn (array $r): bool => $r['kind'] === $kind));
log_screen_view($pdo, 'shared');
if (wants_json()) {
    respond_screen(['rows' => array_map('present_shared_row', $rows), 'page' => $data['page'], 'more' => $data['more'], 'kind' => $kind]);
}
render_screen('Shared with me', view('files/shared.php', ['rows' => $rows, 'page' => $data['page'], 'more' => $data['more'], 'kind' => $kind, 'tz' => member_timezone(), 'guest' => is_guest()]), ['activeNav' => 'shared', 'screen' => 'shared-with-me']);
