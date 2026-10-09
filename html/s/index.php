<?php
declare(strict_types=1);
/**
 * THE LINK DOOR /s/<token> (visibility.md §5; `public-link`): no session of ours, no directory shown; the token is the authority. The rate rule per
 * address first (429), then dc_link_resolve (nothing = the same 404 for every reason), a request link → /r/, the password page once per session,
 * then: a document — name, size, kind, the preview, Download when allowed (the gate /d/{id} admitted through the session); a folder — the read-only
 * listing of the subtree (…/f/{id} inside it, …/d/{id} a document's page), Download all (…/zip: an export keyed by link_id the worker builds;
 * …/zip/{export} serves it). Every page noindex; every open counted (dc_link_open) and logged link.open with source portal.
 */
require_once dirname(__DIR__, 2) . '/app/features/links/public.php';
$pdo = db();
$pdo->exec("SELECT set_config('app.member_id', '', false)");
$raw = (string) ($_GET['token'] ?? '');
$rest = (string) ($_GET['rest'] ?? '');
$hash = public_address_hash();
public_rate_or_refuse($pdo, 'open_minute', null);
$link = link_resolve($pdo, $raw);
if ($link === null) {
    public_unavailable('not_found', $raw);
}
$linkId = (int) $link['link_id'];
$GLOBALS['__public_link_id'] = $linkId;
if ($link['kind'] === 'request') {
    header('Location: /r/' . $raw, true, 302);
    exit;
}
$base = '/s/' . $raw;
// the password step
if (!public_password_ok($link)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $rest === '/password') {
        verify_csrf();
        if (!public_password_try($pdo, $link, (string) ($_POST['password'] ?? ''))) {
            public_render($link['label'], view('public/password.php', ['link' => $link, 'base' => $base, 'wrong' => true]), 403);
        }
        header('Location: ' . $base, true, 302);
        exit;
    }
    public_render($link['label'], view('public/password.php', ['link' => $link, 'base' => $base, 'wrong' => false]));
}
public_admit($link);
// a document link
if ($link['document_id'] !== null) {
    $doc = link_document($pdo, $linkId, (int) $link['document_id']);
    if ($doc === null) {
        public_unavailable('gone');
    }
    $preview = public_preview($pdo, $doc);
    link_open($pdo, $linkId, 'view', $hash, (int) $doc['id']);
    log_activity($pdo, 'link.open', 'link', $linkId, ['actor_member_id' => null, 'document_id' => (int) $doc['id'], 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['link_id' => $linkId, 'what' => 'view', 'document_id' => (int) $doc['id']]]);
    public_render($doc['name'], view('public/link-document.php', ['link' => $link, 'doc' => $doc, 'preview' => $preview, 'base' => $base, 'back' => null]));
}
// a folder link
$folderId = (int) $link['folder_id'];
if (preg_match('#^/d/([0-9]+)$#', $rest, $m)) {
    $doc = link_document($pdo, $linkId, (int) $m[1]);
    if ($doc === null) {
        public_unavailable('outside');
    }
    $preview = public_preview($pdo, $doc);
    link_open($pdo, $linkId, 'view', $hash, (int) $doc['id']);
    log_activity($pdo, 'link.open', 'link', $linkId, ['actor_member_id' => null, 'document_id' => (int) $doc['id'], 'folder_id' => (int) $doc['folder_id'], 'library_id' => (int) $doc['library_id'], 'after' => ['link_id' => $linkId, 'what' => 'view', 'document_id' => (int) $doc['id']]]);
    public_render($doc['name'], view('public/link-document.php', ['link' => $link, 'doc' => $doc, 'preview' => $preview, 'base' => $base, 'back' => $base . '/f/' . (int) $doc['folder_id']]));
}
if (preg_match('#^/zip/([0-9]+)$#', $rest, $m)) {
    $e = link_export($pdo, $linkId, (int) $m[1]);
    if ($e === null || !$link['allow_download']) {
        public_unavailable('no_zip');
    }
    if ($e['status'] !== 'done' || $e['storage_path'] === null) {
        public_render('Your zip', view('public/link-folder.php', ['link' => $link, 'listing' => link_listing($pdo, $linkId, $folderId), 'base' => $base, 'zip' => $e]), 200);
    }
    $pdo->prepare('UPDATE exports SET downloaded_at = now() WHERE id = :id')->execute(['id' => (int) $e['id']]);
    link_open($pdo, $linkId, 'download', $hash, null, (int) $e['byte_size']);
    log_activity($pdo, 'link.open', 'link', $linkId, ['actor_member_id' => null, 'folder_id' => $folderId, 'after' => ['link_id' => $linkId, 'what' => 'download', 'export_id' => (int) $e['id'], 'bytes' => (int) $e['byte_size']]]);
    store_stream((string) $e['storage_path'], ['filename' => dc_safe_filename((string) $link['target_name']) . '.zip', 'mime' => 'application/zip', 'inline' => false, 'sha256' => 'export-' . (int) $e['id'] . '-' . (int) $e['byte_size'], 'byte_size' => (int) $e['byte_size']]);
}
if ($rest === '/zip' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    if (!$link['allow_download']) {
        public_unavailable('no_download');
    }
    // one pending zip per link at a time; else a new export the worker builds, as the maker, keyed by the link
    $existing = one_value($pdo, "SELECT id FROM exports WHERE link_id = :l AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1", ['l' => $linkId]);
    if ($existing === null) {
        $existing = export_make($pdo, 'folder', $folderId, [], (int) $link['made_by']);
        $pdo->prepare('UPDATE exports SET link_id = :l WHERE id = :e')->execute(['l' => $linkId, 'e' => (int) $existing]);
        log_activity($pdo, 'folder.export', 'folder', $folderId, ['actor_member_id' => null, 'folder_id' => $folderId, 'after' => ['link_id' => $linkId, 'export_id' => (int) $existing]]);
    }
    header('Location: ' . $base . '/zip/' . (int) $existing, true, 302);
    exit;
}
$here = null;
if (preg_match('#^/f/([0-9]+)$#', $rest, $m)) {
    $here = (int) $m[1];
} elseif ($rest !== '' && $rest !== '/') {
    public_unavailable('not_found');
}
$listing = link_listing($pdo, $linkId, $here);
if ($listing === null) {
    public_unavailable('outside');
}
link_open($pdo, $linkId, 'view', $hash, null);
log_activity($pdo, 'link.open', 'link', $linkId, ['actor_member_id' => null, 'folder_id' => $listing['here'], 'after' => ['link_id' => $linkId, 'what' => 'view', 'folder_id' => $listing['here']]]);
public_render($link['target_name'], view('public/link-folder.php', ['link' => $link, 'listing' => $listing, 'base' => $base, 'zip' => null]));
