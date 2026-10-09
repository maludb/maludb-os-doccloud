<?php
declare(strict_types=1);

/**
 * The writes of tags (slice 4): one function per action, SQL only, no request parsing — through db/009's referee functions (dc_tag_ensure,
 * dc_tag_apply, dc_tag_remove, dc_tag_merge) so the slug's shape, the counts, the promotion at the threshold and a merge's alias are the
 * database's. A refusal is a DomainException in words (dc_guard turns it into a 422).
 */

/** A tag by label: made as managed (curators) or free; an existing one (by slug or alias) is answered, its kind kept. */
function tag_create(PDO $pdo, string $label, string $kind, ?string $colour, int $by): int
{
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 50) {
        throw new DomainException('A tag is a label of 1 to 50 characters.');
    }
    $slug = (string) one_value($pdo, 'SELECT dc_slugify(:l)', ['l' => $label]);
    if ($slug === '') {
        throw new DomainException('A tag needs at least one letter or digit.');
    }
    $existing = one_value($pdo, 'SELECT dc_tag_resolve(:s)', ['s' => $slug]);
    if ($existing !== null && $existing !== false) {
        $id = (int) $existing;
        if ($kind === 'managed') {
            $pdo->prepare("UPDATE tags SET kind = 'managed', colour = COALESCE(:c, colour), archived_at = NULL WHERE id = :id")->execute(['c' => $colour, 'id' => $id]);
        }
        return $id;
    }
    $id = (int) one_value($pdo, 'SELECT dc_tag_ensure(:l, :by, :k)', ['l' => $label, 'by' => $by, 'k' => $kind]);
    if ($colour !== null) {
        $pdo->prepare('UPDATE tags SET colour = :c WHERE id = :id')->execute(['c' => $colour, 'id' => $id]);
    }
    return $id;
}

/** Relabel or recolour (the slug stays: it is the name every document carries). */
function tag_update(PDO $pdo, int $id, array $f): void
{
    $sets = [];
    $args = ['id' => $id];
    if (array_key_exists('label', $f) && $f['label'] !== null) {
        $label = trim((string) $f['label']);
        if ($label === '' || mb_strlen($label) > 50) {
            throw new DomainException('A tag is a label of 1 to 50 characters.');
        }
        $sets[] = 'label = :label';
        $args['label'] = $label;
    }
    if (array_key_exists('colour', $f)) {
        $sets[] = 'colour = :colour';
        $args['colour'] = $f['colour'];
    }
    if (array_key_exists('kind', $f) && in_array($f['kind'], ['managed', 'free'], true)) {
        $sets[] = 'kind = :kind';
        $args['kind'] = $f['kind'];
    }
    if ($sets === []) {
        return;
    }
    $pdo->prepare('UPDATE tags SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
}

/**
 * Apply one tag to documents the caller may EDIT: a word (a slug, a label or an alias — a missing one becomes a free tag) or an id.
 * Answers [applied, skipped (no edit level), already (had it)] — the caller judged the documents (document_at_level) and passes only the editable ones
 * in $documentIds; $skipped is what it left out.
 */
function tag_apply(PDO $pdo, array $documentIds, int|string $tag, int $by, string $source = 'person', int $skipped = 0): array
{
    $tagId = is_int($tag) ? $tag : (int) one_value($pdo, 'SELECT dc_tag_ensure(:l, :by, :k)', ['l' => (string) $tag, 'by' => $by, 'k' => 'free']);
    if ($tagId <= 0) {
        throw new DomainException('A tag needs at least one letter or digit.');
    }
    $applied = 0;
    $already = 0;
    $st = $pdo->prepare('SELECT dc_tag_apply(:d, :t, :by, :s)');
    foreach ($documentIds as $d) {
        $st->execute(['d' => (int) $d, 't' => $tagId, 'by' => $by, 's' => $source]);
        $v = $st->fetchColumn();
        if ($v === true || $v === 't') {
            $applied++;
        } else {
            $already++;
        }
    }
    return ['tag_id' => $tagId, 'applied' => $applied, 'already' => $already, 'skipped' => $skipped];
}

function tag_remove(PDO $pdo, int $documentId, int $tagId): bool
{
    $st = $pdo->prepare('SELECT dc_tag_remove(:d, :t)');
    $st->execute(['d' => $documentId, 't' => $tagId]);
    $v = $st->fetchColumn();
    return $v === true || $v === 't';
}

/** Merge $from into $into: the documents carry $into, $from's slug becomes an alias, $from is archived (db/009's dc_tag_merge). */
function tag_merge(PDO $pdo, int $from, int $into, int $by): int
{
    if ($from === $into) {
        throw new DomainException('A tag is not merged into itself.');
    }
    $st = $pdo->prepare('SELECT dc_tag_merge(:f, :i, :by)');
    $st->execute(['f' => $from, 'i' => $into, 'by' => $by]);
    return (int) $st->fetchColumn();
}

function tag_archive(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE tags SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}

function tag_restore(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE tags SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

/** A hard delete, only for a tag no document carries (an in-use tag is archived instead — design D12 / the spec's divergence 4). */
function tag_delete(PDO $pdo, int $id): void
{
    $n = (int) one_value($pdo, 'SELECT count(*) FROM document_tags WHERE tag_id = :id', ['id' => $id]);
    if ($n > 0) {
        throw new DomainException('That tag is on ' . $n . ' document' . ($n === 1 ? '' : 's') . ': archive it instead, or merge it into another.');
    }
    $pdo->prepare('DELETE FROM tag_aliases WHERE tag_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM tags WHERE id = :id')->execute(['id' => $id]);
}

/** A proposal: kind promote (tag), apply (tag + document), merge (tag + into). A word for the tag makes a free tag when missing. */
function tag_propose(PDO $pdo, string $kind, array $f, ?int $by): int
{
    $tagId = isset($f['tag_id']) ? (int) $f['tag_id'] : (int) one_value($pdo, 'SELECT dc_tag_ensure(:l, :by, :k)', ['l' => (string) ($f['tag'] ?? ''), 'by' => $by, 'k' => 'free']);
    $st = $pdo->prepare('INSERT INTO tag_proposals (kind, tag_id, into_tag_id, document_id, proposed_by, note) VALUES (:k, :t, :i, :d, :by, :n)
                         ON CONFLICT DO NOTHING RETURNING id');
    $st->execute(['k' => $kind, 't' => $tagId, 'i' => $f['into_tag_id'] ?? null, 'd' => $f['document_id'] ?? null, 'by' => $by, 'n' => $f['note'] ?? null]);
    $id = $st->fetchColumn();
    if ($id === false) {
        // the same proposal is already open: answer it
        $open = one_value($pdo, "SELECT id FROM tag_proposals WHERE kind = :k AND tag_id = :t AND document_id IS NOT DISTINCT FROM :d AND status = 'proposed'", ['k' => $kind, 't' => $tagId, 'd' => $f['document_id'] ?? null]);
        return (int) $open;
    }
    return (int) $id;
}

/** Accept: promote → managed; apply → dc_tag_apply as the decider (source agent when the Clerk proposed it); merge → dc_tag_merge. */
function proposal_accept(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare("SELECT * FROM tag_proposals WHERE id = :id AND status = 'proposed' FOR UPDATE");
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) {
        throw new DomainException('That proposal was already decided.');
    }
    $did = '';
    if ($p['kind'] === 'promote') {
        $pdo->prepare("UPDATE tags SET kind = 'managed', archived_at = NULL WHERE id = :id")->execute(['id' => (int) $p['tag_id']]);
        $did = 'promoted';
    } elseif ($p['kind'] === 'apply') {
        $pdo->prepare('SELECT dc_tag_apply(:d, :t, :by, :s)')->execute(['d' => (int) $p['document_id'], 't' => (int) $p['tag_id'], 'by' => $by, 's' => $p['proposed_by'] !== null && (bool) one_value($pdo, 'SELECT is_agent FROM members WHERE id = :m', ['m' => (int) $p['proposed_by']]) ? 'agent' : 'person']);
        $did = 'applied';
    } else {
        $pdo->prepare('SELECT dc_tag_merge(:f, :i, :by)')->execute(['f' => (int) $p['tag_id'], 'i' => (int) $p['into_tag_id'], 'by' => $by]);
        $did = 'merged';
    }
    $pdo->prepare("UPDATE tag_proposals SET status = 'accepted', decided_by = :by, decided_at = now() WHERE id = :id")->execute(['by' => $by, 'id' => $id]);
    return ['kind' => $p['kind'], 'did' => $did, 'tag_id' => (int) $p['tag_id'], 'document_id' => $p['document_id'] === null ? null : (int) $p['document_id'], 'into_tag_id' => $p['into_tag_id'] === null ? null : (int) $p['into_tag_id']];
}

function proposal_dismiss(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare("UPDATE tag_proposals SET status = 'dismissed', decided_by = :by, decided_at = now() WHERE id = :id AND status = 'proposed' RETURNING kind, tag_id, document_id");
    $st->execute(['by' => $by, 'id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('That proposal was already decided.');
    }
    return ['kind' => $r['kind'], 'did' => 'dismissed', 'tag_id' => (int) $r['tag_id'], 'document_id' => $r['document_id'] === null ? null : (int) $r['document_id']];
}
