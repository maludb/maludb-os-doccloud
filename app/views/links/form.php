<?php /** The link form (`link-add`; also a request's, `request-add`, and a link's edit). Data: kind, id, name, link (null for a new one), allowNever, defaultDays, request (bool) */
$edit = $link !== null;
$action = $request ? '/requests/make.php' : ($edit ? '/links/update.php' : '/links/make.php');
$back = $edit || $request ? (($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/links') : (($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/links');
$title = $request ? 'Request files into ' . $name : ($edit ? 'Edit the link ' . $link['label'] : 'A link to ' . $name);
$days = $edit ? ($link['expires_at'] === null ? 0 : max(1, (int) ceil((strtotime((string) $link['expires_at']) - time()) / 86400))) : $defaultDays;
?>
<?= view('shared/header.php', ['id' => 'link-form', 'title' => $title, 'crumbs' => [['Home', '/'], [$name, ($kind === 'folder' ? '/f/' : '/documents/') . $id], ['Links', $back], [$request ? 'Request' : ($edit ? 'Edit' : 'New'), null]], 'back' => [$back, 'links']]) ?>
<div class="main-content" id="link-form-content">
    <form method="post" action="<?= e($action) ?>" hx-post="<?= e($action) ?>" hx-target="#flash" class="card" id="link-form"><?= csrf_field() ?>
        <?php if ($edit): ?><input type="hidden" name="link" value="<?= (int) $link['id'] ?>"><?php else: ?><input type="hidden" name="<?= e($kind) ?>" value="<?= $id ?>"><?php endif; ?>
        <input type="hidden" name="return_to" value="<?= e($back) ?>">
        <div class="card-body">
            <div class="mb-3"><label class="form-label" for="link-label">Label <span class="text-muted">(what the log and the Clerk call it)</span></label><input type="text" name="label" id="link-label" class="form-control btn-touch" maxlength="120" required value="<?= e($link['label'] ?? '') ?>" placeholder="<?= $request ? 'Supplier invoices' : 'For the auditor' ?>"></div>
            <div class="mb-3"><label class="form-label" for="link-expires">Expires in</label>
                <select name="expires_in_days" id="link-expires" class="form-select btn-touch">
                    <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => 'a year'] as $d => $w): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?>
                    <?php if ($allowNever): ?><option value="0" <?= $days === 0 ? 'selected' : '' ?>>never</option><?php endif; ?>
                </select>
                <?php if (!$allowNever): ?><div class="fs-11 text-muted mt-1">A link that never expires is not allowed here.</div><?php endif; ?></div>
            <?php if (!$request): ?>
            <div class="mb-3 form-check"><input type="checkbox" name="allow_download" id="link-download" class="form-check-input" value="yes" <?= $edit ? ($link['allow_download'] ? 'checked' : '') : 'checked' ?>><input type="hidden" name="allow_download_sent" value="1"><label class="form-check-label" for="link-download">Allow download (else view only)</label></div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label" for="link-password">Password <span class="text-muted">(optional<?= $edit ? '; blank keeps it, the word none removes it' : '' ?>)</span></label><input type="password" name="password" id="link-password" class="form-control btn-touch" autocomplete="new-password"></div>
            <div class="mb-3"><label class="form-label" for="link-note"><?= $request ? 'Message to the uploader' : 'Note shown to the holder' ?> <span class="text-muted">(optional)</span></label><textarea name="<?= $request ? 'message' : 'note' ?>" id="link-note" class="form-control" rows="3" maxlength="1000"><?= e($link['note'] ?? '') ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="link-form-submit"><?= $request ? 'Make the request' : ($edit ? 'Save' : 'Make the link') ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch') ?></div>
    </form>
</div>
