// Slice 2's browser proof (spec "Proof" 14): at 375 x 740 and 1280 x 800 — the folder page's drop zone with three files chosen (setInputFiles),
// the progress rows reaching done, a conflict chosen (Keep both), the document page for a PDF, an image and a DOCX (the preview, the versions
// table), the upload screen; screenshots; no sideways scroll at 375; no console errors. Run through tests/phase3/slice2/run.sh.
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 409/.test(m.text())) errors.push(m.text()); });   // a 409 (a conflict asked) is the uploader's own answer, not an error
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);

// fixtures on disk
const dir = fs.mkdtempSync('/tmp/dc-s2-browser-');
const mkPng = (p) => { execFileSync('php', ['-r', `$im = imagecreatetruecolor(400, 260); imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 180)); imagepng($im, $argv[1]);`, p]); };
mkPng(dir + '/browser-photo.png');
execFileSync('php', ['-r', `require $argv[1]; file_put_contents($argv[2], fx_pdf([fx_wrap(fx_para(41, 700))])); copy(fx_docx('b.docx', [['h1', 'Browser memo'], ['p', fx_para(42, 900)]]), $argv[3]); file_put_contents($argv[4], fx_para(43, 500));`, new URL('../../extract/fixtures.php', import.meta.url).pathname, dir + '/browser-brief.pdf', dir + '/browser-memo.docx', dir + '/browser-notes.txt']);

// ---- desktop: Marco uploads three files onto Contracts, then a conflict ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the drop zone and the progress rows');
  await page.goto(BASE + '/f/' + seed.contracts + '/upload', { waitUntil: 'networkidle' });
  ok(await shown(page, '#drop-zone') && await shown(page, '#upload-choose-btn'), 'the upload screen shows the drop zone and Choose files');
  await page.setInputFiles('#upload-files-input', [dir + '/browser-photo.png', dir + '/browser-brief.pdf', dir + '/browser-memo.docx']);
  await page.waitForFunction(() => document.querySelectorAll('.upload-row[data-state="done"]').length === 3, null, { timeout: 30000 }).catch(() => {});
  const states = await page.$$eval('.upload-row', (rows) => rows.map((r) => r.dataset.state + ':' + r.dataset.name));
  ok(states.length === 3 && states.every((s) => s.startsWith('done:')), 'three progress rows reach done (' + states.join(', ') + ')');
  ok((await page.locator('#upload-summary').innerText()).includes('3 of 3'), 'the summary says 3 of 3 done');
  ok((await page.locator('#upload-open-1').count()) === 1, 'a done row links to the document');
  await page.screenshot({ path: `${SHOTS}/desktop-upload-rows.png` });
  // the same file again → a conflict → Keep both
  await page.setInputFiles('#upload-files-input', [dir + '/browser-photo.png']);
  await page.waitForSelector('.upload-row[data-state="conflict"]', { timeout: 15000 }).catch(() => {});
  ok((await page.locator('.upload-row[data-state="conflict"]').count()) === 1 && await shown(page, '[id^="upload-conflict-both-"]'), 'a same-named file shows the conflict choice inline');
  await page.screenshot({ path: `${SHOTS}/desktop-upload-conflict.png` });
  await page.click('[id^="upload-conflict-both-"]');
  await page.waitForFunction(() => document.querySelectorAll('.upload-row[data-state="done"]').length === 4, null, { timeout: 30000 }).catch(() => {});
  const msg = await page.locator('.upload-row:nth-child(4) .upload-msg').innerText().catch(() => '');
  ok(msg.includes('browser-photo (2).png'), 'Keep both stores "browser-photo (2).png" (' + msg.trim() + ')');
  // the folder page lists them
  await page.goto(BASE + '/f/' + seed.contracts, { waitUntil: 'networkidle' });
  const names = await page.$$eval('#listing-table .listing-name a', (as) => as.map((a) => a.textContent.trim()));
  ok(['browser-photo.png', 'browser-brief.pdf', 'browser-memo.docx', 'browser-photo (2).png'].every((n) => names.includes(n)), 'the folder lists the four documents');
  ok(await shown(page, '#folder-upload-btn') && (await page.locator('#upload-zone-wrap.d-none').count()) === 1, 'the folder page has Upload and a hidden drop zone');
  // the document pages
  await page.click('#listing-table .listing-name a:has-text("browser-brief.pdf")');
  await page.waitForSelector('#document-preview[data-mode="pdf"]', { timeout: 10000 });
  ok(await shown(page, '#document-preview-pdf') && await shown(page, '#versions-table') && await shown(page, '#document-download-btn'), 'the PDF page: the viewer, the versions table, Download');
  await page.screenshot({ path: `${SHOTS}/desktop-document-pdf.png` });
  await page.goto(BASE + '/f/' + seed.contracts, { waitUntil: 'networkidle' });
  await page.click('#listing-table .listing-name a:has-text("browser-memo.docx")');
  await page.waitForSelector('#document-preview', { timeout: 10000 });
  const mode = await page.getAttribute('#document-preview', 'data-mode');
  ok(mode === 'extracted' || mode === 'none', 'the DOCX page shows the extracted text (or waits for the worker): ' + mode);
  await page.screenshot({ path: `${SHOTS}/desktop-document-docx.png` });
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
// ---- phone: Priya on the image and the upload screen ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — a Member on a document and the upload screen');
  const imgId = execFileSync('php', ['-r', `require $argv[1]; echo one("SELECT id FROM documents WHERE name = 'browser-photo.png' AND folder_id = " . (int) $argv[2]);`, new URL('lib.php', import.meta.url).pathname, String(seed.contracts)], { env: process.env }).toString().trim();
  await page.goto(BASE + '/documents/' + imgId, { waitUntil: 'networkidle' });
  ok(await shown(page, '#document-preview-image') && await shown(page, '#document-download-btn'), 'the image page shows the picture and Download');
  let o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the document page (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#versions-table') && !(await shown(page, '#versions-table thead')), 'the versions table is the list form on a phone');
  await page.screenshot({ path: `${SHOTS}/phone-document-image.png` });
  await page.goto(BASE + '/f/' + seed.contracts + '/upload', { waitUntil: 'networkidle' });
  ok(await shown(page, '#upload-choose-btn') && !(await shown(page, '#upload-choose-folder-btn')), 'the upload screen on a phone: Choose files, no folder chooser');
  o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the upload screen (${o.sw} = ${o.iw})`);
  await page.setInputFiles('#upload-files-input', [dir + '/browser-notes.txt']);
  await page.waitForFunction(() => document.querySelectorAll('.upload-row[data-state="done"]').length === 1, null, { timeout: 20000 }).catch(() => {});
  ok((await page.locator('.upload-row[data-state="done"]').count()) === 1, 'a file chosen on the phone uploads to done');
  await page.screenshot({ path: `${SHOTS}/phone-upload.png` });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
fs.rmSync(dir, { recursive: true, force: true });
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
