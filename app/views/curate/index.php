<?php /** Curate (screen `curate`). Data: counts (curate_counts — null when the right is not held). Each card is a link with its count. */
$rules = (int) ($counts['rules'] ?? 0);
$cards = [
    ['groups', '/groups/', 'feather-user-check', 'Groups', $counts['groups'], static fn ($n): string => $n . ' group' . ($n === 1 ? '' : 's'), 'Who belongs to what; the groups libraries are shared with.'],
    ['shared-libraries', '/libraries/?kind=shared', 'feather-hard-drive', 'Shared libraries', $counts['shared_libraries'], static fn ($n): string => $n . ' librar' . ($n === 1 ? 'y' : 'ies'), 'Libraries for everyone or for invited people.'],
    ['tags', '/tags/', 'feather-tag', 'Tags and rules', $counts['tags'], static fn ($n): string => $n . ' tag' . ($n === 1 ? '' : 's') . ' · ' . $rules . ' rule' . ($rules === 1 ? '' : 's'), 'The vocabulary, the aliases and the folders that tag what is added to them.'],
    ['ownership', '/curate/ownership', 'feather-repeat', 'Ownership', $counts['ownership'], static fn ($n): string => $n === 0 ? 'no leaver holds documents' : $n . ' leaver' . ($n === 1 ? '' : 's') . ' still own' . ($n === 1 ? 's' : '') . ' documents', 'Hand a leaver\'s documents to someone.'],
    ['storage', '/curate/storage', 'feather-pie-chart', 'Storage report', $counts['storage_bytes'], static fn ($n): string => format_bytes((int) $n) . ' used', 'By library and by owner; the duplicates; the disk.'],
    ['search', '/curate/search', 'feather-search', 'Search report', $counts['search_gaps'], static fn ($n): string => $n . ' search' . ($n === 1 ? '' : 'es') . ' found nothing (30 days)', 'What people looked for and did not find.'],
    ['proposals', '/curate/proposals', 'feather-inbox', 'Proposals', $counts['proposals'], static fn ($n): string => $n . ' open', 'The Filing Clerk\'s suggestions waiting for a decision.'],
];
?>
<?= view('shared/header.php', ['id' => 'curate', 'title' => 'Curate', 'crumbs' => [['Home', '/'], ['Curate', null]]]) ?>
<div class="main-content" id="curate-content">
    <div class="row g-3" id="curate-index">
    <?php foreach ($cards as [$key, $url, $icon, $title, $n, $fmt, $about]): if ($n === null) { continue; } ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="curate-card-<?= e($key) ?>"><div class="card-body d-flex gap-3 align-items-start">
            <span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="<?= e($icon) ?>"></i></span>
            <div class="min-w-0"><div class="fw-semibold"><?= hx_link($url, e($title), 'text-dark', 'id="curate-link-' . e($key) . '"') ?></div>
                <div class="fs-12 text-muted" id="curate-count-<?= e($key) ?>"><?= e($fmt($n)) ?></div>
                <div class="fs-12 mt-1"><?= e($about) ?></div></div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
