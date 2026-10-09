<?php
declare(strict_types=1);

/**
 * Writes of the Filing Clerk's proposals (slice 8). SQL only; the controllers judge and log. A refusal is a DomainException in words. The Clerk PROPOSES and a person DECIDES: accepting a `tags`
 * proposal applies the tags (through dc_tag_apply, as the decider, source agent); every other kind's accept records the decision and the card links to the thing to act on — nothing is deleted,
 * shared or changed by an accept.
 */
require_once dirname(__DIR__) . '/notifications/write.php';

/** The kinds clerk_propose accepts (db/013's check). */
const PROPOSAL_KINDS = ['untagged', 'duplicate', 'stale_share', 'link_expiring', 'link_suspicious', 'request_open', 'trash_due', 'locked', 'no_text', 'quota', 'budget', 'orphaned', 'tags'];
/** A dismissed proposal is not made again for this many days unless the facts changed (clerk.md: "a person who dismissed a proposal is not asked again"). */
const PROPOSAL_DISMISSED_DAYS = 28;

/**
 * Make one proposal (the Clerk's `clerk_propose`, and the worker's Monday sweep). $f: kind, title, for_member (whose decision it is), document_id, folder_id, library_id, link_id, payload (the facts:
 * ids, names, counts — never a document's text), key (names the thing when a proposal stands for several: "owner:12"). Deduped: nothing is made when the same kind for the same thing is open, was
 * made this week, or was dismissed in the last 28 days — answers null then. $notify: tell the person (one `proposal` notice a day, however many). Returns the id or null.
 */
function clerk_propose(PDO $pdo, array $f, int $by, bool $notify = true): ?int
{
    $kind = (string) ($f['kind'] ?? '');
    if (!in_array($kind, PROPOSAL_KINDS, true)) {
        throw new DomainException('A proposal is one of: ' . implode(', ', PROPOSAL_KINDS) . '.');
    }
    $title = trim((string) ($f['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) {
        throw new DomainException('A proposal has a title of up to 200 characters.');
    }
    $for = isset($f['for_member']) ? (int) $f['for_member'] : null;
    if ($for === null || notifiable_member($pdo, $for) === null) {
        throw new DomainException('A proposal is for an active person here.');
    }
    $payload = $f['payload'] ?? [];
    if (!is_array($payload)) {
        throw new DomainException('The facts are an object.');
    }
    $payload = clerk_payload_clean($payload);
    if (!empty($f['key'])) {
        $payload['key'] = mb_substr((string) $f['key'], 0, 80);
    }
    $ids = ['d' => $f['document_id'] ?? null, 'f' => $f['folder_id'] ?? null, 'l' => $f['library_id'] ?? null, 'lk' => $f['link_id'] ?? null];
    foreach (['d' => ['documents', 'document'], 'f' => ['folders', 'folder'], 'l' => ['libraries', 'library'], 'lk' => ['share_links', 'link']] as $k => [$table, $what]) {
        if ($ids[$k] !== null && one_value($pdo, "SELECT 1 FROM $table WHERE id = :id", ['id' => (int) $ids[$k]]) === null) {
            throw new DomainException('That ' . $what . ' is not here.');
        }
    }
    // the thing a proposal is about is its first of document, link, folder, library (a document's proposal is the same whichever folder the sweep or the Clerk named with it)
    $primary = $ids['d'] !== null ? 'document_id' : ($ids['lk'] !== null ? 'link_id' : ($ids['f'] !== null ? 'folder_id' : ($ids['l'] !== null ? 'library_id' : null)));
    $primaryArg = ['document_id' => $ids['d'], 'link_id' => $ids['lk'], 'folder_id' => $ids['f'], 'library_id' => $ids['l']][$primary ?? 'document_id'];
    $dup = $pdo->prepare("SELECT 1 FROM clerk_proposals WHERE kind = :k AND " . ($primary !== null ? "$primary = :thing" : 'true') . " AND COALESCE(payload->>'key', '') = :key
                             AND (status = 'proposed' OR created_at >= date_trunc('week', now()) OR (status = 'dismissed' AND decided_at > now() - make_interval(days => :days))) LIMIT 1");
    $dupArgs = ['k' => $kind, 'key' => (string) ($payload['key'] ?? ''), 'days' => PROPOSAL_DISMISSED_DAYS] + ($primary !== null ? ['thing' => $primaryArg] : []);
    $dup->execute($dupArgs);
    if ($dup->fetchColumn() !== false) {
        return null;
    }
    $st = $pdo->prepare('INSERT INTO clerk_proposals (kind, document_id, folder_id, library_id, link_id, for_member_id, title, payload, proposed_by) VALUES (:k, :d, :f, :l, :lk, :for, :t, CAST(:p AS jsonb), :by) RETURNING id');
    $st->execute(['k' => $kind, 'd' => $ids['d'], 'f' => $ids['f'], 'l' => $ids['l'], 'lk' => $ids['lk'], 'for' => $for, 't' => $title, 'p' => json_encode($payload === [] ? new stdClass() : $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'by' => $by]);
    $id = (int) $st->fetchColumn();
    if ($notify) {
        notify($pdo, $for, 'proposal', 'The Clerk has something for you to decide', 'Open Proposals to accept or dismiss, starting with: ' . $title, ['record_type' => 'clerk', 'actor' => $by,
            'once_since' => "date_trunc('day', now())"]);
    }
    return $id;
}

/** The facts of a proposal: scalars and short lists of scalars; strings cut at 300 characters; anything that looks like a link token (48 hex), a secret or a long text is refused. */
function clerk_payload_clean(array $payload, int $depth = 0): array
{
    if ($depth > 3) {
        throw new DomainException('The facts are nested too deeply.');
    }
    $out = [];
    foreach ($payload as $k => $v) {
        $k = (string) $k;
        if (is_array($v)) {
            $out[$k] = clerk_payload_clean($v, $depth + 1);
        } elseif (is_string($v)) {
            if (mb_strlen($v) > 300) {
                throw new DomainException('A fact is up to 300 characters: the facts name things, they never carry a document\'s text.');
            }
            if (preg_match('/[a-f0-9]{48,}/i', $v)) {
                throw new DomainException('The facts never carry a link or a token.');
            }
            $out[$k] = $v;
        } elseif (is_scalar($v) || $v === null) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Decide a proposal ($decision accept | dismiss) as $by. An accept of `tags` applies $choice tag slugs (default all of the payload's) to the document through dc_tag_apply with source agent, as the
 * decider; the caller has judged edit on the document. Returns ['did' => accepted|dismissed, 'kind', 'applied' => n, 'land'].
 */
function clerk_proposal_decide(PDO $pdo, int $id, string $decision, int $by, array $choice = []): array
{
    $st = $pdo->prepare("SELECT * FROM clerk_proposals WHERE id = :id FOR UPDATE");
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) {
        throw new DomainException('Not found.');
    }
    if ($p['status'] !== 'proposed') {
        throw new DomainException('That proposal was already decided.');
    }
    $p['payload'] = json_decode((string) $p['payload'], true) ?: [];
    $applied = 0;
    if ($decision === 'accept' && $p['kind'] === 'tags' && $p['document_id'] !== null) {
        $offered = array_values(array_filter(array_map('strval', (array) ($p['payload']['tags'] ?? []))));
        $chosen = $choice === [] ? $offered : array_values(array_intersect($offered, $choice));
        if ($chosen === []) {
            throw new DomainException('Choose at least one of the suggested tags.');
        }
        foreach ($chosen as $slug) {
            $r = tag_apply($pdo, [(int) $p['document_id']], $slug, $by, 'agent');
            $applied += $r['applied'];
        }
        $p['payload']['applied'] = $chosen;
    }
    $pdo->prepare("UPDATE clerk_proposals SET status = :s, decided_by = :by, decided_at = now(), payload = CAST(:pl AS jsonb) WHERE id = :id")
        ->execute(['s' => $decision === 'accept' ? 'accepted' : 'dismissed', 'by' => $by, 'pl' => json_encode($p['payload'] === [] ? new stdClass() : $p['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $id]);
    return ['did' => $decision === 'accept' ? 'accepted' : 'dismissed', 'kind' => $p['kind'], 'applied' => $applied, 'document_id' => $p['document_id'] === null ? null : (int) $p['document_id']];
}
