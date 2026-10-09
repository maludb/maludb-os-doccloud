<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

/** The screen a notification points at, by the record it names (null when none). Phase 3's slices add their screens. */
function notification_record_url(array $n): ?string
{
    if (in_array($n['kind'] ?? '', ['proposal', 'monday_note'], true)) {
        return '/curate/proposals';                                       // the Clerk's notices open the proposals (slice 8)
    }
    if (($n['kind'] ?? '') === 'graph_budget') {
        return '/graph/admin';
    }
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    if ($id === null) {
        return null;
    }
    return match ($n['record_type'] ?? null) {
        'document' => '/documents/' . $id,
        'folder' => '/folders/' . $id,
        'library' => '/libraries/' . $id,
        'group' => '/groups/' . $id,
        'link' => '/links',
        'export' => '/exports/' . $id,
        default => null,
    };
}

/** The icon of a notification kind on the bell's page (slice 8: "the kinds' icons"). */
const NOTICE_ICONS = ['share_received' => 'feather-share-2', 'request_received' => 'feather-upload-cloud', 'link_opened' => 'feather-link', 'version_added' => 'feather-copy', 'comment' => 'feather-message-square',
    'lock_broken' => 'feather-unlock', 'export_ready' => 'feather-download', 'quota_near' => 'feather-hard-drive', 'proposal' => 'feather-inbox', 'monday_note' => 'feather-clipboard',
    'ownership_received' => 'feather-user-check', 'graph_budget' => 'feather-share-2', 'extraction_failed' => 'feather-alert-triangle'];
