<?php /** The versions table. Data: d, versions (document_versions), may (restore, purge), tz, compact? */
$id = (int) $d['document_id'];
$compact = $compact ?? false;
$chip = ['ready' => 'success', 'pending' => 'secondary', 'no_text' => 'warning', 'unsupported' => 'light text-dark', 'failed' => 'danger'];
?>
<div class="card mb-3" id="versions-card"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Versions<?= $compact ? '' : '' ?></h5>
    <?php if ($compact && count($versions) > 5): ?><?= hx_link('/documents/' . $id . '/versions', 'All ' . count($versions), 'ms-auto fs-12', 'id="versions-all-link"') ?><?php endif; ?></div>
<div class="card-body p-0"><div class="table-responsive-none"><table class="table table-hover mb-0 fs-12 listing-table" id="versions-table"><thead class="thead-light"><tr><th>#</th><th>Who</th><th class="d-none d-md-table-cell">When</th><th class="text-end">Size</th><th class="d-none d-md-table-cell">Text</th><th class="text-end"></th></tr></thead><tbody>
<?php foreach (($compact ? array_slice($versions, 0, 5) : $versions) as $v): $vn = (int) $v['version_no']; $purged = $v['purged_at'] !== null; ?>
    <tr id="version-row-<?= $vn ?>" class="<?= $purged ? 'text-muted' : '' ?>">
        <td><span class="fw-semibold">v<?= $vn ?></span> <?php if ($v['is_current']): ?><span class="badge bg-primary">current</span><?php elseif ($purged): ?><span class="badge bg-dark">purged</span><?php endif; ?>
            <div class="d-md-none fs-11 text-muted"><?= e(format_ts($v['uploaded_at'], $tz, 'M j, Y')) ?><?= $v['note'] ? ' · ' . e($v['note']) : '' ?></div></td>
        <td><?= e($v['uploaded_by_name'] ?? '—') ?><?= $v['source'] !== 'web' ? ' <span class="badge bg-soft-secondary text-secondary">' . e($v['source']) . '</span>' : '' ?></td>
        <td class="d-none d-md-table-cell text-nowrap"><?= e(format_ts($v['uploaded_at'], $tz)) ?><?= $v['note'] ? '<div class="fs-11 text-muted">' . e($v['note']) . '</div>' : '' ?></td>
        <td class="text-end text-nowrap"><?= e(format_bytes((int) $v['byte_size'])) ?></td>
        <td class="d-none d-md-table-cell"><span class="badge bg-soft-<?= e($chip[$v['extracted_status']] ?? 'secondary') ?> text-<?= e(explode(' ', $chip[$v['extracted_status']] ?? 'secondary')[0]) ?>"><?= e(str_replace('_', ' ', $v['extracted_status'])) ?></span></td>
        <td class="text-end text-nowrap">
            <?php if (!$purged): ?><a href="/d/<?= $id ?>/v/<?= $vn ?>?download=1" class="btn btn-sm btn-light btn-touch" id="version-download-<?= $vn ?>" title="Download v<?= $vn ?>"><i class="feather-download"></i></a><?php endif; ?>
            <?php if (!$purged && !$v['is_current'] && $may['restore']): ?>
                <form method="post" action="/documents/versions/restore.php" hx-post="/documents/versions/restore.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="version" value="<?= $vn ?>"><input type="hidden" name="return_to" value="/documents/<?= $id ?>">
                    <button type="submit" class="btn btn-sm btn-light btn-touch" id="version-restore-<?= $vn ?>" title="Restore v<?= $vn ?> as a new version"><i class="feather-rotate-ccw me-1"></i>Restore</button></form>
            <?php endif; ?>
            <?php if (!$purged && !$v['is_current'] && $may['purge']): ?>
                <form method="post" action="/documents/versions/purge.php" hx-post="/documents/versions/purge.php" hx-target="#flash" hx-confirm="Purge version <?= $vn ?>? Its bytes are released for good." class="d-inline"><?= csrf_field() ?><input type="hidden" name="document" value="<?= $id ?>"><input type="hidden" name="version" value="<?= $vn ?>"><input type="hidden" name="return_to" value="/documents/<?= $id ?>">
                    <button type="submit" class="btn btn-sm btn-light btn-touch text-danger" id="version-purge-<?= $vn ?>" title="Purge v<?= $vn ?>"><i class="feather-trash-2"></i></button></form>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
