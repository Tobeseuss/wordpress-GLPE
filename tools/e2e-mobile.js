/**
 * E2E — v7.6.0 phone polish of the virtual browser (sandbox engine).
 *
 * Runs a REAL iPhone-14 emulation (touch included) against the WP rig on
 * :3001 with the destination test site on :3005, and verifies the whole
 * host-first pipeline for every phone gesture:
 *   1. mobile layout (44px targets, 16px fields, kbd toggle, short note)
 *   2. tap          → press+release ride the host → destination click counter
 *   3. pan          → finger deltas fold into wheel gestures → page scrolls
 *   4. long-press   → context click rides the host (non-text ground) →
 *                    the surface menu + the site's own contextmenu
 *   5. double tap   → the viewport MAGNIFIES 2× around the tap (the zoom-
 *                    flagged dblclick rides for the site's handlers; a
 *                    second double tap restores) — v7.6.0
 *   5b. long-press on ground TEXT → press + double-click ride (the word
 *                    selects) → the finger drag extends the selection →
 *                    the release opens the surface copy menu — v7.6.0
 *   6. keyboard     → tap on the field arms the relay → typing rides as
 *                    text through the host → the destination input updates
 *   7. rotation     → the resize gesture rides the host → frame adopts the
 *                    new geometry
 *   7b. wide profile → a desktop-shaped destination (no device-width
 *                    viewport, horizontal overflow) adopts the wide
 *                    geometry through one more host ride — v7.6.0
 *   8. zero console/page errors through the whole run
 */
const { chromium, devices } = require('playwright');

const BASE = 'http://localhost:3001';
const DEST = 'http://127.0.0.1.nip.io:3005/';
const DEST_WIDE = 'http://127.0.0.1.nip.io:3005/wide.html';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
function check(name, ok, detail) {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail ? '  [' + detail + ']' : ''));
  if (!ok) failures++;
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ ...devices['iPhone 14'] });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const cdp = await ctx.newCDPSession(page);

  // --- login ---------------------------------------------------------------
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'glpe-test-2026');
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  check('logged in to the WP rig', !page.url().includes('wp-login.php'), page.url());

  // --- open the viewer, sandbox engine, mobile layout ----------------------
  await page.goto(BASE + '/view/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  check('sandbox engine card rendered', await page.evaluate(() => !!window.GLPE_VBS));
  await page.fill('#glpe-vb-url', DEST);
  await page.click('.glpe-vbs-open');
  await page.waitForSelector('#__glpe_vbs_root', { timeout: 20000 });
  await page.waitForFunction(() => {
    const f = document.querySelector('#__glpe_vbs_root iframe');
    return f && f.getAttribute('src') && f.getAttribute('src').indexOf('_glpe') !== -1;
  }, null, { timeout: 20000 });
  // destination load through the gateway
  await page.waitForFunction(() => {
    const s = document.getElementById('__glpe_vbs_status');
    return s && (s.textContent.indexOf('بارگذاری شد') !== -1);
  }, null, { timeout: 30000 });
  check('virtual surface opened on the phone', true);

  const layout = await page.evaluate(() => {
    const root = document.getElementById('__glpe_vbs_root');
    const btns = root.querySelectorAll('button');
    const heights = Array.from(btns).map((b) => Math.round(b.getBoundingClientRect().height));
    const kbd = !!document.getElementById('__glpe_vbs_kbd');
    const kbdBtn = !!document.getElementById('__glpe_vbs_kbd_btn');
    const url = root.querySelector('input[type=text]');
    const fs = url ? getComputedStyle(url).fontSize : '';
    const foot = Array.from(root.children).filter((c) => c.tagName === 'DIV').pop();
    return { heights, kbd, kbdBtn, fs, footH: Math.round(foot.getBoundingClientRect().height), mt: navigator.maxTouchPoints };
  });
  check('touch targets at the 44px phone standard', Math.min(...layout.heights) >= 44,
    'heights=' + JSON.stringify(layout.heights));
  check('keyboard relay field + toggle shipped', layout.kbd && layout.kbdBtn);
  check('URL field at 16px (iOS never zooms on focus)', layout.fs === '16px', layout.fs);
  check('device reports touch', layout.mt > 0, 'maxTouchPoints=' + layout.mt);
  check('compact phone footer (not the wrapped desktop note)', layout.footH <= 34, 'footer=' + layout.footH + 'px');

  const frame = page.frames().find((f) => f.url().indexOf('_glpe=1') !== -1);
  check('sandbox frame rides the gateway', !!frame, frame ? '' : 'no gateway frame found');
  await frame.waitForSelector('#counter', { timeout: 20000 });

  // geometry: where does the frame sit on screen, and where is the counter?
  const geom = await page.evaluate(() => {
    const f = document.querySelector('#__glpe_vbs_root iframe');
    const r = f.getBoundingClientRect();
    return { fl: r.left, ft: r.top, fw: r.width, fh: r.height };
  });
  const stageRect = await page.evaluate(() => {
    const root = document.getElementById('__glpe_vbs_root');
    const st = root.children[1].getBoundingClientRect();
    return { cx: Math.round(st.left + st.width / 2), cy: Math.round(st.top + st.height / 2) };
  });
  const counterBox = await frame.evaluate(() => {
    const r = document.getElementById('counter').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  const inputBox = await frame.evaluate(() => {
    const r = document.getElementById('field').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  const wordBox = await frame.evaluate(() => {
    const r = document.getElementById('bigsel').getBoundingClientRect();
    return { x: r.left + r.width / 3, y: r.top + r.height / 2 };
  });
  const groundBox = await frame.evaluate(() => {
    const r = document.getElementById('ground').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  // frame pixel → page pixel (frame is scaled to fit; ratio 1 on a phone)
  const toPage = (p) => ({
    x: Math.round(geom.fl + p.x),
    y: Math.round(geom.ft + p.y)
  });
  const counterPt = toPage(counterBox);
  const inputPt = toPage(inputBox);
  const wordPt = toPage(wordBox);
  const groundPt = toPage(groundBox);

  async function touchTap(x, y) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await sleep(60);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  }

  // --- 2. tap → host round trip → destination click ------------------------
  const before = await frame.evaluate(() => document.getElementById('counter').textContent);
  await touchTap(counterPt.x, counterPt.y);
  await sleep(2500); // the host round trip is the honest lag
  const after = await frame.evaluate(() => document.getElementById('counter').textContent);
  check('tap rides the host and clicks the destination', before !== after, before + ' → ' + after);

  // --- 3. pan → one wheel gesture per round trip → the page scrolls ---------
  const panRead = () => frame.evaluate(() => ({
    page: window.scrollY,
    box: document.getElementById('scrolly').scrollTop
  }));
  const scrollBefore = await panRead();
  const startX = stageRect.cx, startY = stageRect.cy; // inside the stage, over the page
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: startX, y: startY }] });
  for (let i = 1; i <= 10; i++) {
    await cdp.send('Input.dispatchTouchEvent', {
      type: 'touchMove',
      touchPoints: [{ x: startX, y: startY - i * 18 }]
    });
    await sleep(30);
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(3000);
  const scrollAfter = await panRead();
  const moved = scrollAfter.page > scrollBefore.page + 40 || scrollAfter.box > scrollBefore.box + 40;
  check('finger pan scrolls the destination through the host', moved,
    'pageY ' + scrollBefore.page + '→' + scrollAfter.page + ' boxTop ' + scrollBefore.box + '→' + scrollAfter.box);

  // scroll back up for the rest of the checks (pan up)
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: stageRect.cx, y: stageRect.cy }] });
  for (let i = 1; i <= 12; i++) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: stageRect.cx, y: stageRect.cy + i * 20 }] });
    await sleep(25);
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(3000);
  // deterministic reset: the fling strength is nondeterministic — scroll the
  // destination straight back to the top so every later fixed-coordinate
  // gesture (menu/zoom/selection/field) lands where its box was captured.
  await frame.evaluate(() => window.scrollTo(0, 0));
  await sleep(600);

  // --- 4. long-press on non-text ground → context click → the menu ------
  // (a text-bearing spot long-presses into a word SELECTION now — 5b —
  // so the classic menu rides from a text-less zone)
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: groundPt.x, y: groundPt.y }] });
  await sleep(700); // longer than the 470ms classifier timer
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(1500);
  const menuState = await page.evaluate(() => {
    const m = document.getElementById('__glpe_vbs_menu');
    return { menu: !!m, items: m ? m.textContent : '' };
  });
  check('long-press opens the surface menu (right-click through the host)', menuState.menu,
    menuState.items.slice(0, 60));
  // the destination saw the synthetic contextmenu too (journal + replay)
  const ctxCount = await frame.evaluate(() => document.getElementById('ctxlog').textContent);
  check('destination received the replayed contextmenu', ctxCount.indexOf('context: 1') !== -1, ctxCount);
  // dismiss the open menu deterministically — it floats ABOVE the capture
  // layer where it sits and would eat later gestures at that spot.
  await page.evaluate(() => { const m = document.getElementById('__glpe_vbs_menu'); if (m) m.remove(); });
  await sleep(200);

  // --- 5. double tap → the viewport magnifies 2× around the tap (v7.6.0) --
  await touchTap(wordPt.x, wordPt.y);
  await sleep(150); // inside the 330ms double-tap window
  await touchTap(wordPt.x, wordPt.y);
  await sleep(1400); // the zoom-flagged dblclick rides the host round trip
  const zoomState = await page.evaluate(() => {
    const f = document.querySelector('#__glpe_vbs_root iframe');
    return { tr: getComputedStyle(f).transform, w: Math.round(f.getBoundingClientRect().width) };
  });
  check('double tap magnifies the sandbox viewport 2×', zoomState.tr === 'matrix(2, 0, 0, 2, 0, 0)',
    'transform=' + zoomState.tr + ' rect=' + zoomState.w + 'px');
  const zoomSel = await frame.evaluate(() => String(window.getSelection()));
  check('a zoom-flagged double tap leaves the ground unselected', zoomSel === '', JSON.stringify(zoomSel));
  // restore the viewport for the following tests (a second double tap)
  await touchTap(wordPt.x, wordPt.y);
  await sleep(150);
  await touchTap(wordPt.x, wordPt.y);
  await sleep(900);
  const zoomBack = await page.evaluate(() => getComputedStyle(document.querySelector('#__glpe_vbs_root iframe')).transform);
  check('a second double tap restores the unmagnified viewport', zoomBack === 'none', zoomBack);

  // --- 5b. long-press on ground text → word-select rides, drag extends ------
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: wordPt.x, y: wordPt.y }] });
  await sleep(700);  // the long-press fires: press + double-click ride
  await sleep(2600); // ...and replay through the host (the word selects)
  const wordSel = await frame.evaluate(() => String(window.getSelection()));
  check('long-press on ground text selects the word under the finger', wordSel.length > 0 && wordSel.length < 12,
    JSON.stringify(wordSel));
  for (let i = 1; i <= 8; i++) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: wordPt.x + i * 22, y: wordPt.y }] });
    await sleep(40);
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(3600); // the moves + the release ride and replay
  const dragSel = await frame.evaluate(() => String(window.getSelection()));
  check('the finger drag extends the selection across the words', dragSel.length > wordSel.length,
    JSON.stringify(wordSel) + ' → ' + JSON.stringify(dragSel.slice(0, 44)));
  const selMenu = await page.evaluate(() => {
    const m = document.getElementById('__glpe_vbs_menu');
    return { menu: !!m, txt: m ? m.textContent : '' };
  });
  check('the release opens the surface copy menu over the selection',
    selMenu.menu && selMenu.txt.indexOf('کپی') !== -1, selMenu.txt.slice(0, 50));
  if (selMenu.menu) {
    await page.evaluate(() => { document.getElementById('__glpe_vbs_menu').remove(); });
  }

  // --- 6. keyboard relay: tap the field, type, text rides the host --------
  await touchTap(inputPt.x, inputPt.y);
  await sleep(1200);
  const kbdFocus = await page.evaluate(() => document.activeElement && document.activeElement.id);
  check('tapping an editable arms the keyboard relay field', kbdFocus === '__glpe_vbs_kbd', kbdFocus);
  await page.keyboard.type('salam', { delay: 90 });
  await sleep(3000);
  const typed = await frame.evaluate(() => document.getElementById('typed').textContent);
  check('typed text rides the host into the destination input', typed.indexOf('salam') !== -1, typed);
  await page.keyboard.press('Backspace');
  await page.keyboard.press('Backspace');
  await sleep(2500);
  const typed2 = await frame.evaluate(() => document.getElementById('typed').textContent);
  check('relay backspaces ride the host too', typed2.indexOf('salam') === -1 && typed2.indexOf('sa') !== -1, typed2);

  // --- 7. rotation → resize gesture rides the host → frame adopts ----------
  const frameWBefore = await page.evaluate(() => document.querySelector('#__glpe_vbs_root iframe').style.width);
  await page.setViewportSize({ width: 844, height: 390 });
  await sleep(2500);
  const frameAfterRotate = await page.evaluate(() => {
    const f = document.querySelector('#__glpe_vbs_root iframe');
    return { w: f.style.width, h: f.style.height };
  });
  check('rotation adopts the new geometry through the host', frameAfterRotate.w !== frameWBefore,
    frameWBefore + ' → ' + frameAfterRotate.w + ' x ' + frameAfterRotate.h);

  await page.screenshot({ path: '/home/z/my-project/wordpress-glpe/docs/vb-mobile-touch-v760.png' });

  // --- 8. close + errors ----------------------------------------------------
  await page.evaluate(() => { document.getElementById('__glpe_vbs_root').querySelector('button').click(); });
  await sleep(800);
  check('closing tears the overlay down', await page.evaluate(() => !document.getElementById('__glpe_vbs_root')));

  // --- 7b. the wide viewport profile on a desktop-shaped destination -------
  await page.setViewportSize({ width: 390, height: 844 }); // back to portrait
  await sleep(700);
  await page.fill('#glpe-vb-url', DEST_WIDE);
  await page.click('.glpe-vbs-open');
  await page.waitForSelector('#__glpe_vbs_root', { timeout: 20000 });
  await page.waitForFunction(() => {
    const s = document.getElementById('__glpe_vbs_status');
    return s && (s.textContent.indexOf('بارگذاری شد') !== -1);
  }, null, { timeout: 30000 });
  await sleep(3500); // the detector (450ms) + the rs ride + the echo settle
  const wideState = await page.evaluate(() => {
    const f = document.querySelector('#__glpe_vbs_root iframe');
    return {
      styleW: parseInt(f.style.width, 10) || 0,
      tr: getComputedStyle(f).transform,
      rectW: Math.round(f.getBoundingClientRect().width)
    };
  });
  const wideInner = await page.evaluate(() => {
    try { return document.querySelector('#__glpe_vbs_root iframe').contentWindow.innerWidth; } catch (e) { return 0; }
  });
  check('the desktop-shaped destination adopts the wide session geometry', wideState.styleW >= 1000,
    'style.width=' + wideState.styleW + 'px');
  check('the wide frame shrinks visually to fit the phone stage',
    wideState.tr !== 'none' && wideState.rectW <= 390, 'transform=' + wideState.tr + ' rect=' + wideState.rectW + 'px');
  check('the destination lays out at the wide viewport (innerWidth)', wideInner >= 1000, 'innerWidth=' + wideInner);

  await page.screenshot({ path: '/home/z/my-project/wordpress-glpe/docs/vb-wide-profile-v760.png' });
  await page.evaluate(() => { document.getElementById('__glpe_vbs_root').querySelector('button').click(); });
  await sleep(800);

  const realErrors = errors.filter((e) => e.indexOf('favicon') === -1);
  check('zero page errors through the whole phone run', realErrors.length === 0,
    realErrors.slice(0, 3).join(' ;; '));

  await browser.close();
  console.log('\nE2E ' + (failures === 0 ? 'ALL GREEN' : failures + ' FAILURES'));
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('E2E crashed: ' + e.message); process.exit(2); });
