<?php /** One graph result. Data: r (graph row + excerpt, tags, owner_name), tz, q */ $rid = (int) $r['document_id']; ?>
<div class="list-group-item graph-result" id="graph-result-<?= $rid ?>">
    <div class="d-flex align-items-start gap-2">
        <i class="<?= e(kind_icon($r['mime_kind'])) ?> text-muted mt-1"></i>
        <div class="min-w-0 flex-grow-1">
            <div class="d-flex align-items-center gap-2 flex-wrap"><?= hx_link('/documents/' . $rid, e($r['name']), 'fw-semibold', 'id="graph-link-' . $rid . '"') ?>
                <span class="graph-score" title="score <?= e(number_format((float) $r['score'], 2)) ?>"><span class="graph-score-bar" style="width:<?= (int) min(100, round((float) $r['score'] * 25)) ?>px"></span></span></div>
            <div class="fs-11 text-muted"><?= hx_link('/f/' . (int) $r['folder_id'], e($r['folder_path'] ?? ''), 'text-muted') ?> · <?= e($r['mime_kind']) ?><?php if (!empty($r['owner_name'])): ?> · <?= e($r['owner_name']) ?><?php endif; ?></div>
            <?php if (!empty($r['excerpt'])): ?><div class="fs-12 graph-excerpt"><?= search_excerpt_html((string) $r['excerpt']) ?></div><?php endif; ?>
            <div class="mt-1 graph-subjects"><?php foreach (($r['subjects'] ?? []) as $s): ?><span class="badge bg-soft-primary text-primary me-1 graph-subject-chip"><?= e($s) ?></span><?php endforeach; ?><?php foreach (($r['tags'] ?? []) as $t): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($t) ?></span><?php endforeach; ?></div>
        </div>
    </div>
</div>
