<?php
declare(strict_types=1);

/**
 * THE DISPATCH LOOP (slice 8, spec "Divergences" 3): a proposal that needs a hand becomes ONE chat turn to the kernel's chat endpoint, as the expert, for the person whose thing it is
 * (ask_assistant → POST /api/v1/agents/chat.php?agent=expert with X-Acting-Member). An agent_dispatches row is the record: `sent` (queued when run_id is empty, in flight when it
 * names a run), `answered` (a 200-character excerpt of the reply), `awaiting_approval` (the expert reached an action the kernel pauses; request_id says which), `refused` (the kernel
 * said no — the person may not use the expert, there is none — or a person cancelled it), `failed` (the kernel could not be reached or was busy: tried again `dispatch_retry_minutes`
 * later, up to DISPATCH_MAX_ATTEMPTS). Nothing here calls a model; nothing here reads a document's text — a prompt names folders, labels and counts.
 */
require_once __DIR__ . '/queries.php';

/** The person accountable for a folder's contents: its owner, else its library's owner, else a group's first owner, else the first admin; an active human or null. */
function accountable_member(PDO $pdo, ?int $folderId, ?int $preferred = null): ?int
{
    $human = $pdo->prepare("SELECT id FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL AND member_kind = 'human' AND NOT is_external");
    $try = static function (?int $id) use ($human): ?int {
        if ($id === null) {
            return null;
        }
        $human->execute(['id' => $id]);
        return $human->fetchColumn() === false ? null : $id;
    };
    if (($m = $try($preferred)) !== null) {
        return $m;
    }
    if ($folderId !== null) {
        $st = $pdo->prepare('SELECT f.owner_member_id, l.owner_member_id AS library_owner, l.created_by, l.group_id FROM folders f JOIN libraries l ON l.id = f.library_id WHERE f.id = :id');
        $st->execute(['id' => $folderId]);
        $r = $st->fetch();
        if ($r !== false) {
            foreach ([$r['owner_member_id'], $r['library_owner']] as $c) {
                if (($m = $try($c === null ? null : (int) $c)) !== null) {
                    return $m;
                }
            }
            if ($r['group_id'] !== null) {
                $g = $pdo->prepare("SELECT member_id FROM group_members WHERE group_id = :g AND role = 'owner' AND left_at IS NULL ORDER BY joined_at, member_id");
                $g->execute(['g' => (int) $r['group_id']]);
                foreach ($g->fetchAll(PDO::FETCH_COLUMN) as $c) {
                    if (($m = $try((int) $c)) !== null) {
                        return $m;
                    }
                }
            }
            if (($m = $try($r['created_by'] === null ? null : (int) $r['created_by'])) !== null) {
                return $m;
            }
        }
    }
    return admin_member_ids($pdo)[0] ?? null;
}

/**
 * Queue one dispatch (status `sent`, no run yet). $f: agent_member_id (the expert by default), acting_member_id (a person), folder_id, document_id, record_type, record_id, detail, once_within
 * (an SQL interval — a dispatch of the same kind for the same thing inside it, or one still open, makes this a no-op). Returns the id, or null when nothing was made (no expert named, no
 * acting person, or the same thing already dispatched).
 */
function dispatch_make(PDO $pdo, string $kind, array $f): ?int
{
    if (!isset(DISPATCH_KINDS[$kind])) {
        throw new DomainException('That kind of dispatch is not known.');
    }
    $agent = $f['agent_member_id'] ?? agent_settings($pdo)['expert_member_id'];
    $acting = $f['acting_member_id'] ?? null;
    if ($agent === null || $acting === null) {
        return null;
    }
    $same = 'kind = :k AND folder_id IS NOT DISTINCT FROM :f AND document_id IS NOT DISTINCT FROM :d AND record_type IS NOT DISTINCT FROM :rt AND record_id IS NOT DISTINCT FROM :rid';
    $args = ['k' => $kind, 'f' => $f['folder_id'] ?? null, 'd' => $f['document_id'] ?? null, 'rt' => $f['record_type'] ?? null, 'rid' => $f['record_id'] ?? null];
    $window = isset($f['once_within']) && preg_match('/^\d+ (minute|hour|day)s?$/', (string) $f['once_within']) ? (string) $f['once_within'] : null;
    $open = (int) one_value($pdo, "SELECT count(*) FROM agent_dispatches WHERE $same AND (status IN ('sent', 'awaiting_approval')" . ($window !== null ? " OR created_at > now() - interval '$window'" : '') . ')', $args);
    if ($open > 0) {
        return null;
    }
    $st = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, attempts, detail, document_id, folder_id)
                         VALUES (:rt, :rid, :agent, :k, 'chat', :acting, 'sent', 0, :detail, :d, :f) RETURNING id");
    $st->execute($args + ['agent' => (int) $agent, 'acting' => (int) $acting, 'detail' => isset($f['detail']) ? mb_substr((string) $f['detail'], 0, 500) : null]);
    return (int) $st->fetchColumn();
}

/** The words of the chat turn for a dispatch: the folder's path, labels and counts only — never a document's text. */
function dispatch_utterance(PDO $pdo, array $d): string
{
    $path = $d['folder_id'] === null ? '' : (string) one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => (int) $d['folder_id']]);
    switch ($d['kind']) {
        case 'untagged_batch':
            $n = $d['folder_id'] === null ? 0 : (int) one_value($pdo, "SELECT count(*) FROM documents x WHERE x.folder_id = :f AND x.trashed_at IS NULL AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = x.id)", ['f' => (int) $d['folder_id']]);
            return "The folder \"$path\" (folder {$d['folder_id']}) holds $n documents with no tags. Propose tags for them from the document graph, as your propose-tags-from-the-graph skill says: one tag_propose per document. Change nothing else.";
        case 'request_received':
            $label = $d['record_id'] === null ? '' : (string) one_value($pdo, 'SELECT label FROM share_links WHERE id = :id', ['id' => (int) $d['record_id']]);
            return "Files arrived through the file request \"$label\" into the folder \"$path\" (folder {$d['folder_id']}). File and tag them under your file-an-incoming-document skill; do not share or delete anything.";
        case 'duplicates_found':
            return 'The same file is kept ' . ($d['detail'] !== null ? '(' . $d['detail'] . ') ' : '') . "in several places, the first being document {$d['document_id']}. Say in a few sentences which copy looks like the one to keep and who owns the others; do not delete or move anything.";
        default:
            return $path !== '' ? "About the folder \"$path\" (folder {$d['folder_id']}): " . (string) ($d['detail'] ?? 'what does it hold and who can see it?') : (string) ($d['detail'] ?? 'Say what you can do for me here.');
    }
}

/** A short unique-enough record of a dispatch event for the activity log: ids, kinds, statuses and counts. */
function dispatch_log(PDO $pdo, string $event, array $d, array $after = []): void
{
    log_activity($pdo, $event, 'agent_dispatch', (int) $d['id'], ['source' => 'cron', 'actor_member_id' => null, 'folder_id' => $d['folder_id'] === null ? null : (int) $d['folder_id'],
        'document_id' => $d['document_id'] === null ? null : (int) $d['document_id'], 'after' => ['kind' => $d['kind'], 'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id']] + $after]);
}

/** Close a dispatch on an outcome. */
function dispatch_finish(PDO $pdo, int $id, string $status, ?string $excerpt, ?string $detail, ?int $runId = null, ?string $requestId = null): void
{
    $pdo->prepare('UPDATE agent_dispatches SET status = :s, reply_excerpt = COALESCE(:x, reply_excerpt), detail = :d, run_id = COALESCE(:r, run_id), request_id = COALESCE(:q, request_id), answered_at = now() WHERE id = :id')
        ->execute(['s' => $status, 'x' => $excerpt, 'd' => $detail === null ? null : mb_substr($detail, 0, 500), 'r' => $runId, 'q' => $requestId, 'id' => $id]);
}

/**
 * Send one queued dispatch (status `sent`, no run): the chat call as the expert, for the acting person; the row takes the kernel's answer. Returns ['status', 'detail'?].
 * answered | awaiting_approval | refused (400/403/404/422 from the kernel) | failed (unreachable, 401, 409 busy, 5xx) | sent (the run is still going: polled by dispatch_poll).
 */
function dispatch_run(PDO $pdo, int $id): array
{
    $d = find_dispatch($pdo, $id);
    if ($d === null || $d['status'] !== 'sent' || $d['run_id'] !== null) {
        return ['status' => $d['status'] ?? 'missing', 'skipped' => true];
    }
    $pdo->prepare('UPDATE agent_dispatches SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $id]);
    $d['attempts'] = (int) $d['attempts'] + 1;
    if ($d['acting_member_id'] === null) {
        dispatch_finish($pdo, $id, 'refused', null, 'Nobody to act for: the person this concerns is gone.');
        dispatch_log($pdo, 'agent.refuse', $d, ['why' => 'no_acting_member']);
        return ['status' => 'refused'];
    }
    dispatch_log($pdo, 'agent.dispatch', $d, ['attempt' => $d['attempts']]);
    $a = ask_assistant($pdo, (int) $d['acting_member_id'], dispatch_utterance($pdo, $d), ['screen' => 'dispatch', 'entity' => $d['folder_id'] !== null ? 'folder' : '', 'record_id' => $d['folder_id'] !== null ? (int) $d['folder_id'] : null, 'conversation_id' => '']);
    $http = (int) ($a['http'] ?? 0);
    if ($a['error'] !== null) {
        $refused = in_array($http, [400, 403, 404, 422], true);
        dispatch_finish($pdo, $id, $refused ? 'refused' : 'failed', null, $a['error']);
        dispatch_log($pdo, $refused ? 'agent.refuse' : 'agent.fail', $d, ['http' => $http, 'attempt' => $d['attempts']]);
        return ['status' => $refused ? 'refused' : 'failed', 'detail' => $a['error']];
    }
    $excerpt = trim(mb_substr(trim((string) $a['reply']), 0, 200));
    if ($a['approval'] !== null) {
        dispatch_finish($pdo, $id, 'awaiting_approval', $excerpt === '' ? null : $excerpt, 'Waiting for the approval request ' . $a['approval'] . ' in the kernel.', $a['run_id'], (string) $a['approval']);
        dispatch_log($pdo, 'agent.reply', $d, ['status' => 'awaiting_approval', 'request_id' => (string) $a['approval']]);
        return ['status' => 'awaiting_approval'];
    }
    if (!$a['finished'] && $a['run_id'] !== null) {
        $pdo->prepare('UPDATE agent_dispatches SET run_id = :r WHERE id = :id')->execute(['r' => $a['run_id'], 'id' => $id]);
        return ['status' => 'sent', 'run_id' => $a['run_id']];
    }
    dispatch_finish($pdo, $id, 'answered', $excerpt, null, $a['run_id']);
    dispatch_log($pdo, 'agent.reply', $d, ['status' => 'answered', 'run_id' => $a['run_id']]);
    return ['status' => 'answered'];
}

/** A run that was still going (or waiting for an approval) when last looked at: ask the kernel again; finished → answered. Returns the new status. */
function dispatch_poll(PDO $pdo, array $d): string
{
    $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, [], 20);
    if ($answer === null || $answer['status'] >= 400) {
        return $d['status'];
    }
    $b = $answer['body'] ?? [];
    if (!empty($b['finished']) && empty($b['approval_request_id'])) {
        dispatch_finish($pdo, (int) $d['id'], 'answered', trim(mb_substr(trim((string) ($b['reply'] ?? '')), 0, 200)) ?: null, null);
        dispatch_log($pdo, 'agent.reply', $d, ['status' => 'answered', 'run_id' => (int) $d['run_id'], 'polled' => true]);
        return 'answered';
    }
    return $d['status'];
}

/** A person's Retry: a failed or refused dispatch goes back to the queue (the next pass sends it); the attempts start again. True when it moved. */
function dispatch_retry(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, request_id = NULL, detail = NULL, answered_at = NULL, attempts = 0 WHERE id = :id AND status IN ('failed', 'refused')");
    $st->execute(['id' => $id]);
    return $st->rowCount() === 1;
}

/** A person's Cancel: a dispatch not yet answered is closed as refused with the canceller's name — nothing is sent again. True when it moved. */
function dispatch_cancel(PDO $pdo, int $id, string $byName): bool
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'refused', detail = :d, answered_at = now() WHERE id = :id AND status IN ('sent', 'awaiting_approval', 'failed')");
    $st->execute(['d' => 'Cancelled by ' . $byName . '.', 'id' => $id]);
    return $st->rowCount() === 1;
}

/**
 * The triggers (spec "Divergences" 3), once a day per folder / sha and once an hour per request: forty untagged documents arriving in a folder within a day, uploads on a file request,
 * a file reaching five copies. Each makes a dispatch for the person it concerns; returns how many were made.
 */
function dispatch_scan_triggers(PDO $pdo): int
{
    $s = agent_settings($pdo);
    if ($s['expert_member_id'] === null) {
        return 0;
    }
    $made = 0;
    $batch = $pdo->prepare("SELECT d.folder_id, count(*) AS n FROM documents d WHERE d.trashed_at IS NULL AND d.created_at > now() - interval '1 day'
                              AND NOT EXISTS (SELECT 1 FROM document_tags t WHERE t.document_id = d.id) GROUP BY d.folder_id HAVING count(*) >= :b ORDER BY d.folder_id");
    $batch->execute(['b' => $s['untagged_batch_size']]);
    foreach ($batch->fetchAll() as $r) {
        $who = accountable_member($pdo, (int) $r['folder_id']);
        if ($who !== null && dispatch_make($pdo, 'untagged_batch', ['folder_id' => (int) $r['folder_id'], 'acting_member_id' => $who, 'detail' => (int) $r['n'] . ' untagged in a day', 'once_within' => '1 day']) !== null) {
            $made++;
        }
    }
    $req = $pdo->query("SELECT l.id, l.folder_id, l.made_by, count(*) AS n FROM share_links l JOIN link_opens o ON o.link_id = l.id AND o.what = 'upload' AND o.opened_at > now() - interval '1 day'
                         WHERE l.kind = 'request' AND o.opened_at > COALESCE((SELECT max(a.created_at) FROM agent_dispatches a WHERE a.kind = 'request_received' AND a.record_type = 'link' AND a.record_id = l.id), '-infinity')
                         GROUP BY l.id, l.folder_id, l.made_by ORDER BY l.id")->fetchAll();
    foreach ($req as $r) {
        $who = accountable_member($pdo, (int) $r['folder_id'], (int) $r['made_by']);
        if ($who !== null && dispatch_make($pdo, 'request_received', ['folder_id' => (int) $r['folder_id'], 'record_type' => 'link', 'record_id' => (int) $r['id'], 'acting_member_id' => $who,
            'detail' => (int) $r['n'] . ' upload' . ((int) $r['n'] === 1 ? '' : 's'), 'once_within' => '1 hour']) !== null) {
            $made++;
        }
    }
    $dup = $pdo->prepare('SELECT sha256, count(*) AS n, min(id) AS first_id FROM documents WHERE trashed_at IS NULL AND sha256 IS NOT NULL GROUP BY sha256 HAVING count(*) >= :c ORDER BY min(id)');
    $dup->execute(['c' => max($s['duplicate_copies'], DISPATCH_DUPLICATE_COPIES)]);
    foreach ($dup->fetchAll() as $r) {
        $first = $pdo->prepare('SELECT folder_id, owner_member_id FROM documents WHERE id = :id');
        $first->execute(['id' => (int) $r['first_id']]);
        $f = $first->fetch();
        $who = accountable_member($pdo, (int) $f['folder_id'], $f['owner_member_id'] === null ? null : (int) $f['owner_member_id']);
        if ($who !== null && dispatch_make($pdo, 'duplicates_found', ['document_id' => (int) $r['first_id'], 'folder_id' => (int) $f['folder_id'], 'acting_member_id' => $who,
            'detail' => (int) $r['n'] . ' copies, sha256 ' . substr((string) $r['sha256'], 0, 12), 'once_within' => '7 days']) !== null) {
            $made++;
        }
    }
    return $made;
}

/**
 * The worker's `dispatches` pass: the triggers, failed dispatches whose wait is over back in the queue, the queue sent (at most $limit chat calls a pass), runs still going polled.
 * Returns the counts (made, requeued, sent, answered, awaiting, refused, failed, polled).
 */
function worker_dispatches(PDO $pdo, int $limit = 10): array
{
    $out = ['made' => 0, 'requeued' => 0, 'sent' => 0, 'answered' => 0, 'awaiting_approval' => 0, 'refused' => 0, 'failed' => 0, 'polled' => 0];
    $s = agent_settings($pdo);
    if ($s['expert_member_id'] === null) {
        return $out + ['skipped' => 'no_expert_named'];
    }
    $out['made'] = dispatch_scan_triggers($pdo);
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'sent' WHERE status = 'failed' AND attempts < :max AND answered_at <= now() - make_interval(mins => :m)");
    $st->execute(['max' => DISPATCH_MAX_ATTEMPTS, 'm' => $s['dispatch_retry_minutes']]);
    $out['requeued'] = $st->rowCount();
    $queue = $pdo->prepare("SELECT id FROM agent_dispatches WHERE status = 'sent' AND run_id IS NULL ORDER BY id LIMIT :n");
    $queue->bindValue('n', $limit, PDO::PARAM_INT);
    $queue->execute();
    $justSent = [];
    foreach ($queue->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $justSent[] = (int) $id;
        $r = dispatch_run($pdo, (int) $id);
        $out['sent']++;
        if (isset($out[$r['status']])) {
            $out[$r['status']]++;
        }
    }
    $going = $pdo->query("SELECT * FROM agent_dispatches WHERE status IN ('sent', 'awaiting_approval') AND run_id IS NOT NULL ORDER BY id LIMIT 20")->fetchAll();
    foreach ($going as $d) {
        if (in_array((int) $d['id'], $justSent, true)) {
            continue;                                                  // asked a moment ago: the next pass looks again
        }
        $out['polled']++;
        if (dispatch_poll($pdo, $d) === 'answered') {
            $out['answered']++;
        }
    }
    return $out;
}
