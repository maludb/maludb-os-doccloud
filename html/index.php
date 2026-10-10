<?php
declare(strict_types=1);
/** / — the home (screen `home`, design §9, slice 9): one button for a new Member; for everyone else the libraries, recent, shared this week, open requests, expiring links and the Clerk; a curator's and the admin's blocks on top; Shared with me for a guest. JSON: the same, whitelisted. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$s = home_summary($pdo, $me);
log_screen_view($pdo, 'home');
if (wants_json()) {
    respond_screen([
        'first_run' => $s['first_run'], 'guest' => $s['guest'], 'shared_count' => $s['shared_count'], 'personal_root' => $s['personal_root'],
        'libraries' => array_map(static fn (array $l): array => ['library_id' => (int) $l['library_id'], 'kind' => $l['kind'], 'name' => $l['name'], 'root_folder_id' => (int) $l['root_folder_id'],
            'level' => $l['level'], 'used_bytes' => (int) $l['used_bytes'], 'quota_bytes' => $l['quota_bytes'] === null ? null : (int) $l['quota_bytes'], 'graph_policy' => $l['graph_policy']], $s['libraries']),
        'recent' => array_map(static fn (array $d): array => ['document_id' => (int) $d['document_id'], 'name' => $d['name'], 'folder_id' => (int) $d['folder_id'], 'mime_kind' => $d['mime_kind'],
            'updated_at' => json_ts($d['updated_at']), 'last_opened_at' => json_ts($d['last_opened_at'] ?? null)], $s['recent']),
        'proposals_open' => (int) $s['proposals_open'], 'clerk_note' => $s['clerk_note'] === null ? null : ['title' => $s['clerk_note']['title'], 'body' => $s['clerk_note']['body'], 'created_at' => json_ts($s['clerk_note']['created_at'])],
        'may' => $s['may'],
    ] + present_home_blocks($s));
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'dashboard']);
