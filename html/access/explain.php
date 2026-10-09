<?php
declare(strict_types=1);
/** GET /access/explain?folder=|document=&member= — why a member sees the thing (dc_access_explain): a manager asks for anyone, a holder only for themselves. JSON or the fragment. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$thing = thing_from_request($pdo, 'view');
$me = (int) current_member_id();
$for = request_integer('member') ?? $me;
if ($for !== $me && !(dc_level_at_least($thing['row']['my_level'], 'manage') && has_right('documents.share'))) {
    refuse(403, 'Only a manager of this ' . $thing['kind'] . ' may ask about someone else.');
}
$rows = access_explain($pdo, $thing['kind'], $thing['id'], $for);
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $for]);
if (wants_json()) {
    json_response(['ok' => true, 'data' => ['member' => $for, 'name' => $name, 'level' => $rows === [] ? 'none' : max(array_map(static fn (array $r): int => DC_LEVELS[$r['level']] ?? 0, $rows)), 'reasons' => $rows]]);
}
echo view('access/partials/explain.php', ['name' => $name, 'rows' => $rows, 'self' => $for === $me]);
