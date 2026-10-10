// Slice 9's browser proof (spec "Proof" 7): the four homes (a new Member's first run, a Member's, the curator's, the admin's) and the guest's at 375 x 740 and 1280 x 800, the Curate index and its reports, ownership through
// the record picker with its confirm, the Admin index and Settings, links with their Revoke, the comments on a document. Screenshots in $SHOTS; no sideways scroll at 375; no console errors.
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
const mint = (member, claimsOverride) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.doccloud.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...(claimsOverride || fixture.claims[String(member)]), member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const php = (code, extra = []) => execFileSync('php', ['-r', code, new URL('./lib.php', import.meta.url).pathname, ...extra], { env: process.env }).toString();
// the seed: the slice's world, three more documents for Lee (who has left), a live link of Marco's, a quiet shared library toggled for the first-run screenshot
const seed = JSON.parse(php(`require $argv[1]; $w = world9(); $p = pdo();
  $p->exec("UPDATE members SET status = 'active' WHERE id = 31"); $lr = root_of(31);
  $ids = []; for ($i = 1; $i <= 3; $i++) { $ids[] = seed_doc($lr, "b9-lee-$i.pdf", 31, "Browser Lee $i"); }
  $p->exec("UPDATE members SET status = 'inactive' WHERE id = 31");
  $l = make_link(27, ['document' => $w['board_doc'], 'label' => 'b9 browser link', 'expires_in_days' => 30]);
  $l2 = make_link(27, ['document' => $w['board_doc'], 'label' => 'b9 second link', 'expires_in_days' => 30]);
  echo json_encode(['lee' => $ids, 'link' => $l['link_id'], 'link2' => $l2['link_id'], 'board_doc' => $w['board_doc'], 'guest_doc' => $w['guest_doc'], 'lib' => $w['lib'], 'priya_doc' => $w['priya_doc']]);`));
const newbie = { ...fixture.claims['30'], display_name: 'SMOKE Newbie', email: 'newbie@example.invalid', departments: [] };
const browser = await chromium.launch();
async function session(member, viewport, claims) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  const missing = [];
  page.on('response', (r) => { if (r.status() === 404) missing.push(new URL(r.url()).pathname); });
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  page.missing = () => missing.filter((p) => !/^\/d\//.test(p));
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member, claims), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const clean = (errors, page, label) => ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources ' + label + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().slice(0, 2).join(' | ') : ''));
const fits = async (page, label) => { const o = await overflow(page); ok(o.sw <= o.iw + 1, label + ' fits a phone (' + o.sw + ' of ' + o.iw + ')'); };
const settle = (page, ms = 700) => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(ms));
const sql = (q, a = []) => php('require $argv[1]; echo json_encode(q($argv[2], array_slice($argv, 3)));', [q, ...a]);
const one = (q, a = []) => JSON.parse(php('require $argv[1]; echo json_encode(one($argv[2], array_slice($argv, 3) ? array_combine(array_map(fn($i) => "p" . $i, array_keys(array_slice($argv, 3))), array_slice($argv, 3)) : []));', [q, ...a]));

// ---- 375 x 740: the four homes and the guest's ----
{
  console.log('375 x 740 — a new Member (first run)');
  php(`require $argv[1]; pdo()->exec("UPDATE libraries SET everyone_level = 'none' WHERE kind = 'shared'");`);
  const { ctx, page, errors } = await session(55, { width: 375, height: 740 }, newbie);
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-first-run') && await shown(page, '#home-upload-first-btn') && (await page.locator('[id^="home-section-"]').count()) === 0, 'a new Member: the one button, Upload your first file, and no section');
  await fits(page, 'the first-run home');
  await page.screenshot({ path: SHOTS + '/phone-home-first-run.png', fullPage: true });
  clean(errors, page, 'at 375 (first run)');
  await ctx.close();
  php(`require $argv[1]; pdo()->exec("UPDATE libraries SET everyone_level = 'view' WHERE kind = 'shared'");`);
}
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — a Member (Priya)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-section-shared') && await shown(page, '#home-section-requests') && await shown(page, '#home-section-links') && await shown(page, '#home-libraries-list') && await shown(page, '#home-recent'), 'the Member\'s home: libraries, recent, shared this week, open requests, links ending');
  ok((await page.locator('#home-curator-title').count()) === 0 && (await page.locator('#home-admin-title').count()) === 0, 'no curator or admin block');
  ok((await page.textContent('#home-section-shared')).includes('w9-shared-new.pdf') && !(await page.textContent('#home-section-shared')).includes('w9-shared-old.pdf'), 'shared this week: only the new one');
  await fits(page, 'the Member\'s home');
  await page.screenshot({ path: SHOTS + '/phone-home-member.png', fullPage: true });
  // comments: the composer, add, delete
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  ok(await shown(page, '#comment-form') && await shown(page, '#comment-body'), 'the document page has the comment composer');
  await page.fill('#comment-body', 'Browser comment from a phone.');
  await page.locator('#comment-add-btn').click();
  await settle(page);
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  ok((await page.textContent('#comments-list')).includes('Browser comment from a phone.'), 'the comment was added');
  await fits(page, 'the document with comments');
  await page.screenshot({ path: SHOTS + '/phone-document-comments.png', fullPage: true });
  const del = page.locator('#comments-list button[id^="note-delete-"]').first();
  ok(await del.count() === 1, 'a delete button on her own comment');
  page.once('dialog', (d) => d.accept());
  await del.click();
  await settle(page, 900);
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  ok(!(await page.textContent('#comments-list')).includes('Browser comment from a phone.'), 'deleting it (after the confirm) removes it');
  clean(errors, page, 'at 375 (member)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  console.log('375 x 740 — the curator (Marco)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-curator-title') && await shown(page, '#home-section-untagged') && await shown(page, '#home-section-duplicates') && await shown(page, '#home-section-stale'), 'the curator\'s home adds untagged, duplicates and stale shares');
  ok((await page.locator('#home-admin-title').count()) === 0, '...and no admin block');
  await fits(page, 'the curator\'s home');
  await page.screenshot({ path: SHOTS + '/phone-home-curator.png', fullPage: true });
  await page.goto(BASE + '/curate/', { waitUntil: 'networkidle' });
  ok((await page.locator('#curate-index .card').count()) === 7, 'the Curate index: seven cards');
  await fits(page, 'the Curate index');
  await page.screenshot({ path: SHOTS + '/phone-curate-index.png', fullPage: true });
  await page.goto(BASE + '/curate/storage', { waitUntil: 'networkidle' });
  ok(await shown(page, '#storage-table') && await shown(page, '#storage-totals'), 'the storage report');
  await fits(page, 'the storage report');
  await page.screenshot({ path: SHOTS + '/phone-curate-storage.png', fullPage: true });
  await page.goto(BASE + '/curate/search', { waitUntil: 'networkidle' });
  await fits(page, 'the search report');
  await page.goto(BASE + '/curate/ownership?member=31', { waitUntil: 'networkidle' });
  await fits(page, 'the ownership form');
  await page.screenshot({ path: SHOTS + '/phone-curate-ownership.png', fullPage: true });
  clean(errors, page, 'at 375 (curator)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  console.log('375 x 740 — the admin');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-admin-title') && await shown(page, '#home-section-storage') && await shown(page, '#home-section-graph') && await shown(page, '#home-section-failures'), 'the admin\'s home adds storage, the graph and the failures');
  await fits(page, 'the admin\'s home');
  await page.screenshot({ path: SHOTS + '/phone-home-admin.png', fullPage: true });
  for (const [u, id, label] of [['/admin/', '#admin-index', 'the Admin index'], ['/admin/settings', '#settings-form', 'Settings'], ['/admin/links', '#admin-links-table', 'Links'], ['/admin/libraries', '#admin-libraries-table', 'Libraries'], ['/admin/storage', '#storage-blobs', 'the disk']]) {
    await page.goto(BASE + u, { waitUntil: 'networkidle' });
    ok(await shown(page, id), label + ' opens');
    await fits(page, label);
    await page.screenshot({ path: SHOTS + '/phone-' + u.replace(/\W+/g, '-').replace(/^-|-$/g, '') + '.png', fullPage: true });
  }
  clean(errors, page, 'at 375 (admin)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(29, { width: 375, height: 740 });
  console.log('375 x 740 — a guest (Ann)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#home-guest') && await shown(page, '#home-section-shared') && (await page.locator('#home-section-requests').count()) === 0, 'the guest\'s home: Shared with me, nothing else');
  await fits(page, 'the guest\'s home');
  await page.screenshot({ path: SHOTS + '/phone-home-guest.png', fullPage: true });
  clean(errors, page, 'at 375 (guest)');
  await ctx.close();
}

// ---- 1280 x 800: the admin ----
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const cols = await page.locator('#home-section-storage').boundingBox();
  const cols2 = await page.locator('#home-section-graph').boundingBox();
  ok(Math.abs(cols.y - cols2.y) < 5 && cols.width < 600, 'the admin\'s blocks sit side by side on a desktop (' + Math.round(cols.width) + ' px each)');
  await page.screenshot({ path: SHOTS + '/desktop-home-admin.png', fullPage: true });
  await page.locator('#home-storage-all').click();
  await page.waitForURL(BASE + '/curate/storage');
  await page.waitForSelector('#storage-table');
  ok((await page.title()).startsWith('Storage report'), 'the home\'s Report link opens the storage report by HTMX (title "' + (await page.title()) + '")');
  await page.screenshot({ path: SHOTS + '/desktop-curate-storage.png', fullPage: true });
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('#storage-export-btn').click()]);
  const path = await dl.path();
  const csv = fs.readFileSync(path, 'utf8');
  ok(/^doccloud-storage-report-\d{4}-\d{2}-\d{2}\.csv$/.test(dl.suggestedFilename()) && csv.includes('"section","id","name"') && csv.includes('SMOKE Board'), 'Export CSV downloads ' + dl.suggestedFilename());
  await page.goto(BASE + '/curate/', { waitUntil: 'networkidle' });
  await page.screenshot({ path: SHOTS + '/desktop-curate-index.png', fullPage: true });
  await page.locator('#curate-link-ownership').click();
  await page.waitForURL(BASE + '/curate/ownership');
  await page.waitForSelector('#ownership-leavers');
  ok(await shown(page, '#ownership-leaver-31') && (await page.textContent('#ownership-leaver-31')).includes('3 documents'), 'Ownership: Lee, who left, with his three documents');
  await page.screenshot({ path: SHOTS + '/desktop-curate-ownership.png', fullPage: true });
  await page.locator('#ownership-pick-31').click();
  await page.waitForSelector('#ownership-transfer-form');
  ok((await page.textContent('#ownership-count')) === '3', 'choosing him opens the form with the count (3)');
  await page.locator('#ownership-newowner-open').click();
  await page.waitForSelector('#record-picker-results', { state: 'visible' });
  await page.waitForTimeout(700);
  ok((await page.locator('#record-picker-row-28').count()) === 1, 'the record picker lists people (Bea among them)');
  await page.locator('#record-picker-row-28').click();
  await page.waitForTimeout(400);
  ok((await page.textContent('#ownership-newowner-open')).includes('SMOKE Bea'), 'Bea is chosen');
  let confirmText = '';
  page.once('dialog', (d) => { confirmText = d.message(); d.accept(); });
  await page.locator('#ownership-transfer-btn').click();
  await settle(page, 1200);
  ok(confirmText.includes('Hand 3 documents of SMOKE Lee'), 'the confirm names the count: "' + confirmText.slice(0, 60) + '…"');
  const moved = one("SELECT count(*) FROM documents WHERE owner_member_id = 28 AND name LIKE 'b9-lee-%'");
  ok(moved === 3, 'Hand over moved them to Bea (' + moved + ')');
  await page.goto(BASE + '/curate/ownership', { waitUntil: 'networkidle' });
  ok(await shown(page, '#ownership-leavers-empty'), 'and nobody who left owns a document any more');
  await page.goto(BASE + '/curate/search', { waitUntil: 'networkidle' });
  ok(await shown(page, '#search-gaps') && await shown(page, '#search-gap-0'), 'the search report lists what found nothing');
  await page.screenshot({ path: SHOTS + '/desktop-curate-search.png', fullPage: true });
  await page.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
  ok((await page.locator('#admin-index .card').count()) === 12 && await shown(page, '#admin-health'), 'the Admin index: twelve cards and the health line');
  ok((await page.textContent('#health-storage')).includes('ok'), 'the store is ok');
  await page.screenshot({ path: SHOTS + '/desktop-admin-index.png', fullPage: true });
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  ok(await shown(page, '#settings-form') && await shown(page, '#limits-form') && await shown(page, '#retention-form'), 'Settings: the settings, the limits and the windows');
  ok((await page.getAttribute('#settings-link_default_days', 'max')) === '3650' && (await page.getAttribute('#settings-link_default_days', 'min')) === '1', 'each field carries its bounds');
  await page.fill('#settings-business_name', 'Browser Docs Ltd');
  await page.locator('#settings-save').click();
  await settle(page, 900);
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  ok((await page.inputValue('#settings-business_name')) === 'Browser Docs Ltd', 'saving the business name keeps it');
  await page.fill('#settings-upload_session_hours', '30');
  await page.locator('#limits-save').click();
  await settle(page, 900);
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  ok((await page.inputValue('#settings-upload_session_hours')) === '30', 'saving a limit keeps it (30 hours)');
  await page.fill('#settings-upload_session_hours', '24'); await page.locator('#limits-save').click(); await settle(page, 600);
  await page.screenshot({ path: SHOTS + '/desktop-admin-settings.png', fullPage: true });
  await page.goto(BASE + '/admin/links', { waitUntil: 'networkidle' });
  ok(await shown(page, '#admin-link-row-' + seed.link) && await shown(page, '#admin-link-revoke-' + seed.link), 'Links: Marco\'s link is listed with Revoke');
  await page.screenshot({ path: SHOTS + '/desktop-admin-links.png', fullPage: true });
  page.once('dialog', (d) => d.accept());
  await page.locator('#admin-link-revoke-' + seed.link).click();
  await settle(page, 1000);
  await page.goto(BASE + '/admin/links?status=revoked', { waitUntil: 'networkidle' });
  ok(await shown(page, '#admin-link-row-' + seed.link) && (await page.locator('#admin-link-revoke-' + seed.link).count()) === 0, 'revoking it (after the confirm) moves it to Revoked, with no button');
  await page.goto(BASE + '/admin/libraries', { waitUntil: 'networkidle' });
  ok((await page.locator('#admin-libraries-table [id^="admin-library-row-"]').count()) >= 6 && await shown(page, '#admin-libraries-note'), 'Libraries: every library, with the note that opening one is logged');
  await page.screenshot({ path: SHOTS + '/desktop-admin-libraries.png', fullPage: true });
  await page.goto(BASE + '/admin/storage', { waitUntil: 'networkidle' });
  ok(await shown(page, '#storage-disk') && (await page.textContent('#storage-disk')).includes('free'), 'the disk: free space shown');
  await page.screenshot({ path: SHOTS + '/desktop-admin-storage.png', fullPage: true });
  await page.goto(BASE + '/admin/connections', { waitUntil: 'networkidle' });
  ok(await shown(page, '#connections-kernel') && await shown(page, '#connections-kernel-fallback'), 'Connections: the sentence when the kernel lists none');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#settings-tab-tokens') && await shown(page, '#settings-tab-activity') && await shown(page, '#settings-tab-admin'), 'My settings: tokens and keys, my activity, the application\'s settings');
  clean(errors, page, 'at 1280 (admin)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, { width: 1280, height: 800 });
  console.log('1280 x 800 — a Member (Priya)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const a = await page.locator('#home-section-shared').boundingBox();
  const b = await page.locator('#home-section-requests').boundingBox();
  ok(Math.abs(a.y - b.y) < 5 && a.width < 500, 'the Member\'s blocks sit side by side (' + Math.round(a.width) + ' px each)');
  await page.screenshot({ path: SHOTS + '/desktop-home-member.png', fullPage: true });
  for (const u of ['/curate/', '/admin/', '/admin/settings', '/curate/storage']) { const r = await page.goto(BASE + u, { waitUntil: 'networkidle' }); ok(r.status() === 403, u + ' is refused to a Member (403)'); }
  clean(errors, page, 'at 1280 (member)');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
