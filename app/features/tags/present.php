<?php
declare(strict_types=1);

/** The one JSON branch of the tag screens (whitelists). */
function present_tag(array $t): array
{
    return ['tag_id' => (int) $t['tag_id'], 'slug' => $t['slug'], 'label' => $t['label'], 'kind' => $t['kind'], 'state' => $t['state'] ?? ($t['archived_at'] !== null ? 'archived' : $t['kind']), 'colour' => $t['colour'],
            'usage_count' => (int) $t['usage_count'], 'aliases' => $t['aliases'] ?? [], 'created_by' => $t['created_by'] === null ? null : (int) $t['created_by'], 'created_by_name' => $t['created_by_name'] ?? null,
            'archived_at' => json_ts($t['archived_at']), 'created_at' => json_ts($t['created_at']), 'url' => '/tags/' . (int) $t['tag_id']];
}

function present_document_tag(array $t): array
{
    return ['tag_id' => (int) $t['tag_id'], 'slug' => $t['slug'], 'label' => $t['label'], 'kind' => $t['kind'], 'state' => $t['state'] ?? $t['kind'], 'colour' => $t['colour'], 'source' => $t['source'],
            'added_by' => $t['added_by'] === null ? null : (int) $t['added_by'], 'created_at' => json_ts($t['created_at'])];
}

function present_proposal(array $p): array
{
    return ['tag_proposal_id' => (int) $p['tag_proposal_id'], 'kind' => $p['kind'], 'tag_id' => (int) $p['tag_id'], 'tag_label' => $p['tag_label'] ?? null, 'tag_slug' => $p['tag_slug'] ?? null,
            'into_tag_id' => $p['into_tag_id'] === null ? null : (int) $p['into_tag_id'], 'into_label' => $p['into_label'] ?? null, 'document_id' => $p['document_id'] === null ? null : (int) $p['document_id'],
            'document_name' => $p['document_name'] ?? null, 'proposed_by' => $p['proposed_by'] === null ? null : (int) $p['proposed_by'], 'proposed_by_name' => $p['proposed_by_name'] ?? null, 'note' => $p['note'],
            'people' => isset($p['people']) ? (int) $p['people'] : null, 'status' => $p['status'], 'decided_by' => $p['decided_by'] === null ? null : (int) $p['decided_by'], 'decided_at' => json_ts($p['decided_at']), 'created_at' => json_ts($p['created_at'])];
}

function present_rule(array $r): array
{
    return ['folder_id' => (int) $r['folder_id'], 'name' => $r['name'], 'path' => $r['path_text'], 'library_id' => (int) $r['library_id'], 'library_name' => $r['library_name'] ?? null, 'auto_tags' => $r['auto_tags'], 'my_level' => $r['my_level'] ?? null, 'url' => '/f/' . (int) $r['folder_id']];
}
