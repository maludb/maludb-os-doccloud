<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log, db/015 + db/017: their own rows; rows about documents and folders they may see; everything for the admin). */
const ACTIVITY_PAGE = 50;

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days),
 * library / folder / document (int: that record's history), entity_type + entity_id (one record's history). mcp_activity_log decides what is seen.
 */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['library', 'folder', 'document'] as $k) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = $k . '_id = :' . $k;
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['entity_type'] ?? null) !== null) {
        $where[] = 'entity_type = :etype AND entity_id = :eid';
        $args['etype'] = (string) $filters['entity_type'];
        $args['eid'] = (int) $filters['entity_id'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, entity_type, entity_id, after, library_id, folder_id, document_id, token_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by the audit key for a library, a folder or a document, else by entity. */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 30): array
{
    $f = in_array($type, ['library', 'folder', 'document'], true) ? [$type => $id] : ['entity_type' => $type, 'entity_id' => $id];
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'library_id' => $r['library_id'] === null ? null : (int) $r['library_id'], 'folder_id' => $r['folder_id'] === null ? null : (int) $r['folder_id'],
            'document_id' => $r['document_id'] === null ? null : (int) $r['document_id'], 'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id']];
}

/** The record a row concerns, as a link: [url, label] or null. Phase 3's slices add their screens. */
function activity_record_link(array $r): ?array
{
    if (($r['document_id'] ?? null) !== null) { return ['/documents/' . (int) $r['document_id'], 'the document']; }
    if (($r['folder_id'] ?? null) !== null) { return ['/folders/' . (int) $r['folder_id'], 'the folder']; }
    if (($r['library_id'] ?? null) !== null) { return ['/libraries/' . (int) $r['library_id'], 'the library']; }
    return null;
}

/** One line for a row, in words: "Priya created a doccloud base: Fire code". Falls back to the event name. Later slices add their events. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'DocCloud';
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $title = isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : '');
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_on.refused' => 'was refused at sign-on', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
        'library.create' => 'made a library', 'library.rename' => 'renamed a library', 'library.archive' => 'archived a library', 'library.admin_view' => 'opened a library as admin',
        'group.create' => 'made a group', 'group.update' => 'changed a group', 'group.archive' => 'archived a group', 'group.member_add' => 'added someone to a group', 'group.member_remove' => 'removed someone from a group',
        'folder.create' => 'made a folder', 'folder.rename' => 'renamed a folder', 'folder.move' => 'moved a folder', 'folder.trash' => 'trashed a folder', 'folder.restore' => 'restored a folder',
        'folder.restrict' => 'restricted a folder', 'folder.export' => 'exported a folder',
        'document.upload' => 'uploaded a document', 'document.version' => 'added a version', 'document.rename' => 'renamed a document', 'document.move' => 'moved a document', 'document.copy' => 'copied a document',
        'document.open' => 'opened a document', 'document.download' => 'downloaded a document', 'document.preview' => 'previewed a document', 'document.trash' => 'trashed a document',
        'document.restore' => 'restored a document', 'document.purge' => 'purged a document', 'document.lock' => 'locked a document', 'document.unlock' => 'unlocked a document', 'document.preview_hide' => 'hid a document\'s preview', 'document.preview_show' => 'showed a document\'s preview again',
        'document.lock_break' => 'broke a lock', 'document.transfer' => 'transferred a document', 'version.restore' => 'restored a version', 'version.purge' => 'purged a version',
        'document.describe' => 'described a document', 'document.reextract' => 'asked for the text to be read again', 'document.text_extracted' => 'had its text read', 'document.export' => 'made a zip of documents',
        'upload.start' => 'started an upload', 'upload.finish' => 'finished an upload', 'upload.conflict' => 'met a name already taken', 'upload.refused' => 'had an upload refused', 'upload.expire' => 'had an upload expire', 'upload.cancel' => 'cancelled an upload',
        'export.ready' => 'has a zip ready', 'export.download' => 'downloaded a zip', 'export.expire' => 'had zips expire', 'thumb.made' => 'got a thumbnail', 'document.retype' => 'had its type corrected',
        'access.share' => 'shared something', 'access.share_guest' => 'shared with a guest', 'access.share_application' => 'shared with an application', 'access.level_set' => 'changed a share',
        'access.unshare' => 'removed a share', 'access.restrict' => 'restricted access',
        'link.make' => 'made a link', 'link.rotate' => 'rotated a link', 'link.revoke' => 'revoked a link', 'link.open' => 'opened a link', 'request.make' => 'made a file request',
        'request.close' => 'closed a file request', 'request.upload' => 'uploaded through a file request',
        'tag.create' => 'made a tag', 'tag.apply' => 'tagged a document', 'tag.remove' => 'untagged a document', 'tag.merge' => 'merged tags', 'tag.propose' => 'proposed a tag',
        'search.keyword' => 'searched', 'search.graph' => 'searched the graph', 'search.save' => 'saved a search',
        'graph.queue' => 'queued a document for the graph', 'graph.index' => 'indexed a document', 'graph.full' => 'extracted a document', 'graph.forget' => 'forgot a document',
        'graph.policy_set' => 'set a graph policy', 'graph.budget_set' => 'set the graph budget', 'graph.model_set' => 'set the graph model',
        'clerk.propose' => 'proposed something', 'clerk.accept' => 'accepted a proposal', 'clerk.dismiss' => 'dismissed a proposal',
        'key.mint' => 'minted a key', 'key.rotate' => 'rotated a key', 'key.revoke' => 'revoked a key', 'share.read' => 'read a share',
        'settings.save' => 'changed the settings', 'assistant.ask' => 'asked the command bar',
        'worker.pass' => 'the worker ran', 'cron.run' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
