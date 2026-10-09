<?php /** EXPLORE (screen `graph-explore`). Data: nodes (god_nodes), surprises, library, libraries, state */ ?>
<?= view('shared/header.php', ['id' => 'graph-explore', 'title' => 'Explore the graph', 'crumbs' => [['Home', '/'], ['Graph', '/graph/'], ['Explore', null]], 'action' => hx_link('/graph/path', '<i class="feather-git-merge me-1"></i>A path between two subjects', 'btn btn-light btn-touch', 'id="graph-path-link"')]) ?>
<div class="main-content" id="graph-explore-content">
    <?php if (!$state['available']): ?><div class="alert alert-warning fs-12" id="graph-unavailable"><?= e(graph_unavailable_sentence()) ?></div><?php endif; ?>
    <form method="get" action="/graph/explore" hx-get="/graph/explore" hx-target="#page-content" hx-push-url="true" class="d-flex gap-2 align-items-end flex-wrap mb-3" id="graph-explore-form">
        <div><label class="form-label fs-12" for="graph-explore-library">In library</label><select name="library" id="graph-explore-library" class="form-select btn-touch"><option value="">every library</option><?php foreach ($libraries as $l): ?><option value="<?= (int) $l['library_id'] ?>"<?= $library !== null && (int) $library['library_id'] === (int) $l['library_id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn-light btn-touch" id="graph-explore-go">Show</button>
        <div class="ms-auto"><label class="form-label fs-12" for="graph-subject-search">A subject by name</label><div class="input-group"><input type="search" class="form-control btn-touch" id="graph-subject-search" placeholder="acme" form="graph-subject-find"><button class="btn btn-light btn-touch" type="submit" form="graph-subject-find" id="graph-subject-find-btn">Find documents</button></div></div>
    </form>
    <form method="get" action="/graph/" hx-get="/graph/" hx-target="#page-content" hx-push-url="true" id="graph-subject-find"><input type="hidden" name="q" id="graph-subject-find-q"></form>
    <script>document.getElementById('graph-subject-find').addEventListener('submit', function () { document.getElementById('graph-subject-find-q').value = document.getElementById('graph-subject-search').value; });</script>
    <div class="card mb-3" id="graph-subjects-card"><div class="card-header"><h6 class="m-0">Subjects by degree<?= $library !== null ? ' in ' . e($library['name']) : '' ?></h6></div>
        <div class="card-body p-0">
            <?php if ($nodes['status'] !== 'ok'): ?><div class="p-3 fs-12 text-muted" id="graph-subjects-status"><?= e(graph_status_sentence($nodes['status'])) ?></div>
            <?php elseif ($nodes['subjects'] === []): ?><div class="p-3 fs-12 text-muted" id="graph-subjects-empty">No subject with a document you may see yet. Documents enter the graph within the minute of their text being read.</div>
            <?php else: ?><div class="list-group list-group-flush" id="graph-subjects">
                <?php foreach ($nodes['subjects'] as $s): ?><div class="list-group-item d-flex align-items-center gap-2 graph-subject" id="graph-subject-<?= (int) $s['subject_id'] ?>">
                    <?= hx_link('/graph/subjects/' . (int) $s['subject_id'], e($s['name']), 'fw-semibold', 'id="graph-subject-link-' . (int) $s['subject_id'] . '"') ?>
                    <?php if (!empty($s['type'])): ?><span class="badge bg-soft-secondary text-secondary"><?= e($s['type']) ?></span><?php endif; ?>
                    <span class="ms-auto fs-11 text-muted"><?= (int) $s['documents_visible'] ?> document<?= (int) $s['documents_visible'] === 1 ? '' : 's' ?> · degree <?= (int) $s['degree'] ?></span>
                </div><?php endforeach; ?>
            </div><?php endif; ?>
        </div></div>
    <div class="card mb-3" id="graph-surprises-card"><div class="card-header"><h6 class="m-0">Surprises</h6></div>
        <div class="card-body fs-12 text-muted" id="graph-surprises"><?php $sp = $surprises['surprises'] ?? []; if ($sp === []): ?>None yet: MaluDB does not compute communities on its own (M6, owed upstream); surprising links between them appear here the day it does.<?php else: ?><?php foreach ($sp as $x): ?><div><?= e(is_array($x) ? json_encode($x) : (string) $x) ?></div><?php endforeach; ?><?php endif; ?></div></div>
    <?= view('graph/partials/footer.php', ['state' => $state, 'may' => ['manage' => has_right('graph.manage')]]) ?>
</div>
