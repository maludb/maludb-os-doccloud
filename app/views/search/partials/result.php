<?php /** One search result. Data: r (dc_search row + folder_path), tz, q */ $rid = (int) $r['document_id']; ?>
<div class="list-group-item search-result" id="search-result-<?= $rid ?>">
    <div class="d-flex align-items-start gap-2">
        <i class="<?= e(kind_icon($r['mime_kind'])) ?> text-muted mt-1"></i>
        <div class="min-w-0 flex-grow-1">
            <?= hx_link('/documents/' . $rid, e($r['name']), 'fw-semibold', 'id="search-link-' . $rid . '"') ?>
            <div class="fs-11 text-muted"><?= hx_link('/f/' . (int) $r['folder_id'], e($r['folder_path'] ?? ''), 'text-muted') ?> · <?= e($r['mime_kind']) ?> · <?= e(format_bytes((int) $r['byte_size'])) ?> · <?= e(format_ts($r['occurred_at'], $tz, 'M j, Y')) ?></div>
            <?php if (!empty($r['excerpt'])): ?><div class="fs-12 search-excerpt"><?= search_excerpt_html((string) $r['excerpt']) ?></div><?php endif; ?>
            <?php if (($r['tags'] ?? []) !== []): ?><div class="mt-1"><?php foreach ($r['tags'] as $t): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div>
