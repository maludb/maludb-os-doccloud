<?php /** Ownership (screen `curate-ownership`). Data: leavers, owner (member row or null), holdings, library, notice */ ?>
<?= view('shared/header.php', ['id' => 'curate-ownership', 'title' => 'Ownership', 'crumbs' => [['Home', '/'], ['Curate', '/curate/'], ['Ownership', null]]]) ?>
<div class="main-content" id="curate-ownership-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="ownership-leavers"><div class="card-header"><h6 class="m-0">People who left and still own documents</h6></div>
        <?php if ($leavers === []): ?><div class="card-body fs-12 text-muted" id="ownership-leavers-empty">Nobody who has left owns a document.</div>
        <?php else: ?><div class="list-group list-group-flush">
            <?php foreach ($leavers as $l): $mid = (int) $l['member_id']; ?>
            <div class="list-group-item fs-12 d-flex flex-wrap justify-content-between align-items-center gap-2" id="ownership-leaver-<?= $mid ?>">
                <span><span class="fw-semibold"><?= e($l['display_name']) ?></span> <span class="badge bg-soft-dark text-dark"><?= e($l['status']) ?></span>
                    <span class="text-muted"><?= (int) $l['documents'] ?> document<?= (int) $l['documents'] === 1 ? '' : 's' ?> · <?= e(format_bytes((int) $l['live_bytes'])) ?></span></span>
                <?= hx_link('/curate/ownership?member=' . $mid, 'Hand over', 'btn btn-sm btn-light btn-touch', 'id="ownership-pick-' . $mid . '"') ?>
            </div>
            <?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php if ($owner === null): ?>
    <div class="card mb-3" id="ownership-choose"><div class="card-header"><h6 class="m-0">Someone else</h6></div><div class="card-body">
        <form method="get" action="/curate/ownership" id="ownership-choose-form" class="row g-2 align-items-end">
            <div class="col-12 col-md-6"><label class="form-label fs-12" id="ownership-member-label">Whose documents</label>
                <?= picker_field(['id' => 'ownership-member', 'name' => 'member', 'source' => 'member', 'title' => 'Whose documents', 'placeholder' => 'Choose a person', 'labelledby' => 'ownership-member-label']) ?></div>
            <div class="col-12 col-md-auto"><button type="submit" class="btn btn-primary btn-touch" id="ownership-choose-btn">Continue</button></div>
        </form>
    </div></div>
    <?php else: $oid = (int) $owner['id']; $n = (int) $holdings['documents']; ?>
    <div class="card mb-3" id="ownership-form"><div class="card-header"><h6 class="m-0">Hand over <?= e($owner['display_name']) ?>'s documents</h6></div><div class="card-body">
        <p class="fs-12" id="ownership-summary"><?= e($owner['display_name']) ?> owns <span class="fw-semibold" id="ownership-count"><?= $n ?></span> document<?= $n === 1 ? '' : 's' ?> (<?= e(format_bytes((int) $holdings['bytes'])) ?>, trashed ones included)<?= $library !== null ? ' in ' . e($library['name']) : '' ?>.
            They are not copied: they change owner, and with it who manages them. Folders stay as they are.</p>
        <?php if ($n === 0): ?><div class="alert alert-light fs-12" id="ownership-none">There is nothing to hand over.</div><?php endif; ?>
        <form method="post" action="/curate/ownership/transfer.php" hx-post="/curate/ownership/transfer.php" hx-target="#flash" hx-confirm="Hand <?= $n ?> document<?= $n === 1 ? '' : 's' ?> of <?= e($owner['display_name']) ?> to the new owner? They stop being <?= e($owner['display_name']) ?>'s." id="ownership-transfer-form" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="owner" value="<?= $oid ?>"><input type="hidden" name="return_to" value="/curate/ownership">
            <div class="col-12 col-md-6"><label class="form-label fs-12" id="ownership-newowner-label">The new owner</label>
                <?= picker_field(['id' => 'ownership-newowner', 'name' => 'new_owner', 'source' => 'member', 'title' => 'The new owner', 'placeholder' => 'Choose the new owner', 'labelledby' => 'ownership-newowner-label']) ?></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12" id="ownership-library-label">Only one library (optional)</label>
                <?= picker_field(['id' => 'ownership-library', 'name' => 'library', 'source' => 'library', 'value' => $library === null ? null : (int) $library['library_id'], 'title' => 'One library', 'placeholder' => 'Every library', 'labelledby' => 'ownership-library-label']) ?></div>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch" id="ownership-transfer-btn"<?= $n === 0 ? ' disabled' : '' ?>><i class="feather-repeat me-1"></i>Hand over</button>
                <?= hx_link('/curate/ownership', 'Choose someone else', 'btn btn-light btn-touch', 'id="ownership-other"') ?></div>
        </form>
    </div></div>
    <?php endif; ?>
</div>
