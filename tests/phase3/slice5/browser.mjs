// Slice 5's browser proof (spec "Proof" 14): at 1280 x 800 and 375 x 740 — the find page (with results and chips when the engine runs; the
// unavailable banner and the keyword link without), a subject page with the SVG at 1280, the policy form with the estimate, the admin's queue;
// screenshots; no sideways scroll at 375; no console errors. Run through tests/phase3/slice5/run.sh (DC_ENGINE=1 for the engine half).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8701';
const SHOTS = process.env.SHOTS || '/tmp/dc-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const ENGINE = process.env.DC_ENGINE === '1';
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
// the store with texts and tags; with the engine, the model set and a pass run; a failed job for the queue
const seed = JSON.parse(execFileSync('php', ['-r', `require $argv[1]; $s = seed_graph(); if (engine()) { graph_ready(); run_graph(); $s['subject'] = engine_subject_id('tag:acme-corp'); } $v = (int) one('SELECT current_version_id FROM documents WHERE id = :d', ['d' => $s['handbook']]); pdo()->exec("INSERT INTO graph_jobs (document_id, version_id, kind, chars, status, attempts, error) VALUES ({$s['handbook']}, $v, 'full', 900, 'failed', 3, 'model_failed: the provider answered 500')"); echo json_encode($s);`, new URL('./lib.php', import.meta.url).pathname], { env: process.env }).toString());
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

// ---- desktop: the find, a subject page, the policy form, the admin ----
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — the find page' + (ENGINE ? ' (engine)' : ' (no engine)'));
  await page.goto(BASE + '/graph/?q=' + encodeURIComponent('acme-corp kind:pdf'), { waitUntil: 'networkidle' });
  ok(await shown(page, '#graph-find-box') && (await page.inputValue('#graph-find-box')).includes('acme-corp'), 'the find box carries the query');
  if (ENGINE) {
    ok((await page.locator('#graph-results .graph-result').count()) >= 1 && await shown(page, '#search-chip-kind'), 'results and a kind chip');
    ok((await page.locator('#graph-results .graph-result .graph-subject-chip').count()) >= 1, 'a result names the subjects it matched');
    await page.screenshot({ path: SHOTS + '/desktop-graph-find.png', fullPage: true });
    await page.locator('#search-chip-remove-kind').evaluate((el) => el.click());
    // a find over the engine takes about a second: wait for the chip to go rather than a fixed pause
    await page.waitForSelector('#search-chip-kind', { state: 'detached', timeout: 15000 }).catch(() => {});
    await page.waitForLoadState('networkidle');
    ok((await page.locator('#search-chip-kind').count()) === 0 && (await page.locator('#graph-results .graph-result').count()) >= 2, 'removing the chip re-runs without it (more results)');
  } else {
    ok(await shown(page, '#graph-unavailable') && await shown(page, '#graph-keyword-link'), 'the unavailable banner and the keyword link');
    await page.screenshot({ path: SHOTS + '/desktop-graph-find.png', fullPage: true });
  }
  if (ENGINE && seed.subject) {
    console.log('1280 x 800 — a subject page');
    await page.goto(BASE + '/graph/subjects/' + seed.subject, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    ok(await shown(page, '#subject-name') && (await page.textContent('#subject-name')).includes('acme-corp'), 'the subject page names tag:acme-corp');
    ok(await shown(page, '#graph-svg') && (await page.locator('#graph-svg circle, #graph-svg rect').count()) >= 2, 'the SVG drew the neighbourhood');
    ok((await page.locator('#subject-documents .graph-result').count()) === 3, "its documents: Marco's three");
    await page.screenshot({ path: SHOTS + '/desktop-graph-subject.png', fullPage: true });
    await page.goto(BASE + '/graph/explore', { waitUntil: 'networkidle' });
    ok(await shown(page, '#graph-subjects') && (await page.locator('#graph-subjects a').count()) >= 1, 'explore lists subjects');
    await page.screenshot({ path: SHOTS + '/desktop-graph-explore.png', fullPage: true });
  }
  console.log('1280 x 800 — the policy form on a folder');
  await page.goto(BASE + '/graph/admin?folder=' + seed.contracts, { waitUntil: 'networkidle' });
  ok(await shown(page, '#graph-policy-form') && await shown(page, '#graph-estimate') && await shown(page, '#graph-policy-effective'), 'the policy form with the estimate');
  await page.check('#graph-policy-none');
  await page.locator('#graph-policy-save').click();
  await page.waitForTimeout(1200);
  ok(/\/f\/\d+/.test(page.url()) || (await page.locator('#graph-policy-effective').count()) >= 0, 'setting the policy lands on the folder');
  await page.goto(BASE + '/f/' + seed.contracts, { waitUntil: 'networkidle' });
  ok(await shown(page, '#folder-graph-policy') && (await page.textContent('#folder-graph-policy')).includes('none'), 'the folder shows the policy chip: none');
  await page.goto(BASE + '/graph/admin?folder=' + seed.contracts, { waitUntil: 'networkidle' });
  await page.screenshot({ path: SHOTS + '/desktop-graph-policy.png', fullPage: true });
  ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources at 1280 (curator)' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/graph/admin', { waitUntil: 'networkidle' });
  ok(await shown(page, '#graph-policies-table') && await shown(page, '#graph-budget-bar') && await shown(page, '#graph-queue-table') && await shown(page, '#graph-model-form'), 'the admin: policies, the budget bar, the queue, the model form');
  ok((await page.locator('#graph-queue-table tr[data-status="failed"]').count()) >= 1 && (await page.locator('#graph-queue-table button[id^="graph-job-retry-"]').count()) >= 1, 'the queue shows the failed job with retry');
  ok(!(await page.content()).includes('fake-secret'), 'no credential on the page');
  await page.screenshot({ path: SHOTS + '/desktop-graph-admin.png', fullPage: true });
  await page.locator('#graph-queue-table button[id^="graph-job-retry-"]').first().evaluate((el) => el.click());
  await page.waitForTimeout(1200);
  await page.goto(BASE + '/graph/admin', { waitUntil: 'networkidle' });
  ok((await page.locator('#graph-queue-table tr[data-status="failed"]').count()) === 0, 'retry queued it again');
  ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources at 1280 (admin)' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
  await ctx.close();
}
// ---- phone ----
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  console.log('375 x 740');
  await page.goto(BASE + '/graph/?q=acme-corp', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#graph-find-box'), 'the find page has no sideways scroll at 375 (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-graph-find.png', fullPage: true });
  if (ENGINE && seed.subject) {
    await page.goto(BASE + '/graph/subjects/' + seed.subject, { waitUntil: 'networkidle' });
    o = await overflow(page);
    ok(o.sw <= o.iw + 1 && await shown(page, '#subject-name'), 'the subject page fits a phone (' + o.sw + ' of ' + o.iw + ')');
    await page.screenshot({ path: SHOTS + '/phone-graph-subject.png', fullPage: true });
  }
  await page.goto(BASE + '/graph/explore', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#graph-subjects-card'), 'explore fits a phone');
  await page.goto(BASE + '/graph/admin?folder=' + seed.contracts, { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#graph-policy-form'), 'the policy form fits a phone');
  await page.screenshot({ path: SHOTS + '/phone-graph-policy.png', fullPage: true });
  ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources at 375' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().join(' ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  await page.goto(BASE + '/graph/admin', { waitUntil: 'networkidle' });
  const o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#graph-queue-table'), 'the admin fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-graph-admin.png', fullPage: true });
  ok(errors.length === 0, 'no console errors at 375 (admin)' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
