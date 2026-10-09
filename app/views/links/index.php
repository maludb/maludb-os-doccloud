<?php /** The links (and a folder's requests) screen. Data: kind, id, name, may, links, requests, made (the link just made, or null), once (its raw token, or null), opensFor, opens, tz, notice */
$back = ($kind === 'folder' ? '/f/' : '/documents/') . $id;
$here = ($kind === 'folder' ? '/folders/' : '/documents/') . $id . '/links';
$row = static function (array $l, string $tz, string $here, string $kind, int $id): string {
    $badge = LINK_STATE_BADGE[$l['state']] ?? 'secondary';
    $dead = in_array($l['state'], ['expired', 'revoked', 'rotated'], true);
    $lid = (int) $l['link_id'];
    $h = '<tr id="link-row-' . $lid . '" data-state="' . e($l['state']) . '" class="' . ($dead ? 'text-muted' : '') . '">';
    $h .= '<td><span class="fw-semibold">' . e($l['label']) . '</span>' . ($l['has_password'] ? ' <i class="feather-lock fs-11" title="password"></i>' : '') . '<div class="fs-11 text-muted">by ' . e($l['made_by_name'] ?? '') . ' · ' . e(format_ts($l['created_at'], $tz, 'M j, Y')) . '</div></td>';
    $h .= '<td><span class="badge bg-soft-' . e($badge) . ' text-' . e($badge) . '">' . e($l['state']) . '</span><div class="fs-11 text-muted">' . ($l['expires_at'] === null ? 'never expires' : 'expires ' . e(format_ts($l['expires_at'], $tz, 'M j, Y'))) . '</div></td>';
    $h .= '<td class="d-none d-md-table-cell fs-11">' . ($l['kind'] === 'request' ? (int) $l['opens']['uploads'] . ' uploads' : (int) $l['opens']['views'] . ' views · ' . (int) $l['opens']['downloads'] . ' downloads') . ' · ' . (int) $l['opens']['addresses'] . ' addresses' . ($l['kind'] === 'view' ? ' · ' . ($l['allow_download'] ? 'download' : 'view only') : '')
        . ' · ' . hx_link($here . '?opens=' . $lid . '#link-opens', 'opens', '', 'id="link-opens-' . $lid . '"') . '</td>';
    $h .= '<td class="text-end text-nowrap">';
    if (!$dead) {
        if ($l['kind'] === 'view') {
            $h .= hx_link('/links/' . $lid . '/edit', '<i class="feather-edit-2"></i>', 'btn btn-light btn-sm', 'id="link-edit-' . $lid . '" title="Update"') . ' ';
            $h .= '<form method="post" action="/links/rotate.php" hx-post="/links/rotate.php" hx-target="#flash" hx-confirm="Rotate ' . e($l['label']) . '? The old URL stops working now." class="d-inline">' . csrf_field() . '<input type="hidden" name="link" value="' . $lid . '"><input type="hidden" name="return_to" value="' . e($here) . '"><button type="submit" class="btn btn-light btn-sm" id="link-rotate-' . $lid . '" title="Rotate: a new URL"><i class="feather-refresh-cw"></i></button></form> ';
            $h .= '<form method="post" action="/links/revoke.php" hx-post="/links/revoke.php" hx-target="#flash" hx-confirm="Revoke ' . e($l['label']) . '? It stops opening now." class="d-inline">' . csrf_field() . '<input type="hidden" name="link" value="' . $lid . '"><input type="hidden" name="return_to" value="' . e($here) . '"><button type="submit" class="btn btn-light btn-sm text-danger" id="link-revoke-' . $lid . '" title="Revoke"><i class="feather-x"></i></button></form>';
        } else {
            $h .= '<form method="post" action="/requests/close.php" hx-post="/requests/close.php" hx-target="#flash" hx-confirm="Close the request ' . e($l['label']) . '?" class="d-inline">' . csrf_field() . '<input type="hidden" name="request" value="' . $lid . '"><input type="hidden" name="return_to" value="' . e($here) . '"><button type="submit" class="btn btn-light btn-sm text-danger" id="request-close-' . $lid . '" title="Close">Close</button></form>';
        }
    }
    return $h . '</td></tr>';
};
?>
<?= view('shared/header.php', ['id' => 'links', 'title' => 'Links of ' . $name, 'crumbs' => [['Home', '/'], [$name, $back], ['Links', null]], 'back' => [$back, $name],
    'action' => ($may['make'] ? hx_link('/links/new?' . $kind . '=' . $id, '<i class="feather-link me-1"></i>Make a link', 'btn btn-primary btn-touch', 'id="link-new-btn"') : '') . ($may['request'] ? ' ' . hx_link('/requests/new?folder=' . $id, '<i class="feather-inbox me-1"></i>Request files', 'btn btn-light btn-touch', 'id="request-new-btn"') : '')]) ?>
<div class="main-content" id="links-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($made !== null): ?>
        <?= view('links/shown-once.php', ['link' => $made, 'once' => $once]) ?>
    <?php endif; ?>
    <div class="card mb-3" id="links-card"><div class="card-header"><h5 class="card-title mb-0">Links</h5></div>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="links-table"><thead><tr><th>Label</th><th>State</th><th class="d-none d-md-table-cell">Opens</th><th></th></tr></thead><tbody>
        <?php if ($links === []): ?><tr id="links-empty"><td colspan="4" class="text-muted">No link yet.</td></tr><?php endif; ?>
        <?php foreach ($links as $l): ?><?= $row($l, $tz, $here, $kind, $id) ?><?php endforeach; ?>
        </tbody></table></div></div>
    <?php if ($kind === 'folder'): ?>
    <div class="card mb-3" id="requests-card"><div class="card-header"><h5 class="card-title mb-0">File requests</h5></div>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="requests-table"><thead><tr><th>Label</th><th>State</th><th class="d-none d-md-table-cell">Uploads</th><th></th></tr></thead><tbody>
        <?php if ($requests === []): ?><tr id="requests-empty"><td colspan="4" class="text-muted">No file request yet.</td></tr><?php endif; ?>
        <?php foreach ($requests as $l): ?><?= $row($l, $tz, $here, $kind, $id) ?><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
    <?php if ($opensFor !== null): ?>
    <div class="card mb-3" id="link-opens"><div class="card-header"><h5 class="card-title mb-0">Opens of link <?= $opensFor ?></h5></div>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="link-opens-table"><thead><tr><th>When</th><th>What</th><th>Document</th><th>Address key</th></tr></thead><tbody>
        <?php if ($opens === []): ?><tr><td colspan="4" class="text-muted">Not opened yet.</td></tr><?php endif; ?>
        <?php foreach ($opens as $o): ?><tr><td><?= e(format_ts($o['opened_at'], $tz)) ?></td><td><?= e($o['what']) ?></td><td><?= e($o['document_name'] ?? '') ?></td><td class="font-monospace"><?= e($o['address_key']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
</div>
