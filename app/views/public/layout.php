<?php /** The bare public page (the link and request doors): the design-system assets, the business name only, no navigation, no other names, noindex. Data: title, content */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <meta name="referrer" content="no-referrer" />
    <meta name="csrf-token" id="csrf-token-meta" content="<?= e(csrf_token()) ?>" />
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png" />
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css" />
    <style>body.public-page{background:#f5f6fa}.public-wrap{max-width:960px;margin:0 auto;padding:16px}.public-brand{font-weight:600}</style>
</head>
<body class="public-page">
    <div class="public-wrap" id="public-page">
        <div class="d-flex align-items-center gap-2 mb-3" id="public-brand"><img src="/assets/images/logo-abbr.png" alt="" style="height:28px"><span class="public-brand"><?= e(app_name()) ?></span></div>
        <?= $content ?>
        <div class="fs-11 text-muted mt-4" id="public-foot">A link shared from <?= e(app_name()) ?>.</div>
    </div>
</body>
</html>
