<?php /** The phone's tab bar (hidden from 992 px, where the sidebar is): Home, Channels, Pages, Search, Me. Data: activeNav */ ?>
<nav class="app-tabbar d-lg-none" id="app-tabbar" aria-label="Main">
    <?php foreach (nav_tabs() as [$tid, $turl, $ticon, $tlabel, $tright]): ?>
        <?php if ($tright !== null && !nav_has_right($tright)) { continue; } ?>
        <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab<?= ($activeNav ?? '') === $tid ? ' active' : '' ?>"
           hx-get="<?= e($turl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($turl) ?>">
            <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
