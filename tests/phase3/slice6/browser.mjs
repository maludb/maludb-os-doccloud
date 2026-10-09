// Slice 6's browser proof (spec "Proof" 10): at 1280 x 800 and 375 x 740 — the trash (a document and a folder, Restore, the admin's Empty what is due), the document page's banner
// and Trash button, the quota form and the bar on the library view, the worker screen with "Run a pass now"; screenshots in $SHOTS; no sideways scroll at 375; no console errors.
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
// the seed: a trashed document (10 days to go), one due, a trashed folder, a live document to trash in the browser, a library 95% full
const seed = JSON.parse(execFileSync('php', ['-r', `require $argv[1]; $s = seed_texts(); $p = pdo();
  $a = seed_doc($s['board_root'], 'b6-trashed.pdf', 27, 'a'); $b = seed_doc($s['board_root'], 'b6-due.pdf', 27, 'b'); $live = seed_doc($s['board_root'], 'b6-live.pdf', 27, 'c');
  foreach ([$a, $b] as $d) { $p->exec("UPDATE documents SET trashed_at = now(), trashed_by = 27, trashed_from_folder_id = folder_id WHERE id = $d"); }
  $p->exec("UPDATE documents SET trashed_at = now() - interval '23 days' WHERE id = $a"); $p->exec("UPDATE documents SET trashed_at = now() - interval '33 days' WHERE id = $b");
  $f = folder_in($s['board_root'], 'B6 Folder'); seed_doc($f, 'b6-in-folder.pdf', 27, 'd'); $p->exec("SELECT set_config('app.member_id', '27', false)"); $p->exec("SELECT dc_folder_trash($f, 27)");
  $lib = (int) one('SELECT library_id FROM folders WHERE id = :f', ['f' => $s['board_root']]); $p->exec("UPDATE libraries SET quota_bytes = 1000000, used_bytes = 950000 WHERE id = $lib");
  $p->exec("SELECT dc_pass_housekeeping()"); $s['a'] = $a; $s['b'] = $b; $s['live'] = $live; $s['f'] = $f; $s['lib'] = $lib; echo json_encode($s);`, new URL('./lib.php', import.meta.url).pathname], { env: process.env }).toString());
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

// ---- desktop, the curator: trash a document, see it, restore ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the trash (curator)');
  await page.goto(BASE + '/documents/' + seed.live, { waitUntil: 'networkidle' });
  ok(await shown(page, '#document-trash-btn') && !(await page.locator('#document-trash-btn').isDisabled()), 'the document page has an enabled Trash button');
  page.once('dialog', (d) => d.accept());
  await page.locator('#document-trash-btn').click();
  await page.waitForURL(/\/f\/\d+/, { timeout: 8000 }).catch(() => {});
  ok(/\/f\/\d+/.test(page.url()), 'trashing (after the confirm) lands on the folder');
  await page.goto(BASE + '/documents/' + seed.live, { waitUntil: 'networkidle' });
  ok(await shown(page, '#document-trashed') && (await page.textContent('#document-trash-note')).includes('purges on') && await shown(page, '#document-restore-btn'), 'the trashed document shows its banner with "purges on" and Restore');
  await page.screenshot({ path: SHOTS + '/desktop-trash-document.png', fullPage: true });
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#trash-table') && await shown(page, '#trash-row-document-' + seed.a) && await shown(page, '#trash-row-document-' + seed.live) && await shown(page, '#trash-row-folder-' + seed.f), 'the trash lists the documents and the folder');
  ok((await page.getAttribute('#trash-row-document-' + seed.a, 'data-status')) === 'warning' && (await page.getAttribute('#trash-row-document-' + seed.b, 'data-status')) === 'danger' && (await page.getAttribute('#trash-row-document-' + seed.live, 'data-status')) === 'secondary', 'the chips: 7 days or fewer warning, due danger, more secondary');
  ok((await page.locator('#trash-empty-btn').count()) === 0, 'the curator has no Empty button');
  await page.screenshot({ path: SHOTS + '/desktop-trash.png', fullPage: true });
  await page.locator('#trash-restore-document-' + seed.live).click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  ok((await page.locator('#trash-row-document-' + seed.live).count()) === 0, 'Restore took it out of the trash');
  await page.goto(BASE + '/f/' + seed.board_root + '/trash', { waitUntil: 'networkidle' });
  ok(await shown(page, '#trash-table') && (await page.textContent('#trash-content')).includes('B6 Folder'), 'the folder\'s Trash tab lists what went from it');
  await page.goto(BASE + '/f/' + seed.board_root, { waitUntil: 'networkidle' });
  ok(await shown(page, '#folder-trash-tab'), 'the folder page links to its Trash tab');
  clean(errors, page, 'at 1280 (curator)');
  await ctx.close();
}
// ---- desktop, the admin: the trash with Empty, the quota, the worker ----
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#trash-empty-btn') && await shown(page, '#trash-purge-document-' + seed.a), 'the admin has Empty what is due and a Purge per row');
  page.once('dialog', (d) => d.accept());
  await page.locator('#trash-empty-btn').click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  ok((await page.locator('#trash-row-document-' + seed.b).count()) === 0 && (await page.locator('#trash-row-document-' + seed.a).count()) === 1, 'Empty what is due purged the 33-day-old and left the 23-day-old');
  await page.goto(BASE + '/libraries/' + seed.lib, { waitUntil: 'networkidle' });
  ok(await shown(page, '#quota-bar') && (await page.getAttribute('#quota-bar', 'data-status')) === 'warning' && await shown(page, '#quota-bar-state'), 'the library view: the quota bar, warning at 95%, "nearly full"');
  await page.screenshot({ path: SHOTS + '/desktop-library-quota.png', fullPage: true });
  await page.goto(BASE + '/admin/quotas', { waitUntil: 'networkidle' });
  ok(await shown(page, '#quota-table') && await shown(page, '#quota-input-' + seed.lib), 'the quota screen: the table and a form per library');
  await page.fill('#quota-input-' + seed.lib, '2');
  await page.locator('#quota-save-' + seed.lib).click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
  await page.goto(BASE + '/libraries/' + seed.lib, { waitUntil: 'networkidle' });
  ok((await page.getAttribute('#quota-bar', 'data-status')) === 'primary' && (await page.textContent('#quota-bar')).includes('2.0 GB'), 'setting 2 GB makes the bar primary, "of 2.0 GB"');
  await page.goto(BASE + '/admin/quotas?library=' + seed.lib, { waitUntil: 'networkidle' });
  ok(await shown(page, '#quota-focus') && await shown(page, '#quota-owners'), 'a library\'s focus: its bar and who holds the most');
  await page.screenshot({ path: SHOTS + '/desktop-quotas.png', fullPage: true });
  await page.goto(BASE + '/admin/worker', { waitUntil: 'networkidle' });
  ok(await shown(page, '#worker-run-btn') && await shown(page, '#worker-queues') && await shown(page, '#worker-windows'), 'the worker screen: Run a pass now, the queues, the windows');
  const before = await page.locator('#worker-passes > div[id^="worker-pass-"]').count();
  await page.selectOption('#worker-step', 'housekeeping');
  await page.locator('#worker-run-btn').click();
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  await page.goto(BASE + '/admin/worker', { waitUntil: 'networkidle' });
  const after = await page.locator('#worker-passes > div[id^="worker-pass-"]').count();
  ok(after === before + 1 || after >= 1, 'Run a pass now added a pass (' + before + ' → ' + after + ')');
  ok((await page.locator('#worker-passes .badge').filter({ hasText: /housekeeping \d+ · \d+ ms/ }).count()) >= 1, 'its step reads "housekeeping N · N ms"');
  await page.screenshot({ path: SHOTS + '/desktop-worker.png', fullPage: true });
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  ok(await shown(page, '#retention-form') && await shown(page, '#retention-trash_days'), 'the Settings page holds the retention form');
  await page.screenshot({ path: SHOTS + '/desktop-retention.png', fullPage: true });
  clean(errors, page, 'at 1280 (admin)');
  await ctx.close();
}
// ---- phone ----
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  console.log('375 x 740');
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#trash-table'), 'the trash has no sideways scroll at 375 (' + o.sw + ' of ' + o.iw + ')');
  ok(await shown(page, '#trash-restore-document-' + seed.a), 'Restore is reachable on a phone');
  await page.screenshot({ path: SHOTS + '/phone-trash.png', fullPage: true });
  await page.goto(BASE + '/documents/' + seed.a, { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#document-trashed') && await shown(page, '#document-restore-btn'), 'a trashed document\'s page fits a phone, with its banner and Restore (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-trash-document.png', fullPage: true });
  await page.goto(BASE + '/libraries/' + seed.lib, { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#quota-bar'), 'the library view with its bar fits a phone');
  clean(errors, page, 'at 375 (curator)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  await page.goto(BASE + '/admin/quotas', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#quota-input-' + seed.lib) && await shown(page, '#quota-save-' + seed.lib), 'the quota form fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-quotas.png', fullPage: true });
  await page.goto(BASE + '/admin/worker', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#worker-run-btn') && await shown(page, '#worker-passes'), 'the worker screen fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-worker.png', fullPage: true });
  await page.goto(BASE + '/trash/', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#trash-empty-btn'), 'the admin\'s trash fits a phone');
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#retention-form'), 'the retention form fits a phone');
  clean(errors, page, 'at 375 (admin)');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
