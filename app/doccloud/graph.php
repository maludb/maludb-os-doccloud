<?php
declare(strict_types=1);

/**
 * The document graph module — the ONE file of DocCloud that talks to MaluDB (design §0.3, §6.3, §8, D3, D8).
 *
 * It alone reads MALUDB_DC_API_URL and MALUDB_DC_API_TOKEN (the document graph's own memory database, <tenant>_doccloud_memory,
 * its own token minted by deploy/ROOT_STEPS.sh). Every public function returns an array and NEVER throws on an engine problem:
 * with no URL or token, or an engine that cannot be reached, it answers ['status' => 'graph_unavailable', ...]. Keyword search
 * needs nothing of this file; the worker's graph jobs and dc_graph_find() do.
 *
 * Every result carries:
 *     status   one of: the function's own success word (indexed, extracted, forgotten, ok, …) or
 *              graph_unavailable   no URL/token configured, or the API cannot be reached
 *              graph_timeout       the API did not answer in time
 *              graph_auth          the API refused the token (401)
 *              graph_forbidden     the memory database refused the role (403)
 *              not_found           the record is not in the engine (404)
 *              no_edges            the model extracted nothing from the text (422 no_edges) — not an error
 *              model_failed        the model provider failed or answered nonsense (502)
 *              invalid_argument    an argument failed validation — no call was made
 *              validation_failed, model_not_configured, model_api_key_missing, conflict, …  MaluDB's own code, passed through
 *              graph_error         anything else
 *     http     the HTTP status the API answered (0 when no call completed)
 *     error    a short text that never carries the token
 *
 * WHAT THE ROUTES RETURN (proven by tests/phase0/engine.sh against maludb_core 0.106.0 / API 0.3.1, 2026-10-09; the detail in
 * tests/phase0/engine/FINDINGS.md) — the facts dc_graph_find() (SQL, the lead's) and Phase 1 build on:
 *  - POST /v1/memory/remember accepts `metadata` and stores it, and answers {document_id, chunk_count, statements}; it sets NO
 *    document_type (the route hard-codes None). `source_type` is a CONTROLLED vocabulary in the core (malu$source_type: document,
 *    note, conversation, ticket, log, …; a new value is a 422 foreign-key error), so both policies store `source_type = 'document'`;
 *    which policy a document entered under is DocCloud's own record (graph_documents.policy), and the `full` ones also carry
 *    document_type 'dc'. This memory database holds nothing but DocCloud's graph (D3), so every document in it is DocCloud's.
 *    remember takes at most 8 subjects (MAX_SUBJECTS): dc_graph_index_document() cuts the list and says so (`subjects_dropped`) — M12.
 *  - POST /v1/memory/documents accepts document_type ('dc' here), metadata and subjects; one model call per chunk.
 *  - POST /v1/memory/search answers {chunk_id, statement_id, document_id, source_text, distance, similarity, rank_no,
 *    subject_name, verb_name} — the engine's document_id, NO metadata. A candidate's DocCloud document is found by mapping
 *    maludb_document_id through graph_documents (the lead's table); dc_graph_candidates() leaves `document_id` NULL for that reason.
 *  - POST /v1/memory/recall proposes subjects by trigram (word_similarity >= 0.45 against canonical names) when given none, and
 *    answers the same rows plus `namespace`; limit <= 50.
 *  - GET /v1/graph/query?q= scores subjects by term matches on canonical_name/aliases and walks from the top seeds; its `namespace`
 *    is a canonical-NAME PREFIX (graphify imports), not the memory namespace — not used here.
 *  - GET /v1/graph/neighbors?kind=subject&id= lists {neighbor_kind, neighbor_id, rel, edge_store, confidence, provenance, label}:
 *    a subject's documents arrive as neighbor_kind 'document' (rel `mentions` from the subject tags, `contains` from remember's
 *    edges). GET /v1/subjects/{id} answers {id, label, type, description, verbs[], related_subjects[], documents[{id, title, rel}]}
 *    — a subject's documents directly, the cheaper read when only the documents are wanted. GET /v1/subjects?q= answers
 *    {subjects: [{id, label, type, …}]} (a substring match over name and description).
 *  - GET /v1/documents/{id} answers title, source_type, media_type, document_type, content_size, content_hash, tags — NO metadata.
 *    GET /v1/documents?source_type=eq.document lists the graph's documents (the allow-listed columns only).
 *  - DELETE /v1/documents/{id} forgets the document, its edges and chunks (search drops a deleted document's chunks).
 *  - /v1/graph/{stats,god-nodes} are tenant-wide; this memory database holds nothing but DocCloud's graph (D3), so they are its.
 */

const DC_GRAPH_NAMESPACE = 'dc';
const DC_GRAPH_SOURCE_TYPE = 'document';     // malu$source_type is a CONTROLLED vocabulary (document, note, conversation, ticket, …): 'dc' is refused with a 422 foreign-key error
const DC_GRAPH_DOCUMENT_TYPE = 'dc';
const DC_GRAPH_MAX_SUBJECTS = 8;           // the remember route's MAX_SUBJECTS (M12)
const DC_GRAPH_TIMEOUT = 20;               // seconds, a route that calls no model
const DC_GRAPH_MODEL_TIMEOUT = 60;         // seconds, a route that calls the model (documents)
const DC_GRAPH_CONNECT_TIMEOUT = 3;

// ---------------------------------------------------------------------------------------------------------------------
// Configuration and the result shape
// ---------------------------------------------------------------------------------------------------------------------

function dc_graph_env(string $key): string
{
    $value = function_exists('env') ? env($key) : null;
    if ($value === null || $value === '') {
        $raw = getenv($key);
        $value = $raw === false ? ($_ENV[$key] ?? $_SERVER[$key] ?? '') : $raw;
    }
    return trim((string) $value);
}

/** @return array{url: string, token: string}|null */
function dc_graph_config(): ?array
{
    $url = rtrim(dc_graph_env('MALUDB_DC_API_URL'), '/');
    $token = dc_graph_env('MALUDB_DC_API_TOKEN');
    if ($url === '' || $token === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    return ['url' => $url, 'token' => $token];
}

function dc_graph_text_chars(): int
{
    $v = dc_graph_env('DC_GRAPH_TEXT_CHARS');
    return ($v !== '' && ctype_digit($v) && (int) $v >= 200) ? min((int) $v, 200000) : 20000;
}

/** Secrets handed to the engine (a provider credential): redacted from everything returned, like the token. */
function dc_graph_secrets(?string $add = null): array
{
    static $secrets = [];
    if ($add !== null && strlen($add) >= 6) {
        $secrets[$add] = true;
    }
    return array_keys($secrets);
}

function dc_graph_scrub_text(string $text): string
{
    foreach (array_merge([dc_graph_env('MALUDB_DC_API_TOKEN')], dc_graph_secrets()) as $secret) {
        if ($secret !== '' && strlen($secret) >= 6) {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }
    $text = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text);
    return (string) preg_replace('/malu_[A-Za-z0-9_\-]{8,}/', 'malu_[redacted]', $text);
}

function dc_graph_scrub(mixed $value): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/^(token|api_key|apikey|password|pg_password|authorization|secret|secret_value)$/i', $k)) {
                continue;
            }
            $out[$k] = dc_graph_scrub($v);
        }
        return $out;
    }
    return is_string($value) ? dc_graph_scrub_text($value) : $value;
}

function dc_graph_fail(string $status, int $http = 0, string $error = '', array $extra = []): array
{
    return dc_graph_scrub(['status' => $status, 'http' => $http, 'error' => $error] + $extra);
}

function dc_graph_invalid(string $why): array
{
    return dc_graph_fail('invalid_argument', 0, $why);
}

/** User-safe words for a status (never MaluDB's own message). */
function dc_graph_error_message(string $status): string
{
    return match ($status) {
        'graph_unavailable' => 'The document graph is not available right now. Keyword search still works.',
        'graph_timeout' => 'The document graph took too long to answer. Try again in a moment.',
        'graph_auth', 'graph_forbidden' => 'The document graph refused this installation\'s credentials. An administrator must check them.',
        'invalid_argument' => 'The request was not valid.',
        'not_found' => 'The document graph does not hold that record.',
        'no_edges' => 'The model found nothing to extract from this document.',
        'model_failed' => 'The model behind the document graph failed. The document stays queued.',
        'model_not_configured', 'model_api_key_missing' => 'The document graph has no model configured. An administrator sets one on the Graph screen.',
        default => 'The document graph could not complete the request.',
    };
}

// ---------------------------------------------------------------------------------------------------------------------
// The one HTTP call
// ---------------------------------------------------------------------------------------------------------------------

/** @return array{ok: bool, http: int, data: array, status: string, error: string} */
function dc_graph_request(string $method, string $path, array $query = [], ?array $body = null, bool $model = false): array
{
    $cfg = dc_graph_config();
    if ($cfg === null) {
        return ['ok' => false, 'http' => 0, 'data' => [], 'status' => 'graph_unavailable', 'error' => 'MALUDB_DC_API_URL or MALUDB_DC_API_TOKEN is not set.'];
    }
    $timeout = $model ? DC_GRAPH_MODEL_TIMEOUT : DC_GRAPH_TIMEOUT;
    $override = dc_graph_env('MALUDB_DC_API_TIMEOUT');
    if ($override !== '' && ctype_digit($override)) {
        $timeout = max(1, min(120, (int) $override));
    }
    try {
        $url = $cfg['url'] . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $send = ['Authorization: Bearer ' . $cfg['token'], 'Accept: application/json'];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $send[] = 'Content-Type: application/json';
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $send,
            CURLOPT_CONNECTTIMEOUT => min(DC_GRAPH_CONNECT_TIMEOUT, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } catch (Throwable) {
        return ['ok' => false, 'http' => 0, 'data' => [], 'status' => 'graph_error', 'error' => 'The request could not be prepared.'];
    }
    if ($errno !== 0 || $raw === false) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return ['ok' => false, 'http' => 0, 'data' => [], 'status' => 'graph_timeout', 'error' => "The document graph did not answer within {$timeout} s."];
        }
        return ['ok' => false, 'http' => 0, 'data' => [], 'status' => 'graph_unavailable', 'error' => 'The document graph could not be reached (curl error ' . $errno . ').'];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    if ($http >= 200 && $http < 300) {
        return ['ok' => true, 'http' => $http, 'data' => $data, 'status' => 'ok', 'error' => ''];
    }
    $code = (string) ($data['error']['code'] ?? $data['code'] ?? $data['detail']['code'] ?? '');
    $msg = (string) ($data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? '');
    if (!is_string($data['detail'] ?? null) && is_array($data['detail'] ?? null)) {
        $msg = (string) json_encode($data['detail']);
    }
    $status = match (true) {
        $http === 401 => 'graph_auth',
        $http === 403 => 'graph_forbidden',
        $http === 404 => $code === '' || $code === 'not_found' ? 'not_found' : 'route_not_found',
        $http === 422 && $code === 'no_edges' => 'no_edges',
        $http === 502 || $code === 'upstream_error' => 'model_failed',
        $code !== '' => preg_replace('/[^a-z0-9_]/', '_', strtolower($code)),
        default => 'graph_error',
    };
    return ['ok' => false, 'http' => $http, 'data' => $data, 'status' => (string) $status, 'error' => dc_graph_scrub_text(mb_substr($msg === '' ? 'HTTP ' . $http : $msg, 0, 300))];
}

/** A failed request → the public failure shape (data kept for the caller's log, scrubbed). */
function dc_graph_failed(array $r, array $extra = []): array
{
    return dc_graph_fail($r['status'], $r['http'], $r['error'], ['engine_code' => $r['data']['error']['code'] ?? $r['data']['code'] ?? null] + $extra);
}

// ---------------------------------------------------------------------------------------------------------------------
// Availability and configuration
// ---------------------------------------------------------------------------------------------------------------------

/** True when a URL and token are set AND the API answers its health check. Never throws. */
function dc_graph_available(): bool
{
    if (dc_graph_config() === null) {
        return false;
    }
    $r = dc_graph_request('GET', '/health');
    return $r['ok'];
}

/** The namespace's model configuration as MaluDB keeps it (secret_ref, never the credential). */
function dc_graph_config_get(): array
{
    $r = dc_graph_request('GET', '/v1/memory/config', ['namespace' => DC_GRAPH_NAMESPACE]);
    if (!$r['ok']) {
        return dc_graph_failed($r);
    }
    return dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'namespace' => DC_GRAPH_NAMESPACE, 'config' => $r['data']['config'] ?? null]);
}

/**
 * Set the namespace's provider, model alias, embedding model and (optionally) the extraction prompt (the `full` policy's).
 * $cfg: provider_name, provider_kind, model_alias, model_identifier, base_url (the kernel's ledger proxy, K24), embedding_model;
 * optional: context_length, prompt_template (must contain {{chunk}}), secret_name + token (the K24 credential — stored by MaluDB,
 * never by DocCloud), generation_params, default_subject_type. Changing it pauses for an agent (§5).
 */
function dc_graph_config_set(array $cfg): array
{
    $need = ['provider_name' => 80, 'provider_kind' => 40, 'model_alias' => 120, 'model_identifier' => 200, 'base_url' => 500, 'embedding_model' => 200];
    $clean = [];
    foreach ($need as $key => $max) {
        $v = $cfg[$key] ?? null;
        $v = is_string($v) ? trim($v) : null;
        if ($v === null || $v === '' || mb_strlen($v) > $max) {
            return dc_graph_invalid("Configuration field \"$key\" is required (at most $max characters).");
        }
        $clean[$key] = $v;
    }
    if (!preg_match('#^https?://#i', $clean['base_url'])) {
        return dc_graph_invalid('base_url must be an http(s) URL.');
    }
    $body = [
        'namespace' => DC_GRAPH_NAMESPACE,
        'provider' => ['name' => $clean['provider_name'], 'kind' => $clean['provider_kind']],
        'alias' => ['name' => $clean['model_alias'], 'model_identifier' => $clean['model_identifier'], 'base_url' => $clean['base_url']],
        'embedding_model' => $clean['embedding_model'],
    ];
    if (isset($cfg['context_length'])) {
        if (!is_int($cfg['context_length']) || $cfg['context_length'] <= 0) {
            return dc_graph_invalid('context_length must be a positive integer.');
        }
        $body['alias']['context_length'] = $cfg['context_length'];
    }
    if (array_key_exists('prompt_template', $cfg) && $cfg['prompt_template'] !== null) {
        $tpl = $cfg['prompt_template'];
        if (!is_string($tpl) || !str_contains($tpl, '{{chunk}}') || strlen($tpl) > 60000) {
            return dc_graph_invalid('prompt_template must be text containing {{chunk}}.');
        }
        $body['prompt_template'] = $tpl;
    }
    if (isset($cfg['token'])) {
        $secret = is_string($cfg['secret_name'] ?? null) ? trim($cfg['secret_name']) : '';
        if (!is_string($cfg['token']) || $cfg['token'] === '' || $secret === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $secret)) {
            return dc_graph_invalid('A provider token needs a secret_name of letters, digits and _.:-');
        }
        dc_graph_secrets($cfg['token']);
        $body['secret_name'] = $secret;
        $body['token'] = $cfg['token'];
    } elseif (isset($cfg['secret_name']) && is_string($cfg['secret_name']) && trim($cfg['secret_name']) !== '') {
        $body['secret_name'] = trim($cfg['secret_name']);
    }
    if (isset($cfg['generation_params'])) {
        if (!is_array($cfg['generation_params'])) {
            return dc_graph_invalid('generation_params must be an object.');
        }
        $body['generation_params'] = (object) $cfg['generation_params'];
    }
    if (isset($cfg['default_subject_type']) && is_string($cfg['default_subject_type']) && trim($cfg['default_subject_type']) !== '') {
        $body['default_subject_type'] = trim($cfg['default_subject_type']);
    }
    $r = dc_graph_request('POST', '/v1/memory/config', [], $body);
    if (!$r['ok']) {
        return dc_graph_failed($r);
    }
    return dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'config' => $r['data']['config'] ?? $r['data']]);
}

// ---------------------------------------------------------------------------------------------------------------------
// What enters the graph (the policies, D8) and what leaves it
// ---------------------------------------------------------------------------------------------------------------------

/** @return list<string> clean, unique subject names (order kept) */
function dc_graph_clean_subjects(array $subjects): array
{
    $out = [];
    foreach ($subjects as $s) {
        if (!is_string($s)) {
            continue;
        }
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '' || mb_strlen($s) > 200) {
            continue;
        }
        $key = mb_strtolower($s);
        if (!isset($out[$key])) {
            $out[$key] = $s;
        }
    }
    return array_values($out);
}

function dc_graph_clean_meta(array $doc, array $meta): array
{
    $m = ['document_id' => (int) ($meta['document_id'] ?? $doc['id'] ?? 0)];
    foreach (['library_id', 'version_id', 'folder_id'] as $k) {
        if (isset($meta[$k]) && (is_int($meta[$k]) || ctype_digit((string) $meta[$k]))) {
            $m[$k] = (int) $meta[$k];
        }
    }
    $m['application'] = 'doccloud';
    return $m;
}

/**
 * The `index` policy (the default; no model call): one POST /v1/memory/remember with the document's first DC_GRAPH_TEXT_CHARS
 * characters of text under its KNOWN subjects — its managed tags, folder, library, owner, kind, year, the sibling record it
 * came from, the document itself (`doc:<id>`) — the most-used first; the route takes at most eight (M12), the rest are dropped
 * and named in `subjects_dropped`. $doc: {id, name}; $meta: {document_id, library_id, version_id, folder_id}.
 * → {status: 'indexed', maludb_document_id, chunk_count, statements, subjects, subjects_dropped}
 */
function dc_graph_index_document(array $doc, string $text, array $subjects, array $meta = []): array
{
    $name = trim((string) ($doc['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 500) {
        return dc_graph_invalid('A document needs a name of at most 500 characters.');
    }
    $subjects = dc_graph_clean_subjects($subjects);
    if ($subjects === []) {
        return dc_graph_invalid('The index policy needs at least one subject (the document itself, doc:<id>, at the least).');
    }
    $dropped = array_slice($subjects, DC_GRAPH_MAX_SUBJECTS);
    $subjects = array_slice($subjects, 0, DC_GRAPH_MAX_SUBJECTS);
    $body = trim($text) === '' ? $name : $text;
    $body = mb_substr($body, 0, dc_graph_text_chars(), 'UTF-8');
    $r = dc_graph_request('POST', '/v1/memory/remember', [], [
        'text' => $body,
        'subjects' => $subjects,
        'verb' => 'contains',
        'namespace' => DC_GRAPH_NAMESPACE,
        'title' => mb_substr($name, 0, 500),
        'source_type' => DC_GRAPH_SOURCE_TYPE,
        'metadata' => (object) dc_graph_clean_meta($doc, $meta),
        'projects' => [],
        'chunk' => ['max' => 2000, 'overlap' => 200],
    ]);
    if (!$r['ok']) {
        return dc_graph_failed($r, ['subjects' => $subjects, 'subjects_dropped' => $dropped]);
    }
    return dc_graph_scrub([
        'status' => 'indexed',
        'http' => $r['http'],
        'maludb_document_id' => isset($r['data']['document_id']) ? (string) $r['data']['document_id'] : null,
        'chunk_count' => (int) ($r['data']['chunk_count'] ?? 0),
        'statements' => (int) ($r['data']['statements'] ?? 0),
        'embedding_model' => $r['data']['embedding_model'] ?? null,
        'subjects' => $subjects,
        'subjects_dropped' => $dropped,
        'text_chars' => mb_strlen($body, 'UTF-8'),
    ]);
}

/**
 * The `full` policy (the model extracts people, organisations, places, amounts, dates, products and topics with verbatim
 * spans): one POST /v1/memory/documents, queued by the worker one document at a time under the monthly budget (K24 meters).
 * → {status: 'extracted'|'no_edges', maludb_document_id, chunk_count, edges_count, usage?}
 */
function dc_graph_full_document(array $doc, string $text, array $subjects, array $meta = []): array
{
    $name = trim((string) ($doc['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 500) {
        return dc_graph_invalid('A document needs a name of at most 500 characters.');
    }
    if (trim($text) === '') {
        return dc_graph_invalid('The full policy needs text.');
    }
    $subjects = dc_graph_clean_subjects($subjects);
    $r = dc_graph_request('POST', '/v1/memory/documents', [], [
        'title' => mb_substr($name, 0, 500),
        'text' => $text,
        'namespace' => DC_GRAPH_NAMESPACE,
        'source_type' => DC_GRAPH_SOURCE_TYPE,
        'document_type' => DC_GRAPH_DOCUMENT_TYPE,
        'metadata' => (object) dc_graph_clean_meta($doc, $meta),
        'projects' => [],
        'subjects' => $subjects,
        'chunk' => ['max' => 2000, 'overlap' => 200],
    ], true);
    if (!$r['ok']) {
        if ($r['status'] === 'no_edges') {
            return dc_graph_fail('no_edges', $r['http'], $r['error'], ['maludb_document_id' => null, 'chunk_count' => 0, 'edges_count' => 0]);
        }
        return dc_graph_failed($r);
    }
    return dc_graph_scrub([
        'status' => 'extracted',
        'http' => $r['http'],
        'maludb_document_id' => isset($r['data']['document_id']) ? (string) $r['data']['document_id'] : null,
        'chunk_count' => (int) ($r['data']['chunk_count'] ?? 0),
        'edges_count' => is_array($r['data']['edges'] ?? null) ? count($r['data']['edges']) : (int) ($r['data']['edges_count'] ?? 0),
        'extractor' => $r['data']['extractor'] ?? null,
        'embedding_model' => $r['data']['embedding_model'] ?? null,
        'usage' => $r['data']['usage'] ?? null,
    ]);
}

/** Forget one of the engine's documents (its chunks and edges with it). → {status: forgotten|missing|graph_unavailable|…} */
function dc_graph_forget(string|int $maludbDocumentId): array
{
    $id = (string) $maludbDocumentId;
    if (!ctype_digit($id) || $id === '0') {
        return dc_graph_invalid('A MaluDB document id is a positive integer.');
    }
    $r = dc_graph_request('DELETE', '/v1/documents/' . $id);
    if ($r['ok']) {
        return ['status' => 'forgotten', 'http' => $r['http'], 'maludb_document_id' => $id];
    }
    if ($r['status'] === 'not_found') {
        return ['status' => 'missing', 'http' => $r['http'], 'maludb_document_id' => $id];
    }
    return dc_graph_failed($r);
}

/** GET /v1/documents/{id} — as MaluDB keeps it (title, source_type, document_type, size, hash, tags; NOT its metadata). */
function dc_graph_document(string|int $maludbDocumentId): array
{
    $id = (string) $maludbDocumentId;
    if (!ctype_digit($id) || $id === '0') {
        return dc_graph_invalid('A MaluDB document id is a positive integer.');
    }
    $r = dc_graph_request('GET', '/v1/documents/' . $id);
    if (!$r['ok']) {
        return dc_graph_failed($r);
    }
    return dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'document' => $r['data']['document'] ?? $r['data']]);
}

/** The graph's documents (this memory database is DocCloud's alone), newest first, paged — the admin's reconciliation with graph_documents. */
function dc_graph_documents(int $limit = 50, int $offset = 0): array
{
    $r = dc_graph_request('GET', '/v1/documents', ['source_type' => 'eq.' . DC_GRAPH_SOURCE_TYPE, 'limit' => max(1, min(200, $limit)), 'offset' => max(0, $offset), 'order' => 'id.desc']);
    if (!$r['ok']) {
        return dc_graph_failed($r);
    }
    $rows = $r['data']['documents'] ?? $r['data']['items'] ?? (array_is_list($r['data']) ? $r['data'] : []);
    return dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'documents' => $rows]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Finding — the gathering half of dc_graph_find() (the WALL is SQL: every candidate's document judged by dc_can_see_document())
// ---------------------------------------------------------------------------------------------------------------------

/**
 * The subjects a query names: the query's terms matched against subject names and aliases (GET /v1/graph/query, the seeds),
 * plus the subjects recall proposes by trigram (POST /v1/memory/recall with no subject).
 * → {status: 'ok', subjects: [{subject_id: ?int, name, score, via: 'terms'|'recall'}]}
 */
function dc_graph_resolve_subjects(string $query, int $limit = 8): array
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if ($query === '' || mb_strlen($query) > 400) {
        return dc_graph_invalid('A query is 1 to 400 characters.');
    }
    $limit = max(1, min(DC_GRAPH_MAX_SUBJECTS, $limit));
    $found = [];
    $r = dc_graph_request('GET', '/v1/graph/query', ['q' => $query, 'depth' => 1, 'seeds' => $limit, 'max_nodes' => 1]);
    if (!$r['ok'] && $r['status'] !== 'validation_failed') {
        return dc_graph_failed($r);
    }
    foreach ($r['data']['seeds'] ?? [] as $s) {
        $name = (string) ($s['canonical_name'] ?? '');
        if ($name !== '') {
            $found[mb_strtolower($name)] = ['subject_id' => isset($s['subject_id']) ? (int) $s['subject_id'] : null, 'name' => $name, 'score' => (float) ($s['score'] ?? 0), 'via' => 'terms'];
        }
    }
    $r2 = dc_graph_request('POST', '/v1/memory/recall', [], ['query' => $query, 'namespaces' => [DC_GRAPH_NAMESPACE], 'limit' => 1]);
    if ($r2['ok']) {
        foreach ($r2['data']['subjects_proposed'] ?? [] as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name !== '' && !isset($found[mb_strtolower($name)])) {
                $found[mb_strtolower($name)] = ['subject_id' => null, 'name' => $name, 'score' => (float) ($s['score'] ?? 0), 'via' => 'recall'];
            }
        }
    }
    uasort($found, static fn($a, $b) => $b['score'] <=> $a['score']);
    return ['status' => 'ok', 'http' => $r['http'], 'query' => $query, 'subjects' => array_slice(array_values($found), 0, $limit)];
}

/** A subject's engine id by its exact name (GET /v1/subjects?q= — a substring match over name and description, filtered here), or null. */
function dc_graph_subject_id(string $name): ?int
{
    $r = dc_graph_request('GET', '/v1/subjects', ['q' => $name, 'limit' => 20]);
    if (!$r['ok']) {
        return null;
    }
    // GET /v1/subjects answers {subjects: [{id, label (the canonical name), type, description, …}]}
    $rows = $r['data']['subjects'] ?? [];
    foreach ($rows as $row) {
        if (mb_strtolower((string) ($row['label'] ?? $row['canonical_name'] ?? '')) === mb_strtolower($name)) {
            return isset($row['id']) ? (int) $row['id'] : (isset($row['subject_id']) ? (int) $row['subject_id'] : null);
        }
    }
    return null;
}

/**
 * The candidates behind a query, OVER-FETCHED and UNJUDGED: for each subject (at most eight) the passages near the query within
 * it (POST /v1/memory/search, limit each) and the documents on its edges (GET /v1/graph/neighbors). `document_id` is NULL on
 * every candidate because no search route returns a document's metadata (the header): the caller maps `maludb_document_id`
 * through graph_documents and then judges each by dc_can_see_document() — in SQL, before ranking, before any excerpt.
 * $subjects: names, or {name, subject_id} rows from dc_graph_resolve_subjects().
 * → {status: 'ok', candidates: [{maludb_document_id, document_id: null, subject, similarity, chunk_text, via: search|neighbors}], subjects_used}
 */
function dc_graph_candidates(array $subjects, string $query, int $limit = 200): array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) > 400) {
        return dc_graph_invalid('A query is 1 to 400 characters.');
    }
    $limit = max(1, min(200, $limit));
    $names = [];
    $ids = [];
    foreach ($subjects as $s) {
        $name = is_array($s) ? (string) ($s['name'] ?? '') : (string) $s;
        $name = trim($name);
        if ($name === '' || isset($names[mb_strtolower($name)])) {
            continue;
        }
        $names[mb_strtolower($name)] = $name;
        if (is_array($s) && isset($s['subject_id']) && is_int($s['subject_id'])) {
            $ids[mb_strtolower($name)] = $s['subject_id'];
        }
    }
    $names = array_slice($names, 0, DC_GRAPH_MAX_SUBJECTS, true);
    if ($names === []) {
        return dc_graph_invalid('At least one subject is needed.');
    }
    $candidates = [];
    $used = [];
    $lastHttp = 0;
    foreach ($names as $key => $name) {
        $r = dc_graph_request('POST', '/v1/memory/search', [], ['query' => $query, 'subject' => $name, 'namespace' => DC_GRAPH_NAMESPACE, 'limit' => $limit]);
        if (!$r['ok'] && in_array($r['status'], ['graph_unavailable', 'graph_timeout', 'graph_auth', 'graph_forbidden'], true)) {
            return dc_graph_failed($r);
        }
        $lastHttp = $r['http'];
        if ($r['ok']) {
            foreach ($r['data']['results'] ?? [] as $row) {
                if (!isset($row['document_id'])) {
                    continue;
                }
                $mid = (string) $row['document_id'];
                $sim = isset($row['similarity']) ? (float) $row['similarity'] : null;
                $k = $mid . '|' . $key;
                if (!isset($candidates[$k]) || ($sim !== null && $sim > ($candidates[$k]['similarity'] ?? -1))) {
                    $candidates[$k] = ['maludb_document_id' => $mid, 'document_id' => null, 'subject' => $name, 'similarity' => $sim,
                        'chunk_text' => mb_substr((string) ($row['source_text'] ?? ''), 0, 400), 'chunk_id' => $row['chunk_id'] ?? null, 'via' => 'search'];
                }
            }
        }
        $sid = $ids[$key] ?? dc_graph_subject_id($name);
        if ($sid !== null) {
            $n = dc_graph_request('GET', '/v1/graph/neighbors', ['kind' => 'subject', 'id' => $sid, 'direction' => 'both']);
            if ($n['ok']) {
                foreach ($n['data']['neighbors'] ?? [] as $nb) {
                    if (($nb['neighbor_kind'] ?? '') !== 'document') {
                        continue;
                    }
                    $mid = (string) $nb['neighbor_id'];
                    $k = $mid . '|' . $key;
                    if (!isset($candidates[$k])) {
                        $candidates[$k] = ['maludb_document_id' => $mid, 'document_id' => null, 'subject' => $name, 'similarity' => null,
                            'chunk_text' => '', 'chunk_id' => null, 'via' => 'neighbors', 'rel' => $nb['rel'] ?? null];
                    }
                }
            }
        }
        $used[] = ['name' => $name, 'subject_id' => $sid];
    }
    $list = array_values($candidates);
    usort($list, static fn($a, $b) => (($b['similarity'] ?? -1) <=> ($a['similarity'] ?? -1)) ?: strcmp($a['maludb_document_id'], $b['maludb_document_id']));
    return dc_graph_scrub(['status' => 'ok', 'http' => $lastHttp, 'query' => $query, 'subjects_used' => $used, 'candidates' => array_slice($list, 0, $limit * DC_GRAPH_MAX_SUBJECTS)]);
}

// ---------------------------------------------------------------------------------------------------------------------
// The explorer (every document node is judged by the caller before display)
// ---------------------------------------------------------------------------------------------------------------------

function dc_graph_valid_kind(string $kind): bool
{
    return (bool) preg_match('/^[a-z_]{1,40}$/', $kind);
}

/** One hop from a node. → {status, neighbors: [{neighbor_kind, neighbor_id, rel, confidence, label}]} */
function dc_graph_neighbors(string $kind, int $id, string $direction = 'both', ?string $rel = null): array
{
    if (!dc_graph_valid_kind($kind) || $id <= 0 || !in_array($direction, ['both', 'out', 'in', 'outbound', 'inbound'], true)) {
        return dc_graph_invalid('neighbors need a kind, a positive id and a direction.');
    }
    $q = ['kind' => $kind, 'id' => $id, 'direction' => $direction];
    if ($rel !== null && $rel !== '') {
        $q['rel'] = mb_substr($rel, 0, 400);
    }
    $r = dc_graph_request('GET', '/v1/graph/neighbors', $q);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'neighbors' => $r['data']['neighbors'] ?? []]) : dc_graph_failed($r);
}

/** A path between two nodes. $a, $b: {kind, id}. → {status, path: […]} */
function dc_graph_path(array $a, array $b, int $maxDepth = 6): array
{
    foreach ([$a, $b] as $n) {
        if (!dc_graph_valid_kind((string) ($n['kind'] ?? '')) || (int) ($n['id'] ?? 0) <= 0) {
            return dc_graph_invalid('A path needs two nodes, each a kind and a positive id.');
        }
    }
    $r = dc_graph_request('GET', '/v1/graph/path', ['source_kind' => $a['kind'], 'source_id' => (int) $a['id'], 'target_kind' => $b['kind'], 'target_id' => (int) $b['id'], 'max_depth' => max(1, min(32, $maxDepth))]);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http']] + $r['data']) : dc_graph_failed($r);
}

/** The most connected nodes of the graph (this memory database is DocCloud's alone, so tenant-wide IS the application's). */
function dc_graph_god_nodes(int $limit = 10): array
{
    $r = dc_graph_request('GET', '/v1/graph/god-nodes', ['limit' => max(1, min(100, $limit))]);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http']] + $r['data']) : dc_graph_failed($r);
}

function dc_graph_surprises(int $limit = 25): array
{
    $r = dc_graph_request('GET', '/v1/graph/surprises', ['namespace' => DC_GRAPH_NAMESPACE, 'limit' => max(1, min(200, $limit))]);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http']] + $r['data']) : dc_graph_failed($r);
}

function dc_graph_stats(): array
{
    $r = dc_graph_request('GET', '/v1/graph/stats', ['top_rels' => 10]);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http']] + $r['data']) : dc_graph_failed($r);
}

/** A subject as the engine keeps it (name, type, description) — the explorer's subject page. */
function dc_graph_subject(int $subjectId): array
{
    if ($subjectId <= 0) {
        return dc_graph_invalid('A subject id is a positive integer.');
    }
    $r = dc_graph_request('GET', '/v1/subjects/' . $subjectId);
    return $r['ok'] ? dc_graph_scrub(['status' => 'ok', 'http' => $r['http'], 'subject' => $r['data']['subject'] ?? $r['data']]) : dc_graph_failed($r);
}
