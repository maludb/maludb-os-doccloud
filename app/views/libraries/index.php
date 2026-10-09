<?php /** Libraries (library-list). Data: rows, kind, may (create), notice, archived */ ?>
<?= view('shared/header.php', ['id' => 'library-list', 'title' => 'Libraries', 'crumbs' => [['Home', '/'], ['Libraries', null]],
    'action' => $may['create'] ? hx_link('/libraries/new', '<i class="feather-plus me-1"></i>New shared library', 'btn btn-primary btn-touch', 'id="library-new-btn"') : '']) ?>
<div class="main-content" id="library-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="library-filters">
        <?php foreach (['' => 'All', 'personal' => 'Personal', 'group' => 'Groups', 'shared' => 'Shared'] as $k => $label): ?><?= hx_link('/libraries' . ($k === '' ? '' : '?kind=' . $k), e($label), 'btn btn-touch ' . (($kind ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="library-filter-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
        <?php if (is_dc_admin()): ?><?= hx_link('/libraries?archived=1', 'Archived', 'btn btn-touch ' . ($archived ? 'btn-primary' : 'btn-light'), 'id="library-filter-archived"') ?><?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card" id="library-empty-card"><div class="card-body"><div class="empty-state text-center py-4" id="library-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-hard-drive"></i></span><div class="fw-semibold">No libraries here.</div></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="library-cards"><?php foreach ($rows as $l) { echo view('libraries/partials/card.php', ['l' => $l, 'back' => '/libraries']); } ?></div>
    <?php endif; ?>
</div>
