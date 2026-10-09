<?php /** One member row of a group's table. Data: m, g, may (members, run), tz */ $gid = (int) $g['group_id']; $mid = (int) $m['member_id']; ?>
<tr id="group-member-<?= $mid ?>">
    <td><span class="avatar-text avatar-sm me-1"><?= e(strtoupper(mb_substr((string) $m['display_name'], 0, 1))) ?></span><?= e($m['display_name']) ?><?= ($m['member_kind'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></td>
    <td><?= $m['role'] === 'owner' ? '<span class="badge bg-soft-primary text-primary">owner</span>' : '<span class="text-muted">member</span>' ?></td>
    <td class="d-none d-md-table-cell text-muted"><?= e(format_ts($m['joined_at'], $tz, 'M j, Y')) ?></td>
    <td class="text-end text-nowrap">
        <?php if ($may['run']): ?>
            <form method="post" action="/groups/members/owner.php" hx-post="/groups/members/owner.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="group" value="<?= $gid ?>"><input type="hidden" name="member" value="<?= $mid ?>"><input type="hidden" name="owner" value="<?= $m['role'] === 'owner' ? 'no' : 'yes' ?>"><input type="hidden" name="return_to" value="/groups/<?= $gid ?>">
                <button type="submit" class="btn btn-light btn-sm" id="group-member-<?= $mid ?>-owner-btn"><?= $m['role'] === 'owner' ? 'Make member' : 'Make owner' ?></button></form>
        <?php endif; ?>
        <?php if ($may['members']): ?>
            <form method="post" action="/groups/members/remove.php" hx-post="/groups/members/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($m['display_name']) ?> from the group?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="group" value="<?= $gid ?>"><input type="hidden" name="member" value="<?= $mid ?>"><input type="hidden" name="return_to" value="/groups/<?= $gid ?>">
                <button type="submit" class="btn btn-light btn-sm" id="group-member-<?= $mid ?>-remove-btn" aria-label="Remove <?= e($m['display_name']) ?>"><i class="feather-x"></i></button></form>
        <?php endif; ?>
    </td>
</tr>
