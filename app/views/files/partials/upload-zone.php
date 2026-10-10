<?php /** The drop zone and the per-file progress list (assets/js/dc-upload.js). Data: folderId, documentId?, settings (part_bytes, max_document_bytes — null = no ceiling), open (bool: shown at once), doneUrl */
$documentId = $documentId ?? null;
$open = $open ?? true;
?>
<div id="upload-zone-wrap" class="<?= $open ? '' : 'd-none' ?> mb-3" data-folder="<?= (int) $folderId ?>" data-document="<?= $documentId === null ? '' : (int) $documentId ?>" data-part-bytes="<?= (int) $settings['part_bytes'] ?>" data-max-bytes="<?= (int) ($settings['max_document_bytes'] ?? 0) ?>" data-done-url="<?= e($doneUrl) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div id="drop-zone" class="card border-dashed text-center p-4 mb-2" tabindex="0" role="button" aria-label="Drop files or folders here, or choose">
        <i class="feather-upload-cloud fs-1 text-muted"></i>
        <div class="fw-semibold mt-1 d-none d-md-block"><?= $documentId === null ? 'Drop files or folders here, or choose' : 'Drop the new version here, or choose' ?></div>
        <div class="mt-2 d-flex gap-2 justify-content-center flex-wrap">
            <label class="btn btn-primary btn-touch mb-0" for="upload-files-input" id="upload-choose-btn"><i class="feather-file-plus me-1"></i><?= $documentId === null ? 'Choose files' : 'Choose the file' ?></label>
            <?php if ($documentId === null): ?><label class="btn btn-light btn-touch mb-0 d-none d-md-inline-block" for="upload-folder-input" id="upload-choose-folder-btn"><i class="feather-folder-plus me-1"></i>Choose a folder</label><?php endif; ?>
        </div>
        <input type="file" id="upload-files-input" class="d-none" <?= $documentId === null ? 'multiple' : '' ?>>
        <input type="file" id="upload-folder-input" class="d-none" webkitdirectory multiple>
        <div class="fs-11 text-muted mt-2" id="upload-zone-hint"><?= ($settings['max_document_bytes'] ?? null) === null ? 'Files of any size.' : 'Up to ' . e(format_bytes((int) $settings['max_document_bytes'])) . ' a file.' ?> Large files go up in parts; if the connection drops or the page closes, choose the same file again to carry on.</div>
    </div>
    <div id="upload-list" class="list-group mb-2" aria-live="polite"></div>
    <div class="d-flex gap-2"><?= hx_link($doneUrl, 'Done', 'btn btn-light btn-touch', 'id="upload-done-btn"') ?><span class="fs-12 text-muted align-self-center" id="upload-summary"></span></div>
    <noscript>
        <form method="post" action="/documents/upload.php" enctype="multipart/form-data" class="card card-body mt-2"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= (int) $folderId ?>"><?php if ($documentId !== null): ?><input type="hidden" name="document" value="<?= (int) $documentId ?>"><?php endif; ?>
            <label class="form-label" for="upload-noscript-file">A file (one at a time, up to 64 MB)</label><input type="file" name="file" id="upload-noscript-file" class="form-control btn-touch" required>
            <label class="form-label mt-2" for="upload-noscript-conflict">If a document of that name is here</label><select name="on_conflict" id="upload-noscript-conflict" class="form-select btn-touch"><option value="version">Add it as a new version</option><option value="keep_both">Keep both</option><option value="refuse">Refuse</option></select>
            <button type="submit" class="btn btn-primary btn-touch mt-2">Upload</button></form>
    </noscript>
</div>
