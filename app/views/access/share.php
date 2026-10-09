<?php /** The share screen. Data: kind, id, name, thing, manage, mine, access (null for a non-manager), summary, departments, explainFor, explainName, explainRows, tz, back, notice, adminView */
$levels = $kind === 'folder' ? ACCESS_LEVELS_FOLDER : ACCESS_LEVELS_DOCUMENT;
$shareUrl = ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/share';
$crumbs = [['Home', '/'], [$name, $back], ['Who can see this', null]];
?>
<?= view('shared/header.php', ['id' => 'share', 'title' => 'Who can see ' . $name, 'crumbs' => $crumbs, 'back' => [$back, $name]]) ?>
<div class="main-content" id="share-content" data-kind="<?= e($kind) ?>" data-id="<?= $id ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($adminView): ?><div class="fs-11 text-muted mb-2">You see this as the admin.</div><?php endif; ?>
    <div class="card mb-3" id="share-mine"><div class="card-body fs-12"><?= view('access/partials/explain.php', ['name' => 'You', 'rows' => $mine, 'self' => true]) ?></div></div>
    <?php if (!$manage): ?>
        <div class="text-muted fs-12" id="share-not-manager">Only a manager of this <?= e($kind) ?> sees and changes who else can.</div>
    <?php else: ?>
    <div class="card mb-3" id="share-base"><div class="card-body fs-12"><span class="text-muted">The library</span><div id="share-base-line"><?= e($access['base_line']) ?></div>
        <?php if ($kind === 'document' && !empty($access['own_access'])): ?><div class="mt-1 text-warning" id="share-own-access">This file no longer follows its folder's sharing (the folder's managers still manage it).</div><?php endif; ?>
        <?php if ($kind === 'folder' && $access['explicit'] === [] && $thing['parent_id'] !== null): ?><div class="mt-1 text-muted" id="share-additive-note">Sharing this folder keeps what it inherits; use Restrict to narrow it.</div><?php endif; ?>
    </div></div>
    <div class="card mb-3" id="share-rows-card">
        <div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Who can see it</h5>
            <?php if ($kind === 'folder' && $thing['parent_id'] !== null): ?>
            <form method="post" action="/access/restrict.php" hx-post="/access/restrict.php" hx-target="#flash" hx-confirm="Stop inheriting: keep only the rows you ticked (the library's own owners always keep theirs)?" class="ms-auto" id="share-restrict-form"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch" id="share-restrict-btn" title="Stop inheriting; keep only the ticked rows"><i class="feather-lock me-1"></i>Restrict</button></form>
            <?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="share-table"><thead><tr><th>Who</th><th>Level</th><th class="d-none d-md-table-cell">Granted</th><th></th></tr></thead>
            <tbody id="share-inherited">
            <?php foreach ($access['inherited'] as $r): ?>
                <?php if ($kind === 'folder' && $thing['parent_id'] !== null): ?><tr class="d-none"><td colspan="4"><input form="share-restrict-form" type="checkbox" name="keep[]" value="<?= e($r['principal_kind']) ?>:<?= e((string) ($r['principal_id'] ?? $r['principal_ref'] ?? '')) ?>:<?= e($r['level']) ?>" id="keep-<?= (int) $r['access_id'] ?>"></td></tr><?php endif; ?>
                <?= view('access/partials/row.php', ['r' => $r, 'kind' => $kind, 'inherited' => true, 'manage' => $manage, 'levels' => $levels, 'tz' => $tz, 'fromName' => $access['inherited_from_name']]) ?>
            <?php endforeach; ?>
            </tbody>
            <tbody id="share-rows">
            <?php foreach ($access['explicit'] as $r): ?>
                <?php if ($kind === 'folder' && $thing['parent_id'] !== null): ?><tr class="d-none"><td colspan="4"><input form="share-restrict-form" type="checkbox" name="keep[]" value="<?= e($r['principal_kind']) ?>:<?= e((string) ($r['principal_id'] ?? $r['principal_ref'] ?? '')) ?>:<?= e($r['level']) ?>" id="keep-<?= (int) $r['access_id'] ?>" checked></td></tr><?php endif; ?>
                <?= view('access/partials/row.php', ['r' => $r, 'kind' => $kind, 'inherited' => false, 'manage' => $manage, 'levels' => $levels, 'tz' => $tz]) ?>
            <?php endforeach; ?>
            <?php if ($access['explicit'] === [] && $access['inherited'] === []): ?><tr id="share-rows-empty"><td colspan="4" class="text-muted">No one beyond the library yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php if ($kind === 'folder' && $thing['parent_id'] !== null): ?><div class="card-body fs-11 text-muted pt-2" id="share-restrict-help">Restrict keeps the rows ticked here: <?= count($access['explicit']) ?> of this folder's own<?= $access['inherited'] !== [] ? ' (the inherited ones are dropped unless you tick them)' : '' ?>.
            <?php foreach ($access['inherited'] as $r): ?><label class="ms-2"><input type="checkbox" form="share-restrict-form" name="keep[]" value="<?= e($r['principal_kind']) ?>:<?= e((string) ($r['principal_id'] ?? $r['principal_ref'] ?? '')) ?>:<?= e($r['level']) ?>" id="keep-i-<?= (int) $r['access_id'] ?>"> keep <?= e($r['principal_name']) ?></label><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="row g-3" id="share-add">
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="share-add-person"><div class="card-header"><h5 class="card-title mb-0">A person or an agent</h5></div><div class="card-body">
                <form method="post" action="/access/share.php" hx-post="/access/share.php" hx-target="#flash" id="share-add-person-form"><?= csrf_field() ?><input type="hidden" name="<?= e($kind) ?>" value="<?= $id ?>">
                    <label class="form-label" id="share-member-label">Who</label><?= picker_field(['id' => 'share-member', 'name' => 'member', 'source' => 'member', 'required' => true, 'labelledby' => 'share-member-label']) ?>
                    <label class="form-label mt-2" for="share-member-level">Level</label><select name="level" id="share-member-level" class="form-select btn-touch"><?php foreach ($levels as $l): ?><option value="<?= e($l) ?>" <?= $l === 'view' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="share-member-btn">Share</button>
                </form></div></div>
            <div class="card mb-3" id="share-add-group"><div class="card-header"><h5 class="card-title mb-0">A group</h5></div><div class="card-body">
                <form method="post" action="/access/share-group.php" hx-post="/access/share-group.php" hx-target="#flash" id="share-add-group-form"><?= csrf_field() ?><input type="hidden" name="<?= e($kind) ?>" value="<?= $id ?>">
                    <label class="form-label" id="share-group-label">Group</label><?= picker_field(['id' => 'share-group', 'name' => 'group', 'source' => 'group', 'required' => true, 'labelledby' => 'share-group-label']) ?>
                    <label class="form-label mt-2" for="share-group-level">Level</label><select name="level" id="share-group-level" class="form-select btn-touch"><?php foreach ($levels as $l): ?><option value="<?= e($l) ?>" <?= $l === 'view' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="share-group-btn">Share</button>
                </form></div></div>
            <div class="card mb-3" id="share-add-department"><div class="card-header"><h5 class="card-title mb-0">A department</h5></div><div class="card-body">
                <form method="post" action="/access/share-department.php" hx-post="/access/share-department.php" hx-target="#flash" id="share-add-department-form"><?= csrf_field() ?><input type="hidden" name="<?= e($kind) ?>" value="<?= $id ?>">
                    <label class="form-label" for="share-department">Department</label><select name="department" id="share-department" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select>
                    <label class="form-label mt-2" for="share-department-level">Level</label><select name="level" id="share-department-level" class="form-select btn-touch"><?php foreach ($levels as $l): ?><option value="<?= e($l) ?>" <?= $l === 'view' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="share-department-btn">Share</button>
                </form></div></div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="share-add-guest"><div class="card-header"><h5 class="card-title mb-0">A guest</h5></div><div class="card-body">
                <div class="fs-11 text-muted mb-2">A guest sees only what is named to them. An agent's share to a guest waits for a person's approval.</div>
                <form method="post" action="/access/share-guest.php" hx-post="/access/share-guest.php" hx-target="#flash" id="share-add-guest-form"><?= csrf_field() ?><input type="hidden" name="<?= e($kind) ?>" value="<?= $id ?>">
                    <label class="form-label" id="share-guest-label">Guest</label><?= picker_field(['id' => 'share-guest', 'name' => 'guest', 'source' => 'guest', 'required' => true, 'labelledby' => 'share-guest-label']) ?>
                    <label class="form-label mt-2" for="share-guest-level">Level</label><select name="level" id="share-guest-level" class="form-select btn-touch"><option value="view">view</option><option value="edit">edit</option></select>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="share-guest-btn">Share</button>
                </form></div></div>
            <?php if ($kind === 'folder'): ?>
            <div class="card mb-3" id="share-add-application"><div class="card-header"><h5 class="card-title mb-0">An application</h5></div><div class="card-body">
                <div class="fs-11 text-muted mb-2">A sibling application reads this folder through the kernel (view only); the kernel must also approve a connection.</div>
                <form method="post" action="/access/share-application.php" hx-post="/access/share-application.php" hx-target="#flash" id="share-add-application-form"><?= csrf_field() ?><input type="hidden" name="folder" value="<?= $id ?>">
                    <label class="form-label" for="share-application">Application</label><input type="text" name="application" id="share-application" class="form-control btn-touch" list="share-application-list" placeholder="knowledge" required pattern="[a-z][a-z0-9_]{0,39}">
                    <datalist id="share-application-list"><?php foreach (KNOWN_APPLICATIONS as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></datalist>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="share-application-btn">Share</button>
                </form></div></div>
            <?php endif; ?>
            <div class="card mb-3" id="share-explain"><div class="card-header"><h5 class="card-title mb-0">Who can see this, and why</h5></div><div class="card-body">
                <form method="get" action="<?= e($shareUrl) ?>" hx-get="<?= e($shareUrl) ?>" hx-target="#page-content" hx-push-url="true" id="share-explain-form">
                    <label class="form-label" id="share-explain-label">Check a person</label><?= picker_field(['id' => 'share-explain-member', 'name' => 'member', 'source' => 'member', 'required' => true, 'labelledby' => 'share-explain-label', 'value' => $explainFor]) ?>
                    <button type="submit" class="btn btn-light btn-touch mt-2" id="share-explain-btn">Explain</button>
                </form>
                <?php if ($explainRows !== null): ?><div class="mt-3"><?= view('access/partials/explain.php', ['name' => $explainName ?? '', 'rows' => $explainRows, 'self' => false]) ?></div><?php endif; ?>
            </div></div>
            <div class="card mb-3" id="share-links-summary"><div class="card-body fs-12 d-flex flex-wrap gap-3">
                <span><i class="feather-link me-1"></i><?= (int) $summary['links'] ?> live link<?= $summary['links'] === 1 ? '' : 's' ?></span>
                <?php if ($kind === 'folder'): ?><span><i class="feather-inbox me-1"></i><?= (int) $summary['requests'] ?> open file request<?= $summary['requests'] === 1 ? '' : 's' ?></span><?php endif; ?>
                <?= hx_link(($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/links', 'Links' . ($kind === 'folder' ? ' and requests' : ''), 'fw-semibold ms-auto', 'id="share-links-link"') ?>
                <?= hx_link(($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/history', 'History', 'fw-semibold', 'id="share-history-link"') ?>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
