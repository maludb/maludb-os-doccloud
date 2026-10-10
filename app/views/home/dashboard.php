<?php /** Home (screen `home`, design §9), each role's own (slice 9). Data: s (home_summary: libraries, recent, shared_count, shared_recent, first_run, personal_root, guest, member, curator, admin, may), tz.
 * A new Member sees ONE button; a guest their Shared with me; everyone else their libraries as cards, their recent documents and the blocks of home/partials/{member,curator,admin}.php for the roles they hold. */
$may = $s['may'];
$libraries = $s['libraries'];
$kindBadge = ['personal' => 'light text-dark', 'group' => 'info text-info', 'shared' => 'secondary text-secondary'];
$fmtBytes = static function (?int $b): string { if ($b === null) { return ''; } $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $v = (float) $b; while ($v >= 1024 && $i < 4) { $v /= 1024; $i++; } return ($i === 0 ? (string) $b : number_format($v, 1)) . ' ' . $u[$i]; };
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
<?php if ($s['guest']): ?>
    <div class="card" id="home-guest"><div class="card-body">
        <div class="empty-state text-center py-4" id="home-empty">
            <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-users"></i></span>
            <div class="fw-semibold mb-1">Shared with you</div>
            <?php if ($s['shared_count'] > 0): ?>
                <div class="fs-12 text-muted mb-3" id="home-shared-count"><?= (int) $s['shared_count'] ?> thing<?= $s['shared_count'] === 1 ? '' : 's' ?> shared with you.</div>
                <?= hx_link('/shared/', 'Open what is shared with you', 'btn btn-primary btn-touch', 'id="home-shared-btn"') ?>
            <?php else: ?>
                <div class="fs-12 text-muted" id="home-nothing-shared">Nothing has been shared with you yet. When someone shares a folder or a document, it appears here.</div>
            <?php endif; ?>
        </div>
    </div></div>
    <?= view('home/partials/guest.php', ['s' => $s, 'tz' => $tz]) ?>
<?php elseif ($s['first_run']): ?>
    <div class="card" id="home-first-run"><div class="card-body">
        <div class="empty-state text-center py-4" id="home-empty">
            <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-upload-cloud"></i></span>
            <div class="fw-semibold mb-1">Your files, in one place</div>
            <div class="fs-12 text-muted mb-3">Drop files into your own library, share a folder with a group, find anything by name, tag or the words inside it.</div>
            <?php if ($may['upload'] && $s['personal_root'] !== null): $u = '/f/' . (int) $s['personal_root'] . '?upload=1'; ?>
                <a href="<?= e($u) ?>" class="btn btn-primary btn-touch" id="home-upload-first-btn" hx-get="<?= e($u) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($u) ?>"><i class="feather-upload me-1"></i>Upload your first file</a>
            <?php endif; ?>
            <?php if ($may['curate']): ?>
                <div class="mt-2"><?= hx_link('/groups/new', 'Create a group', 'btn btn-light btn-touch', 'id="home-create-group-btn"') ?></div>
            <?php endif; ?>
        </div>
    </div></div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" id="home-libraries-title">Your libraries</h5>
        <?php if ($may['curate']): ?><?= hx_link('/groups/new', '<i class="feather-plus me-1"></i>Create a group', 'btn btn-light btn-touch', 'id="home-create-group-btn"') ?><?php endif; ?>
    </div>
    <?= view('home/partials/clerk-note.php', ['note' => $s['clerk_note'], 'open' => $s['proposals_open'], 'tz' => $tz]) ?>
    <div class="row g-3" id="home-libraries-list">
    <?php foreach ($libraries as $l): $lid = (int) $l['library_id']; $root = (int) $l['root_folder_id']; $pct = $l['quota_bytes'] !== null ? min(100, (int) round(100 * (int) $l['used_bytes'] / max(1, (int) $l['quota_bytes']))) : null; ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-library-<?= $lid ?>"><div class="card-body">
            <div class="fw-semibold fs-14"><?= hx_link(with_back('/f/' . $root, '/'), e($l['name']), 'text-dark', 'id="home-library-' . $lid . '-link"') ?></div>
            <div class="mt-2 d-flex flex-wrap gap-1 fs-12">
                <span class="badge bg-soft-<?= e($kindBadge[$l['kind']] ?? 'secondary text-secondary') ?>"><?= e($l['kind']) ?></span>
                <span class="badge bg-soft-secondary text-dark"><?= e($l['level']) ?></span>
                <span class="badge bg-soft-secondary text-dark"><?= e($fmtBytes((int) $l['used_bytes'])) ?><?= $pct !== null ? ' · ' . $pct . '% of the quota' : '' ?></span>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
    <div class="card mt-3" id="home-recent"><div class="card-header"><h5 class="card-title mb-0">Recent</h5></div>
        <?php if ($s['recent'] === []): ?>
            <div class="card-body fs-12 text-muted" id="home-recent-empty">Nothing opened or changed lately.</div>
        <?php else: ?>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12"><tbody>
            <?php foreach ($s['recent'] as $d): $did = (int) $d['document_id']; ?>
                <tr id="home-recent-<?= $did ?>"><td><i class="feather-file me-1 text-muted"></i><?= hx_link(with_back('/documents/' . $did, '/'), e($d['name']), 'text-dark') ?></td>
                    <td class="text-muted text-nowrap text-end"><?= e(format_ts($d['updated_at'], $tz, 'M j, g:i A')) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <?php endif; ?>
    </div>
    <?= view('home/partials/member.php', ['s' => $s, 'tz' => $tz]) ?>
    <?= view('home/partials/curator.php', ['s' => $s, 'tz' => $tz, 'fmtBytes' => $fmtBytes]) ?>
    <?= view('home/partials/admin.php', ['s' => $s, 'tz' => $tz, 'fmtBytes' => $fmtBytes]) ?>
<?php endif; ?>
</div>
