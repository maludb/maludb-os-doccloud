<?php
declare(strict_types=1);
/** `tag-add` (/tags/new) and `tag-edit` (/tags/{id}/edit): label (the slug shown as it will be), kind (curators), colour; the edit offers Merge into (the tag picker). */
require_once dirname(__DIR__, 2) . '/app/features/tags/handler.php';
require_login();
$pdo = db();
$editId = request_integer('id');
$tag = null;
if ($editId !== null) {
    require_right('tags.manage');
    $tag = find_tag($pdo, $editId) ?? dc_not_found('Not found.');
} else {
    require_right('tags.apply');
}
$manage = has_right('tags.manage');
if (wants_json()) {
    respond_screen(['tag' => $tag === null ? null : present_tag($tag), 'may' => ['manage' => $manage], 'kinds' => $manage ? ['managed', 'free'] : ['free']]);
}
render_screen($tag === null ? 'New tag' : 'Edit the tag ' . $tag['label'], view('tags/form.php', ['tag' => $tag, 'manage' => $manage, 'refusal' => null]),
    ['activeNav' => 'tags', 'screen' => $tag === null ? 'tag-add' : 'tag-edit', 'entity' => 'tag', 'recordId' => $editId]);
