<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; design-system record-picker.md).
 * One entry per record kind, added by the slice whose form chooses it:
 *   key => ['title', 'noun', 'roles' => [DocCloud rights, any one admits — has_right()], 'params' => [filter keys the endpoint forwards, as integers],
 *           'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *           'label'  => fn(PDO, int $id): ?string]
 * `search` reuses the feature's own list query (find_*() with ILIKE and paging), never a second one; `label` names the chosen record.
 *
 * Empty in the Phase 2 shell. The slices add (see docs/doccloud-design.md and the ten slice specs):
 *   base     — slice 1, a form that chooses a doccloud base (copy a guideline set from, share with, move a source to, a key's base)
 *   source   — slice 2, a source (reprocess, supersede with, attach a section's source)
 *   section  — slice 2/5, a section (pin an answer to, link a guideline to)
 *   guideline— slice 3, a guideline (copy from another base, compile)
 *   person   — slice 1/8, a member or agent (share a folder with, add to a group) — over the directory mirror, never a select of everyone
 * A short enumeration (a kind, a status, a role, a guideline type) stays a <select>.
 */
return [];
