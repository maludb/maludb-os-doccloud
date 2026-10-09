<?php /** The document preview by kind. Data: d, preview (document_preview), extraction (the sentence) */
$id = (int) $d['document_id'];
$mode = $preview['mode'];
?>
<div class="card mb-3" id="document-preview" data-mode="<?= e($mode) ?>">
    <div class="card-body p-2">
    <?php if ($mode === 'image'): ?>
        <a href="/d/<?= $id ?>" target="_blank" rel="noopener" id="document-preview-image-link"><img src="/d/<?= $id ?>" alt="<?= e($d['name']) ?>" class="img-fluid rounded d-block mx-auto" style="max-height:70vh" id="document-preview-image"></a>
    <?php elseif ($mode === 'pdf'): ?>
        <iframe src="/d/<?= $id ?>#toolbar=0" title="<?= e($d['name']) ?>" class="w-100 border-0 rounded" style="height:70vh;min-height:360px" id="document-preview-pdf" sandbox="allow-same-origin allow-scripts"></iframe>
    <?php elseif ($mode === 'audio'): ?>
        <audio controls preload="none" class="w-100" id="document-preview-audio"><source src="/d/<?= $id ?>" type="<?= e($d['mime_type']) ?>"></audio>
    <?php elseif ($mode === 'video'): ?>
        <video controls preload="metadata" class="w-100 rounded" style="max-height:70vh" id="document-preview-video"><source src="/d/<?= $id ?>" type="<?= e($d['mime_type']) ?>"></video>
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
    </div>
</div>
