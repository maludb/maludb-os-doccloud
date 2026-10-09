<?php /** Favourites (screen `favourites`): folders then documents I starred. Data: rows, tz, stars, notice */ ?>
<?= view('shared/header.php', ['id' => 'favourites', 'title' => 'Favourites', 'crumbs' => [['Home', '/'], ['Favourites', null]]]) ?>
<div class="main-content" id="favourites-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('files/partials/listing.php', ['rows' => $rows, 'tz' => $tz, 'select' => false, 'stars' => $stars, 'back' => '/favourites', 'id' => 'listing-table', 'empty' => 'Star a folder or a document and it appears here.',
        'extra' => static function (array $r): string {
            $k = $r['kind'] === 'folder' ? 'folder' : 'document';
            return '<form method="post" action="/favourites/remove.php" hx-post="/favourites/remove.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="' . $k . '" value="' . (int) $r['id'] . '"><input type="hidden" name="return_to" value="/favourites">'
                . '<button type="submit" class="btn btn-light btn-sm" id="favourite-remove-' . $k . '-' . (int) $r['id'] . '" aria-label="Remove from favourites"><i class="feather-x"></i></button></form>';
        }]) ?>
</div>
