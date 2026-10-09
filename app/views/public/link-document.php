<?php /** A document behind a link: name, size, kind, shared by, the note, the preview, Download when allowed. Data: link, doc, preview, base, back */
$id = (int) $doc['id'];
$d = $doc + ['document_id' => $id];
$allow = (bool) $link['allow_download'];
?>
<?php if ($back !== null): ?><div class="mb-2"><a href="<?= e($back) ?>" class="fs-12 fw-semibold" id="public-back"><i class="feather-arrow-left me-1"></i>Back to the folder</a></div><?php endif; ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="public-document-header">
    <div class="min-w-0 flex-grow-1">
        <h5 class="mb-0 text-truncate d-flex align-items-center gap-2"><i class="<?= e(kind_icon($doc['mime_kind'])) ?> text-muted"></i><span id="public-document-name"><?= e($doc['name']) ?></span></h5>
        <div class="fs-12 text-muted" id="public-document-facts"><?= e(format_bytes((int) $doc['byte_size'])) ?> · <?= e($doc['mime_kind']) ?> · shared by <?= e($link['made_by_name']) ?></div>
    </div>
    <?php if ($allow): ?><a href="/d/<?= $id ?>?download=1" class="btn btn-primary btn-touch" id="public-download-btn"><i class="feather-download me-1"></i>Download</a>
    <?php else: ?><span class="badge bg-soft-secondary text-secondary" id="public-no-download">View only</span><?php endif; ?>
</div>
<?php if (!empty($link['note'])): ?><div class="alert alert-light fs-12" id="public-note"><?= nl2br(e($link['note'])) ?></div><?php endif; ?>
<?php if ($preview['mode'] === 'none' && !$allow): ?>
    <div class="card"><div class="card-body text-center py-5 text-muted fs-12" id="public-preview-none">This file has no preview, and the link does not download it.</div></div>
<?php elseif ($preview['mode'] === 'extracted' || ($preview['mode'] === 'none')): ?>
    <?php if ($preview['mode'] === 'extracted'): ?>
    <div class="card mb-3" id="document-preview" data-mode="extracted"><div class="card-body p-2"><div class="fs-11 text-muted px-2 pt-1">The text we read from it</div><pre class="mb-0 p-2 fs-12" style="white-space:pre-wrap;max-height:60vh;overflow:auto"><?= e($preview['text']) ?></pre></div></div>
    <?php else: ?>
    <div class="card mb-3" id="document-preview" data-mode="none"><div class="card-body text-center py-4"><span class="avatar-text avatar-lg rounded mb-2"><i class="<?= e(kind_icon($doc['mime_kind'])) ?>"></i></span><div class="fw-semibold"><?= e($doc['name']) ?></div><div class="fs-12 text-muted">No preview for this kind.</div></div></div>
    <?php endif; ?>
<?php else: ?>
    <?= view('files/partials/preview.php', ['d' => $d, 'preview' => $preview, 'extraction' => '']) ?>
<?php endif; ?>
