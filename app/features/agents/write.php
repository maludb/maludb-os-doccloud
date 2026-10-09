<?php
declare(strict_types=1);

/** Writes of slice 8's agents screen: the settings (the Clerk's and the expert's member ids, the thresholds). SQL only; the controller judges and logs. A refusal is a DomainException in words. */

/**
 * Save the agents' settings: only the keys present are changed (expert_member_id / clerk_member_id null = none). Both must be active OS AI agents admitted to DocCloud, and not the
 * same one. Returns ['before' => …, 'after' => …] of what differs.
 */
function agents_settings_save(PDO $pdo, array $f, int $by): array
{
    $before = agent_settings($pdo);
    $set = [];
    $args = [];
    foreach (['expert_member_id' => 'The expert', 'clerk_member_id' => 'The Clerk'] as $col => $who) {
        if (!array_key_exists($col, $f)) {
            continue;
        }
        $id = $f[$col];
        if ($id !== null) {
            $m = mirrored_member($pdo, (int) $id);
            if ($m === null || $m['member_kind'] !== 'agent' || $m['status'] !== 'active' || $m['capability'] === null) {
                throw new DomainException($who . ' must be an agent admitted to DocCloud.');
            }
            $id = (int) $id;
        }
        $set[] = $col . ' = :' . $col;
        $args[$col] = $id;
    }
    $expert = array_key_exists('expert_member_id', $args) ? $args['expert_member_id'] : $before['expert_member_id'];
    $clerk = array_key_exists('clerk_member_id', $args) ? $args['clerk_member_id'] : $before['clerk_member_id'];
    if ($expert !== null && $expert === $clerk) {
        throw new DomainException('The expert and the Clerk are two agents, not one.');
    }
    foreach (['untagged_batch_size' => [1, 1000, 'The batch size'], 'duplicate_copies' => [2, 100, 'The number of copies'], 'dispatch_retry_minutes' => [1, 1440, 'The retry wait']] as $col => [$lo, $hi, $label]) {
        if (array_key_exists($col, $f) && $f[$col] !== null) {
            if ((int) $f[$col] < $lo || (int) $f[$col] > $hi) {
                throw new DomainException($label . ' is between ' . $lo . ' and ' . $hi . '.');
            }
            $set[] = $col . ' = :' . $col;
            $args[$col] = (int) $f[$col];
        }
    }
    if (array_key_exists('clerk_report_enabled', $f) && $f['clerk_report_enabled'] !== null) {
        $set[] = 'clerk_report_enabled = :clerk_report_enabled';
        $args['clerk_report_enabled'] = $f['clerk_report_enabled'] ? 't' : 'f';
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE dc_settings SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($args);
    }
    return dc_diff($before, agent_settings($pdo));
}
