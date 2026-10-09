<?php /** The access summary line. Data: line, folderId, may (share, manage), adminView, autoTags, graphPolicy (slice 5), mayPolicy */ ?>
<div class="fs-12 text-muted d-flex align-items-center flex-wrap gap-2" id="folder-access-line">
    <i class="feather-users"></i><span id="folder-access-text"><?= e($line) ?></span>
    <?php if (!empty($adminView)): ?><span class="badge bg-soft-danger text-danger" id="folder-admin-view">You see this as the admin</span><?php endif; ?>
    <?php if (!empty($may['share'])): ?><?= hx_link('/folders/' . (int) $folderId . '/share', 'Who can see this', 'fw-semibold', 'id="folder-access-link"') ?><?php endif; ?>
    <?php $rule = $autoTags ?? []; if ($rule !== []): ?><span id="folder-auto-tags"><i class="feather-tag"></i> tags on upload: <?php foreach ($rule as $t): ?><span class="badge bg-soft-secondary text-secondary"><?= e($t) ?></span> <?php endforeach; ?></span><?php endif; ?>
    <?php if (!empty($may['manage'])): ?><?= hx_link('/tags/rules?folder=' . (int) $folderId, $rule === [] ? 'Tag on upload' : 'change', 'fw-semibold', 'id="folder-auto-tags-link"') ?><?php endif; ?>
    <?php if (!empty($graphPolicy)): ?><span id="folder-graph-policy"><i class="feather-share-2"></i> graph: <span class="badge bg-soft-<?= e(GRAPH_POLICY_BADGE[$graphPolicy] ?? 'secondary') ?>"><?= e($graphPolicy) ?></span></span><?php if (!empty($mayPolicy)): ?><?= hx_link('/graph/admin?folder=' . (int) $folderId, 'Policy…', 'fw-semibold', 'id="folder-graph-policy-link"') ?><?php endif; ?><?php endif; ?>
</div>
