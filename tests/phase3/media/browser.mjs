// The grid-and-media browser proof (docs/build-specs/grid-and-media.md "Proof"): at 1280 x 800 (Marco) and 375 x 740 (Priya) — the grid's tiles
// a row, the thumbnails loaded, the hide-preview switch swapping its tile in place and surviving a reload, the document page's placeholder and
// Show, a WebM streaming and playing through the gate, an MP4 either playing or falling back (this Chromium may lack H.264), the AVI's message;
// screenshots; no sideways scroll at 375; no console errors. Run through tests/phase3/media/run.sh.
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
const seed = JSON.parse(execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { env: process.env }).toString());
const ids = seed.ids;
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
const perRow = (page) => page.evaluate(() => { const t = [...document.querySelectorAll('#listing-grid > .col')]; if (!t.length) return 0; const top = t[0].getBoundingClientRect().top; return t.filter((c) => Math.abs(c.getBoundingClientRect().top - top) < 2).length; });
const imgLoaded = (page, sel) => page.evaluate(async (s) => { const i = document.querySelector(s); if (!i) return false; if (!i.complete) await new Promise((r) => { i.onload = r; i.onerror = r; setTimeout(r, 5000); }); return i.naturalWidth > 0; }, sel);

// ---- desktop: Marco ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the grid');
  await page.goto(BASE + '/f/' + seed.media + '?view=grid', { waitUntil: 'networkidle' });
  ok(await shown(page, '#listing-grid') && (await page.locator('#listing-grid .listing-tile').count()) >= 9, 'the grid shows a tile per item');
  ok(await perRow(page) === 5, 'five tiles a row at 1280 (' + await perRow(page) + ')');
  ok(await imgLoaded(page, `#tile-document-${ids.png}-img`) && await imgLoaded(page, `#tile-document-${ids.mp4}-img`), 'the image\'s and the video\'s thumbnails load');
  ok(await shown(page, `#tile-document-${ids.docx}-icon`) && (await page.locator(`#tile-document-${ids.docx}-icon .tile-ext`).innerText()) === 'DOCX', 'the DOCX shows its type icon and DOCX');
  ok(await shown(page, `#tile-document-${ids.jpg}-placeholder`) && (await page.locator(`#tile-document-${ids.jpg} img`).count()) === 0, 'the hidden JPEG shows the placeholder, with no <img> in its tile');
  await page.screenshot({ path: `${SHOTS}/media-desktop-grid.png`, fullPage: true });
  console.log('1280 x 800 — the switch');
  await page.locator(`label[for="tile-document-${ids.png}-switch"]`).click();
  await page.waitForSelector(`#tile-document-${ids.png}.is-hidden`, { timeout: 10000 }).catch(() => {});
  ok(await shown(page, `#tile-document-${ids.png}-placeholder`) && (await page.locator(`#tile-document-${ids.png} img`).count()) === 0, 'flipping the switch swaps the tile in place: the placeholder, no image');
  ok(await page.locator(`#tile-document-${ids.png}-switch`).isChecked(), '...the switch stays on');
  await page.reload({ waitUntil: 'networkidle' });
  ok(await shown(page, `#tile-document-${ids.png}-placeholder`), 'after a reload it is still hidden (saved, for everyone)');
  await page.locator(`label[for="tile-document-${ids.png}-switch"]`).click();
  await page.waitForSelector(`#tile-document-${ids.png}:not(.is-hidden)`, { timeout: 10000 }).catch(() => {});
  ok(await imgLoaded(page, `#tile-document-${ids.png}-img`), 'flipping it back shows the thumbnail again');
  console.log('1280 x 800 — the document page');
  await page.goto(BASE + '/documents/' + ids.jpg, { waitUntil: 'networkidle' });
  ok(await shown(page, '#document-preview-hidden') && (await page.locator('#document-preview-image').count()) === 0, 'the hidden JPEG\'s page: the placeholder, the image not in the page');
  ok(await page.locator('#document-preview-switch').isChecked(), '...the switch on for Marco');
  await page.screenshot({ path: `${SHOTS}/media-desktop-hidden.png` });
  await page.locator('#document-preview-show-btn').click();
  ok(await imgLoaded(page, '#document-preview-image') && !(await shown(page, '#document-preview-hidden')), 'Show reveals the image for this view');
  await page.goto(BASE + '/documents/' + ids.webm, { waitUntil: 'networkidle' });
  const webm = await page.evaluate(async () => {
    const v = document.getElementById('document-preview-video'); if (!v) return { none: true };
    if (v.readyState < 1) await new Promise((r) => { v.addEventListener('loadedmetadata', r, { once: true }); setTimeout(r, 8000); });
    v.muted = true; try { await v.play(); } catch (e) { return { readyState: v.readyState, playError: String(e) }; }
    await new Promise((r) => setTimeout(r, 1200));
    return { readyState: v.readyState, duration: v.duration, currentTime: v.currentTime };
  });
  ok(webm.readyState >= 1 && Math.abs(webm.duration - 3) < 0.4 && webm.currentTime > 0.3, 'the WebM streams from the gate and plays (' + JSON.stringify(webm) + ')');
  await page.screenshot({ path: `${SHOTS}/media-desktop-video.png` });
  await page.goto(BASE + '/documents/' + ids.mp4, { waitUntil: 'networkidle' });
  const mp4 = await page.evaluate(async () => {
    const v = document.getElementById('document-preview-video');
    if (v && v.readyState < 1) await new Promise((r) => { v.addEventListener('loadedmetadata', r, { once: true }); v.addEventListener('error', r, { once: true }); v.querySelector('source')?.addEventListener('error', r, { once: true }); setTimeout(r, 8000); });
    await new Promise((r) => setTimeout(r, 300));
    const fb = document.getElementById('document-preview-video-fallback');
    return { playable: !!v && v.readyState >= 1 && !v.classList.contains('d-none'), fallback: !!fb && !fb.classList.contains('d-none') };
  });
  ok(mp4.playable !== mp4.fallback, 'the MP4 either plays or says it cannot here — never a dead player (' + (mp4.playable ? 'it plays' : 'this Chromium lacks H.264: the fallback') + ')');
  await page.goto(BASE + '/documents/' + ids.avi, { waitUntil: 'networkidle' });
  ok(await shown(page, '#document-preview-unplayable') && (await page.locator('#document-preview-video').count()) === 0, 'the AVI: the message and Download');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- phone: Priya ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — the grid');
  await page.goto(BASE + '/f/' + seed.media + '?view=grid', { waitUntil: 'networkidle' });
  ok(await perRow(page) === 2, 'two tiles a row at 375 (' + await perRow(page) + ')');
  const o = await overflow(page);
  ok(o.sw <= o.iw, 'no sideways scroll (' + o.sw + ' <= ' + o.iw + ')');
  ok(await shown(page, `#tile-document-${ids.png}-switch-form`), 'Priya (edit) has the switch under the image');
  await page.locator(`label[for="tile-document-${ids.png}-switch"]`).tap();
  await page.waitForSelector(`#tile-document-${ids.png}.is-hidden`, { timeout: 10000 }).catch(() => {});
  ok(await shown(page, `#tile-document-${ids.png}-placeholder`), 'a tap hides it');
  await page.screenshot({ path: `${SHOTS}/media-phone-grid.png` });   // the viewport: a full-page shot resizes a mobile viewport under the next tap
  await page.locator(`label[for="tile-document-${ids.png}-switch"]`).tap();
  await page.waitForSelector(`#tile-document-${ids.png}:not(.is-hidden)`, { timeout: 10000 }).catch(() => {});
  ok(await imgLoaded(page, `#tile-document-${ids.png}-img`), 'a second tap shows it');
  await page.goto(BASE + '/documents/' + ids.jpg, { waitUntil: 'networkidle' });
  const o2 = await overflow(page);
  ok(await shown(page, '#document-preview-hidden') && await shown(page, '#document-preview-show-btn') && o2.sw <= o2.iw, 'the hidden JPEG\'s page at 375: the placeholder and Show, no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/media-phone-hidden.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
