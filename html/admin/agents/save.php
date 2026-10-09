<?php
declare(strict_types=1);
/**
 * Action `agents_settings_save` (expert, clerk — an agent member each, empty for none —, untagged_batch_size, duplicate_copies, dispatch_retry_minutes, clerk_report_enabled; log agents_settings.save): who the
 * expert and the Filing Clerk are and the thresholds that wake them. Only the keys sent change. agents.settings (the admin); pauses for an agent (other); restore prior.
 */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
dc_handler_begin();
require_right('agents.settings');
$pdo = db();
$me = (int) current_member_id();
$f = [];
$errors = [];
foreach (['expert' => 'expert_member_id', 'clerk' => 'clerk_member_id'] as $field => $col) {
    if (req_has($field)) {
        $v = req_val($field);
        if ($v === '' || $v === 'none') {
            $f[$col] = null;
        } elseif (ctype_digit((string) $v)) {
            $f[$col] = (int) $v;
        } else {
            $errors[$field] = 'Choose an agent from the list.';
        }
    }
}
foreach (['untagged_batch_size' => [1, 1000, 'The batch size'], 'duplicate_copies' => [2, 100, 'The number of copies'], 'dispatch_retry_minutes' => [1, 1440, 'The retry wait']] as $field => [$lo, $hi, $label]) {
    if (req_has($field) && req_val($field) !== '') {
        $v = req_val($field);
        if (!ctype_digit((string) $v) || (int) $v < $lo || (int) $v > $hi) {
            $errors[$field] = $label . ' is a whole number from ' . $lo . ' to ' . $hi . '.';
        } else {
            $f[$field] = (int) $v;
        }
    }
}
if (req_has('clerk_report_enabled')) {
    $f['clerk_report_enabled'] = dc_yes('clerk_report_enabled');
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
if ($f === []) {
    dc_refuse_fields(['expert' => 'Nothing to save: say what changes.']);
}
$diff = dc_guard($pdo, static function () use ($pdo, $f, $me): array {
    $pdo->beginTransaction();
    $diff = agents_settings_save($pdo, $f, $me);
    log_activity($pdo, 'agents_settings.save', 'settings', null, ['before' => $diff['before'], 'after' => $diff['after']]);
    $pdo->commit();
    return $diff;
});
dc_done($diff['after'] === [] ? 'Nothing changed' : 'Saved the agents\' settings', null, dc_land(return_path('/admin/agents'), 'saved', 'agents-thresholds'), 'agentChanged', ['changed' => array_keys($diff['after'])]);
