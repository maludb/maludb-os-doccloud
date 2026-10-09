<?php /** The filters as removable chips. Data: chips (search_chips) */ ?>
<div id="search-chips" class="d-flex flex-wrap gap-1 mb-2">
    <?php foreach ($chips as $c): ?><span class="badge bg-soft-primary text-primary search-chip" id="search-chip-<?= e($c['key']) ?>"><?= e($c['label']) ?> <?= hx_link($c['without'], '&times;', 'text-reset text-decoration-none ms-1', 'aria-label="Remove ' . e($c['label']) . '" id="search-chip-remove-' . e($c['key']) . '"') ?></span><?php endforeach; ?>
</div>
