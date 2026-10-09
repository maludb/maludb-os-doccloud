<?php /** Related documents on the document page. Data: related (graph_related), subjects (document_subjects_of or null), docId */ ?>
<div id="document-related-line">
<?php if ($subjects === null): ?><span class="text-muted">Not in the document graph yet.</span>
<?php else: ?><span class="badge bg-soft-<?= e(GRAPH_POLICY_BADGE[$subjects['policy']] ?? 'secondary') ?>" id="document-graph-policy">in the graph: <?= e($subjects['policy']) ?></span>
    <span class="text-muted">· subjects:</span> <?php foreach (array_slice($subjects['subjects'], 0, 8) as $s): ?><?= hx_link('/graph/?' . http_build_query(['q' => preg_replace('/^[a-z]+:/', '', $s)]), e($s), 'badge bg-soft-primary text-primary text-decoration-none', 'title="find documents about this"') ?> <?php endforeach; ?>
    <?php if ($related['status'] === 'graph_unavailable'): ?><div class="text-muted mt-1" id="document-related-unavailable"><?= e(graph_unavailable_sentence()) ?></div>
    <?php elseif ($related['rows'] === []): ?><div class="text-muted mt-1" id="document-related-empty">No related document you may see.</div>
    <?php else: ?><div class="row g-2 mt-1" id="document-related-cards"><?php foreach ($related['rows'] as $r): ?><div class="col-12 col-md-6"><div class="card h-100 document-related-card" id="document-related-<?= (int) $r['document_id'] ?>"><div class="card-body p-2 fs-12">
        <i class="<?= e(kind_icon($r['mime_kind'])) ?> text-muted me-1"></i><?= hx_link('/documents/' . (int) $r['document_id'], e($r['name']), 'fw-semibold') ?>
        <div class="fs-11 text-muted"><?= e($r['folder_path'] ?? '') ?></div><div class="mt-1"><?php foreach (array_slice($r['subjects'], 0, 4) as $s): ?><span class="badge bg-soft-primary text-primary me-1"><?= e($s) ?></span><?php endforeach; ?></div>
    </div></div></div><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
</div>
