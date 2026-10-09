<?php /** The policy and the queue state in a footer. Data: state, may (manage) */ $b = $state['budget']; ?>
<div class="fs-11 text-muted d-flex flex-wrap gap-2 align-items-center mt-3" id="graph-footer">
    <span id="graph-footer-policy">index: <?= number_format($state['by_policy']['index']) ?> · full: <?= number_format($state['by_policy']['full']) ?> documents</span>
    <span>· queued <?= (int) $state['queued'] ?><?php if ($state['running'] > 0): ?>, running <?= (int) $state['running'] ?><?php endif; ?><?php if ($state['failed'] > 0): ?>, failed <?= (int) $state['failed'] ?><?php endif; ?></span>
    <span>· this month <?= e(format_bytes($b['full_chars'])) ?> of <?= e(format_bytes($b['budget_chars'])) ?></span>
    <?php if (!$state['available']): ?><span class="badge bg-soft-warning text-warning" id="graph-footer-unavailable">engine unavailable</span><?php endif; ?>
    <?php if (!empty($may['manage'])): ?><?= hx_link('/graph/admin', 'Policies, budget, model and the queue', 'fw-semibold', 'id="graph-admin-link"') ?><?php endif; ?>
</div>
