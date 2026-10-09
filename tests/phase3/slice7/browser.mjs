// Slice 7's browser proof (spec "Proof" 8): at 1280 x 800 and 375 x 740 — the keys part of the tokens page, the mint form, a key minted and shown ONCE (Copy), gone on reload, rotated (the replacement shown once, the old one "rotating"),
// revoked; the admin's keys screen and its filters; the connections screen with a sibling's shares. Screenshots in $SHOTS; no sideways scroll at 375; no console errors.
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
// the seed: a folder shared with the sibling `knowledge`, a document it read through the door, a key for the admin to see
const seed = JSON.parse(execFileSync('php', ['-r', `require $argv[1]; $s = seed_texts(); share_folder_with($s['contracts'], 'knowledge');
  $d = door('share_documents', app_caller('knowledge'), ['folder' => $s['contracts']]); door('share_folders', app_caller('knowledge')); door('share_text', app_caller('knowledge'), ['document' => $s['board_doc']]);
  door('share_documents', app_caller('knowledge'), ['folder' => $s['board_root']]);
  mint_key(30, 'Dana scanner'); mint_key(27, 'Marco workflow'); echo json_encode($s);`, new URL('./lib.php', import.meta.url).pathname], { env: process.env }).toString());
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
const clean = (errors, page, label) => ok(errors.length === 0 && page.missing().length === 0, 'no console errors or missing resources ' + label + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : '') + (page.missing().length ? ' 404: ' + page.missing().slice(0, 3).join(' ') : ''));

// ---- desktop, Lee: the tokens page, mint, once, rotate, revoke ----
{
  const { ctx, page, errors } = await session(31, { width: 1280, height: 800 });
  console.log('1280 x 800 — Lee\'s keys');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#tokens-keys') && await shown(page, '#key-mint-btn') && await shown(page, '#keys-empty'), 'the tokens page carries the keys part: Mint a key, and "No keys yet"');
  await page.screenshot({ path: SHOTS + '/desktop-keys-tokens.png', fullPage: true });
  await page.locator('#key-mint-btn').click();
  await page.waitForSelector('#key-form-form', { timeout: 8000 });
  ok(await shown(page, '#key-form-field-label') && await shown(page, '#key-form-field-rate_per_minute') && await shown(page, '#key-form-field-expires_in_days') && await shown(page, '#key-form-save-btn'), 'the mint form: label, limits, expiry');
  await page.screenshot({ path: SHOTS + '/desktop-key-form.png', fullPage: true });
  await page.fill('#key-form-field-label', 'Mail-room scanner');
  await page.fill('#key-form-field-rate_per_minute', '20');
  await page.locator('#key-form-save-btn').click();
  await page.waitForSelector('#key-once', { timeout: 8000 });
  const raw = (await page.textContent('#key-once-value')).trim();
  ok(/^dck_[a-f0-9]{48}$/.test(raw) && await shown(page, '#key-once-copy') && (await page.textContent('#key-once')).includes('shown once'), 'minting shows the key once, with Copy: ' + raw.slice(0, 8) + '…');
  const rowId = await page.evaluate(() => document.querySelector('#keys-table [id^=key-row-][data-state]').id);
  ok(await shown(page, '#' + rowId + '-rotate-btn') && await shown(page, '#' + rowId + '-revoke-btn') && (await page.textContent('#' + rowId + '-state')).trim() === 'live', 'the row: live, with Rotate and Revoke');
  await page.screenshot({ path: SHOTS + '/desktop-key-once.png', fullPage: true });
  const me = execFileSync('curl', ['-s', '-H', 'Authorization: Bearer ' + raw, BASE + '/api/v1/me']).toString();
  ok(JSON.parse(me).member.member_id === 31 && JSON.parse(me).rate_per_minute === 20, 'the key works and acts as Lee with the 20 a minute');
  await page.reload({ waitUntil: 'networkidle' });
  ok(!(await shown(page, '#key-once')) && !(await page.content()).includes(raw) && await shown(page, '#keys-table'), 'after a reload the key is gone — only the row');
  page.once('dialog', (d) => d.accept());
  await page.locator('#' + rowId + '-rotate-btn').click();
  await page.waitForSelector('#key-once', { timeout: 8000 });
  const raw2 = (await page.textContent('#key-once-value')).trim();
  ok(/^dck_/.test(raw2) && raw2 !== raw && (await page.textContent('#key-once')).includes('replacement'), 'rotating (after the confirm) shows the replacement once');
  ok((await page.textContent('#' + rowId + '-state')).trim() === 'rotating' && !(await shown(page, '#' + rowId + '-rotate-btn')), 'the old key is "rotating", with no Rotate of its own');
  ok(await page.locator('#keys-table [data-state=live]').count() === 1, 'one live key: the replacement');
  await page.screenshot({ path: SHOTS + '/desktop-key-rotated.png', fullPage: true });
  const liveId = await page.evaluate(() => document.querySelector('#keys-table [data-state=live]').id);
  page.once('dialog', (d) => d.accept());
  await page.locator('#' + liveId + '-revoke-btn').click();
  await page.waitForFunction((id) => document.querySelector('#' + id + '-state')?.textContent.trim() === 'revoked', liveId, { timeout: 8000 });
  ok(!(await shown(page, '#' + liveId + '-revoke-btn')), 'revoking (after the confirm): the chip says revoked and the buttons are gone');
  await page.screenshot({ path: SHOTS + '/desktop-key-revoked.png', fullPage: true });
  ok(execFileSync('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', 'Authorization: Bearer ' + raw2, BASE + '/api/v1/me']).toString() === '401', 'the revoked key answers 401');
  clean(errors, page, 'at 1280 (Lee)');
  await ctx.close();
}
// ---- desktop, the admin: every key, the connections ----
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  await page.goto(BASE + '/admin/keys', { waitUntil: 'networkidle' });
  ok(await shown(page, '#admin-keys-table') && (await page.textContent('#admin-keys-table')).includes('Dana scanner') && (await page.textContent('#admin-keys-table')).includes('SMOKE Marco'), 'every person\'s keys, by label and person');
  ok(!(await page.content()).includes('dck_'), 'no key value on the page');
  await page.screenshot({ path: SHOTS + '/desktop-admin-keys.png', fullPage: true });
  await page.locator('#admin-keys-filter-live').click();
  await page.waitForFunction(() => location.search.includes('status=live'), null, { timeout: 8000 });
  ok(await page.locator('#admin-keys-table [data-state=revoked]').count() === 0, 'the Live filter hides revoked keys');
  const danaRow = await page.evaluate(() => [...document.querySelectorAll('#admin-keys-table [id^=admin-key-row-][data-state]')].find((e) => e.textContent.includes('Dana scanner'))?.id);
  page.once('dialog', (d) => d.accept());
  await page.locator('#' + danaRow + '-revoke-btn').click();
  await page.waitForFunction(() => !document.body.textContent.includes('Dana scanner'), null, { timeout: 8000 }).catch(() => {});
  ok(!(await page.textContent('#admin-keys-table')).includes('Dana scanner') || (await page.locator('#' + danaRow + '[data-state=revoked]').count()) === 1, 'the admin revokes Dana\'s key from here (confirmed)');
  await page.goto(BASE + '/admin/connections', { waitUntil: 'networkidle' });
  ok(await shown(page, '#connection-knowledge') && (await page.textContent('#connection-knowledge-folders')).includes('Contracts') && (await page.textContent('#connection-knowledge-reads')).includes('documents_index'), 'connections: knowledge, the folder it is given, what it read');
  ok((await page.textContent('#connection-knowledge-reads')).includes('refused'), 'and the refusal (a folder it was not given)');
  await page.screenshot({ path: SHOTS + '/desktop-admin-connections.png', fullPage: true });
  clean(errors, page, 'at 1280 (admin)');
  await ctx.close();
}
// ---- phone ----
{
  const { ctx, page, errors } = await session(31, { width: 375, height: 740 });
  console.log('375 x 740 — Lee');
  await page.goto(BASE + '/settings/keys/', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#key-mint-btn') && await shown(page, '#keys-table'), 'the keys screen fits a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-keys.png', fullPage: true });
  await page.locator('#key-mint-btn').click();
  await page.waitForSelector('#key-form-form', { timeout: 8000 });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#key-form-save-btn'), 'the mint form fits a phone');
  await page.fill('#key-form-field-label', 'Phone test');
  await page.screenshot({ path: SHOTS + '/phone-key-form.png', fullPage: true });
  await page.locator('#key-form-save-btn').click();
  await page.waitForSelector('#key-once', { timeout: 8000 });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#key-once-copy') && await shown(page, '#key-once-value'), 'the key shown once fits a phone, long value wrapped (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-key-once.png', fullPage: true });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#tokens-keys'), 'the tokens page with the keys part fits a phone');
  clean(errors, page, 'at 375 (Lee)');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  console.log('375 x 740 — the admin');
  await page.goto(BASE + '/admin/keys', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#admin-keys-table'), 'the admin\'s keys fit a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-admin-keys.png', fullPage: true });
  await page.goto(BASE + '/admin/connections', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw <= o.iw + 1 && await shown(page, '#connection-knowledge'), 'the connections fit a phone (' + o.sw + ' of ' + o.iw + ')');
  await page.screenshot({ path: SHOTS + '/phone-admin-connections.png', fullPage: true });
  clean(errors, page, 'at 375 (admin)');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
