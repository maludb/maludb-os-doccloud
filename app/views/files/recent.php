<?php /** Recent (screen `recent`): what I opened or changed lately. Data: rows, tz, stars */ ?>
<?= view('shared/header.php', ['id' => 'recent', 'title' => 'Recent', 'crumbs' => [['Home', '/'], ['Recent', null]]]) ?>
<div class="main-content" id="recent-content">
    <?= view('files/partials/listing.php', ['rows' => $rows, 'tz' => $tz, 'select' => false, 'stars' => $stars, 'back' => '/recent', 'id' => 'listing-table', 'empty' => 'Nothing opened or changed lately.',
        'extraHead' => 'Opened', 'extra' => static fn (array $r): string => '<span class="text-muted">' . e(format_ts($r['last_opened_at'] ?? null, $tz, 'M j') ?: '—') . '</span>']) ?>
</div>
