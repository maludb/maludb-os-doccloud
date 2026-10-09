<?php /** After an upload: thank you, nothing shown back. Data: link, base */ ?>
<div class="card" id="public-thanks"><div class="card-body text-center py-5">
    <span class="avatar-text avatar-lg rounded mb-2 bg-soft-success text-success"><i class="feather-check"></i></span>
    <h5>Thank you</h5>
    <div class="text-muted fs-12">Your files were sent to <?= e($link['made_by_name']) ?>.</div>
    <a href="<?= e($base) ?>" class="btn btn-light btn-touch mt-3" id="public-thanks-more">Send more</a>
</div></div>
