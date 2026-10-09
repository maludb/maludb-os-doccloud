<?php /** Tags (screen `tag-list`). Data: tags, filters, proposals, manage, notice */ ?>
<?= view('shared/header.php', ['id' => 'tags', 'title' => 'Tags', 'crumbs' => [['Home', '/'], ['Tags', null]],
    'action' => ($manage ? hx_link('/tags/new', '<i class="feather-plus me-1"></i>New tag', 'btn btn-primary btn-touch', 'id="tag-new-btn"') . ' ' : '') . hx_link('/tags/rules', '<i class="feather-git-branch me-1"></i>Rules', 'btn btn-light btn-touch', 'id="tag-rules-btn"')]) ?>
<div class="main-content" id="tags-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/tags" hx-get="/tags" hx-target="#page-content" hx-push-url="true" class="d-flex gap-1 mb-3 flex-wrap" id="tags-filter">
        <input type="search" name="q" value="<?= e($filters['q']) ?>" class="form-control btn-touch" style="max-width:280px" placeholder="Find a tag" aria-label="Find a tag" id="tags-q">
        <select name="kind" class="form-select btn-touch w-auto" aria-label="Kind" id="tags-kind"><option value="">managed and free</option><option value="managed" <?= $filters['kind'] === 'managed' ? 'selected' : '' ?>>managed</option><option value="free" <?= $filters['kind'] === 'free' ? 'selected' : '' ?>>free</option></select>
        <label class="form-check-label d-flex align-items-center gap-1 fs-12"><input type="checkbox" name="archived" value="1" class="form-check-input" <?= $filters['archived'] ? 'checked' : '' ?> id="tags-archived"> archived too</label>
        <button type="submit" class="btn btn-light btn-touch">Filter</button>
    </form>
    <?php if ($manage && $proposals !== []): ?>
    <div class="card mb-3" id="proposals-card"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Proposals</h5><?= hx_link('/tags/proposals', 'all', 'ms-auto fs-12', 'id="proposals-all-link"') ?></div>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="proposals-list"><tbody>
        <?php foreach (array_slice($proposals, 0, 10) as $p): ?><?= view('tags/partials/proposal-row.php', ['p' => $p, 'tz' => member_timezone(), 'decided' => false]) ?><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <div class="card" id="tags-card"><div class="card-body">
        <?php if ($tags === []): ?><div class="text-muted" id="tags-empty">No tag yet. Add one on any document, or make a managed one here.</div><?php endif; ?>
        <?php $lastKind = null; foreach ($tags as $t): ?>
            <?php if ($t['state'] !== $lastKind): $lastKind = $t['state']; ?><div class="fs-11 text-muted text-uppercase mt-2 mb-1 w-100"><?= e($lastKind) ?></div><?php endif; ?>
            <?= view('tags/partials/chip.php', ['t' => $t, 'count' => true]) ?>
        <?php endforeach; ?>
    </div></div>
</div>
