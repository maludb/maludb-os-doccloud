// Slice 1's browser proof (spec "Proof" 4, 14): at 375 x 740 and 1280 x 800 — the folder page with a long listing (no sideways scroll, the
// list form on a phone), the form, the move picker opening and choosing, the libraries cards, a group's members table; screenshots; no
// console errors. Run through tests/phase3/slice1/run.sh (the servers and the environment). Playwright from the kernel's web/node_modules.
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
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44) && !a.classList.contains('btn-sm') && !a.classList.contains('avatar-text'); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// a long listing in the Board's root: 60 folders
execFileSync('php', ['-r', `require "${new URL('lib.php', import.meta.url).pathname}"; pdo()->exec("SELECT set_config('app.member_id', '27', false)"); for ($i = 1; $i <= 60; $i++) { pdo()->prepare("INSERT INTO folders (library_id, parent_id, name, owner_member_id, created_by) VALUES ((SELECT library_id FROM folders WHERE id = :p), :p, :n, 27, 27) ON CONFLICT DO NOTHING")->execute(['p' => ${seed.board_root}, 'n' => sprintf('Browser folder %02d with a rather long name to wrap', $i)]); }`], { env: process.env });

// ---- phone: Priya in the Board's root ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — a Member on a folder with a long listing');
  await page.goto(BASE + '/f/' + seed.board_root, { waitUntil: 'networkidle' });
  ok((await page.title()).startsWith('SMOKE Board'), 'the folder page opens with its name as the title');
  let o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on a folder with 60 rows (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#listing-table') && (await page.locator('#listing-table tbody tr').count()) >= 61, 'the listing table shows at least 61 rows (60 folders, Contracts, …)');
  ok(!(await shown(page, '#listing-table thead')) && await shown(page, '#listing-table .listing-sub'), 'on a phone the table header is hidden and each row carries kind · modified beneath the name (the list form)');
  ok(await shown(page, '#folder-access-line') && await shown(page, '#folder-crumbs'), 'the access line and the crumbs are shown');
  const past = await page.evaluate(() => [...document.querySelectorAll('#page-content *')].filter((e) => e.getBoundingClientRect().right > window.innerWidth + 0.5 && getComputedStyle(e).display !== 'none').map((e) => e.id || e.className).slice(0, 5));
  ok(past.length === 0, 'nothing reaches past the right edge' + (past.length ? ': ' + past.join(', ') : ''));
  const small = await smallControls(page, '#page-content');
  ok(small.length === 0, 'every control is at least 44 px' + (small.length ? ': ' + small.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-folder-listing.png` });
  await page.check('#listing-table tbody tr:nth-child(2) .listing-check');
  await page.waitForTimeout(100);
  ok(await shown(page, '#select-bar') && (await page.locator('#select-bar-count').innerText()) === '1 selected', 'ticking a row shows the select bar with "1 selected"');
  await page.click('#folder-new-btn');
  await page.waitForSelector('#folder-form-field-name');
  ok(page.url().includes('/folders/new?parent=' + seed.board_root) && await page.evaluate(() => document.activeElement && document.activeElement.id === 'folder-form-field-name'), 'New folder opens the form with the name focused');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the form');
  await page.screenshot({ path: `${SHOTS}/phone-folder-form.png` });
  await page.fill('#folder-form-field-name', 'Phone folder');
  await page.click('#folder-form-colour-green');
  await page.click('#folder-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#folder-title').innerText()) === 'Phone folder' && (await page.locator('#notice-banner').innerText()).includes('Folder made') && await shown(page, '#folder-colour-dot'), 'saving lands on the new folder with the notice and its colour dot');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Marco moves a folder with the picker, the libraries cards, a group's members ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — a curator: the move picker, the libraries, a group');
  await page.goto(BASE + '/f/' + seed.board_root, { waitUntil: 'networkidle' });
  ok(await shown(page, '#listing-table thead') && await shown(page, '#listing-sort-size'), 'on a desktop the table has its header with sortable columns');
  await page.screenshot({ path: `${SHOTS}/desktop-folder-listing.png` });
  const phoneFolder = await page.evaluate(() => { const a = [...document.querySelectorAll('#listing-table a')].find((x) => x.textContent.trim() === 'Phone folder'); return a ? a.getAttribute('href').replace(/\?.*$/, '') : ''; });
  ok(phoneFolder.startsWith('/f/'), 'the folder made on the phone is listed (' + phoneFolder + ')');
  await page.goto(BASE + phoneFolder.replace('/f/', '/folders/') + '/move', { waitUntil: 'networkidle' });
  ok(await shown(page, '#folder-move-field-target-open'), 'the move screen shows the picker field');
  await page.click('#folder-move-field-target-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-list');
  ok(await shown(page, '#record-picker-search') && (await page.locator('.record-picker-row').count()) >= 10, 'the picker opens with a page of folders');
  await page.fill('#record-picker-search', 'Contracts');
  await page.waitForFunction(() => { const r = document.querySelector('.record-picker-row'); return r && r.textContent.includes('Contracts'); }, null, { timeout: 8000 }).catch(() => {});
  ok((await page.locator('.record-picker-row').count()) >= 1 && (await page.locator('.record-picker-row').first().innerText()).includes('Contracts'), 'the search narrows to Contracts');
  await page.screenshot({ path: `${SHOTS}/desktop-move-picker.png` });
  await page.locator('.record-picker-row').first().click();
  await page.waitForTimeout(500);
  ok(!(await shown(page, '#record-picker.show')) && (await page.locator('#folder-move-field-target').inputValue()) === String(seed.contracts) && (await page.locator('#folder-move-field-target-picker .record-picker-label').innerText()).includes('Contracts'), 'choosing closes the picker and fills the field with Contracts');
  await page.click('#folder-move-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Moved') && (await page.locator('#folder-crumbs').innerText()).includes('Contracts'), 'the move lands on the folder, now under Contracts');
  await page.goto(BASE + '/libraries', { waitUntil: 'networkidle' });
  ok((await page.locator('#library-cards .card').count()) >= 2 && await shown(page, '#library-new-btn'), 'the libraries page shows cards and New shared library for a curator');
  await page.screenshot({ path: `${SHOTS}/desktop-libraries.png` });
  await page.goto(BASE + '/groups/' + seed.group, { waitUntil: 'networkidle' });
  ok(await shown(page, '#group-members-table') && (await page.locator('#group-members-table tbody tr').count()) === 3 && await shown(page, '#group-add-members-btn'), 'the group page shows three members and Add');
  await page.screenshot({ path: `${SHOTS}/desktop-group.png` });
  await page.click('#group-add-members-btn');
  await page.waitForSelector('#group-add-field-member-open');
  await page.click('#group-add-field-member-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-list');
  const labels = await page.locator('.record-picker-row').allInnerTexts();
  ok(labels.some((t) => t.includes('SMOKE Dana')) && !labels.some((t) => t.includes('SMOKE Ann')) && !labels.some((t) => t.includes('SMOKE Priya')), 'the member picker offers Dana, hides the guest Ann and the member Priya');
  await page.locator('.record-picker-row').filter({ hasText: 'SMOKE Dana' }).first().click();
  await page.waitForTimeout(400);
  await page.click('#group-add-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#group-members-table tbody tr').count()) === 4, 'Dana is added through the picker: four members');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
