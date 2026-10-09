<?php
declare(strict_types=1);
/**
 * The home (screen `home`), design §9, in its Phase 2 form: a new Member sees ONE button — "Upload your first file"; everyone else their
 * libraries as cards (dc_my_libraries, db/014) and their recent documents (dc_recent_documents). A guest sees Shared with me (slice 3's
 * read; until then: told nothing is shared). The design's other regions (shared this week, open requests, links expiring, the Clerk's
 * note, the curators' and the admin's lines) are Phase 3 slices' and fill their own keys.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $libraries = $pdo->query('SELECT library_id, kind, name, group_id, root_folder_id, level, used_bytes, quota_bytes, graph_policy FROM dc_my_libraries()')->fetchAll();
    $recent = $pdo->query('SELECT document_id, name, folder_id, mime_kind, updated_at, last_opened_at FROM dc_recent_documents(8)')->fetchAll();
    $guest = is_guest();
    $sharedCount = $guest ? (int) one_value($pdo, 'SELECT count(*) FROM dc_shared_with_me(500, 0)') : 0;
    $hasDocuments = (int) one_value($pdo, 'SELECT count(*) FROM mcp_documents WHERE trashed_at IS NULL LIMIT 1') > 0;
    $personal = null;
    foreach ($libraries as $l) {
        if ($l['kind'] === 'personal') {
            $personal = $l;
        }
    }
    $note = $pdo->query("SELECT notification_id, title, body, created_at, read_at FROM mcp_notifications WHERE kind = 'monday_note' ORDER BY created_at DESC, notification_id DESC LIMIT 1")->fetch();
    return [
        'clerk_note' => $note === false || $guest ? null : $note,
        'proposals_open' => $guest ? 0 : (int) one_value($pdo, "SELECT count(*) FROM mcp_clerk_proposals WHERE status = 'proposed' AND for_member_id = app_current_member_id()"),
        'libraries' => $libraries,
        'recent' => $recent,
        'shared_count' => $sharedCount,
        'first_run' => !$guest && !$hasDocuments,
        'personal_root' => $personal !== null ? (int) $personal['root_folder_id'] : null,
        'guest' => $guest,
        'may' => ['upload' => has_right('documents.upload'), 'curate' => has_right('groups.manage'), 'admin' => has_right('settings.manage')],
    ];
}
