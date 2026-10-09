// Slice 8's browser proof (spec "Proof" 9): at 1280 x 800 and 375 x 740 — the proposals as cards (accept of two tags, dismiss, the tabs and filters, a curator's "for X"), the home's Clerk card, the folder's strip,
// the bell, the Agents screen (the two cards, the thresholds form, the dispatches with Retry · Cancel). Screenshots in $SHOTS; no sideways scroll at 375; no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8701';
const SHOTS = process.env.SHOTS || '/tmp/dc-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.doccloud.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const php = (code, extra = []) => execFileSync('php', ['-r', code, new URL('./lib.php', import.meta.url).pathname, ...extra], { env: process.env }).toString();
// the seed: three proposals for Priya (two tags / one tag / a duplicate), two for Marco, the dispatches in every state, a folder of Marco's with a proposal and untagged documents
const seed = JSON.parse(php(`require $argv[1]; $w = world(); $p = pdo(); chat_answers(chat_reply('Done.'));
  $run = 9100; $prop = function (array $f) use (&$run) { return (int) (post_agent(CLERK, $run++, '/agents/propose.php', $f)['json']['record_id'] ?? 0); };
  $d1 = seed_doc($w['priya_root'], 'b8-contract.pdf', 26, 'Browser contract'); $d2 = seed_doc($w['priya_root'], 'b8-photo.pdf', 26, 'Browser photo'); $d3 = seed_doc($w['priya_root'], 'b8-copy.pdf', 26, 'Browser copy');
  $f = new_folder($w['board_root'], 'B8 Folder', 27); seed_doc($f, 'b8-loose-1.pdf', 27, 'x'); seed_doc($f, 'b8-loose-2.pdf', 27, 'y');
  $a = $prop(['kind' => 'tags', 'title' => 'Tag b8-contract.pdf: supplier-terms, net-thirty', 'for_member' => 26, 'document' => $d1, 'payload' => json_encode(['tags' => ['supplier-terms', 'net-thirty']])]);
  $b = $prop(['kind' => 'tags', 'title' => 'Tag b8-photo.pdf: team-photo', 'for_member' => 26, 'document' => $d2, 'payload' => json_encode(['tags' => ['team-photo']])]);
  $c = $prop(['kind' => 'duplicate', 'title' => '2 copies of b8-copy.pdf', 'for_member' => 26, 'document' => $d3, 'payload' => json_encode(['copies' => 2])]);
  $e = $prop(['kind' => 'untagged', 'title' => '2 documents in B8 Folder have no tags', 'for_member' => 27, 'folder' => $f, 'key' => 'folder:' . $f, 'payload' => json_encode(['count' => 2])]);
  $g = $prop(['kind' => 'quota', 'title' => 'SMOKE Board is 92% full', 'for_member' => 1, 'library' => $w['lib'], 'key' => 'b8-quota', 'payload' => json_encode(['pct' => 92])]);
  $p->exec('DELETE FROM agent_dispatches');
  foreach ([['answered', 'untagged_batch', 'I proposed tags for all forty.'], ['failed', 'request_received', null], ['refused', 'duplicates_found', null], ['awaiting_approval', 'request_received', 'I need approval to share.'], ['sent', 'duplicates_found', null]] as [$s, $k, $x]) {
     $p->exec("INSERT INTO agent_dispatches (agent_member_id, kind, via, acting_member_id, status, reply_excerpt, detail, attempts, folder_id, request_id, answered_at, created_at) VALUES (" . EXPERT . ", '$k', 'chat', 27, '$s', " . ($x === null ? 'NULL' : "'$x'") . ", " . ($s === 'failed' ? "'The kernel is not reachable right now.'" : ($s === 'refused' ? "'This person may not use the expert.'" : 'NULL')) . ", 1, $f, " . ($s === 'awaiting_approval' ? "'88'" : 'NULL') . ", " . ($s === 'sent' ? 'NULL' : 'now()') . ", now() - interval '3 hours')");
  }
  $ids = array_column(q('SELECT id, status FROM agent_dispatches ORDER BY id'), 'id', 'status');
  echo json_encode(['a' => $a, 'b' => $b, 'c' => $c, 'e' => $e, 'g' => $g, 'd1' => $d1, 'd2' => $d2, 'f' => $f, 'dis' => $ids, 'lib' => $w['lib']]);`));
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  const missing = [];
  page.on('response', (r) => { if (r.status() === 404) missing.push(new URL(r.url()).pathname); });
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  page.missing = () => missing.filter((p) => !/^\/d\//.test(p));
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const clean = (errors, page, label) => ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources ' + label + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
const tagsOf = (doc) => php('require $argv[1]; echo json_encode(tags_of((int) $argv[2]));', [String(doc)]);
const status = (id) => php('require $argv[1]; echo one("SELECT status FROM clerk_proposals WHERE id = :i", ["i" => (int) $argv[2]]);', [String(id)]);

// ---- desktop, Priya: the cards, accept two tags, dismiss ----
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800 — a member (Priya)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-clerk') && await shown(page, '#home-clerk-open') && (await page.textContent('#home-clerk-open')).includes('proposals waiting'), 'the home shows the Clerk card with her open proposals');
  await page.screenshot({ path: SHOTS + '/desktop-home-clerk.png', fullPage: true });
  ok(await shown(page, '#header-bell'), 'the bell is in the header');
  await page.goto(BASE + '/curate/proposals', { waitUntil: 'networkidle' });
  for (const id of [seed.a, seed.b, seed.c]) ok(await shown(page, '#proposal-card-' + id), 'Priya\'s card ' + id + ' is there');
  ok((await page.locator('#proposal-card-' + seed.e).count()) === 0, '...and Marco\'s is not');
  ok(await shown(page, '#proposal-' + seed.a + '-tag-supplier-terms') && await shown(page, '#proposal-' + seed.a + '-tag-net-thirty') && (await page.isChecked('#proposal-' + seed.a + '-tag-net-thirty')), 'the tags proposal offers both tags, ticked');
  ok(await shown(page, '#proposal-kind-' + seed.a) && (await page.textContent('#proposal-kind-' + seed.a)).includes('Suggested tags'), 'the card has its kind chip');
  await page.screenshot({ path: SHOTS + '/desktop-proposals.png', fullPage: true });
  await page.locator('#proposal-accept-btn-' + seed.a).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  await page.goto(BASE + '/curate/proposals', { waitUntil: 'networkidle' });
  ok((await page.locator('#proposal-card-' + seed.a).count()) === 0, 'accepting took the card off the open list');
  ok(JSON.parse(tagsOf(seed.d1)).join(',') === 'net-thirty,supplier-terms', 'the document now carries both tags (' + tagsOf(seed.d1) + ')');
  await page.locator('#proposal-dismiss-btn-' + seed.b).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  ok(status(seed.b).trim() === 'dismissed' && JSON.parse(tagsOf(seed.d2)).length === 0, 'dismissing closed the proposal and applied nothing');
  await page.goto(BASE + '/curate/proposals?status=accepted', { waitUntil: 'networkidle' });
  ok(await shown(page, '#proposal-card-' + seed.a) && await shown(page, '#proposal-decided-' + seed.a), 'the Accepted tab shows it, decided');
  await page.goto(BASE + '/curate/proposals?status=dismissed', { waitUntil: 'networkidle' });
  ok(await shown(page, '#proposal-card-' + seed.b), 'the Dismissed tab shows the other');
  await page.goto(BASE + '/curate/proposals?kind=duplicate', { waitUntil: 'networkidle' });
  ok(await shown(page, '#proposal-card-' + seed.c) && await shown(page, '#proposals-kind-all'), 'the kind filter narrows');
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  ok(await shown(page, '#notifications-content'), 'the notifications page loads');
  clean(errors, page, 'at 1280 (member)');
  await ctx.close();
}
// ---- desktop, Marco the curator ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the curator (Marco)');
  await page.goto(BASE + '/curate/proposals', { waitUntil: 'networkidle' });
  ok(await shown(page, '#proposal-card-' + seed.e) && await shown(page, '#proposal-card-' + seed.c) && await shown(page, '#proposal-for-' + seed.c), 'the curator sees his own and everyone\'s, with "for X" on the others\'');
  ok((await page.textContent('#proposal-for-' + seed.c)).includes('Priya'), '...naming Priya');
  ok(await shown(page, '#proposals-mine'), 'and an "Only mine" switch');
  await page.goto(BASE + '/f/' + seed.f, { waitUntil: 'networkidle' });
  ok(await shown(page, '#folder-proposals-strip') && await shown(page, '#folder-proposals-count') && await shown(page, '#folder-ask-expert-btn'), 'the folder page: the Clerk\'s strip and "Ask the expert to tag"');
  await page.screenshot({ path: SHOTS + '/desktop-folder-strip.png', fullPage: true });
  await page.locator('#folder-ask-expert-btn').click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  ok(php('require $argv[1]; echo (int) one("SELECT count(*) FROM agent_dispatches WHERE folder_id = :f AND status = \'sent\' AND acting_member_id = 27 AND run_id IS NULL", ["f" => (int) $argv[2]]);', [String(seed.f)]).trim() >= '2', 'asking made a queued dispatch acting for him');
  clean(errors, page, 'at 1280 (curator)');
  await ctx.close();
}
// ---- desktop, the admin: the Agents screen ----
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  ok(await shown(page, '#agent-card-expert') && await shown(page, '#agent-card-clerk') && (await page.getAttribute('#agent-card-clerk', 'data-state')) === 'hired', 'the two agents as cards, both hired');
  ok((await page.textContent('#agent-facts-clerk')).includes('50 6 * * 1'), 'the Clerk\'s duty and schedule');
  ok(await shown(page, '#agents-thresholds') && (await page.inputValue('#agents-batch')) === '40' && (await page.inputValue('#agents-copies')) === '2' && (await page.inputValue('#agents-retry')) === '30', 'the thresholds: 40, 2, 30');
  ok(await shown(page, '#agents-field-expert-open') && (await page.textContent('#agents-field-expert-open')).includes('SMOKE Seamus') && (await page.textContent('#agents-field-clerk-open')).includes('SMOKE Clerk'), 'the expert and the Clerk are shown by name, chosen from a picker');
  await page.locator('#agents-field-clerk-open').click();
  await page.waitForSelector('#record-picker-results', { state: 'visible' });
  await page.waitForTimeout(700);
  const rows = await page.textContent('#record-picker-results');
  ok(rows.includes('SMOKE Seamus') && rows.includes('SMOKE Clerk') && !rows.includes('SMOKE Priya'), 'the picker lists the agents and no person');
  await page.keyboard.press('Escape'); await page.waitForTimeout(500);
  ok(await shown(page, '#dispatches-table'), 'the dispatches table');
  for (const s of ['answered', 'failed', 'refused', 'awaiting_approval', 'sent']) ok(await shown(page, '#dispatch-status-' + seed.dis[s]), 'a ' + s.replace('_', ' ') + ' chip');
  ok(await shown(page, '#dispatch-retry-' + seed.dis.failed) && await shown(page, '#dispatch-retry-' + seed.dis.refused) && (await page.locator('#dispatch-retry-' + seed.dis.answered).count()) === 0, 'Retry on the failed and the refused, not on the answered');
  ok(await shown(page, '#dispatch-cancel-' + seed.dis.sent) && (await page.locator('#dispatch-cancel-' + seed.dis.answered).count()) === 0, 'Cancel on the waiting, not on the answered');
  await page.screenshot({ path: SHOTS + '/desktop-agents.png', fullPage: true });
  await page.fill('#agents-batch', '41');
  await page.locator('#agents-save-btn').click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  ok((await page.inputValue('#agents-batch')) === '41', 'saving the batch size keeps it (41)');
  await page.fill('#agents-batch', '40'); await page.locator('#agents-save-btn').click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(600);
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  await page.locator('#dispatch-retry-' + seed.dis.failed).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  ok((await page.textContent('#dispatch-status-' + seed.dis.failed)).trim() === 'sent', 'Retry put the failed dispatch back in the queue (sent)');
  page.once('dialog', (d) => d.accept());
  await page.locator('#dispatch-cancel-' + seed.dis.sent).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(900);
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  ok((await page.textContent('#dispatch-status-' + seed.dis.sent)).trim() === 'refused', 'Cancel (after the confirm) closed the waiting one');
  await page.goto(BASE + '/admin/agents?status=refused', { waitUntil: 'networkidle' });
  ok(await shown(page, '#dispatches-status-refused') && (await page.locator('#dispatches-table tbody tr[data-status]').count()) >= 2, 'the status filter lists the refused');
  clean(errors, page, 'at 1280 (admin)');
  await ctx.close();
}
// ---- phone ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#home-clerk'), 'the home with the Clerk card fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-home-clerk.png', fullPage: true });
  await page.goto(BASE + '/curate/proposals', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#proposals-tabs'), 'the proposals fit a phone (' + o.sw + ' of ' + o.iw + ')');
  const any = await page.locator('.proposal-card').first().getAttribute('id');
  const id = (any || '').replace('proposal-card-', '');
  const box = await page.locator('#proposal-accept-btn-' + id).boundingBox();
  ok(box !== null && box.height >= 40, 'the Accept button is a touch target (' + (box ? Math.round(box.height) : 0) + ' px high)');
  await page.screenshot({ path: SHOTS + '/phone-proposals.png', fullPage: true });
  clean(errors, page, 'at 375 (member)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#agents-thresholds') && await shown(page, '#agents-save-btn'), 'the Agents screen fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-agents.png', fullPage: true });
  await page.goto(BASE + '/curate/proposals', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#proposal-card-' + seed.g), 'the admin\'s proposals fit a phone, with the quota one');
  clean(errors, page, 'at 375 (admin)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  await page.goto(BASE + '/f/' + seed.f, { waitUntil: 'networkidle' });
  const o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#folder-proposals-strip'), 'the folder strip fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-folder-strip.png', fullPage: true });
  clean(errors, page, 'at 375 (curator)');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
