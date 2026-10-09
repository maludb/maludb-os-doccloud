<?php /** A PATH (screen `graph-path`). Data: route, a, b, labels, asked (`route`, never `path`: view() keeps the template's own path in $path) */ ?>
<?= view('shared/header.php', ['id' => 'graph-path', 'title' => 'A path in the graph', 'crumbs' => [['Home', '/'], ['Graph', '/graph/'], ['Explore', '/graph/explore'], ['Path', null]]]) ?>
<div class="main-content" id="graph-path-content">
    <form method="get" action="/graph/path" hx-get="/graph/path" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="graph-path-form"><div class="card-body d-flex flex-wrap gap-3 align-items-end">
        <div style="min-width:240px"><label class="form-label fs-12" id="graph-path-from-label">From</label><?= picker_field(['id' => 'graph-path-from', 'name' => 'from', 'source' => 'subject', 'required' => true, 'value' => $a !== null && $a > 0 ? $a : null, 'label' => $labels['from'], 'placeholder' => 'Choose a subject', 'labelledby' => 'graph-path-from-label']) ?></div>
        <div style="min-width:240px"><label class="form-label fs-12" id="graph-path-to-label">To</label><?= picker_field(['id' => 'graph-path-to', 'name' => 'to', 'source' => 'subject', 'required' => true, 'value' => $b !== null && $b > 0 ? $b : null, 'label' => $labels['to'], 'placeholder' => 'Choose a subject', 'labelledby' => 'graph-path-to-label']) ?></div>
        <button type="submit" class="btn btn-primary btn-touch" id="graph-path-go">Find the path</button>
    </div></form>
    <?php if ($asked): ?>
    <div class="card" id="graph-path-card"><div class="card-body">
        <?php if ($route['status'] !== 'ok'): ?><div class="fs-12 text-muted" id="graph-path-status"><?= e(graph_status_sentence($route['status'])) ?></div>
        <?php elseif (!$route['found']): ?><div class="fs-12 text-muted" id="graph-path-none">No path between <?= e($labels['from'] ?? '') ?> and <?= e($labels['to'] ?? '') ?> within six steps.</div>
        <?php else: ?><div class="d-flex flex-wrap align-items-center gap-1" id="graph-path-steps">
            <?php foreach ($route['steps'] as $i => $st): ?><?php if ($i > 0): ?><i class="feather-arrow-right text-muted"></i><?php endif; ?>
                <?php if ($st['kind'] === 'document'): ?><?php if (!empty($st['visible'])): ?><?= hx_link('/documents/' . (int) $st['document_id'], '<i class="feather-file-text me-1"></i>' . e($st['name']), 'badge bg-soft-secondary text-secondary text-decoration-none graph-path-step', 'id="graph-path-step-' . $i . '"') ?><?php else: ?><span class="badge bg-light text-muted graph-path-step" id="graph-path-step-<?= $i ?>"><i class="feather-eye-off me-1"></i><?= e($st['name']) ?></span><?php endif; ?>
                <?php else: ?><?= hx_link('/graph/subjects/' . (int) $st['id'], e($st['name']), 'badge bg-soft-primary text-primary text-decoration-none graph-path-step', 'id="graph-path-step-' . $i . '"') ?><?php endif; ?>
            <?php endforeach; ?></div><?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
