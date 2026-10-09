// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof" 1):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar (Home · Files · Search · Me) on a phone and the sidebar on a
//   desktop, the offcanvas from the menu button, the one button for a new Member, the cards once libraries hold documents and the sidebar's
//   Your libraries, a guest told nothing is shared (then one thing), the placeholders reached by HTMX (title, highlight, context stamp), the
//   bell, the command bar answering the fake kernel and following a navigate, My settings saving prefs, a token minted and shown once then
//   revoked, every control ≥ 44 px, no console errors, the home whole with JavaScript off, the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's
// web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8701';
const SHOTS = process.env.SHOTS || '/tmp/dc-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.doccloud.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get dana() { return mint(30); }, get member() { return mint(26); }, get curator() { return mint(27); }, get guest() { return mint(29); }, get admin() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const groupsOf = async (page) => (await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]), ' + s + ' select, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a new Member (Dana: an empty personal library, no group) — the first-run home ----
{
  const { ctx, page, errors } = await session(urls.dana, { width: 375, height: 740 });
  console.log('375 x 740 — a new Member');
  ok(page.url() === BASE + '/', 'signed on and landed on the home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar') && !(await shown(page, '#left-sidenav')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').allInnerTexts();
  ok(tabs.map((t) => t.trim()).join(',') === 'Home,Files,Search,Me', `four tabs: ${tabs.map((t) => t.trim()).join(', ')}`);
  const small = await smallControls(page, '#app-tabbar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-first-run') && await shown(page, '#home-upload-first-btn') && /upload your first file/i.test(await page.locator('#home-upload-first-btn').innerText()), 'a new Member sees the one button, Upload your first file');
  const btn = await page.locator('#home-upload-first-btn').boundingBox();
  ok(btn.height >= 44 && btn.x >= 0 && btn.x + btn.width <= 375, `the button is ${Math.round(btn.height)} px tall and inside the screen (right edge ${Math.round(btn.x + btn.width)})`);
  const past = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button, #page-content .card')].filter((e) => e.getBoundingClientRect().right > window.innerWidth + 0.5).map((e) => e.id || e.className));
  ok(past.length === 0, 'nothing on the home reaches past the right edge' + (past.length ? ': ' + past.join(', ') : ''));
  ok((await page.locator('#header-role-badge').innerText()).includes('Member'), 'the header badge reads Member');
  await page.screenshot({ path: `${SHOTS}/phone-home-first-run.png` });
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#left-sidenav') && (await page.locator('#nav-files').count()) === 1 && (await page.locator('#nav-graph').count()) === 1 && (await page.locator('#nav-trash').count()) === 1 && (await page.locator('#nav-admin').count()) === 0 && (await page.locator('#nav-curate').count()) === 0, 'the menu button opens the sidebar: the eleven items and Me, no Curate, no Admin');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'your libraries,me', 'a member\'s captions: ' + groups.join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#left-sidenav')), 'the menu button closes it again');
  await page.click('#tab-my-settings');
  await page.waitForURL(BASE + '/settings/');
  await page.waitForSelector('#prefs-save-btn');
  ok(page.url() === BASE + '/settings/', 'the Me tab pushed /settings/ without a page load');
  ok((await page.title()).startsWith('My settings'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-my-settings').classList.contains('active') && !document.querySelector('#tab-home').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'settings'), 'and re-stamped #page-content data-screen for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px tall' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  await page.uncheck('#prefs-field-email');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-email')), 'and email stayed off');
  await page.click('#tab-files');
  await page.waitForURL(BASE + '/files/');
  await page.waitForSelector('#files-coming');
  ok((await page.title()).startsWith('My files') && (await page.locator('#files-coming').innerText()).includes('slice 1'), 'the Files tab opens the My files placeholder (slice 1), title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#tab-files').classList.contains('active') && document.getElementById('page-content').dataset.screen === 'files'), 'the tab is highlighted and the screen stamped files');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the placeholder');
  await page.screenshot({ path: `${SHOTS}/phone-placeholder-files.png` });
  await page.goto(BASE + '/activity', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My activity');
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Notifications');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'Where is the Acme contract filed?');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a guest (Ann), nothing shared yet ----
{
  const { ctx, page, errors } = await session(urls.guest, { width: 375, height: 740 });
  console.log('375 x 740 — a guest, nothing shared');
  ok((await page.locator('#header-role-badge').innerText()).includes('Guest'), 'the badge reads Guest');
  ok(await shown(page, '#home-nothing-shared') && (await page.locator('#home-upload-first-btn').count()) === 0, 'a guest is told nothing has been shared and shown no button');
  const tabs = await page.locator('#app-tabbar .app-tab').allInnerTexts();
  ok(tabs.map((t) => t.trim()).join(',') === 'Home,Files,Me' && (await page.locator('#tab-shared').getAttribute('href')) === '/shared/', 'a guest\'s tabs: Home · Files (= Shared with me) · Me, no Search');
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  ok((await page.locator('#nav-admin').count()) === 0 && (await page.locator('[id^="nav-library-"]').count()) === 0 && (await page.locator('#nav-shared').count()) === 1 && (await page.locator('#nav-files').count()) === 0 && (await page.locator('#nav-groups').count()) === 0 && (await page.locator('#nav-graph').count()) === 0 && (await page.locator('#nav-trash').count()) === 0, 'a guest\'s sidebar: Home, Shared with me and Me — no My files, Groups, Graph, Trash, libraries or Admin');
  await page.screenshot({ path: `${SHOTS}/phone-home-guest.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- the SMOKE store exists from here ----
const seed = JSON.parse(execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { encoding: 'utf8' }).trim());
console.log(`seeded the store: group ${seed.group}, Priya's note ${seed.priya_doc}, the Board's contract ${seed.board_doc}, the guest's document ${seed.guest_doc}`);

// ---- phone: a member with a document of her own and a group (Priya) ----
{
  const { ctx, page, errors } = await session(urls.member, { width: 375, height: 740 });
  console.log('375 x 740 — a member with a document and a group');
  ok((await page.locator('#header-role-badge').innerText()).includes('Member'), 'the badge reads Member');
  ok(await shown(page, '#home-libraries-list') && (await page.locator('#home-libraries-list > div').count()) === 2 && (await page.locator('#home-first-run').count()) === 0, 'the home lists her two libraries as cards; the first-run card is gone');
  ok((await overflow(page)).sw === 375, 'no sideways scroll with the cards');
  const card = await page.locator('#home-libraries-list > div .card').first().boundingBox();
  ok(card.width >= 300 && card.width <= 375, `a card takes the width of the phone (${Math.round(card.width)} px)`);
  ok(await shown(page, '#home-recent') && (await page.locator(`#home-recent-${seed.priya_doc}`).count()) === 1, 'Recent lists her document');
  await page.screenshot({ path: `${SHOTS}/phone-home-libraries.png` });
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'your libraries,me' && (await page.locator('[id^="nav-library-"]').count()) === 2, 'the sidebar lists Your libraries beneath the menu (two): ' + groups.join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-sidebar-libraries.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- phone: the guest again, one thing shared now ----
{
  const { ctx, page, errors } = await session(urls.guest, { width: 375, height: 740 });
  console.log('375 x 740 — the guest, one document shared');
  ok(await shown(page, '#home-shared-count') && (await page.locator('#home-shared-count').innerText()).includes('1 thing') && await shown(page, '#home-shared-btn'), 'the guest is told one thing is shared and shown the way to it');
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- desktop: the sidebar, the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-admin') && await shown(page, '#nav-curate'), 'the sidebar is shown, with Curate and Admin');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'your libraries,me,curate,admin', 'the admin\'s captions: ' + groups.join(', '));
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  const cards = await page.locator('#home-libraries-list > div').count();
  const first = await page.locator('#home-libraries-list > div .card').first().boundingBox();
  ok(cards >= 3 && first.width < 500, `the cards sit side by side on a desktop (${cards} cards, ${Math.round(first.width)} px each)`);
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.click('#nav-admin .nxl-link');
  await page.waitForURL(BASE + '/admin/');
  await page.waitForSelector('#admin-coming');
  ok((await page.title()).startsWith('Admin') && (await page.locator('#admin-coming').innerText()).includes('slice 9'), 'HTMX navigation: /admin/ pushed, the placeholder names slice 9, title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#nav-admin .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Home');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder-admin.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke-btn"]');
  await page.waitForSelector('.badge:has-text("revoked")');
  ok((await page.locator('.badge:has-text("revoked")').count()) >= 1, 'revoking it (with the confirm) marks it revoked');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#header-bell').count()) === 1 && (await page.locator('#header-bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your tokens.', actions: [], navigate: '/settings/tokens/' }; return s; });
  await page.fill('#assistant-input', 'open my tokens');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/settings/tokens/');
  await page.waitForSelector('#tokens-connect');
  ok(page.url() === BASE + '/settings/tokens/', 'a navigate answer from the command bar opened /settings/tokens/');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.member, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('Your libraries') && text.includes('SMOKE Board') && text.includes('SMOKE Priya') && text.includes('my-notes.md'), 'the libraries and the recent document are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-files').getAttribute('href')) === '/files/' && (await page.locator('#tab-my-settings').getAttribute('href')) === '/settings/', 'the tabs are plain links');
  ok((await page.locator(`a[href="/f/${seed.board_root}?back=%2F"]`).count()) === 1, 'a library card\'s name is a plain link to its root folder carrying ?back=');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.member, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'DocCloud', 'standalone, start_url /, named DocCloud');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
