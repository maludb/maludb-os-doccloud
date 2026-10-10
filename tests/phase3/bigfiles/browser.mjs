// The large-uploads browser proof (docs/build-specs/large-uploads.md "Proof" 10–13): at 1280 x 800 in Chromium through the real uploader —
// a 2.1 GB file chosen goes up to done (the browser refused anything over 2 GB before db/024) with the file's sha256; a 120 MB file whose page
// is left mid-way (the leave-page question asked) and chosen again carries on from where it stopped; a part the store refuses as full pauses
// the file with Resume, which finishes it; a part lost on the network is retried. Run through tests/phase3/bigfiles/run.sh.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8701';
const SHOTS = process.env.SHOTS || '/tmp/dc-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const PART = 8388608;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const seed = JSON.parse(execFileSync('php', [new URL('../../phase2/seed.php', import.meta.url).pathname], { env: process.env }).toString());
const lib = new URL('../../phase2/lib.php', import.meta.url).pathname;
const sql = (q, ...args) => JSON.parse(execFileSync('php', ['-r', 'require $argv[1]; $a = array_slice($argv, 3); echo json_encode(q($argv[2], $a));', lib, q, ...args.map(String)], { env: process.env }).toString());
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.doccloud.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
/** A file of $size bytes, every 8 MB part beginning with its number; answers its sha256. */
function bigFile(path, size) {
  const block = crypto.randomBytes(PART); const h = crypto.createHash('sha256'); const fd = fs.openSync(path, 'w');
  for (let off = 0, n = 1; off < size; off += PART, n++) {
    const len = Math.min(PART, size - off); const p = Buffer.from(block.subarray(0, len)); if (len >= 8) p.writeBigUInt64BE(BigInt(n), 0);
    fs.writeSync(fd, p); h.update(p);
  }
  fs.closeSync(fd); return h.digest('hex');
}
const rowState = (page, name) => page.evaluate((n) => { const r = [...document.querySelectorAll('.upload-row')].filter((x) => x.dataset.name === n).pop(); return r ? r.dataset.state : null; }, name);
const waitState = (page, name, st, timeout) => page.waitForFunction(([n, s]) => { const r = [...document.querySelectorAll('.upload-row')].filter((x) => x.dataset.name === n).pop(); return r && r.dataset.state === s; }, [name, st], { timeout }).then(() => true, () => false);
const docOf = (name) => sql('SELECT d.id, d.sha256, d.byte_size FROM documents d WHERE d.folder_id = ? AND d.name = ? AND d.trashed_at IS NULL', seed.contracts, name)[0] || {};

const dir = fs.mkdtempSync('/tmp/dc-big-browser-');
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();
const errors = [];
page.on('console', (m) => { if (m.type() === 'error' && !/status of (409|507)|net::ERR_FAILED/.test(m.text())) errors.push(m.text()); });
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
await page.goto(mint(27), { waitUntil: 'networkidle' });
const uploadUrl = BASE + '/f/' + seed.contracts + '/upload';

console.log('1280 x 800 — a file over 2 GB');
{
  const size = 2147483648 + 104857600 + 7;
  const sha = bigFile(dir + '/survey-2026.mkv', size);
  await page.goto(uploadUrl, { waitUntil: 'networkidle' });
  ok((await page.textContent('#upload-zone-hint')).includes('Files of any size.') && (await page.getAttribute('#upload-zone-wrap', 'data-max-bytes')) === '0', 'the zone says "Files of any size" and sets no ceiling for the browser');
  const t = Date.now();
  await page.setInputFiles('#upload-files-input', dir + '/survey-2026.mkv');
  await sleep(1500);
  ok((await rowState(page, 'survey-2026.mkv')) === 'uploading', 'a 2.1 GB file is uploading (the browser refused it before db/024): ' + await rowState(page, 'survey-2026.mkv'));
  const done = await waitState(page, 'survey-2026.mkv', 'done', 600000);
  ok(done, `it reaches done (${Math.round((Date.now() - t) / 1000)} s, 269 parts)`);
  const d = docOf('survey-2026.mkv');
  ok(d.sha256 === sha && Number(d.byte_size) === size, 'the document holds the file: the same sha256 and ' + size.toLocaleString() + ' bytes');
  await page.screenshot({ path: `${SHOTS}/bigfiles-done.png` });
  fs.unlinkSync(dir + '/survey-2026.mkv');
}

console.log('1280 x 800 — the page left mid-way, the same file chosen again');
{
  const sha = bigFile(dir + '/walkthrough.mov', 15 * PART - 4321);
  await page.goto(uploadUrl, { waitUntil: 'networkidle' });
  await page.click('#upload-content');                                         // a gesture: the browser asks before leaving only after one
  const cdp = await ctx.newCDPSession(page);                                   // a slow line (16 MB/s up) in the browser's own network stack
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 20, downloadThroughput: -1, uploadThroughput: 16 * 1048576 });
  await page.setInputFiles('#upload-files-input', dir + '/walkthrough.mov');
  await page.waitForFunction(() => { const b = document.querySelector('.upload-row .upload-state'); const m = b && b.textContent.match(/uploading (\d+)\//); return m && Number(m[1]) >= 4; }, null, { timeout: 30000 }).catch(() => {});
  let asked = null;
  page.once('dialog', async (dlg) => { asked = dlg.type(); await dlg.accept(); });
  await page.goto(uploadUrl, { waitUntil: 'networkidle' });
  ok(asked === 'beforeunload', 'leaving the page while a file goes up asks first (' + asked + ')');
  const held = sql("SELECT parts_done, status FROM upload_sessions WHERE filename = 'walkthrough.mov' ORDER BY id DESC LIMIT 1")[0] || {};
  ok(held.status === 'open' && Number(held.parts_done) >= 4 && Number(held.parts_done) < 15, `the server holds ${held.parts_done} of 15 parts, the session open`);
  ok((await page.textContent('#upload-open-sessions').catch(() => '')).includes('walkthrough.mov'), 'the upload screen lists it under Still arriving');
  await page.evaluate(() => { window.__seen = ''; new MutationObserver(() => { document.querySelectorAll('.upload-msg').forEach((m) => { if (m.textContent.includes('Carrying on')) window.__seen = m.textContent; }); }).observe(document.getElementById('upload-list'), { subtree: true, childList: true, characterData: true }); });
  await page.setInputFiles('#upload-files-input', dir + '/walkthrough.mov');
  ok(await waitState(page, 'walkthrough.mov', 'done', 60000), 'chosen again, it reaches done: ' + await rowState(page, 'walkthrough.mov'));
  const seen = await page.evaluate(() => window.__seen);
  ok(/Carrying on from \d+%: (\d+) of 15 parts were already here/.test(seen) && Number(seen.match(/: (\d+) of/)[1]) >= Number(held.parts_done), 'on the way the row said it carried on from where it stopped ("' + seen + '")');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await cdp.detach();
  const sessions = sql("SELECT count(*)::int AS n FROM upload_sessions WHERE filename = 'walkthrough.mov'")[0].n;
  ok(docOf('walkthrough.mov').sha256 === sha && sessions === 1, `the document has the file's sha256, from ONE session (${sessions})`);
  const resumed = sql("SELECT count(*)::int AS n FROM activity_log WHERE action = 'upload.start' AND (after->>'resumed')::boolean AND (after->>'parts_received')::int >= 4")[0].n;
  ok(resumed === 1, 'upload.start logged the resume with the parts it found');
}

console.log('1280 x 800 — the store full, then Resume; a part lost on the network');
{
  const sha = bigFile(dir + '/drone-raw.bin', 6 * PART + 99);
  await page.goto(uploadUrl, { waitUntil: 'networkidle' });
  let refused = false;
  await page.route('**/upload/part**', async (route) => {
    if (!refused && /[?&]n=2\b/.test(route.request().url())) { refused = true; return route.fulfill({ status: 507, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'no_space', message: 'The store is full. An admin can free space or add disk; the upload carries on after that.' } }) }); }
    await route.continue();
  });
  await page.setInputFiles('#upload-files-input', dir + '/drone-raw.bin');
  ok(await waitState(page, 'drone-raw.bin', 'paused', 30000), 'a part refused because the store is full pauses the file');
  const msg = await page.locator('.upload-row[data-name="drone-raw.bin"] .upload-msg').innerText();
  ok(msg.includes('The store is full') && await page.locator('.upload-row[data-name="drone-raw.bin"] [id^="upload-resume-"]').count() === 1, 'it says why and offers Resume');
  ok((await page.textContent('#upload-summary')).includes('1 paused'), 'the summary counts it paused');
  await page.unrouteAll({ behavior: 'wait' });
  await page.click('.upload-row[data-name="drone-raw.bin"] [id^="upload-resume-"]');
  ok(await waitState(page, 'drone-raw.bin', 'done', 30000) && docOf('drone-raw.bin').sha256 === sha, 'Resume finishes it, with the file\'s sha256');

  const sha2 = bigFile(dir + '/drone-raw-2.bin', 4 * PART + 5);
  let dropped = 0;
  await page.route('**/upload/part**', async (route) => {
    if (dropped === 0 && /[?&]n=3\b/.test(route.request().url())) { dropped++; return route.abort('failed'); }
    await route.continue();
  });
  await page.setInputFiles('#upload-files-input', dir + '/drone-raw-2.bin');
  ok(await waitState(page, 'drone-raw-2.bin', 'done', 30000) && dropped === 1 && docOf('drone-raw-2.bin').sha256 === sha2, 'a part lost on the network is sent again and the file finishes');
  await page.unrouteAll({ behavior: 'wait' });
}
ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
await browser.close();
fs.rmSync(dir, { recursive: true, force: true });
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
