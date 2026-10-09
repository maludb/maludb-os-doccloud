<?php /** My API keys (the keys part of screen `settings-tokens`, /settings/keys/). Data: keys, once, defaults, tz */ ?>
<?= view('shared/header.php', ['id' => 'keys', 'title' => 'API keys', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['Tokens', '/settings/tokens/'], ['API keys', null]]]) ?>
<div class="main-content" id="keys-content">
    <?= view('shared/notice.php', ['notice' => $notice ?? null]) ?>
    <?= view('settings/partials/keys.php', ['keys' => $keys, 'once' => $once, 'defaults' => $defaults, 'tz' => $tz]) ?>
</div>
