<?php
declare(strict_types=1);
/** The prelude of the tag and search controllers (slice 4): the files prelude (the gate readers, files_log, the listing helpers) plus the tag and search modules. */
require_once dirname(__DIR__) . '/files/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/search/parse.php';
require_once dirname(__DIR__) . '/search/queries.php';
require_once dirname(__DIR__) . '/search/present.php';
require_once dirname(__DIR__) . '/search/write.php';

/** The tag a request names (`tag` or `id`): the row (mcp_tags: everyone admitted sees the vocabulary) or 404. */
function tag_from_request(PDO $pdo, string $param = 'tag'): array
{
    $raw = req_val($param) ?? ($_GET[$param] ?? null) ?? ($_GET['id'] ?? null);
    if ($raw === null || $raw === '') {
        refuse(422, 'Say which tag.');
    }
    $t = ctype_digit((string) $raw) ? find_tag($pdo, (int) $raw) : tag_by_word($pdo, (string) $raw);
    if ($t === null) {
        dc_not_found('Not found.');
    }
    return $t;
}

/** A hex colour or null; anything else refused on the field. */
function colour_from_request(array &$errors, ?string $keep = null): ?string
{
    if (!req_has('colour')) {
        return $keep;
    }
    $c = strtolower((string) req_val('colour'));
    if ($c === '' || $c === 'none') {
        return null;
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $c)) {
        $errors['colour'] = 'A colour is a hex value like #3454d1, or none.';
        return $keep;
    }
    return $c;
}

/** The tag chip's classes. */
function tag_chip_class(array $t): string
{
    $state = $t['state'] ?? ((($t['archived_at'] ?? null) !== null) ? 'archived' : ($t['kind'] ?? 'free'));
    if ($state === 'archived') {
        return 'badge bg-soft-dark text-dark';
    }
    $b = TAG_KIND_BADGE[$state] ?? 'secondary';
    return 'badge bg-soft-' . $b . ' text-' . $b;
}
