<?php /** The upload screen (`upload`). Data: folder, doc (null or the document a version is for), settings, crumbs, sessions, tz, standalone */
$id = (int) $folder['id'];
?>
<?= view('shared/header.php', ['id' => 'upload', 'title' => $doc === null ? 'Upload to ' . $folder['name'] : 'A new version of ' . $doc['name'], 'crumbs' => array_merge([['Home', '/']], array_map(static fn (array $c): array => [$c['name'], '/f/' . (int) $c['folder_id']], $crumbs), [['Upload', null]]),
    'back' => [$doc === null ? '/f/' . $id : '/documents/' . (int) $doc['id'], $doc === null ? $folder['name'] : $doc['name']]]) ?>
<div class="main-content" id="upload-content">
    <?= view('files/partials/upload-zone.php', ['folderId' => $id, 'documentId' => $doc === null ? null : (int) $doc['id'], 'settings' => $settings, 'open' => true, 'doneUrl' => $doc === null ? '/f/' . $id : '/documents/' . (int) $doc['id']]) ?>
    <?php $open = array_values(array_filter($sessions, static fn (array $s): bool => in_array($s['status'], ['open', 'assembling'], true))); if ($open !== []): ?>
    <div class="card" id="upload-open-sessions"><div class="card-header"><h5 class="card-title mb-0">Still arriving</h5></div><div class="list-group list-group-flush">
        <?php foreach ($open as $s): ?><div class="list-group-item fs-12 d-flex justify-content-between"><span><?= e($s['filename']) ?> · <?= (int) $s['parts_done'] ?>/<?= (int) $s['parts_total'] ?> parts</span><span class="text-muted">expires <?= e(format_ts($s['expires_at'], $tz)) ?></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
<script src="/assets/js/dc-upload.js"></script>
<script>if (window.DcUpload) { DcUpload.mount(document.getElementById('upload-zone-wrap')); }</script>
