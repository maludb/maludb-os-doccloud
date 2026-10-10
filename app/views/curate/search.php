<?php /** Search report (screen `curate-search`). Data: r (search_report), tz */
$range = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days'];
?>
<?= view('shared/header.php', ['id' => 'curate-search', 'title' => 'Search report', 'crumbs' => [['Home', '/'], ['Curate', '/curate/'], ['Search report', null]]]) ?>
<div class="main-content" id="curate-search-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="search-range">
        <?php foreach ($range as $k => $label): ?><?= hx_link('/curate/search?since=' . $k, 'Last ' . $label, 'btn btn-sm btn-touch ' . ((int) $r['days'] === (int) $k ? 'btn-primary' : 'btn-light'), 'id="search-range-' . e($k) . '"') ?><?php endforeach; ?>
        <form method="post" action="/curate/storage/export.php" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="report" value="search"><input type="hidden" name="format" value="csv"><input type="hidden" name="since" value="<?= (int) $r['days'] ?>d">
            <button type="submit" class="btn btn-light btn-touch btn-sm" id="search-export-btn"><i class="feather-download me-1"></i>Export CSV</button></form>
    </div>
    <div class="row g-2 mb-3" id="search-totals">
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Searches</div><div class="fs-5 fw-semibold" id="search-total"><?= (int) $r['searches'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Keyword</div><div class="fs-5 fw-semibold" id="search-kind-keyword"><?= (int) $r['by_kind']['keyword'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">Graph</div><div class="fs-5 fw-semibold" id="search-kind-graph"><?= (int) $r['by_kind']['graph'] ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted">By name</div><div class="fs-5 fw-semibold" id="search-kind-name"><?= (int) $r['by_kind']['name'] ?></div></div></div></div>
    </div>
    <div class="card mb-3" id="search-gaps"><div class="card-header"><h6 class="m-0">Searches that found nothing</h6></div>
        <?php if ($r['gaps'] === []): ?><div class="card-body fs-12 text-muted" id="search-gaps-empty">Everything people looked for was found.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>What they typed</th><th class="text-end">Times</th><th class="text-end">Last</th></tr></thead><tbody>
        <?php foreach ($r['gaps'] as $i => $g): ?><tr id="search-gap-<?= $i ?>"><td><?= e($g['query']) ?></td><td class="text-end"><?= (int) $g['times'] ?></td><td class="text-end text-muted text-nowrap"><?= e(format_ts($g['last_at'], $tz, 'M j')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <div class="row g-3">
        <div class="col-12 col-lg-6"><div class="card h-100 mb-0" id="search-top"><div class="card-header"><h6 class="m-0">What they searched most</h6></div>
            <?php if ($r['top'] === []): ?><div class="card-body fs-12 text-muted">No searches in this time.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Query</th><th class="text-end">Times</th><th class="text-end">Avg. results</th></tr></thead><tbody>
            <?php foreach ($r['top'] as $t): ?><tr><td><?= e($t['query']) ?></td><td class="text-end"><?= (int) $t['times'] ?></td><td class="text-end"><?= e((string) $t['avg_results']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?></div></div>
        <div class="col-12 col-lg-6"><div class="card h-100 mb-0" id="search-per-day"><div class="card-header"><h6 class="m-0">Searches a day</h6></div>
            <?php if ($r['per_day'] === []): ?><div class="card-body fs-12 text-muted">No searches in this time.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Day</th><th class="text-end">Searches</th><th class="text-end">Found nothing</th></tr></thead><tbody>
            <?php foreach (array_slice($r['per_day'], 0, 14) as $d): ?><tr><td><?= e(format_date($d['day'])) ?></td><td class="text-end"><?= (int) $d['searches'] ?></td><td class="text-end"><?= (int) $d['empty'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?></div></div>
    </div>
</div>
