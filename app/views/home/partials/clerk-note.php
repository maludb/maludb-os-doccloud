<?php /** The Clerk's last Monday note and my open proposals (home). Data: note (a monday_note row or null), open (int), tz */ ?>
<?php if ($note !== null || $open > 0): ?>
<div class="card mb-3" id="home-clerk"><div class="card-body d-flex flex-wrap gap-3 align-items-start">
    <span class="avatar-text avatar-md rounded bg-soft-info text-info"><i class="feather-inbox"></i></span>
    <div class="flex-grow-1 min-w-0">
        <?php if ($note !== null): ?><div class="fw-semibold" id="home-clerk-title"><?= e($note['title']) ?></div>
            <div class="fs-12 text-muted" id="home-clerk-when">The Filing Clerk · <?= e(format_ts($note['created_at'], $tz, 'M j')) ?></div><?php endif; ?>
        <?php if ($open > 0): ?><div class="fs-12 mt-1" id="home-clerk-open"><?= (int) $open ?> proposal<?= $open === 1 ? '' : 's' ?> waiting for you.</div><?php endif; ?>
    </div>
    <?= hx_link('/curate/proposals', $open > 0 ? 'Decide' : 'Proposals', 'btn btn-light btn-touch', 'id="home-clerk-btn"') ?>
</div></div>
<?php endif; ?>
