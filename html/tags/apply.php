<?php
declare(strict_types=1);
/**
 * Action `tag_apply` (document, tags[] — slugs or labels, a missing one becomes a free tag; or documents[] from a listing's select bar: bulk):
 * tags.apply and edit on each document (a document the caller may not edit is SKIPPED and counted, never a refusal of the whole). Log tag.apply per document.
 */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
require_right('tags.apply');
$pdo = db();
$me = (int) current_member_id();
$words = request_list('tags') ?? [];
if (req_has('tag') && req_val('tag') !== '') {
    $words[] = (string) req_val('tag');
}
$words = array_values(array_unique(array_filter(array_map('trim', $words))));
if ($words === []) {
    dc_refuse_fields(['tags' => 'Say which tag.']);
}
$ids = request_list('documents') ?? [];
if (req_has('document') && req_val('document') !== '') {
    $ids[] = (string) req_val('document');
}
$ids = array_values(array_unique(array_map('intval', $ids)));
if ($ids === []) {
    dc_refuse_fields(['document' => 'Say which document.']);
}
$editable = [];
$skipped = 0;
foreach ($ids as $did) {
    $st = $pdo->prepare('SELECT id, folder_id, library_id, name, locked_by FROM documents WHERE id = :id');
    $st->execute(['id' => $did]);
    $d = $st->fetch();
    $level = $d === false ? 'none' : dc_document_level($pdo, $did);
    if ($d === false || $level === 'none') {
        if (count($ids) === 1) { dc_not_found('Not found.'); }
        $skipped++;
        continue;
    }
    if (!dc_level_at_least($level, 'edit')) {
        if (count($ids) === 1) { refuse(403, 'You may not change this document.'); }
        $skipped++;
        continue;
    }
    $editable[] = $d;
}
$result = dc_guard($pdo, static function () use ($pdo, $editable, $words, $me, $skipped): array {
    $pdo->beginTransaction();
    $out = ['applied' => 0, 'already' => 0, 'skipped' => $skipped, 'tags' => []];
    foreach ($words as $w) {
        $r = tag_apply($pdo, array_map(static fn (array $d): int => (int) $d['id'], $editable), ctype_digit($w) ? (int) $w : $w, $me, 'person', $skipped);
        $t = find_tag($pdo, $r['tag_id']);
        $out['tags'][] = $t['slug'];
        $out['applied'] += $r['applied'];
        $out['already'] += $r['already'];
        foreach ($editable as $d) {
            files_log($pdo, 'tag.apply', 'document', (int) $d['id'], ['document_id' => (int) $d['id'], 'folder_id' => (int) $d['folder_id'], 'library_id' => (int) $d['library_id'], 'after' => ['tag' => $t['slug'], 'tag_id' => (int) $t['tag_id']]]);
        }
    }
    $pdo->commit();
    return $out;
});
$one = count($editable) === 1 ? $editable[0] : null;
$land = $one !== null ? '/documents/' . (int) $one['id'] : return_path('/f/' . (int) ($editable[0]['folder_id'] ?? 0));
dc_done('Tagged ' . count($editable) . ' document' . (count($editable) === 1 ? '' : 's') . ' with ' . implode(', ', $result['tags']) . ($skipped > 0 ? ' (' . $skipped . ' skipped: not yours to change)' : ''),
    $one !== null ? (int) $one['id'] : null, dc_land($land, 'tagged', $one !== null ? 'document-tags' : null), 'tagsChanged', ['applied' => $result['applied'], 'already' => $result['already'], 'skipped' => $skipped, 'tags' => $result['tags']]);
