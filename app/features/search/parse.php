<?php
declare(strict_types=1);

/**
 * search_parse(typed): the typed modifiers out, the rest the query (slice 4, docs/build-specs/tags-search.md "The index and the search"):
 *   tag:x (a slug, label or alias → dc_tag_resolve; an unknown one stays a filter that matches nothing) · kind:pdf|image|office|text|audio|video|archive|other
 *   owner:me | owner:<member id> · mine · shared (shared with me) · in:<folder id> | in:<a path fragment matched against the folders I may see>
 *   library:<id> · after:YYYY-MM-DD · before:YYYY-MM-DD · size>N / size<N (bytes; k, m, g suffixes) · a quoted phrase and -word stay in the query (websearch).
 * Unknown modifiers stay in the query. Explicit GET parameters (library, folder, owner, tag, kind, before, after, mine, shared_with_me, min_bytes, max_bytes)
 * are merged on top by the page (search_filters_from_query()).
 */
const SEARCH_KINDS = ['image', 'pdf', 'office', 'text', 'audio', 'video', 'archive', 'other'];

function search_parse(PDO $pdo, string $typed): array
{
    $filters = [];
    $rest = [];
    $tokens = preg_split('/\s+/', trim($typed)) ?: [];
    foreach ($tokens as $tok) {
        if ($tok === '') {
            continue;
        }
        $low = strtolower($tok);
        if ($low === 'mine') { $filters['mine'] = true; continue; }
        if ($low === 'shared') { $filters['shared_with_me'] = true; continue; }
        if (preg_match('/^size([<>])(\d+(?:\.\d+)?)([kmg]?)b?$/i', $tok, $m)) {
            $n = (float) $m[2] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[3])];
            $filters[$m[1] === '>' ? 'min_bytes' : 'max_bytes'] = (int) $n;
            continue;
        }
        if (!preg_match('/^([a-z]+):(.+)$/i', $tok, $m)) {
            $rest[] = $tok;
            continue;
        }
        $key = strtolower($m[1]);
        $val = trim($m[2], '"\'');
        switch ($key) {
            case 'tag':
                $id = one_value($pdo, 'SELECT dc_tag_resolve(:w)', ['w' => $val]);
                $filters['tag'] = $id === null || $id === false ? '-' : (string) one_value($pdo, 'SELECT slug FROM tags WHERE id = :id', ['id' => (int) $id]);
                break;
            case 'kind':
                $filters['kind'] = in_array(strtolower($val), SEARCH_KINDS, true) ? strtolower($val) : '-';
                break;
            case 'owner':
                if (strtolower($val) === 'me') { $filters['mine'] = true; }
                elseif (ctype_digit($val)) { $filters['owner'] = (int) $val; }
                else { $rest[] = $tok; }
                break;
            case 'in':
                if (ctype_digit($val)) { $filters['folder'] = (int) $val; }
                else {
                    $st = $pdo->prepare('SELECT f.folder_id FROM mcp_folders f WHERE f.trashed_at IS NULL AND (f.name ILIKE :q OR dc_folder_path_text(f.folder_id) ILIKE :q) ORDER BY f.depth, f.path LIMIT 1');
                    $st->execute(['q' => '%' . $val . '%']);
                    $f = $st->fetchColumn();
                    $filters['folder'] = $f === false ? -1 : (int) $f;
                }
                break;
            case 'library':
                if (ctype_digit($val)) { $filters['library'] = (int) $val; } else { $rest[] = $tok; }
                break;
            case 'after':
            case 'before':
                $d = date_create($val);
                if ($d !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) { $filters[$key] = $val; } else { $rest[] = $tok; }
                break;
            default:
                $rest[] = $tok;
        }
    }
    return ['q' => implode(' ', $rest), 'filters' => $filters];
}

/** The GET parameters the search page admits, merged over the typed modifiers (an explicit chip wins). */
function search_filters_from_query(PDO $pdo, array $get, array $filters): array
{
    foreach (['library', 'folder', 'owner', 'min_bytes', 'max_bytes'] as $k) {
        if (isset($get[$k]) && ctype_digit((string) $get[$k])) { $filters[$k] = (int) $get[$k]; }
    }
    if (!empty($get['tag'])) {
        $id = one_value($pdo, 'SELECT dc_tag_resolve(:w)', ['w' => (string) $get['tag']]);
        $filters['tag'] = $id === null || $id === false ? '-' : (string) one_value($pdo, 'SELECT slug FROM tags WHERE id = :id', ['id' => (int) $id]);
    }
    if (!empty($get['kind']) && in_array((string) $get['kind'], SEARCH_KINDS, true)) { $filters['kind'] = (string) $get['kind']; }
    foreach (['after', 'before'] as $k) {
        if (!empty($get[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $get[$k])) { $filters[$k] = (string) $get[$k]; }
    }
    if (!empty($get['mine']) && in_array(strtolower((string) $get['mine']), ['1', 'yes', 'true', 'on'], true)) { $filters['mine'] = true; }
    if (!empty($get['shared_with_me']) && in_array(strtolower((string) $get['shared_with_me']), ['1', 'yes', 'true', 'on'], true)) { $filters['shared_with_me'] = true; }
    return $filters;
}

/** The filters as chips: [key, label, the URL without it]. */
function search_chips(PDO $pdo, string $q, array $filters): array
{
    $chips = [];
    foreach ($filters as $k => $v) {
        $label = match ($k) {
            'library' => 'in library ' . ((string) (one_value($pdo, 'SELECT name FROM mcp_libraries WHERE library_id = :id', ['id' => (int) $v]) ?: '#' . $v)),
            'folder' => 'in ' . ((string) (one_value($pdo, 'SELECT dc_folder_path_text(:id)', ['id' => (int) $v]) ?: 'an unknown folder')),
            'owner' => 'owner ' . ((string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id', ['id' => (int) $v]) ?: '#' . $v)),
            'mine' => 'mine', 'shared_with_me' => 'shared with me',
            'tag' => 'tag ' . ($v === '-' ? '(unknown)' : $v), 'kind' => 'kind ' . ($v === '-' ? '(unknown)' : $v),
            'min_bytes' => 'over ' . format_bytes((int) $v), 'max_bytes' => 'under ' . format_bytes((int) $v),
            'after' => 'after ' . $v, 'before' => 'before ' . $v,
            default => $k . ' ' . $v,
        };
        $rest = $filters;
        unset($rest[$k]);
        $chips[] = ['key' => $k, 'value' => $v, 'label' => $label, 'without' => search_url($q, $rest)];
    }
    return $chips;
}

/** The search page's URL for a query and filters. */
function search_url(string $q, array $filters, int $page = 1): string
{
    $params = ['q' => $q];
    foreach ($filters as $k => $v) {
        $params[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
    }
    if ($page > 1) {
        $params['page'] = (string) $page;
    }
    return '/search?' . http_build_query(array_filter($params, static fn ($v): bool => $v !== '' && $v !== '0'));
}
