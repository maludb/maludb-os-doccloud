<?php /** Search (screen `search`). Data: s (search result), chips, asked, saved, tz, notice, stars */
$q = $s['typed'];
$few = $asked && $s['total'] < 3;
?>
<?= view('shared/header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]], 'action' => hx_link('/search/saved', '<i class="feather-bookmark me-1"></i>Saved searches', 'btn btn-light btn-touch', 'id="search-saved-btn"')]) ?>
<div class="main-content" id="search-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/search" hx-get="/search" hx-target="#page-content" hx-push-url="true" id="search-form" class="mb-2">
        <div class="input-group"><span class="input-group-text"><i class="feather-search"></i></span>
            <input type="search" name="q" id="search-box" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="Words, &quot;a phrase&quot;, -not, tag:x kind:pdf owner:me in:Contracts after:2026-01-01" autofocus autocomplete="off" aria-label="Search">
            <?php foreach ($s['filters'] as $k => $v): ?><?php if (!in_array($k, ['tag', 'kind', 'mine', 'shared_with_me', 'after', 'before', 'owner', 'folder', 'library', 'min_bytes', 'max_bytes'], true)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e(is_bool($v) ? '1' : (string) $v) ?>"><?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-touch" id="search-submit">Search</button>
        </div>
        <div class="fs-11 text-muted mt-1">Modifiers: <code>tag:</code> <code>kind:</code> <code>owner:me</code> <code>in:</code> <code>library:</code> <code>after:</code> <code>before:</code> <code>size&gt;1m</code> <code>mine</code> <code>shared</code></div>
    </form>
    <?= view('search/partials/chips.php', ['chips' => $chips]) ?>
    <?php if ($asked): ?>
    <div class="d-flex align-items-center gap-2 mb-2 fs-12 flex-wrap" id="search-summary">
        <span id="search-total"><?= $s['capped'] ? 'about ' : '' ?><?= (int) $s['total'] ?> result<?= (int) $s['total'] === 1 ? '' : 's' ?></span><span class="text-muted">in <?= (int) $s['took_ms'] ?> ms</span>
        <form method="post" action="/search/save.php" hx-post="/search/save.php" hx-target="#flash" class="d-inline-flex gap-1 ms-auto" id="search-save-form"><?= csrf_field() ?><input type="hidden" name="query" value="<?= e($q) ?>"><input type="hidden" name="filters" value="<?= e(json_encode((object) $s['filters'])) ?>"><input type="hidden" name="kind" value="keyword"><input type="hidden" name="return_to" value="/search?<?= e(http_build_query(['q' => $q])) ?>">
            <input type="text" name="name" class="form-control form-control-sm" placeholder="Name it to save" maxlength="80" required aria-label="Name the search" id="search-save-name" style="max-width:200px"><label class="fs-11 d-flex align-items-center gap-1"><input type="checkbox" name="is_shared" value="yes" class="form-check-input mt-0"> shared</label><button type="submit" class="btn btn-light btn-sm" id="search-save-btn">Save this search</button></form>
    </div>
    <div class="list-group mb-3" id="search-results">
        <?php if ($s['rows'] === []): ?><div class="list-group-item text-muted fs-12" id="search-empty">Nothing you may see matches.</div><?php endif; ?>
        <?php foreach ($s['rows'] as $r): ?><?= view('search/partials/result.php', ['r' => $r, 'tz' => $tz, 'q' => $s['q']]) ?><?php endforeach; ?>
    </div>
    <?php if ($s['pages'] > 1): ?><div class="d-flex gap-2 fs-12 mb-3" id="search-pages"><?php for ($p = max(1, $s['page'] - 3); $p <= min($s['pages'], $s['page'] + 3); $p++): ?><?= hx_link(search_url($q, $s['filters'], $p), (string) $p, $p === $s['page'] ? 'fw-bold' : '', 'id="search-page-' . $p . '"') ?><?php endfor; ?></div><?php endif; ?>
    <?php if ($s['names'] !== []): ?><div class="card mb-3" id="search-names"><div class="card-body fs-12"><span class="text-muted">Did you mean</span> <?php foreach ($s['names'] as $n): ?><?= hx_link('/documents/' . (int) $n['document_id'], e($n['name']), 'me-2', 'id="search-name-' . (int) $n['document_id'] . '"') ?><?php endforeach; ?></div></div><?php endif; ?>
    <?php if ($few): ?><div class="fs-12" id="search-try-graph"><?= hx_link('/graph/?' . http_build_query(['q' => $s['q']]), '<i class="feather-share-2 me-1"></i>Try the graph: documents about this', 'fw-semibold', 'id="search-graph-link"') ?></div><?php endif; ?>
    <?php else: ?>
    <div class="card" id="search-intro"><div class="card-body fs-12 text-muted">Type words to search the names, tags, descriptions and text of every document you may see.<?php if ($saved !== []): ?> Or run a saved search: <?php foreach (array_slice($saved, 0, 6) as $v): ?><?= hx_link(present_saved_search($v)['url'], e($v['name']), 'me-2', 'id="search-saved-' . (int) $v['saved_search_id'] . '"') ?><?php endforeach; ?><?php endif; ?></div></div>
    <?php endif; ?>
</div>
