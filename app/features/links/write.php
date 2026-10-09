<?php
declare(strict_types=1);

/**
 * The writes of links and file requests (slice 3): mint (dc_link_mint — the raw token answered ONCE), rotate, update, revoke, a request
 * made and closed, an open counted (dc_link_open), a request's upload landing (slice 2's upload_one as the maker, source request, the
 * request tag). The token is hashed by the database and never stored, logged or shown twice.
 */

/** Mint a link (kind view) or a request (kind request) on a folder or a document. $f: label, days (NULL default, 0 never), allow_download, password (plain or null), note, rotate_of. */
function link_make(PDO $pdo, string $kind, int $id, array $f, int $by, string $linkKind = 'view'): array
{
    $hash = isset($f['password']) && $f['password'] !== '' ? password_hash((string) $f['password'], PASSWORD_DEFAULT) : null;
    $st = $pdo->prepare('SELECT link_id, raw_token FROM dc_link_mint(:f, :d, :kind, :label, :days, :dl, :pw, :by, :note, :rot)');
    $st->execute(['f' => $kind === 'folder' ? $id : null, 'd' => $kind === 'document' ? $id : null, 'kind' => $linkKind, 'label' => $f['label'], 'days' => $f['days'] ?? null,
                  'dl' => !empty($f['allow_download']) ? 't' : 'f', 'pw' => $hash, 'by' => $by, 'note' => $f['note'] ?? null, 'rot' => $f['rotate_of'] ?? null]);
    $r = $st->fetch();
    return ['link_id' => (int) $r['link_id'], 'raw' => (string) $r['raw_token']];
}

/** Rotate: the same label, note, download switch and (remaining) password, a new token; the old link dies now. */
function link_rotate(PDO $pdo, array $old, int $by): array
{
    $days = $old['expires_at'] === null ? 0 : max(1, (int) ceil((strtotime((string) $old['expires_at']) - time()) / 86400));
    $st = $pdo->prepare('SELECT link_id, raw_token FROM dc_link_mint(:f, :d, :kind, :label, :days, :dl, :pw, :by, :note, :rot)');
    $st->execute(['f' => $old['folder_id'], 'd' => $old['document_id'], 'kind' => $old['kind'], 'label' => $old['label'], 'days' => $days === 0 && $old['expires_at'] !== null ? 1 : $days,
                  'dl' => $old['allow_download'] ? 't' : 'f', 'pw' => $old['password_hash'], 'by' => $by, 'note' => $old['note'], 'rot' => (int) $old['id']]);
    $r = $st->fetch();
    return ['link_id' => (int) $r['link_id'], 'raw' => (string) $r['raw_token']];
}

/** Update a live link in place: label, note, allow_download, password (null = keep, '' = remove, else set), days (NULL = keep, 0 = never). */
function link_update(PDO $pdo, int $id, array $f): void
{
    $sets = [];
    $args = ['id' => $id];
    foreach (['label', 'note'] as $k) {
        if (array_key_exists($k, $f)) { $sets[] = "$k = :$k"; $args[$k] = $f[$k]; }
    }
    if (array_key_exists('allow_download', $f)) { $sets[] = 'allow_download = :dl'; $args['dl'] = $f['allow_download'] ? 't' : 'f'; }
    if (array_key_exists('password', $f) && $f['password'] !== null) {
        $sets[] = 'password_hash = :pw';
        $args['pw'] = $f['password'] === '' ? null : password_hash((string) $f['password'], PASSWORD_DEFAULT);
    }
    if (array_key_exists('days', $f) && $f['days'] !== null) {
        $sets[] = $f['days'] === 0 ? 'expires_at = NULL' : 'expires_at = now() + make_interval(days => :days)';
        if ($f['days'] !== 0) { $args['days'] = (int) $f['days']; }
    }
    if ($sets === []) {
        return;
    }
    $pdo->prepare('UPDATE share_links SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
}

function link_revoke(PDO $pdo, int $id, int $by): bool
{
    $st = $pdo->prepare('UPDATE share_links SET revoked_at = now(), revoked_by = :by WHERE id = :id AND revoked_at IS NULL');
    $st->execute(['by' => $by, 'id' => $id]);
    return $st->rowCount() === 1;
}

function request_make(PDO $pdo, int $folderId, array $f, int $by): array
{
    return link_make($pdo, 'folder', $folderId, $f + ['allow_download' => false], $by, 'request');
}

function request_close(PDO $pdo, int $id, int $by): bool
{
    return link_revoke($pdo, $id, $by);
}

/** An open counted (dc_link_open): view, download, upload, password_refused, rate_refused — with the address hash, never the address. */
function link_open(PDO $pdo, int $linkId, string $what, string $addressHash, ?int $documentId = null, ?int $bytes = null): void
{
    $pdo->prepare('SELECT dc_link_open(:l, :w, :a, :d, :b)')->execute(['l' => $linkId, 'w' => $what, 'a' => $addressHash, 'd' => $documentId, 'b' => $bytes]);
}

/**
 * A request's upload: the file lands in the request's folder OWNED BY THE MAKER (slice 2's upload_one: source request, keep both on a
 * conflict), tagged request:<label> (dc_tag_ensure as a free tag, dc_tag_apply source request), counted on the link, the maker told
 * (dc_notify request_received, deduped per hour). $file: ['name', 'tmp_name', 'size']; $uploader: the name typed (≤ 120). Returns the upload's answer.
 */
function request_upload(PDO $pdo, array $link, array $file, string $uploader, string $addressHash): array
{
    $maker = (int) $link['made_by'];
    $folderId = (int) $link['folder_id'];
    $note = 'from ' . ($uploader !== '' ? $uploader : 'someone') . ' via the request ' . $link['label'];
    $r = upload_one($pdo, $folderId, $file, $maker, ['source' => 'request', 'on_conflict' => 'keep_both', 'note' => mb_substr($note, 0, 500)]);
    $docId = (int) $r['document_id'];
    $tagId = (int) one_value($pdo, "SELECT dc_tag_ensure(:l, NULL, 'free')", ['l' => 'request:' . $link['label']]);
    $pdo->prepare("SELECT dc_tag_apply(:d, :t, NULL, 'request')")->execute(['d' => $docId, 't' => $tagId]);
    link_open($pdo, (int) $link['link_id'], 'upload', $addressHash, $docId, (int) ($file['size'] ?? 0));
    $folderName = (string) one_value($pdo, 'SELECT name FROM folders WHERE id = :id', ['id' => $folderId]);
    $hour = date('YmdH');
    $n = (int) one_value($pdo, "SELECT count(*) FROM link_opens WHERE link_id = :l AND what = 'upload' AND opened_at >= date_trunc('hour', now())", ['l' => (int) $link['link_id']]);
    $pdo->prepare('SELECT dc_notify(:m, :k, :t, :b, :rt, :rid, :d, :f, NULL, :dd)')->execute(['m' => $maker, 'k' => 'request_received',
        't' => ($n === 1 ? 'A file arrived' : $n . ' files arrived') . ' in ' . $folderName . ' from the request ' . $link['label'], 'b' => $file['name'] . ($uploader !== '' ? ' from ' . $uploader : ''),
        'rt' => 'link', 'rid' => (int) $link['link_id'], 'd' => $docId, 'f' => $folderId, 'dd' => 'request:' . (int) $link['link_id'] . ':' . $hour]);
    return $r + ['document_id' => $docId];
}
