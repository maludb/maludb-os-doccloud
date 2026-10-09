<?php /** The agents (screen `admin-agents`). Data: cards, settings, list, status, kind, osUrl, tz, notice */
$stateBadge = ['hired' => ['success', 'Named and admitted'], 'inactive' => ['danger', 'Named, but not active here'], 'not_named' => ['warning', 'Not named yet']];
$page = static fn (int $p): string => '/admin/agents?' . http_build_query(array_filter(['status' => $status ?: null, 'kind' => $kind ?: null, 'page' => $p > 1 ? $p : null]));
?>
<?= view('shared/header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Agents', null]]]) ?>
<div class="main-content" id="admin-agents-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-2 mb-3" id="agents-cards">
        <?php foreach ($cards as $c): [$sb, $sw] = $stateBadge[$c['state']]; ?>
        <div class="col-12 col-md-6"><div class="card h-100" id="agent-card-<?= e($c['key']) ?>" data-state="<?= e($c['state']) ?>"><div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-1"><span class="avatar-text avatar-md rounded bg-soft-info text-info"><i class="feather-<?= $c['key'] === 'clerk' ? 'inbox' : 'message-circle' ?>"></i></span>
                <div class="min-w-0"><div class="fw-semibold"><?= e($c['name']) ?></div><div class="fs-12 text-muted" id="agent-member-<?= e($c['key']) ?>"><?= $c['member'] !== null ? e($c['member']['display_name']) . ' · member ' . (int) $c['member']['id'] : 'No agent named' ?></div></div>
                <span class="badge bg-soft-<?= e($sb) ?> text-<?= e($sb) ?> ms-auto" id="agent-state-<?= e($c['key']) ?>"><?= e($sw) ?></span></div>
            <p class="fs-12 text-muted mb-2"><?= e($c['about']) ?></p>
            <div class="fs-12" id="agent-facts-<?= e($c['key']) ?>">
                <?php if ($c['key'] === 'expert'): ?>
                    <div>Last dispatch: <?= $c['last_dispatch'] === null ? '<span class="text-muted">none yet</span>' : e(DISPATCH_KINDS[$c['last_dispatch']['kind']] ?? $c['last_dispatch']['kind']) . ' · ' . e(str_replace('_', ' ', $c['last_dispatch']['status'])) . ' · ' . e(format_ts($c['last_dispatch']['created_at'], $tz, 'M j, g:i A')) ?></div>
                    <div>Waiting or failed: <?= (int) $c['dispatches_open'] ?></div>
                <?php else: ?>
                    <div>Duty: <?= $c['duty'] !== null ? e($c['duty']['name']) . ' · <code>' . e($c['duty']['schedule_cron'] ?? '') . '</code>' : '<span class="text-muted">none declared</span>' ?></div>
                    <div>Last Monday report: <?= $c['last_report'] === null ? '<span class="text-muted">none yet</span>' : e(format_ts($c['last_report'], $tz, 'M j, g:i A')) ?> · <?= $c['report_enabled'] ? 'on' : '<span class="text-danger">switched off</span>' ?></div>
                    <div>Open proposals: <?= hx_link('/curate/proposals', (string) (int) $c['proposals_open'], '', 'id="agent-proposals-link"') ?></div>
                <?php endif; ?>
                <div class="text-muted"><?= (int) $c['tools'] ?> tools · <?= (int) $c['skills'] ?> skills shipped</div>
            </div></div></div></div>
        <?php endforeach; ?>
    </div>
    <p class="fs-12 text-muted" id="agents-kernel-note">Hiring an agent, giving it its skills and granting it DocCloud are done in the kernel<?= $osUrl ? ': <a href="' . e($osUrl) . '" id="agents-kernel-link">open the operating system</a>' : '' ?>. Here you only say which hired agent is the expert and which is the Clerk.</p>

    <div class="card mb-3" id="agents-thresholds"><div class="card-header"><h6 class="m-0">Who and when</h6></div><div class="card-body">
        <form method="post" action="/admin/agents/save.php" hx-post="/admin/agents/save.php" hx-target="#flash" id="agents-settings-form"><?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12" id="agents-expert-label">The expert</label>
                    <?= picker_field(['id' => 'agents-field-expert', 'name' => 'expert', 'source' => 'agent', 'value' => $settings['expert_member_id'], 'title' => 'The expert', 'placeholder' => 'Choose the expert agent', 'labelledby' => 'agents-expert-label']) ?></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12" id="agents-clerk-label">The Filing Clerk</label>
                    <?= picker_field(['id' => 'agents-field-clerk', 'name' => 'clerk', 'source' => 'agent', 'value' => $settings['clerk_member_id'], 'title' => 'The Filing Clerk', 'placeholder' => 'Choose the Clerk agent', 'labelledby' => 'agents-clerk-label']) ?></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12" for="agents-batch">Untagged batch</label><input type="number" class="form-control btn-touch" name="untagged_batch_size" id="agents-batch" min="1" max="1000" value="<?= (int) $settings['untagged_batch_size'] ?>">
                    <div class="form-text">Untagged documents in a folder within a day that wake the expert.</div></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12" for="agents-copies">Copies of a file</label><input type="number" class="form-control btn-touch" name="duplicate_copies" id="agents-copies" min="2" max="100" value="<?= (int) $settings['duplicate_copies'] ?>">
                    <div class="form-text">The Clerk lists a file kept this many times; the expert is woken from <?= (int) DISPATCH_DUPLICATE_COPIES ?>.</div></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12" for="agents-retry">Retry after (minutes)</label><input type="number" class="form-control btn-touch" name="dispatch_retry_minutes" id="agents-retry" min="1" max="1440" value="<?= (int) $settings['dispatch_retry_minutes'] ?>">
                    <div class="form-text">A failed dispatch is tried again, up to <?= (int) DISPATCH_MAX_ATTEMPTS ?> times.</div></div>
                <div class="col-6 col-md-3 d-flex align-items-end"><input type="hidden" name="clerk_report_enabled" value="0"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="clerk_report_enabled" value="1" id="agents-report-enabled"<?= $settings['clerk_report_enabled'] ? ' checked' : '' ?>>
                    <label class="form-check-label fs-12" for="agents-report-enabled">The Monday report is on</label></div></div>
            </div>
            <button type="submit" class="btn btn-primary btn-touch mt-2" id="agents-save-btn">Save</button>
        </form>
    </div></div>

    <div class="card mb-3" id="dispatches-card"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><h6 class="m-0">Dispatches</h6>
        <div class="ms-auto d-flex flex-wrap gap-1" id="dispatches-filters">
            <?= hx_link('/admin/agents', 'All', 'btn btn-sm ' . ($status === '' ? 'btn-secondary' : 'btn-light'), 'id="dispatches-all"') ?>
            <?php foreach (DISPATCH_BADGE as $s => $b): ?><?= hx_link('/admin/agents?status=' . $s, e(str_replace('_', ' ', $s)), 'btn btn-sm ' . ($status === $s ? 'btn-secondary' : 'btn-light'), 'id="dispatches-status-' . e($s) . '"') ?><?php endforeach; ?>
        </div></div>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="dispatches-table"><thead><tr><th>What for</th><th>Acting for</th><th>Status</th><th>Reply</th><th class="text-end">Tries</th><th></th></tr></thead><tbody>
        <?php if ($list['rows'] === []): ?><tr id="dispatches-empty"><td colspan="6" class="text-muted">Nothing has been sent to the expert yet.</td></tr><?php endif; ?>
        <?php foreach ($list['rows'] as $d): $did = (int) $d['agent_dispatch_id']; $b = DISPATCH_BADGE[$d['status']] ?? 'secondary'; ?>
            <tr id="dispatch-<?= $did ?>" data-status="<?= e($d['status']) ?>" data-kind="<?= e($d['kind']) ?>">
                <td><div class="fw-semibold"><?= e(DISPATCH_KINDS[$d['kind']] ?? $d['kind']) ?></div>
                    <div class="text-muted"><?= $d['folder_id'] !== null && $d['folder_name'] !== null ? hx_link('/folders/' . (int) $d['folder_id'], e($d['folder_name'])) : '' ?> <?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></div></td>
                <td><?= e($d['acting_name'] ?? '—') ?></td>
                <td><span class="badge bg-soft-<?= e($b) ?> text-<?= e($b) ?>" id="dispatch-status-<?= $did ?>"><?= e(str_replace('_', ' ', $d['status'])) ?></span><?php if ($d['request_id'] !== null): ?><div class="text-muted">request <?= e($d['request_id']) ?></div><?php endif; ?></td>
                <td class="text-break" style="max-width:260px"><?= e($d['reply_excerpt'] ?? $d['detail'] ?? '') ?></td>
                <td class="text-end"><?= (int) $d['attempts'] ?></td>
                <td class="text-end text-nowrap">
                    <?php if (in_array($d['status'], ['failed', 'refused'], true)): ?><form method="post" action="/admin/agents/retry.php" hx-post="/admin/agents/retry.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $did ?>"><button type="submit" class="btn btn-light btn-sm" id="dispatch-retry-<?= $did ?>">Retry</button></form><?php endif; ?>
                    <?php if (in_array($d['status'], ['sent', 'awaiting_approval', 'failed'], true)): ?><form method="post" action="/admin/agents/cancel.php" hx-post="/admin/agents/cancel.php" hx-target="#flash" hx-confirm="Cancel this dispatch? Nothing will be sent again." class="d-inline"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $did ?>"><button type="submit" class="btn btn-light btn-sm" id="dispatch-cancel-<?= $did ?>">Cancel</button></form><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($list['pages'] > 1): ?><div class="card-footer d-flex align-items-center fs-12" id="dispatches-pager"><span class="text-muted"><?= (int) $list['total'] ?> dispatches · page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?></span>
            <div class="ms-auto d-flex gap-1"><?php if ($list['page'] > 1): ?><?= hx_link($page($list['page'] - 1), 'Previous', 'btn btn-light btn-sm', 'id="dispatches-prev"') ?><?php endif; ?><?php if ($list['page'] < $list['pages']): ?><?= hx_link($page($list['page'] + 1), 'Next', 'btn btn-light btn-sm', 'id="dispatches-next"') ?><?php endif; ?></div></div><?php endif; ?>
    </div>
</div>
