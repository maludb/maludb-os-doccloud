<?php
/**
 * The document preview by kind. Data: d, preview (document_preview), extraction (the sentence), mayHide (the "Hide preview" switch — the document
 * page for a caller who may edit an image or a video; never on the public link page). A hidden preview (docs/build-specs/grid-and-media.md) shows
 * the placeholder and Show: the preview waits in a <template>, so nothing of it loads until asked, and Show reveals it for this one view.
 * A video: playsinline, the poster from the thumbnail, a MOV offered as MP4; a format no browser plays, or one this browser rejects, says so with Download.
 */
$id = (int) $d['document_id'];
$mode = $preview['mode'];
$hidden = !empty($preview['hidden']);
$mayHide = !empty($mayHide);
ob_start();
?>
    <?php if ($mode === 'image'): ?>
        <a href="/d/<?= $id ?>" target="_blank" rel="noopener" id="document-preview-image-link"><img src="/d/<?= $id ?>" alt="<?= e($d['name']) ?>" class="img-fluid rounded d-block mx-auto" style="max-height:70vh" id="document-preview-image"></a>
    <?php elseif ($mode === 'video' && empty($preview['playable'])): ?>
        <div class="empty-state text-center py-4" id="document-preview-unplayable">
            <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-film"></i></span>
            <div class="fw-semibold">This format can't play in the browser</div>
            <div class="fs-12 text-muted mb-2"><?= e($d['mime_type']) ?> — download it to watch it in a video player.</div>
            <a href="/d/<?= $id ?>?download=1" class="btn btn-primary btn-touch" id="document-preview-unplayable-download"><i class="feather-download me-1"></i>Download</a>
        </div>
    <?php elseif ($mode === 'video'): ?>
        <video controls playsinline preload="metadata" class="w-100 rounded bg-dark d-block" style="max-height:70vh" id="document-preview-video"<?= !empty($preview['poster']) ? ' poster="/d/' . $id . '/thumb?v=' . e((string) strtotime((string) $d['updated_at'])) . '"' : '' ?>><source src="/d/<?= $id ?>"<?= !empty($preview['source_type']) ? ' type="' . e($preview['source_type']) . '"' : '' ?>></video>
        <div class="empty-state text-center py-4 d-none" id="document-preview-video-fallback">
            <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-film"></i></span>
            <div class="fw-semibold">This video can't play in this browser</div>
            <div class="fs-12 text-muted mb-2"><?= e($d['mime_type']) ?> — download it to watch it in a video player.</div>
            <a href="/d/<?= $id ?>?download=1" class="btn btn-primary btn-touch" id="document-preview-video-download"><i class="feather-download me-1"></i>Download</a>
        </div>
    <?php elseif ($mode === 'pdf'): ?>
        <iframe src="/d/<?= $id ?>#toolbar=0" title="<?= e($d['name']) ?>" class="w-100 border-0 rounded" style="height:70vh;min-height:360px" id="document-preview-pdf" sandbox="allow-same-origin allow-scripts"></iframe>
    <?php elseif ($mode === 'audio'): ?>
        <audio controls preload="none" class="w-100" id="document-preview-audio"><source src="/d/<?= $id ?>" type="<?= e($d['mime_type']) ?>"></audio>
    <?php elseif ($mode === 'markdown'): ?>
        <div class="p-2 markdown-preview" id="document-preview-markdown"><?= dc_markdown($preview['text']) ?></div>
    <?php elseif ($mode === 'text'): ?>
        <pre class="mb-0 p-2 fs-12 text-wrap" style="white-space:pre-wrap;max-height:70vh;overflow:auto" id="document-preview-text"><?= e($preview['text']) ?></pre>
    <?php elseif ($mode === 'csv'): ?>
        <div class="table-responsive" style="max-height:70vh"><table class="table table-sm table-striped fs-12 mb-0" id="document-preview-csv"><tbody>
        <?php foreach ($preview['rows'] as $i => $row): ?><tr><?php foreach ($row as $cell): ?><?= $i === 0 ? '<th>' . e((string) $cell) . '</th>' : '<td>' . e((string) $cell) . '</td>' ?><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (!empty($preview['truncated'])): ?><div class="fs-11 text-muted p-2">The first 200 rows; download for the rest.</div><?php endif; ?>
    <?php elseif ($mode === 'extracted'): ?>
        <div class="d-flex justify-content-between align-items-center px-2 pt-1 fs-11 text-muted"><span>The text we read from it<?= !empty($preview['truncated']) ? ' (the first 20,000 characters)' : '' ?></span><a href="/d/<?= $id ?>?download=1" class="fw-semibold" id="document-preview-download-link">Download to open</a></div>
        <pre class="mb-0 p-2 fs-12 text-wrap" style="white-space:pre-wrap;max-height:60vh;overflow:auto" id="document-preview-extracted"><?= e($preview['text']) ?></pre>
    <?php else: ?>
        <div class="empty-state text-center py-4" id="document-preview-none">
            <span class="avatar-text avatar-lg rounded mb-2"><i class="<?= e(kind_icon($d['mime_kind'])) ?>"></i></span>
            <div class="fw-semibold"><?= e($d['name']) ?></div>
            <div class="fs-12 text-muted mb-2"><?= e($extraction) ?></div>
            <a href="/d/<?= $id ?>?download=1" class="btn btn-primary btn-touch" id="document-preview-download-btn"><i class="feather-download me-1"></i>Download to open</a>
        </div>
    <?php endif; ?>
<?php $media = (string) ob_get_clean(); ?>
<div class="card mb-3" id="document-preview" data-mode="<?= e($mode) ?>"<?= $hidden ? ' data-hidden="1"' : '' ?>>
    <div class="card-body p-2">
    <?php if ($hidden): ?>
        <div class="preview-hidden text-center" id="document-preview-hidden">
            <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-eye-off"></i></span>
            <div class="fw-semibold">Preview hidden</div>
            <div class="fs-12 text-muted mb-2">This <?= $mode === 'video' ? 'video' : 'image' ?> shows a placeholder to everyone<?= $mayHide ? ' until you turn hiding off' : '' ?>. Show it here, just this once.</div>
            <button type="button" class="btn btn-light btn-touch" id="document-preview-show-btn"><i class="feather-eye me-1"></i>Show</button>
        </div>
        <template id="document-preview-template"><?= $media ?></template>
    <?php else: ?>
        <?= $media ?>
    <?php endif; ?>
    </div>
    <?php if ($mayHide): ?>
    <div class="card-footer py-2 px-3">
        <form method="post" action="/documents/preview-<?= $hidden ? 'show' : 'hide' ?>.php" hx-post="/documents/preview-<?= $hidden ? 'show' : 'hide' ?>.php" hx-target="#flash" hx-trigger="change" hx-on::response-error="this.reset()" class="mb-0" id="document-preview-switch-form"><?= csrf_field() ?>
            <input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="return_to" value="/documents/<?= $id ?>">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="document-preview-switch"<?= $hidden ? ' checked' : '' ?>>
                <label class="form-check-label fs-12" for="document-preview-switch">Hide preview <span class="text-muted">— everyone sees a placeholder</span></label>
            </div>
            <noscript><button type="submit" class="btn btn-sm btn-light mt-1"><?= $hidden ? 'Show the preview' : 'Hide the preview' ?></button></noscript>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php if ($hidden || $mode === 'video'): ?>
<script>
(function () {
    var video = function () {
        var v = document.getElementById('document-preview-video'); if (!v || v.dataset.watched) return; v.dataset.watched = '1';
        var fb = document.getElementById('document-preview-video-fallback');
        var fail = function () { v.classList.add('d-none'); if (fb) fb.classList.remove('d-none'); };
        var src = v.querySelector('source'); if (src) src.addEventListener('error', fail);
        v.addEventListener('error', fail);
        if (v.error || v.networkState === 3) fail();   // already failed before this ran (NETWORK_NO_SOURCE), e.g. after an HTMX swap
    };
    var show = document.getElementById('document-preview-show-btn');
    if (show) show.addEventListener('click', function () {
        var t = document.getElementById('document-preview-template'), h = document.getElementById('document-preview-hidden');
        if (t && h) { h.replaceWith(t.content.cloneNode(true)); t.remove(); video(); }
    });
    video();
})();
</script>
<?php endif; ?>
