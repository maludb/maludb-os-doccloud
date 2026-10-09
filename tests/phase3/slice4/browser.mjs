// Slice 4's browser proof (spec "Proof" 11): at 375 x 740 and 1280 x 800 — the tag editor adding and removing a chip, the search page with chips and a
// marked excerpt, the tags page's proposals; screenshots; no sideways scroll at 375; no console errors. Run through tests/phase3/slice4/run.sh.
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
// the store with texts and a proposal, through PHP
const seed = JSON.parse(execFileSync('php', ['-r', `require $argv[1]; $s = seed_texts(); foreach ([[27, $s['board_doc']], [26, $s['minutes']], [30, $s['design']]] as [$m, $d]) { post_j($m, '/tags/apply.php', ['document' => $d, 'tags' => ['Browser Free']]); } post_j(27, '/tags/save.php', ['label' => 'Browser Managed', 'kind' => 'managed', 'colour' => '#3454d1']); echo json_encode($s);`, new URL('./lib.php', import.meta.url).pathname], { env: process.env }).toString());
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  const missing = [];
  page.on('response', (r) => { if (r.status() === 404) missing.push(new URL(r.url()).pathname); });
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  page.missing = () => missing.filter((p) => !/^\/d\//.test(p));   // the scratch seed's documents have no bytes on disk: a preview's /d/ 404 is the seed's, not the page's
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);

// ---- desktop: the editor on the document, the search page, the tags page ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the tag editor');
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  ok(await shown(page, '#tag-editor') && await shown(page, '#tag-input'), 'the document page shows the editor');
  await page.fill('#tag-input', 'Browser Managed');
  await page.press('#tag-input', 'Enter');
  await page.waitForTimeout(1200);
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  const chip = await page.locator('#document-tag-chips .tag-chip[data-slug="browser-managed"]').count();
  ok(chip === 1, 'Enter added the chip (browser-managed)');
  await page.screenshot({ path: SHOTS + '/desktop-document-tags.png', fullPage: true });
  await page.locator('#document-tag-chips .tag-chip[data-slug="browser-managed"] .tag-chip-remove').evaluate((el) => el.click());   // the sticky header overlays a scrolled chip: a DOM click, as a finger would land
  await page.waitForTimeout(1200);
  await page.goto(BASE + '/documents/' + seed.board_doc, { waitUntil: 'networkidle' });
  ok((await page.locator('#document-tag-chips .tag-chip[data-slug="browser-managed"]').count()) === 0, 'the × removed the chip');
  console.log('1280 x 800 — the search page');
  await page.goto(BASE + '/search?q=' + encodeURIComponent('ninety kind:pdf'), { waitUntil: 'networkidle' });
  ok(await shown(page, '#search-box') && await shown(page, '#search-chip-kind') && (await page.locator('#search-results .search-result').count()) >= 1, 'the search page: the box, a kind chip, results');
  ok((await page.locator('#search-results mark').count()) >= 1 && (await page.locator('#search-results mark').first().textContent()) === 'ninety', 'the excerpt marks the word');
  await page.screenshot({ path: SHOTS + '/desktop-search.png', fullPage: true });
  await page.locator('#search-chip-remove-kind').evaluate((el) => el.click());
  await page.waitForTimeout(800);
  ok((await page.locator('#search-chip-kind').count()) === 0, 'removing the chip re-runs without it');
  console.log('1280 x 800 — the tags page');
  await page.goto(BASE + '/tags', { waitUntil: 'networkidle' });
  ok(await shown(page, '#tags-card') && await shown(page, '#proposals-card') && (await page.locator('#proposals-list tr[data-kind="promote"]').count()) >= 1, 'the tags page: the chips and a promote proposal for the curator');
  await page.screenshot({ path: SHOTS + '/desktop-tags.png', fullPage: true });
  await page.locator('#proposals-list tr[data-kind="promote"] button[id^="proposal-accept-"]').first().evaluate((el) => el.click());
  await page.waitForTimeout(1200);
  await page.goto(BASE + '/tags', { waitUntil: 'networkidle' });
  ok((await page.locator('#tags-card .tag-chip[data-slug="browser-free"][data-state="managed"]').count()) === 1, 'Promote made the free tag managed');
  ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources at 1280' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
  await ctx.close();
}
// ---- phone ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740');
  await page.goto(BASE + '/search?q=termination', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1, 'the search page has no sideways scroll at 375 (' + o.sw + ' of ' + o.iw + ')');
  ok(await shown(page, '#search-results') && (await page.locator('#search-results .search-result').count()) >= 2, 'results on a phone');
  await page.screenshot({ path: SHOTS + '/phone-search.png', fullPage: true });
  await page.goto(BASE + '/documents/' + seed.minutes, { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#tag-editor'), 'the document page with the editor fits a phone');
  await page.screenshot({ path: SHOTS + '/phone-document-tags.png', fullPage: true });
  await page.goto(BASE + '/tags', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#tags-card'), 'the tags page fits a phone');
  await page.screenshot({ path: SHOTS + '/phone-tags.png', fullPage: true });
  ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources at 375' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
