<?php /** The banner of a document in the trash. Data: d (trashed_at, trashed_from_folder_id…), id, may (manage), tz, window (days) */
$purgeAt = date(DATE_ATOM, strtotime((string) $d['trashed_at']) + $window * 86400);
[$badge, $words] = trash_status($purgeAt);
?>
<div class="alert alert-warning fs-12 d-flex flex-wrap align-items-center gap-2" id="document-trashed">
    <span id="document-trash-note">In the trash — purges on <?= e(format_ts($purgeAt, $tz, 'M j, Y')) ?><?= $words !== '' ? ' (' . e($words) . ')' : '' ?>.</span>
    <?php if ($may['manage'] && has_right('trash.own')): ?>
    <form method="post" action="/trash/restore.php" hx-post="/trash/restore.php" hx-target="#flash" class="d-inline ms-auto"><?= csrf_field() ?><input type="hidden" name="document" value="<?= (int) $id ?>">
        <button type="submit" class="btn btn-light btn-touch" id="document-restore-btn"><i class="feather-rotate-ccw me-1"></i>Restore</button></form>
    <?php endif; ?>
</div>
