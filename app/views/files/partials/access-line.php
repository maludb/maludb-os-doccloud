<?php /** The access summary line. Data: line, folderId, may (share), adminView */ ?>
<div class="fs-12 text-muted d-flex align-items-center flex-wrap gap-2" id="folder-access-line">
    <i class="feather-users"></i><span id="folder-access-text"><?= e($line) ?></span>
    <?php if (!empty($adminView)): ?><span class="badge bg-soft-danger text-danger" id="folder-admin-view">You see this as the admin</span><?php endif; ?>
    <?php if (!empty($may['share'])): ?><?= hx_link('/folders/' . (int) $folderId . '/share', 'Who can see this', 'fw-semibold', 'id="folder-access-link"') ?><?php endif; ?>
</div>
