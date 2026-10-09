<?php
declare(strict_types=1);
/**
 * Action `clerk_propose` (kind, title, for_member, document / folder / library / link, payload — a JSON object of facts, note; log clerk.propose): the Filing Clerk leaves a PROPOSAL for the one
 * person whose decision it is. Only the Clerk (the agent named in the settings, dc_is_clerk()) — another agent, or a person, is refused. Deduped by kind + thing + week and not repeated for
 * 28 days after a dismissal (answers the same id's absence as `proposed: false`). The facts name things (ids, names, counts); a document's text, a link or a token is refused.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (!caller_is_clerk($pdo)) {
    refuse(403, 'Only the Filing Clerk proposes; everyone else decides.');
}
$errors = [];
$kind = (string) (req_val('kind') ?? '');
if (!in_array($kind, PROPOSAL_KINDS, true)) {
    $errors['kind'] = 'Say the kind: ' . implode(', ', PROPOSAL_KINDS) . '.';
}
$title = (string) (req_val('title') ?? '');
if ($title === '' || mb_strlen($title) > 200) {
    $errors['title'] = 'Give the proposal a title of up to 200 characters.';
}
$for = request_integer('for_member');
if ($for === null) {
    $errors['for_member'] = 'Say whose decision it is.';
}
$payload = [];
if (req_has('payload') && req_val('payload') !== '') {
    $payload = json_decode((string) req_val('payload'), true);
    if (!is_array($payload)) {
        $errors['payload'] = 'The facts are a JSON object.';
        $payload = [];
    }
}
if (req_has('note') && req_val('note') !== '') {
    if (mb_strlen((string) req_val('note')) > 300) {
        $errors['note'] = 'The note is up to 300 characters.';
    } else {
        $payload['note'] = (string) req_val('note');
    }
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$f = ['kind' => $kind, 'title' => $title, 'for_member' => $for, 'payload' => $payload, 'document_id' => request_integer('document'), 'folder_id' => request_integer('folder'),
      'library_id' => request_integer('library'), 'link_id' => request_integer('link'), 'key' => req_val('key') ?: null];
$id = dc_guard($pdo, static function () use ($pdo, $f, $me): ?int {
    $pdo->beginTransaction();
    $id = clerk_propose($pdo, $f, $me, true);
    if ($id !== null) {
        log_activity($pdo, 'clerk.propose', 'clerk_proposal', $id, ['document_id' => $f['document_id'], 'folder_id' => $f['folder_id'], 'library_id' => $f['library_id'],
            'after' => ['kind' => $f['kind'], 'for_member_id' => $f['for_member'], 'title' => $f['title']]]);
    }
    $pdo->commit();
    return $id;
});
dc_done($id === null ? 'Already proposed: nothing new was made' : 'Proposed: ' . $title, $id, dc_land('/curate/proposals', $id === null ? 'duplicate' : 'proposed'), 'proposalChanged', ['proposed' => $id !== null]);
