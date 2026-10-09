<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the person's libraries for the sidebar, the bell's count
 * (mcp_notifications) and the business name. Each is cached for the request — one query per render.
 */

/** The libraries the caller reaches — personal first, then groups, then shared — with their level (dc_my_libraries, db/014). [{library_id, kind, name, root_folder_id, level, …}] */
function sidebar_libraries(PDO $pdo, int $limit = 12): array
{
    static $cache = null;
    if ($cache === null) {
        $st = $pdo->prepare('SELECT library_id, kind, name, group_id, root_folder_id, level, used_bytes, quota_bytes, graph_policy FROM dc_my_libraries() LIMIT :n');
        $st->bindValue('n', $limit, PDO::PARAM_INT);
        $st->execute();
        $cache = $st->fetchAll();
    }
    return $cache;
}

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** The business's name on the header (dc_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}
