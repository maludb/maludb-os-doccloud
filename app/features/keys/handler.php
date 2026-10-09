<?php
declare(strict_types=1);

/** The prelude of the key controllers (slice 7): the files prelude (the walls' readers, files_log), the key reads and writes. */
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

/** A key is for a person who works here: people only (an agent uses the tools), and not a guest. */
function require_key_maker(): void
{
    require_human();
    if (!has_right('search.use')) {
        refuse(403, 'A guest may not hold a key: a key acts as you, and a guest reaches only what is shared with them.');
    }
}

/** The key a request names (`api_key`, `key` or `id`) that the caller owns; 404 for anyone else's. */
function own_key_from_request(PDO $pdo): array
{
    $id = request_integer('api_key') ?? request_integer('key') ?? request_integer('id');
    $k = $id === null ? null : find_key($pdo, $id);
    if ($k === null || (int) $k['member_id'] !== (int) current_member_id()) {
        dc_not_found('Not found.');
    }
    return $k;
}

/** Keep the raw value for ONE render of the keys screen (a browser) — never in a log, never twice. */
function key_show_once(int $tokenId, string $raw, string $label, string $how): void
{
    $_SESSION['minted_key'] = ['token_id' => $tokenId, 'raw' => $raw, 'label' => $label, 'how' => $how];
}
