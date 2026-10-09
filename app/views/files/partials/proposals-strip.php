<?php /** The Clerk's open proposals for a folder, and the ask for the expert's tags. Data: folderId, proposals (my_proposals rows for the folder), mayAsk (bool), untagged (int) */ ?>
<?php if ($proposals !== [] || $mayAsk): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2 fs-12" id="folder-proposals-strip">
    <?php if ($proposals !== []): ?><span class="badge bg-soft-info text-info" id="folder-proposals-count"><i class="feather-inbox me-1"></i><?= count($proposals) ?> proposal<?= count($proposals) === 1 ? '' : 's' ?> from the Clerk</span>
        <?= hx_link('/curate/proposals?folder=' . (int) $folderId, 'Look', '', 'id="folder-proposals-link"') ?><?php endif; ?>
    <?php if ($mayAsk && $untagged > 0): ?>
        <form method="post" action="/agents/propose-tags.php" hx-post="/agents/propose-tags.php" hx-target="#flash" class="ms-auto d-inline"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $folderId ?>">
            <button type="submit" class="btn btn-light btn-touch" id="folder-ask-expert-btn"><i class="feather-message-circle me-1"></i>Ask the expert to tag <?= (int) $untagged ?> untagged</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>
