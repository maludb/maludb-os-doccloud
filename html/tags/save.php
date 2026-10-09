<?php
declare(strict_types=1);
/**
 * Actions `tag_create` (label, kind, colour — managed needs tags.manage; log tag.create) and `tag_update` (tag, label, colour — tags.manage; log tag.update):
 * one file, as the manifest says; which one is `tag` being named.
 */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
dc_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$colour = colour_from_request($errors);
if (req_has('tag') && req_val('tag') !== '') {
    require_right('tags.manage');
    $tag = tag_from_request($pdo, 'tag');
    $id = (int) $tag['tag_id'];
    $f = [];
    if (req_has('label')) { $f['label'] = req_val('label'); }
    if (req_has('colour')) { $f['colour'] = $colour; }
    if (req_has('kind') && $manageKind = in_array(req_val('kind'), ['managed', 'free'], true)) { $f['kind'] = req_val('kind'); }
    if ($errors !== []) {
        dc_refuse_fields($errors);
    }
    $before = ['label' => $tag['label'], 'colour' => $tag['colour'], 'kind' => $tag['kind']];
    dc_guard($pdo, static function () use ($pdo, $id, $f, $before): void {
        $pdo->beginTransaction();
        tag_update($pdo, $id, $f);
        $after = find_tag($pdo, $id);
        files_log($pdo, 'tag.update', 'tag', $id, dc_diff($before, ['label' => $after['label'], 'colour' => $after['colour'], 'kind' => $after['kind']]));
        $pdo->commit();
    });
    dc_done('Saved the tag ' . ($f['label'] ?? $tag['label']), $id, dc_land(return_path('/tags/' . $id), 'updated'), 'tagsChanged');
}
require_right('tags.apply');
$label = (string) (req_val('label') ?? '');
$kind = req_val('kind') ?? 'free';
if (!in_array($kind, ['managed', 'free'], true)) {
    $errors['kind'] = 'A tag is managed or free.';
}
if ($kind === 'managed' && !has_right('tags.manage')) {
    refuse(403, 'Only a curator makes a managed tag; anyone makes a free one.');
}
if ($label === '' || mb_strlen($label) > 50) {
    $errors['label'] = 'A tag is a label of 1 to 50 characters.';
}
if ($errors !== []) {
    dc_refuse_fields($errors);
}
$existed = tag_by_word($pdo, $label) !== null;
$id = dc_guard($pdo, static function () use ($pdo, $label, $kind, $colour, $me, $existed): int {
    $pdo->beginTransaction();
    $id = tag_create($pdo, $label, $kind, $colour, $me);
    $t = find_tag($pdo, $id);
    files_log($pdo, 'tag.create', 'tag', $id, ['after' => ['label' => $t['label'], 'slug' => $t['slug'], 'kind' => $t['kind'], 'colour' => $t['colour'], 'existed' => $existed]]);
    $pdo->commit();
    return $id;
});
$t = find_tag($pdo, $id);
dc_done(($existed ? 'That tag exists already: ' : 'Made the tag ') . $t['label'] . ' (' . $t['slug'] . ')', $id, dc_land(return_path('/tags/' . $id), 'created'), 'tagsChanged', ['slug' => $t['slug'], 'kind' => $t['kind'], 'existed' => $existed]);
