<?php /** Groups (group-list). Data: rows, filters, may (create), notice */ ?>
<?= view('shared/header.php', ['id' => 'group-list', 'title' => 'Groups', 'crumbs' => [['Home', '/'], ['Groups', null]],
    'action' => $may['create'] ? hx_link('/groups/new', '<i class="feather-plus me-1"></i>New group', 'btn btn-primary btn-touch', 'id="group-new-btn"') : '']) ?>
<div class="main-content" id="group-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/groups" class="d-flex flex-wrap gap-1 mb-3 align-items-center" id="group-filters" hx-get="/groups" hx-target="#page-content" hx-push-url="true">
        <input type="search" name="q" class="form-control btn-touch" style="max-width: 260px" placeholder="Search groups…" value="<?= e($filters['q']) ?>" id="group-filter-q" aria-label="Search groups">
        <?= hx_link('/groups', 'All', 'btn btn-touch ' . (!$filters['mine'] && !$filters['archived'] && $filters['kind'] === null ? 'btn-primary' : 'btn-light'), 'id="group-filter-all"') ?>
        <?= hx_link('/groups?mine=1', 'Mine', 'btn btn-touch ' . ($filters['mine'] ? 'btn-primary' : 'btn-light'), 'id="group-filter-mine"') ?>
        <?= hx_link('/groups?kind=department', 'Departments', 'btn btn-touch ' . ($filters['kind'] === 'department' ? 'btn-primary' : 'btn-light'), 'id="group-filter-department"') ?>
        <?= hx_link('/groups?archived=1', 'Archived', 'btn btn-touch ' . ($filters['archived'] ? 'btn-primary' : 'btn-light'), 'id="group-filter-archived"') ?>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="group-empty-card"><div class="card-body"><div class="empty-state text-center py-4" id="group-empty"><span class="avatar-text avatar-lg rounded mb-2"><i class="feather-users"></i></span><div class="fw-semibold">No groups<?= $filters['q'] !== '' ? ' match' : ' yet' ?>.</div>
            <?php if ($may['create']): ?><div class="mt-2"><?= hx_link('/groups/new', 'Make the first group', 'btn btn-primary btn-touch', 'id="group-empty-new-btn"') ?></div><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row g-3" id="group-cards"><?php foreach ($rows as $g) { echo view('groups/partials/card.php', ['g' => $g, 'back' => '/groups']); } ?></div>
    <?php endif; ?>
</div>
