<?php /** The same page for every reason a link does not open. Data: title?, message? */
$title = $title ?? 'This link is not available';
$message = $message ?? 'It may have expired, been revoked, or never existed. Ask the person who shared it for a new one.';
?>
<div class="card" id="public-unavailable"><div class="card-body text-center py-5">
    <span class="avatar-text avatar-lg rounded mb-2"><i class="feather-link-2"></i></span>
    <h5 id="public-unavailable-title"><?= e($title) ?></h5>
    <div class="text-muted fs-12"><?= e($message) ?></div>
</div></div>
