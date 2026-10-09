<?php /** The lock state and its buttons. Data: d, may (lock, unlock, break), tz, lockedByOther */
$id = (int) $d['document_id'];
$locked = $d['locked_by'] !== null;
?>
<div class="d-flex flex-wrap align-items-center gap-2 fs-12" id="lock-state" data-locked="<?= $locked ? '1' : '0' ?>">
    <?php if ($locked): ?>
        <span class="badge bg-soft-warning text-warning"><i class="feather-lock me-1"></i>Locked by <?= e($d['locked_by_name'] ?? 'someone') ?> until <?= e(format_ts($d['lock_expires_at'], $tz)) ?></span>
        <?php if ($may['unlock']): ?><form method="post" action="/documents/unlock.php" hx-post="/documents/unlock.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light btn-touch" id="document-unlock-btn"><i class="feather-unlock me-1"></i>Unlock</button></form><?php endif; ?>
        <?php if ($may['break']): ?><form method="post" action="/documents/lock-break.php" hx-post="/documents/lock-break.php" hx-target="#flash" hx-confirm="Break the lock? Its holder will be told." class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light btn-touch text-danger" id="document-lock-break-btn"><i class="feather-unlock me-1"></i>Break the lock</button></form><?php endif; ?>
    <?php elseif ($may['lock']): ?>
        <form method="post" action="/documents/lock.php" hx-post="/documents/lock.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light btn-touch" id="document-lock-btn" title="Lock it while you work on it"><i class="feather-lock me-1"></i>Lock</button></form>
    <?php endif; ?>
</div>
