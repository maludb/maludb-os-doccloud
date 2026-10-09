<?php /** Why someone sees a thing: the sentences. Data: name, rows (access_explain), self */ ?>
<div id="explain-result" class="fs-12">
    <div class="fw-semibold mb-1"><?= $self ? 'You' : e($name) ?> <?= $rows === [] ? 'cannot see this.' : 'can see this because:' ?></div>
    <?php if ($rows !== []): ?><ul class="mb-0 ps-3"><?php foreach ($rows as $r): ?><li id="explain-<?= e($r['reason']) ?>-<?= e((string) ($r['via_id'] ?? 0)) ?>"><?= e($r['sentence']) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
