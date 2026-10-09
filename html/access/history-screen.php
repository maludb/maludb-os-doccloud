<?php
declare(strict_types=1);
/** The access-history screen's body (required by html/folders/history.php and html/documents/history.php): manage on the thing, or reports.search. */
if (!(dc_level_at_least($thing['my_level'], 'manage') || has_right('reports.search'))) {
    refuse(403, 'Only a manager of this ' . $kind . ' sees its access history.');
}
$at = request_date('at');
if ($at === false) {
    refuse(422, 'The day is a date (YYYY-MM-DD).');
}
$at ??= date('Y-m-d');
$rows = access_history($pdo, $kind, $id, $at);
$changes = access_changes($pdo, $kind, $id);
log_activity($pdo, 'screen.view', $kind, $id, ['screen' => 'access-history', $kind . '_id' => $id, 'library_id' => (int) $thing['library_id']]);
if (wants_json()) {
    respond_screen(['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'at' => $at, 'rows' => array_map('present_history_row', $rows),
        'changes' => array_map(static fn (array $c): array => ['principal_kind' => $c['principal_kind'], 'principal_id' => $c['principal_id'] === null ? null : (int) $c['principal_id'], 'principal_ref' => $c['principal_ref'], 'principal_name' => $c['principal_name'],
            'old_level' => $c['old_level'], 'new_level' => $c['new_level'], 'changed_by' => $c['changed_by'] === null ? null : (int) $c['changed_by'], 'changed_by_name' => $c['changed_by_name'], 'changed_at' => json_ts($c['changed_at'])], $changes)]);
}
render_screen('Access history of ' . $thing['name'], view('access/history.php', ['kind' => $kind, 'id' => $id, 'name' => $thing['name'], 'at' => $at, 'rows' => $rows, 'changes' => $changes, 'tz' => member_timezone()]),
    ['activeNav' => 'files', 'screen' => 'access-history', 'entity' => $kind, 'recordId' => $id]);
