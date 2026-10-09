<?php /** The versions screen. Data: d, versions, may, window, tz, crumbs, notice */
$id = (int) $d['document_id'];
?>
<?= view('shared/header.php', ['id' => 'document-versions', 'title' => 'Versions of ' . $d['name'], 'crumbs' => array_merge([['Home', '/']], array_map(static fn (array $c): array => [$c['name'], '/f/' . (int) $c['folder_id']], $crumbs), [[$d['name'], '/documents/' . $id], ['Versions', null]]), 'back' => ['/documents/' . $id, $d['name']]]) ?>
<div class="main-content" id="document-versions-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2" id="versions-window"><?= e($window) ?></div>
    <?= view('files/partials/versions.php', ['d' => $d, 'versions' => $versions, 'may' => $may, 'tz' => $tz, 'compact' => false]) ?>
</div>
