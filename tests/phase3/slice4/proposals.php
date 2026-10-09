<?php
/** Slice 4 proof — proposals and merges (spec "Proof" 3): three people → a promote proposal; accept makes it managed; dismiss closes it; a merge keeps the alias; tag: with the alias finds. */
require __DIR__ . '/lib.php';
$s = seed_texts();
$acme = $s['board_doc']; $minutes = $s['minutes']; $handbook = $s['handbook']; $design = $s['design'];

echo "1. The threshold\n";
post_j(27, '/tags/apply.php', ['document' => $acme, 'tags' => ['Quarterly']]);
post_j(26, '/tags/apply.php', ['document' => $minutes, 'tags' => ['quarterly']]);
ok((int) one("SELECT count(*) FROM tag_proposals WHERE kind = 'promote' AND tag_id = (SELECT id FROM tags WHERE slug = 'quarterly')") === 0, 'two people using a free tag: no proposal yet');
post_j(30, '/tags/apply.php', ['document' => $design, 'tags' => ['quarterly']]);
$q = tag_id('quarterly');
$pid = (int) one("SELECT id FROM tag_proposals WHERE kind = 'promote' AND tag_id = :t AND status = 'proposed'", ['t' => $q]);
ok($pid > 0 && tag_row('quarterly')['kind'] === 'free', 'the third person: ONE promote proposal, the tag still free');
post_j(1, '/tags/apply.php', ['document' => $handbook, 'tags' => ['quarterly']]);
ok((int) one("SELECT count(*) FROM tag_proposals WHERE kind = 'promote' AND tag_id = :t", ['t' => $q]) === 1, 'a fourth person adds no second proposal');
$r = page(actor(27), '/tags');
ok($r['code'] === 200 && str_contains($r['body'], 'id="proposal-' . $pid . '"') && str_contains($r['body'], 'id="proposal-accept-' . $pid . '"') && str_contains($r['body'], 'people use it'), 'the curator sees the proposal on the tags page with Promote');
$r = page(actor(27), '/tags/proposals');
ok($r['code'] === 200 && str_contains($r['body'], 'id="proposal-' . $pid . '"') && str_contains($r['body'], 'data-kind="promote"'), 'the proposals screen lists it');
$r = page(actor(26), '/tags/proposals');
ok($r['code'] === 403, 'a member may not see the proposals (tags.manage)');
$r = post_j(26, '/tags/proposals/decide.php', ['tag_proposal' => $pid, 'decision' => 'accept']);
ok($r['code'] === 404, 'a member may not decide a promotion (a proposal they may not see: the same nothing, 404)');
$r = post_j(27, '/tags/proposals/decide.php', ['tag_proposal' => $pid, 'decision' => 'maybe']);
ok($r['code'] === 422, 'a decision is accept or dismiss');
$r = post_j(27, '/tags/proposals/decide.php', ['tag_proposal' => $pid, 'decision' => 'accept']);
ok($r['code'] === 200 && ($r['json']['outcome'] ?? '') === 'promoted' && tag_row('quarterly')['kind'] === 'managed' && one('SELECT status FROM tag_proposals WHERE id = :id', ['id' => $pid]) === 'accepted', 'accepted: the tag is managed, the proposal accepted');
ok(last_event('tag.decide') !== null && json_decode(last_event('tag.decide')['after'], true)['decision'] === 'accept', 'logged tag.decide');
$r = post_j(27, '/tags/proposals/decide.php', ['tag_proposal' => $pid, 'decision' => 'dismiss']);
ok($r['code'] === 422, 'a decided proposal is not decided twice');
// a second free tag to dismiss
foreach ([[27, $acme], [26, $minutes], [30, $design]] as [$m, $d]) { post_j($m, '/tags/apply.php', ['document' => $d, 'tags' => ['Urgent']]); }
$pid2 = (int) one("SELECT id FROM tag_proposals WHERE kind = 'promote' AND tag_id = (SELECT id FROM tags WHERE slug = 'urgent') AND status = 'proposed'");
$r = post_j(27, '/tags/proposals/decide.php', ['tag_proposal' => $pid2, 'decision' => 'dismiss']);
ok($r['code'] === 200 && ($r['json']['outcome'] ?? '') === 'dismissed' && tag_row('urgent')['kind'] === 'free', 'dismissed: the tag stays free, the proposal closed');
$r = page(actor(27), '/tags/proposals?status=dismissed');
ok($r['code'] === 200 && str_contains($r['body'], 'id="proposal-' . $pid2 . '"') && str_contains($r['body'], 'data-status="dismissed"'), 'the dismissed tab');

echo "2. An apply proposal (the Clerk's kind)\n";
$r = post_agent(40, 4001, '/tags/propose.php', ['document' => $acme, 'tag' => 'Renewal', 'note' => 'The minutes say so.']);
ok($r['code'] === 200 && ($r['json']['record_id'] ?? 0) > 0 && one("SELECT kind FROM tag_proposals WHERE id = :id", ['id' => (int) $r['json']['record_id']]) === 'apply' && tag_row('renewal') !== null, 'an agent (a viewer of the Board) proposes a tag for a document: an apply proposal; the word made a free tag');
$apid = (int) $r['json']['record_id'];
$r2 = post_agent(40, 4002, '/tags/propose.php', ['document' => $acme, 'tag' => 'renewal']);
ok($r2['code'] === 200 && (int) $r2['json']['record_id'] === $apid, 'the same proposal again answers the open one');
$r = post_j(27, '/tags/proposals/decide.php', ['tag_proposal' => $apid, 'decision' => 'accept']);
ok($r['code'] === 200 && ($r['json']['outcome'] ?? '') === 'applied' && in_array('renewal', tags_of($acme), true) && one('SELECT source FROM document_tags WHERE document_id = :d AND tag_id = :t', ['d' => $acme, 't' => tag_id('renewal')]) === 'agent', 'accepted: applied with source agent (the Clerk proposed it)');
ok(last_event('tag.propose') !== null && (int) last_event('tag.propose')['document_id'] === $acme, 'logged tag.propose');

echo "3. A merge keeps the alias\n";
post_j(27, '/tags/apply.php', ['document' => $minutes, 'tags' => ['ACME']]);
$acmeShort = tag_id('acme');
$r = post_j(27, '/tags/save.php', ['label' => 'Acme Corp.', 'kind' => 'managed']);
$acmeCorp = (int) $r['json']['record_id'];
post_j(27, '/tags/apply.php', ['document' => $acme, 'tags' => ['acme-corp']]);
$r = post_j(26, '/tags/merge.php', ['tag' => $acmeShort, 'into' => $acmeCorp]);
ok($r['code'] === 403, 'a member may not merge');
$r = post_j(27, '/tags/merge.php', ['tag' => $acmeShort, 'into' => $acmeShort]);
ok($r['code'] === 422, 'a tag is not merged into itself');
$r = post_j(27, '/tags/merge.php', ['tag' => $acmeShort, 'into' => 'acme-corp']);
ok($r['code'] === 200 && ($r['json']['moved'] ?? 0) === 1 && ($r['json']['alias'] ?? '') === 'acme' && in_array('acme-corp', tags_of($minutes), true) && !in_array('acme', tags_of($minutes), true), 'merged by slug: the minutes carry acme-corp, acme moved');
ok(one('SELECT archived_at FROM tags WHERE id = :id', ['id' => $acmeShort]) !== null && one("SELECT tag_id FROM tag_aliases WHERE alias = 'acme'") == $acmeCorp && (int) one('SELECT usage_count FROM tags WHERE id = :id', ['id' => $acmeCorp]) === 2, 'the old tag archived, its slug an alias of the survivor, the count right');
ok((int) one('SELECT dc_tag_resolve(:w)', ['w' => 'acme']) === $acmeCorp, 'dc_tag_resolve("acme") answers the survivor');
ok(last_event('tag.merge') !== null && (int) last_event('tag.merge')['entity_id'] === $acmeCorp, 'logged tag.merge on the survivor');
$r = post_j(27, '/tags/apply.php', ['document' => $handbook, 'tags' => ['acme']]);
ok($r['code'] === 200 && in_array('acme-corp', tags_of($handbook), true), 'applying the alias applies the survivor');
$f = search_as(27, 'tag:acme');
ok($f['code'] === 200 && in_array('acme-msa.pdf', $f['names'], true) && in_array('minutes-jan.docx', $f['names'], true), 'tag: with the alias in a search finds the survivor\'s documents');
$r = page(actor(27), '/tags/' . $acmeCorp);
ok($r['code'] === 200 && str_contains($r['body'], 'also known as acme'), 'the tag page names the alias');
$r = page(actor(27), '/tags/proposals');
ok($r['code'] === 200 && str_contains($r['body'], 'id="proposals-empty"'), 'nothing open is left');
finish();
