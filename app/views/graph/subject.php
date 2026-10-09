<?php /** A SUBJECT (screen `subject-view`). Data: s (subject_page), tz */ $ok = $s['status'] === 'ok'; $id = (int) ($s['subject_id'] ?? 0); ?>
<?= view('shared/header.php', ['id' => 'subject-view', 'title' => $ok ? $s['name'] : 'Subject', 'crumbs' => [['Home', '/'], ['Graph', '/graph/'], ['Explore', '/graph/explore'], [$ok ? $s['name'] : 'Subject', null]],
    'action' => $ok ? hx_link('/graph/?' . http_build_query(['q' => $s['name']]), '<i class="feather-share-2 me-1"></i>Find documents about this', 'btn btn-primary btn-touch', 'id="subject-find-btn"') . ' ' . hx_link('/graph/path?from=' . $id, '<i class="feather-git-merge me-1"></i>Path to…', 'btn btn-light btn-touch', 'id="subject-path-btn"') : '']) ?>
<div class="main-content" id="subject-content">
    <?php if (!$ok): ?><div class="alert alert-warning fs-12" id="subject-status"><?= e(graph_status_sentence($s['status'])) ?></div><?php else: ?>
    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card mb-3" id="subject-card"><div class="card-body">
                <h5 class="mb-1 d-flex align-items-center gap-2"><span id="subject-name"><?= e($s['name']) ?></span><?php if (!empty($s['type'])): ?><span class="badge bg-soft-secondary text-secondary" id="subject-type"><?= e($s['type']) ?></span><?php endif; ?></h5>
                <?php if (!empty($s['description'])): ?><div class="fs-12 text-muted"><?= e($s['description']) ?></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="subject-documents-card"><div class="card-header"><h6 class="m-0">Documents you may see (<?= count($s['documents']) ?>)<?php if ($s['documents_hidden'] > 0): ?> <span class="fs-11 text-muted">· <?= (int) $s['documents_hidden'] ?> you cannot</span><?php endif; ?></h6></div>
                <div class="list-group list-group-flush" id="subject-documents"><?php if ($s['documents'] === []): ?><div class="list-group-item fs-12 text-muted" id="subject-documents-empty">None you may see.</div><?php endif; ?>
                <?php foreach ($s['documents'] as $r): ?><?= view('graph/partials/result.php', ['r' => $r, 'tz' => $tz, 'q' => $s['name']]) ?><?php endforeach; ?></div></div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card mb-3" id="subject-neighbours-card"><div class="card-header"><h6 class="m-0">Neighbours</h6></div>
                <div class="card-body">
                    <div class="d-none d-lg-block mb-2" id="graph-svg-wrap"><svg id="graph-svg" width="100%" height="280" viewBox="0 0 400 280" role="img" aria-label="The subject's neighbourhood"></svg></div>
                    <div class="d-flex flex-wrap gap-1" id="subject-neighbours">
                        <?php foreach ($s['neighbour_subjects'] as $n): ?><?= hx_link('/graph/subjects/' . (int) $n['subject_id'], e($n['name']), 'badge bg-soft-primary text-primary text-decoration-none subject-neighbour', 'id="subject-neighbour-' . (int) $n['subject_id'] . '" title="' . e($n['rel']) . '"') ?><?php endforeach; ?>
                        <?php foreach ($s['documents'] as $r): ?><?= hx_link('/documents/' . (int) $r['document_id'], e($r['name']), 'badge bg-soft-secondary text-secondary text-decoration-none subject-neighbour-doc', 'id="subject-doc-' . (int) $r['document_id'] . '"') ?><?php endforeach; ?>
                        <?php if ($s['neighbour_subjects'] === [] && $s['documents'] === []): ?><span class="fs-12 text-muted">No neighbours you may see.</span><?php endif; ?>
                    </div>
                </div></div>
        </div>
    </div>
    <script id="graph-svg-data" type="application/json"><?= json_encode(['centre' => ['id' => $id, 'name' => $s['name']],
        'subjects' => array_map(static fn (array $n): array => ['id' => (int) $n['subject_id'], 'name' => $n['name'], 'url' => '/graph/subjects/' . (int) $n['subject_id']], array_slice($s['neighbour_subjects'], 0, 24)),
        'documents' => array_map(static fn (array $r): array => ['id' => (int) $r['document_id'], 'name' => $r['name'], 'url' => '/documents/' . (int) $r['document_id']], array_slice($s['documents'], 0, 15))], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="/assets/js/graph-svg.js"></script>
    <?php endif; ?>
</div>
