<?php
declare(strict_types=1);

/**
 * The token API's shared machinery (slice 7, docs/build-specs/api-keys-k7.md; design §8): a route file requires this, calls api_route() — the method gate, the key (api_authenticate: 401 /
 * 429), the JSON body into $_POST — and answers. The WRITES reuse the slices' action handlers unchanged (folder_create, document_rename/describe/move, tag_apply/remove, document_trash,
 * link_make): api_run_handler() runs one with the request as its form and CAPTURES its answer (json_response throws ApiCaptured while `__api_capture` is set), so every right, level,
 * lock, validation and log row is the handler's own — logged with source `api` and the key's token_id — and the route reshapes the answer. Errors are the kit's {error:{code, message}}.
 */
require_once dirname(__DIR__) . '/features/files/handler.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/documents.php';

/** The route's front door: the allowed methods (a HEAD is a GET), the key, the body. Returns the caller's member row. $body: parse a JSON body into $_POST (writes). */
function api_route(array $methods, bool $body = false): array
{
    api_cors();
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    api_require_method(...$methods);
    $member = api_authenticate();
    if ($body) {
        api_read_body();
    }
    return $member;
}

/** The request body as a form: JSON ({...}) or a form post; the result lands in $_POST so the handlers read it as they always do. Arrays stay arrays. */
function api_read_body(): array
{
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_starts_with($ct, 'application/json') || (!str_starts_with($ct, 'multipart/') && !str_starts_with($ct, 'application/x-www-form-urlencoded'))) {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            return $_POST;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            api_error('invalid', 'The body is JSON: an object.', 400);
        }
        $_POST = [];
        foreach ($j as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (is_scalar($v) || is_array($v)) {
                $v = is_array($v) ? array_map(static fn ($x) => is_scalar($x) ? (string) $x : '', $v) : (string) $v;
            } else {
                continue;
            }
            $_POST[(string) $k] = $v;
        }
    }
    return $_POST;
}

/** A field of the body (JSON decoded as the form), or null. */
function api_field(string $name): ?string
{
    return array_key_exists($name, $_POST) && !is_array($_POST[$name]) ? trim((string) $_POST[$name]) : null;
}

/** The {id} the route's rewrite put in the query (?id=), a positive integer, else a 404. */
function api_id(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        api_error('not_found', 'Not found.', 404);
    }
    return $id;
}

/**
 * Run one of the slices' action handlers with $form as the request (POST, JSON mode) and answer its outcome: [status, payload] — the handler's own success
 * ({ok, did, record_id, location, …}) or its refusal ({error:{code, message}, errors?, fields?}). The handler has done everything else: rights, levels, the write, the log.
 */
function api_run_handler(string $handlerFile, array $form): array
{
    $keepPost = $_POST;
    $keepMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
    $_POST = $form;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['__api_capture'] = true;
    try {
        (static function (string $file): void {
            require $file;                                   // not require_once: a PATCH may run two handlers, a handler may run twice in one request
        })(dirname(__DIR__, 2) . '/html/' . $handlerFile);
        return [500, ['error' => ['code' => 'server_error', 'message' => 'The action did not answer.']]];
    } catch (ApiCaptured $c) {
        return [$c->status, $c->payload];
    } finally {
        unset($GLOBALS['__api_capture']);
        $_POST = $keepPost;
        $_SERVER['REQUEST_METHOD'] = $keepMethod;
    }
}

/** Send a captured refusal as it came (status, body). */
function api_relay(int $status, array $payload): never
{
    if ($status >= 400 && !isset($payload['error'])) {
        $payload = ['error' => ['code' => 'refused', 'message' => 'That could not be done.']] + $payload;
    }
    if (isset($payload['error']) && !is_array($payload['error'])) {
        $payload['error'] = ['code' => 'refused', 'message' => (string) $payload['error']];
    }
    api_json($payload, $status);
}

/** The key's own facts for the log. */
function api_key_label(): ?string
{
    return api_key()['label'] ?? null;
}
