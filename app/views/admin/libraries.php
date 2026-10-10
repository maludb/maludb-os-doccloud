<?php /** Every library (screen `admin-libraries`). Data: rows, kind, archived */
$u = static fn (string $k, bool $a): string => '/admin/libraries' . ($k !== '' || $a ? '?' . http_build_query(array_filter(['kind' => $k, 'archived' => $a ? '1' : ''])) : '');
?>
<?= view('shared/header.php', ['id' => 'admin-libraries', 'title' => 'Libraries', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Libraries', null]]]) ?>
<div class="main-content" id="admin-libraries-content">
    <div class="d-flex flex-wrap gap-1 mb-2" id="admin-libraries-filters">
        <?php foreach ([['', 'Every library'], ['personal', 'Personal'], ['group', 'Group'], ['shared', 'Shared']] as [$k, $label]): ?><?= hx_link($u($k, $archived), $label, 'btn btn-sm btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="admin-libraries-filter-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
        <?= hx_link($u($kind, !$archived), $archived ? 'Live ones' : 'Archived', 'btn btn-sm btn-touch ms-auto ' . ($archived ? 'btn-secondary' : 'btn-light'), 'id="admin-libraries-archived"') ?>
    </div>
    <div class="alert alert-light fs-12" id="admin-libraries-note"><i class="feather-eye me-1"></i>Opening a library you do not belong to is logged as an admin view.</div>
    <div class="card" id="admin-libraries-card"><div class="list-group list-group-flush" id="admin-libraries-table">
        <?php if ($rows === []): ?><div class="list-group-item text-muted fs-12" id="admin-libraries-empty">No library here.</div><?php endif; ?>
        <?php foreach ($rows as $l): $id = (int) $l['library_id']; $q = ['used_bytes' => $l['used_bytes'], 'quota_bytes' => $l['quota_bytes'], 'pct' => $l['pct']]; ?>
        <div class="list-group-item fs-12" id="admin-library-row-<?= $id ?>" data-kind="<?= e($l['kind']) ?>">
            <div class="d-flex flex-wrap justify-content-between gap-1">
                <span><?= hx_link(with_back('/libraries/' . $id, '/admin/libraries'), e($l['name']), 'fw-semibold', 'id="admin-library-link-' . $id . '"') ?>
                    <span class="badge bg-soft-<?= $l['kind'] === 'personal' ? 'warning text-warning' : 'secondary text-secondary' ?>" id="admin-library-kind-<?= $id ?>"><?= e($l['kind']) ?></span>
                    <?php if (!$l['mine']): ?><span class="badge bg-soft-dark text-dark" title="You do not belong to it: opening it is logged">admin view</span><?php endif; ?>
                    <?php if ($l['archived_at'] !== null): ?><span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?></span>
                <span class="text-muted"><?= $l['kind'] === 'personal' ? 'of ' . e($l['owner_name'] ?? 'someone who left') : ($l['group_name'] !== null ? 'group ' . e($l['group_name']) : '') ?><?= $l['documents'] !== null ? ' · ' . (int) $l['documents'] . ' document' . ((int) $l['documents'] === 1 ? '' : 's') : '' ?></span>
            </div>
            <div class="my-1"><?= view('libraries/partials/quota-bar.php', ['q' => $q, 'idPrefix' => 'admin-library-' . $id]) ?></div>
        </div>
        <?php endforeach; ?>
    </div></div>
</div>
