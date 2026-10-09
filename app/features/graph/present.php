<?php
declare(strict_types=1);

/** The one JSON branch of the graph screens (whitelists; excerpts as the page shows them, <mark> kept, everything else escaped). */
function present_graph_row(array $r): array
{
    return ['document_id' => (int) $r['document_id'], 'name' => $r['name'], 'folder_id' => (int) $r['folder_id'], 'folder_path' => $r['folder_path'] ?? null, 'library_id' => (int) $r['library_id'],
            'owner_member_id' => (int) $r['owner_member_id'], 'owner_name' => $r['owner_name'] ?? null, 'mime_kind' => $r['mime_kind'], 'score' => round((float) $r['score'], 3), 'subjects' => array_values($r['subjects'] ?? []),
            'hits' => (int) ($r['hits'] ?? 0), 'tags' => array_values($r['tags'] ?? []), 'excerpt' => isset($r['excerpt']) ? search_excerpt_html((string) $r['excerpt']) : null, 'url' => '/documents/' . (int) $r['document_id']];
}

function present_graph_find(array $f): array
{
    return ['status' => $f['status'], 'query' => $f['query'] ?? '', 'filters' => (object) ($f['filters'] ?? []), 'subjects' => array_values($f['subjects'] ?? []), 'rows' => array_map('present_graph_row', $f['rows'] ?? []),
            'total' => (int) ($f['total'] ?? 0), 'candidates' => (int) ($f['candidates'] ?? 0), 'took_ms' => (int) ($f['took_ms'] ?? 0), 'sentence' => graph_status_sentence($f['status'])];
}

/** The sentence a status reads as on the page. */
function graph_status_sentence(string $status): string
{
    return match ($status) {
        'ok' => '', 'no_query' => 'Type what the documents should be about.', 'no_subjects' => 'Nothing in the graph matches those words; try the keyword search.',
        'graph_unavailable' => graph_unavailable_sentence(), 'graph_timeout' => 'The document graph did not answer in time; try again, or the keyword search.',
        'not_in_graph' => 'Not in the document graph yet.', 'refused' => 'Sign in first.', default => 'The document graph could not answer (' . $status . '); keyword search still works.',
    };
}

function present_subject_page(array $s): array
{
    if ($s['status'] !== 'ok') {
        return ['status' => $s['status'], 'sentence' => graph_status_sentence($s['status'])];
    }
    return ['status' => 'ok', 'subject_id' => $s['subject_id'], 'name' => $s['name'], 'type' => $s['type'], 'description' => $s['description'], 'documents' => array_map('present_graph_row', $s['documents']),
            'documents_hidden' => $s['documents_hidden'], 'neighbour_subjects' => $s['neighbour_subjects'], 'verbs' => array_values(array_map(static fn ($v) => is_array($v) ? ($v['name'] ?? $v['label'] ?? json_encode($v)) : (string) $v, (array) $s['verbs']))];
}

function present_job(array $j): array
{
    return ['graph_job_id' => (int) ($j['graph_job_id'] ?? $j['id']), 'document_id' => $j['document_id'] === null ? null : (int) $j['document_id'], 'document_name' => $j['document_name'] ?? null, 'kind' => $j['kind'], 'chars' => (int) $j['chars'],
            'status' => $j['status'], 'attempts' => (int) $j['attempts'], 'error' => $j['error'], 'created_at' => json_ts($j['created_at']), 'started_at' => json_ts($j['started_at']), 'finished_at' => json_ts($j['finished_at'])];
}

function present_budget(array $b): array
{
    return ['month' => (string) $b['month'], 'full_chars' => (int) $b['full_chars'], 'budget_chars' => (int) $b['budget_chars'], 'left_chars' => (int) $b['left_chars'], 'pct' => (float) $b['pct'],
            'full_documents' => (int) ($b['full_documents'] ?? 0), 'index_documents' => (int) ($b['index_documents'] ?? 0)];
}

function present_policy_row(array $l): array
{
    return ['library_id' => (int) $l['library_id'], 'kind' => $l['kind'], 'name' => $l['name'], 'graph_policy' => $l['graph_policy'], 'root_folder_id' => (int) $l['root_folder_id'], 'my_level' => $l['my_level'],
            'documents' => (int) $l['documents'], 'indexed' => (int) $l['indexed'], 'extracted' => (int) $l['extracted'], 'queued' => (int) $l['queued']];
}

function present_estimate(array $e): array
{
    return ['documents' => $e['documents'], 'chars' => $e['chars'], 'calls' => $e['calls'], 'budget_left_chars' => $e['budget_left_chars'], 'over_budget' => $e['over_budget'], 'engine_available' => $e['engine_available'],
            'sentence' => estimate_sentence($e)];
}

/** "1,240 documents, 38 MB of text, about 22,000 model calls; this month's budget has 61 MB left — over budget: …" */
function estimate_sentence(array $e): string
{
    $s = number_format($e['documents']) . ' document' . ($e['documents'] === 1 ? '' : 's') . ', ' . format_bytes($e['chars']) . ' of text, about ' . number_format($e['calls']) . ' model call' . ($e['calls'] === 1 ? '' : 's')
       . '; this month\'s budget has ' . format_bytes($e['budget_left_chars']) . ' left';
    return $s . ($e['over_budget'] ? ' — over budget: the queue will hold the rest until next month.' : '.');
}
