<?php /** One proposal as a card. Data: p (my_proposals row), me, curator, tz */
$id = (int) $p['clerk_proposal_id'];
$b = PROPOSAL_KIND_BADGE[$p['kind']] ?? 'secondary';
$open = $p['status'] === 'proposed';
$land = proposal_land($p);
$tags = $p['kind'] === 'tags' ? array_values(array_filter(array_map('strval', (array) ($p['payload']['tags'] ?? [])))) : [];
$thing = $p['document_name'] !== null ? [$p['document_name'], '/documents/' . (int) $p['document_id']] : ($p['folder_name'] !== null ? [$p['folder_name'], '/folders/' . (int) $p['folder_id']] : ($p['library_name'] !== null ? [$p['library_name'], '/libraries/' . (int) $p['library_id']] : null));
?>
<div class="card mb-2 proposal-card" id="proposal-card-<?= $id ?>" data-kind="<?= e($p['kind']) ?>" data-status="<?= e($p['status']) ?>">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <span class="badge bg-soft-<?= e($b) ?> text-<?= e($b) ?>" id="proposal-kind-<?= $id ?>"><?= e(PROPOSAL_KIND_WORDS[$p['kind']] ?? $p['kind']) ?></span>
            <?php if ($curator && (int) $p['for_member_id'] !== $me && $p['for_name'] !== null): ?><span class="fs-11 text-muted" id="proposal-for-<?= $id ?>">for <?= e($p['for_name']) ?></span><?php endif; ?>
            <span class="fs-11 text-muted ms-auto"><?= e(format_ts($p['created_at'], $tz, 'M j')) ?></span>
        </div>
        <div class="fw-semibold" id="proposal-title-<?= $id ?>"><?= e($p['title']) ?></div>
        <?php if ($thing !== null): ?><div class="fs-12 mt-1"><?= hx_link($thing[1], '<i class="feather-external-link me-1"></i>' . e($thing[0]), '', 'id="proposal-thing-' . $id . '"') ?></div><?php endif; ?>
        <?php if (!$open): ?>
            <div class="fs-11 text-muted mt-2" id="proposal-decided-<?= $id ?>"><?= e($p['status']) ?><?= $p['decided_by_name'] !== null ? ' by ' . e($p['decided_by_name']) : '' ?><?= $p['decided_at'] !== null ? ' · ' . e(format_ts($p['decided_at'], $tz, 'M j')) : '' ?></div>
        <?php elseif ((int) $p['for_member_id'] === $me || $curator): ?>
            <form method="post" action="/curate/proposals/decide.php" hx-post="/curate/proposals/decide.php" hx-target="#flash" class="mt-2" id="proposal-form-<?= $id ?>">
                <?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><input type="hidden" name="return_to" value="/curate/proposals">
                <?php if ($tags !== []): ?>
                    <div class="d-flex flex-wrap gap-2 mb-2" id="proposal-tags-<?= $id ?>">
                        <?php foreach ($tags as $t): ?><label class="form-check form-check-inline m-0 badge bg-soft-info text-info fs-12 p-2" for="proposal-<?= $id ?>-tag-<?= e($t) ?>">
                            <input class="form-check-input me-1" type="checkbox" name="tags[]" value="<?= e($t) ?>" id="proposal-<?= $id ?>-tag-<?= e($t) ?>" checked><?= e($t) ?></label><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="d-flex gap-1 flex-wrap">
                    <button type="submit" name="decision" value="accept" class="btn btn-primary btn-touch" id="proposal-accept-btn-<?= $id ?>"><?= $tags !== [] ? 'Apply the ticked tags' : 'Accept' ?></button>
                    <button type="submit" name="decision" value="dismiss" class="btn btn-light btn-touch" id="proposal-dismiss-btn-<?= $id ?>">Dismiss</button>
                    <?php if ($tags === [] && $land !== '/curate/proposals'): ?><?= hx_link($land, 'Look first', 'btn btn-link btn-touch', 'id="proposal-look-' . $id . '"') ?><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
