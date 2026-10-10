<?php /** The document screen. Data: d, preview, versions, crumbs, may, stars, tz, text, extraction, notes, timeline, notice, adminView, lockedByOther */
$id = (int) $d['document_id'];
$starred = isset($stars['document'][$id]);
$tags = is_array($d['tags'] ?? null) ? $d['tags'] : pg_text_array((string) ($d['tags'] ?? '{}'));
$back = '/documents/' . $id;
?>
<div class="page-header" id="document-header">
    <div class="page-header-left d-flex align-items-center min-w-0">
        <div class="page-header-title min-w-0"><h5 class="m-b-10 text-truncate d-flex align-items-center gap-2"><i class="<?= e(kind_icon($d['mime_kind'])) ?> text-muted"></i><span id="document-title"><?= e($d['name']) ?></span></h5></div>
        <?= view('files/partials/crumbs.php', ['crumbs' => $crumbs, 'here' => null]) ?>
    </div>
    <div class="page-header-right ms-auto d-flex gap-1 flex-wrap justify-content-end">
        <a href="/d/<?= $id ?>?download=1" class="btn btn-primary btn-touch" id="document-download-btn"><i class="feather-download me-1"></i>Download</a>
        <?php if ($may['edit']): ?><?= hx_link('/f/' . (int) $d['folder_id'] . '/upload?document=' . $id, '<i class="feather-upload me-1"></i>New version', 'btn btn-light btn-touch', 'id="document-new-version-btn"') ?><?php endif; ?>
    </div>
</div>
<div class="main-content" id="document-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($adminView): ?><div class="fs-11 text-muted mb-2" id="document-admin-view">You see this as the admin.</div><?php endif; ?>
    <?php if ($d['trashed_at'] !== null): ?><?= view('files/partials/trash-banner.php', ['d' => $d, 'id' => $id, 'may' => $may, 'tz' => $tz, 'window' => trash_window_days(db())]) ?><?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <?= view('files/partials/preview.php', ['d' => $d, 'preview' => $preview, 'extraction' => $extraction, 'mayHide' => $may['hide_preview'] ?? false]) ?>
            <?= view('files/partials/versions.php', ['d' => $d, 'versions' => $versions, 'may' => $may, 'tz' => $tz, 'compact' => true]) ?>
            <div class="card mb-3" id="document-comments"><div class="card-header"><h5 class="card-title mb-0">Comments</h5></div>
                <div id="comments-list"><div class="list-group list-group-flush" id="document-notes-list">
                    <?php if ($notes === []): ?><div class="list-group-item text-muted fs-12" id="document-notes-empty">No comments yet.</div><?php endif; ?>
                    <?php foreach ($notes as $n): $nid = (int) $n['note_id']; $mineNote = $n['member_id'] !== null && (int) $n['member_id'] === (int) current_member_id(); ?><div class="list-group-item fs-12" id="note-<?= $nid ?>">
                        <div class="d-flex justify-content-between gap-2"><span><span class="fw-semibold"><?= e($n['author'] ?? 'Someone') ?></span> <span class="text-muted"><?= e(format_ts($n['created_at'], $tz)) ?></span></span>
                        <?php if ($mineNote || $may['manage']): ?><form method="post" action="/notes/delete.php" hx-post="/notes/delete.php" hx-target="#flash" hx-confirm="Delete this comment?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="note" value="<?= $nid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                            <button type="submit" class="btn btn-sm btn-light" id="note-delete-<?= $nid ?>" aria-label="Delete this comment"><i class="feather-trash-2"></i></button></form><?php endif; ?></div>
                        <div id="note-<?= $nid ?>-body"><?= nl2br(e($n['body'])) ?></div></div><?php endforeach; ?>
                </div></div>
                <?php if ($d['trashed_at'] === null): ?><div class="card-body border-top">
                    <form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" id="comment-form"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                        <label class="form-label fs-12" for="comment-body">Add a comment</label>
                        <textarea name="body" id="comment-body" class="form-control" rows="2" maxlength="4000" required placeholder="Say something about this document"></textarea>
                        <button type="submit" class="btn btn-primary btn-touch mt-2" id="comment-add-btn">Comment</button>
                    </form></div><?php endif; ?></div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'document']) ?>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card mb-3" id="document-facts"><div class="card-body fs-12">
                <div class="d-flex flex-wrap gap-1 mb-2" id="document-actions">
                    <?php if ($may['star']): ?><form method="post" action="/favourites/<?= $starred ? 'remove' : 'add' ?>.php" hx-post="/favourites/<?= $starred ? 'remove' : 'add' ?>.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>"><button type="submit" class="btn btn-light btn-touch btn-icon" id="document-star-btn" title="<?= $starred ? 'Remove from favourites' : 'Add to favourites' ?>"><i class="feather-star<?= $starred ? ' text-warning' : '' ?>"></i></button></form><?php endif; ?>
                    <?php if ($may['edit']): ?><?= hx_link('/documents/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="document-edit-btn"') ?><?= hx_link('/documents/' . $id . '/move', '<i class="feather-corner-up-right me-1"></i>Move', 'btn btn-light btn-touch', 'id="document-move-btn"') ?><?php endif; ?>
                    <?php if ($may['copy']): ?><?= hx_link('/documents/' . $id . '/copy', '<i class="feather-copy me-1"></i>Copy', 'btn btn-light btn-touch', 'id="document-copy-btn"') ?><?php endif; ?>
                    <?php if ($may['share']): ?><?= hx_link('/documents/' . $id . '/share', '<i class="feather-share-2 me-1"></i>Share', 'btn btn-light btn-touch', 'id="document-share-btn"') ?><?= hx_link('/documents/' . $id . '/links', '<i class="feather-link me-1"></i>Links', 'btn btn-light btn-touch', 'id="document-links-btn"') ?><?php endif; ?>
                    <?php if ($may['export']): ?><form method="post" action="/documents/export.php" hx-post="/documents/export.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="documents" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="document-export-btn" title="A zip with this document"><i class="feather-package me-1"></i>Zip</button></form><?php endif; ?>
                    <?php if ($may['manage'] && $d['trashed_at'] === null): ?><form method="post" action="/documents/trash.php" hx-post="/documents/trash.php" hx-target="#flash" hx-confirm="Send <?= e($d['name']) ?> to the trash? It can be restored for 30 days." class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="document-trash-btn" <?= is_file(dirname(__DIR__, 3) . '/html/documents/trash.php') ? '' : 'disabled title="The trash arrives with slice 6"' ?>><i class="feather-trash-2 me-1"></i>Trash</button></form><?php endif; ?>
                </div>
                <?= view('files/partials/lock.php', ['d' => $d, 'may' => $may, 'tz' => $tz, 'lockedByOther' => $lockedByOther]) ?>
                <dl class="row mb-0 mt-2" id="document-meta">
                    <dt class="col-4 text-muted">Owner</dt><dd class="col-8" id="document-owner"><?= e($d['owner_name'] ?? '') ?></dd>
                    <dt class="col-4 text-muted">Folder</dt><dd class="col-8"><?= hx_link('/f/' . (int) $d['folder_id'], e($d['folder_name'] ?? ''), '', 'id="document-folder-link"') ?></dd>
                    <dt class="col-4 text-muted">Kind</dt><dd class="col-8"><?= e($d['mime_kind']) ?> <span class="text-muted">(<?= e($d['mime_type']) ?>)</span></dd>
                    <dt class="col-4 text-muted">Size</dt><dd class="col-8" id="document-size"><?= e(format_bytes((int) $d['byte_size'])) ?></dd>
                    <dt class="col-4 text-muted">Version</dt><dd class="col-8">v<?= (int) ($d['current_version_no'] ?? $d['version_count']) ?> of <?= (int) $d['version_count'] ?> · <?= hx_link('/documents/' . $id . '/versions', 'all versions', '', 'id="document-versions-link"') ?></dd>
                    <dt class="col-4 text-muted">Modified</dt><dd class="col-8"><?= e(format_ts($d['updated_at'], $tz)) ?></dd>
                    <dt class="col-4 text-muted">Text</dt><dd class="col-8" id="document-extraction"><?= e($extraction) ?><?php if ($may['reextract']): ?> <form method="post" action="/documents/reextract.php" hx-post="/documents/reextract.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><button type="submit" class="btn btn-link btn-sm p-0 fs-12" id="document-reextract-btn">read again</button></form><?php endif; ?></dd>
                    <dt class="col-4 text-muted">Your level</dt><dd class="col-8"><span class="badge bg-soft-<?= e(LEVEL_BADGE[$d['my_level']] ?? 'secondary') ?> text-<?= e(LEVEL_BADGE[$d['my_level']] ?? 'secondary') ?>" id="document-my-level"><?= e($d['my_level']) ?></span></dd>
                </dl>
                <div class="mt-2" id="document-description"><span class="text-muted">Description</span><div><?= $d['description'] !== null && $d['description'] !== '' ? nl2br(e($d['description'])) : '<span class="text-muted">—</span>' ?></div></div>
                <div class="mt-2" id="document-tags"><span class="text-muted">Tags</span><?= view('tags/partials/editor.php', ['documentId' => $id, 'tags' => $documentTags, 'may' => $may['tag'], 'datalist' => $datalist]) ?></div>
            </div></div>
            <div class="card mb-3" id="document-access"><div class="card-body fs-12"><span class="text-muted">Who can see this</span>
                <div id="document-access-line"><?= e($accessLine) ?></div>
                <?php if ($may['share']): ?><?= hx_link('/documents/' . $id . '/share', 'Who can see this and why', 'fw-semibold', 'id="document-access-link"') ?><?php else: ?><?= hx_link('/documents/' . $id . '/share', 'Why you see it', 'fw-semibold', 'id="document-access-link"') ?><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="document-related"><div class="card-body fs-12"><span class="text-muted">Related documents</span><?= view('graph/partials/related.php', ['related' => $related, 'subjects' => $graphSubjects, 'docId' => $id]) ?></div></div>
        </div>
    </div>
</div>
<script src="/assets/js/tag-editor.js"></script>
