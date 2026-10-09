<?php /** The graph's FIND (screen `graph`). Data: f (graph_find), typed, q, chips, asked, state, tz, may */ $st = $f['status']; ?>
<?= view('shared/header.php', ['id' => 'graph', 'title' => 'Graph', 'crumbs' => [['Home', '/'], ['Graph', null]], 'action' => hx_link('/graph/explore', '<i class="feather-compass me-1"></i>Explore', 'btn btn-light btn-touch', 'id="graph-explore-tab"')]) ?>
<div class="main-content" id="graph-content">
    <?php if (!$state['available']): ?><div class="alert alert-warning fs-12" id="graph-unavailable"><?= e(graph_unavailable_sentence()) ?> <?= hx_link('/search?' . http_build_query(['q' => $q]), 'Keyword search', 'fw-semibold', 'id="graph-keyword-link"') ?></div><?php endif; ?>
    <form method="get" action="/graph/" hx-get="/graph/" hx-target="#page-content" hx-push-url="true" id="graph-form" class="mb-2">
        <div class="input-group"><span class="input-group-text"><i class="feather-share-2"></i></span>
            <input type="search" name="q" id="graph-find-box" class="form-control btn-touch" value="<?= e($typed) ?>" placeholder="Documents about… (acme termination clause; tag:contract in:Board)" autofocus autocomplete="off" aria-label="Find in the graph">
            <?php foreach ($f['filters'] as $k => $v): ?><?php if (!in_array($k, ['tag', 'kind', 'mine', 'owner', 'folder', 'library'], true)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e(is_bool($v) ? '1' : (string) $v) ?>"><?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-touch" id="graph-find-submit">Find</button>
        </div>
        <div class="fs-11 text-muted mt-1">What a document is ABOUT — people, companies, places, topics — not its exact words (that is <?= hx_link('/search', 'the keyword search', '', 'id="graph-search-hint"') ?>).</div>
    </form>
    <?= view('search/partials/chips.php', ['chips' => $chips]) ?>
    <?php if ($asked): ?>
    <div class="d-flex align-items-center gap-2 mb-2 fs-12 flex-wrap" id="graph-summary">
        <?php if ($st === 'ok'): ?><span id="graph-total"><?= (int) $f['total'] ?> found of <?= (int) $f['candidates'] ?> candidate<?= (int) $f['candidates'] === 1 ? '' : 's' ?></span><span class="text-muted">in <?= (int) $f['took_ms'] ?> ms</span>
        <?php if ($f['subjects'] !== []): ?><span class="text-muted">about</span><?php foreach ($f['subjects'] as $s): ?><span class="badge bg-soft-info text-info graph-subject-used" title="<?= e($s['via']) ?>"><?= e($s['name']) ?></span><?php endforeach; ?><?php endif; ?>
        <?php else: ?><span id="graph-status" class="text-muted"><?= e(graph_status_sentence($st)) ?></span> <?= hx_link('/search?' . http_build_query(['q' => $q]), 'Keyword search', 'fw-semibold', 'id="graph-keyword-link-2"') ?><?php endif; ?>
    </div>
    <div class="list-group mb-3" id="graph-results">
        <?php if ($st === 'ok' && $f['rows'] === []): ?><div class="list-group-item text-muted fs-12" id="graph-empty">The graph holds nothing on this that you may see. <?= hx_link('/search?' . http_build_query(['q' => $q]), 'Try the keyword search', 'fw-semibold') ?></div><?php endif; ?>
        <?php foreach ($f['rows'] as $r): ?><?= view('graph/partials/result.php', ['r' => $r, 'tz' => $tz, 'q' => $q]) ?><?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card" id="graph-intro"><div class="card-body fs-12 text-muted">Type what the documents should be about. The graph knows each document by its tags, folder, library, owner, kind and year, and — where a folder's policy is <em>full</em> — by the people, companies, places and topics the model read in it.</div></div>
    <?php endif; ?>
    <?= view('graph/partials/footer.php', ['state' => $state, 'may' => $may]) ?>
</div>
