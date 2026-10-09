<?php /** The URL shown ONCE after a make or a rotate. Data: link, once (the raw token or null when already shown) */
$isRequest = $link['kind'] === 'request';
?>
<div class="card mb-3 border-primary" id="link-url-once" data-link="<?= (int) $link['link_id'] ?>"><div class="card-body">
    <div class="fw-semibold mb-1"><?= $isRequest ? 'The request\'s link' : 'The link' ?> <?= e($link['label']) ?><?= $link['expires_at'] === null ? ' (never expires)' : ' (expires ' . e(format_ts($link['expires_at'], member_timezone(), 'M j, Y')) . ')' ?></div>
    <?php if ($once !== null): ?>
        <div class="fs-12 text-muted mb-2">Copy it now: it is shown once. Rotate the link to get a new one later.</div>
        <div class="input-group"><input type="text" class="form-control btn-touch font-monospace" id="link-url-input" value="<?= e(link_public_url($link, $once)) ?>" readonly onclick="this.select()"><button type="button" class="btn btn-primary btn-touch" id="link-url-copy" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('link-url-input').value); this.textContent='Copied'">Copy</button></div>
    <?php else: ?>
        <div class="fs-12 text-muted" id="link-url-gone">The URL was shown once already. Rotate the link to get a new one.</div>
    <?php endif; ?>
</div></div>
