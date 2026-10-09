// Slice 3's browser proof (spec "Proof" 12): at 375 x 740 and 1280 x 800 — the share screen with the picker adding a person and a group, restrict with
// the confirm, the link made and shown once, the public page on a phone with the download, the request page with a drop; screenshots; no sideways
// scroll at 375; no console errors. Run through tests/phase3/slice3/run.sh.
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
const seed = JSON.parse(execFileSync('php', [new URL('../../phase2/seed.php', import.meta.url).pathname], { env: process.env }).toString());
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.doccloud.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  if (member) await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const contracts = seed.contracts;

// a second group to pick (the Board already holds Contracts through the library)
execFileSync('php', ['-r', `require $argv[1]; pdo()->exec("INSERT INTO groups (key, name, kind, created_by) VALUES ('s3-browser', 'SMOKE Browser group', 'custom', 27) ON CONFLICT (key) DO NOTHING"); pdo()->exec("DELETE FROM folder_access WHERE folder_id = ${contracts} AND principal_kind IN ('member', 'group') AND NOT (principal_kind = 'member' AND principal_id = 27)");`, new URL('./lib.php', import.meta.url).pathname], { env: process.env });
async function pick(page, field, text, label) {
  await page.click('#' + field + '-search-btn');
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  await page.fill('#record-picker-search', text);
  await page.waitForTimeout(600);
  await page.locator('#record-picker .record-picker-row').filter({ hasText: label }).first().click();
  await page.waitForSelector('#record-picker.show', { state: 'detached', timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(300);
}

// ---- desktop: Marco shares Contracts with the picker, restricts, makes a link ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the share screen');
  await page.goto(BASE + '/folders/' + contracts + '/share', { waitUntil: 'networkidle' });
  ok(await shown(page, '#share-table') && await shown(page, '#share-add-person') && await shown(page, '#share-restrict-btn'), 'the share screen: the table, the add forms, Restrict');
  await page.click('#share-member-search-btn');
  await page.waitForSelector('#record-picker.show', { timeout: 5000 }).catch(() => {});
  ok(await shown(page, '#record-picker'), 'the picker opens for a person');
  await page.fill('#record-picker-search', 'Bea');
  await page.waitForTimeout(600);
  await page.locator('#record-picker .record-picker-row').filter({ hasText: 'SMOKE Bea' }).first().click();
  await page.waitForTimeout(400);
  ok((await page.inputValue('#share-member')) === '28', 'Bea chosen (the hidden input holds 28)');
  await page.selectOption('#share-member-level', 'view');
  await page.click('#share-member-btn');
  await page.waitForTimeout(1000);
  await page.goto(BASE + '/folders/' + contracts + '/share', { waitUntil: 'networkidle' });
  ok((await page.locator('#share-rows tr[data-principal="member:28"]').count()) === 1, 'the row for Bea is on the screen');
  await pick(page, 'share-group', 'Browser', 'SMOKE Browser group');
  ok((await page.inputValue('#share-group')) !== '', 'a group chosen through the picker');
  await page.selectOption('#share-group-level', 'edit');
  await page.click('#share-group-btn');
  await page.waitForTimeout(1000);
  await page.goto(BASE + '/folders/' + contracts + '/share', { waitUntil: 'networkidle' });
  ok((await page.locator('#share-rows tr[data-principal^="group:"][data-level="edit"]').count()) === 1, 'the group\'s row at edit is on the screen');
  await page.screenshot({ path: SHOTS + '/desktop-share.png', fullPage: true });
  // restrict with the confirm: keep the ticked rows (this folder's own rows are ticked)
  page.once('dialog', (d) => d.accept());
  await page.click('#share-restrict-btn');
  await page.waitForTimeout(1000);
  await page.goto(BASE + '/folders/' + contracts + '/share', { waitUntil: 'networkidle' });
  ok((await page.locator('#share-rows tr[data-principal="member:28"]').count()) === 1 && (await page.locator('#share-inherited tr').count()) === 0, 'after Restrict (confirmed): Bea\'s row kept, nothing inherited');
  // a link, shown once
  await page.goto(BASE + '/links/new?folder=' + contracts, { waitUntil: 'networkidle' });
  await page.fill('#link-label', 'browser link');
  await page.click('#link-form-submit');
  await page.waitForTimeout(1200);
  ok(await shown(page, '#link-url-once') && /\/s\/[a-f0-9]{48}$/.test(await page.inputValue('#link-url-input')), 'the link is made and its URL shown once with Copy');
  const linkUrl = await page.inputValue('#link-url-input');
  await page.screenshot({ path: SHOTS + '/desktop-link-once.png', fullPage: true });
  await page.reload({ waitUntil: 'networkidle' });
  ok(await shown(page, '#link-url-gone'), 'reloaded: the URL is gone');
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ': ' + errors.join(' | ') : ''));
  fs.writeFileSync('/tmp/dc-s3-link.txt', linkUrl);
  await ctx.close();
}
// ---- phone: the public link page and the request page ----
{
  const linkUrl = fs.readFileSync('/tmp/dc-s3-link.txt', 'utf8').trim().replace(/^https?:\/\/[^/]+/, BASE);
  const { ctx, page, errors } = await session(null, { width: 375, height: 740 });
  console.log('375 x 740 — a link holder, an uploader');
  await page.goto(linkUrl, { waitUntil: 'networkidle' });
  ok(await shown(page, '#public-listing') && await shown(page, '#public-zip-btn') && (await page.locator('#left-sidenav').count()) === 0, 'the public folder page on a phone: the listing and Download all, no shell');
  const o1 = await overflow(page);
  ok(o1.sw <= o1.iw, 'no sideways scroll on the public page (' + o1.sw + ' <= ' + o1.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-public-folder.png', fullPage: true });
  // the request page
  const req = execFileSync('php', ['-r', `require '${new URL('./lib.php', import.meta.url).pathname}'; $m = make_request(27, ['folder' => ${contracts}, 'label' => 'Browser request', 'message' => 'Drop your files.']); echo $m['path'];`], { env: process.env }).toString().trim();
  await page.goto(BASE + req, { waitUntil: 'networkidle' });
  ok(await shown(page, '#request-drop-zone') && await shown(page, '#public-request-title'), 'the request page: who asks and the drop zone');
  const tmp = fs.mkdtempSync('/tmp/dc-s3-browser-');
  fs.writeFileSync(tmp + '/from-phone.txt', 'hello from a phone\n');
  await page.setInputFiles('#public-request-file', tmp + '/from-phone.txt');
  await page.click('#public-request-send');
  await page.waitForURL(/done=1/, { timeout: 8000 });
  ok(await shown(page, '#public-thanks'), 'after the upload: thank you, nothing shown back');
  const o2 = await overflow(page);
  ok(o2.sw <= o2.iw, 'no sideways scroll on the request page');
  await page.screenshot({ path: SHOTS + '/phone-public-request.png', fullPage: true });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- phone: the share screen for a manager ----
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  await page.goto(BASE + '/folders/' + contracts + '/share', { waitUntil: 'networkidle' });
  ok(await shown(page, '#share-table'), 'the share screen on a phone');
  const o = await overflow(page);
  ok(o.sw <= o.iw, 'no sideways scroll on the share screen (' + o.sw + ' <= ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-share.png', fullPage: true });
  await page.goto(BASE + '/shared/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#shared-table'), 'Shared with me on a phone');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
