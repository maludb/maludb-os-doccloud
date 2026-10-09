<?php
/**
 * Slice 3's helpers on top of slice 2's (the actors, post/post_j/post_json/post_agent, upload_file, worker, doc_row, the fixtures): a public GET/POST
 * with its own cookie jar (a link holder is nobody), the raw token of a link just made (from the handler's JSON answer), and the seed of a
 * second group member outside the Board (Dana 30) and a department (Engineering 8 with Dana and Lee 31).
 */
require dirname(__DIR__) . '/slice2/lib.php';
/** A nobody's jar (a link holder, an uploader). */
function visitor(string $key = 'v'): string { static $jars = []; return $jars[$key] ??= jar(); }
/** A GET with a visitor's jar. */
function pub(string $path, string $who = 'v', array $o = []): array { return req('GET', $path, ['jar' => visitor($who)] + $o); }
/** A visitor's form POST (the public page's CSRF token read from the page first). */
function pub_post(string $path, array $form, string $who = 'v', string $pagePath = ''): array
{
    $j = visitor($who);
    $html = req('GET', $pagePath !== '' ? $pagePath : $path, ['jar' => $j])['body'];
    $form['csrf_token'] = csrf_of($html);
    return req('POST', $path, ['jar' => $j, 'form' => $form]);
}
/** A visitor's multipart upload to a request door. */
function pub_upload(string $path, string $file, string $name, string $who = 'v', string $pagePath = ''): array
{
    $j = visitor($who);
    $html = req('GET', $pagePath !== '' ? $pagePath : dirname($path), ['jar' => $j])['body'];
    $fields = ['csrf_token' => csrf_of($html), 'name' => $name, 'file' => new CURLFile($file, mime_content_type($file) ?: 'application/octet-stream', basename($file))];
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $hs);
    return ['code' => $code, 'headers' => substr($r, 0, $hs), 'body' => $body, 'json' => json_decode($body, true) ?: []];
}
/** A link made as a member: [link_id, raw token, url] from the JSON answer. */
function make_link(int $m, array $form): array
{
    $r = post_j($m, '/links/make.php', $form);
    $url = (string) ($r['json']['url'] ?? '');
    return ['r' => $r, 'link_id' => (int) ($r['json']['record_id'] ?? 0), 'raw' => $url === '' ? '' : substr($url, -48), 'url' => $url, 'path' => $url === '' ? '' : (string) parse_url($url, PHP_URL_PATH)];
}
function make_request(int $m, array $form): array
{
    $r = post_j($m, '/requests/make.php', $form);
    $url = (string) ($r['json']['url'] ?? '');
    return ['r' => $r, 'link_id' => (int) ($r['json']['record_id'] ?? 0), 'raw' => $url === '' ? '' : substr($url, -48), 'url' => $url, 'path' => $url === '' ? '' : (string) parse_url($url, PHP_URL_PATH)];
}
/** The access rows of a thing as the database holds them. */
function access_of(string $kind, int $id): array { return q('SELECT principal_kind, principal_id, principal_ref, level FROM ' . ($kind === 'folder' ? 'folder_access WHERE folder_id' : 'document_access WHERE document_id') . ' = :id ORDER BY id', ['id' => $id]); }
/** A folder's access row id for a principal. */
function access_id(string $kind, int $id, string $pk, ?int $pid): int { return (int) one('SELECT id FROM ' . ($kind === 'folder' ? 'folder_access WHERE folder_id' : 'document_access WHERE document_id') . ' = :id AND principal_kind = :k AND principal_id IS NOT DISTINCT FROM :p', ['id' => $id, 'k' => $pk, 'p' => $pid]); }
/** Seed beyond the store: Engineering (8) as a department with Dana 30 and Lee 31; the group SMOKE Board exists (Marco 27 owner, Priya 26, Seamus 40). */
function seed_people(): void
{
    $p = pdo();
    $p->exec("INSERT INTO departments (id, name) VALUES (8, 'SMOKE Engineering') ON CONFLICT (id) DO NOTHING");
    $p->exec("INSERT INTO department_members (member_id, department_id) VALUES (30, 8), (31, 8) ON CONFLICT DO NOTHING");
}
function bell(int $m, string $kind): array { return q('SELECT * FROM notifications WHERE member_id = :m AND kind = :k ORDER BY id DESC', ['m' => $m, 'k' => $kind]); }
