/**
 * E2E — v7.5.0 phone optimization of the virtual browser (SERVICE engine).
 *
 * The service engine renders everything host-side (a throwaway headless
 * Chromium per session) and the phone receives only JPEG frames on a
 * canvas — so gestures are verified BOTH ways: the WebSocket message log
 * (captured through a send wrapper installed before the surface boots)
 * proves every finger gesture rides the renderer with the right shape,
 * and VLM-read screenshots prove the destination state actually changed
 * through the picture channel.
 */
const { chromium, devices } = require('playwright');

const BASE = 'http://localhost:3001';
const DEST = 'http://127.0.0.1.nip.io:3005/';

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

  // --- login + open the viewer on the SERVICE engine -----------------------
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'glpe-test-2026');
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.goto(BASE + '/view/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  check('service engine card rendered', await page.evaluate(() => !!window.GLPE_VB));

  // Capture every WebSocket message this surface sends (before boot).
  await page.evaluate(() => {
    window.__vb_sends = [];
    const OrigWS = window.WebSocket;
    window.WebSocket = function (u, p) {
      const ws = p !== undefined ? new OrigWS(u, p) : new OrigWS(u);
      const origSend = ws.send.bind(ws);
      ws.send = (d) => {
        try { window.__vb_sends.push(String(d).slice(0, 160)); } catch (e) {}
        origSend(d);
      };
      return ws;
    };
    window.WebSocket.prototype = OrigWS.prototype;
  });

  await page.fill('#glpe-vb-url', DEST);
  await page.click('.glpe-vb-open');
  await page.waitForSelector('#__glpe_vb_root', { timeout: 20000 });
  // frames must flow: the canvas holds a real picture of the destination
  // (a static page paints few frames, so the fps counter alone would lie —
  // the pixels are the honest signal)
  await page.waitForFunction(() => {
    const c = document.querySelector('#__glpe_vb_root canvas');
    if (!c || !c.width || !c.height) return false;
    try {
      const g = c.getContext('2d');
      const d = g.getImageData(0, 0, c.width, c.height).data;
      let lit = 0;
      for (let i = 4; i < d.length; i += 400) { if (d[i] > 16) lit++; }
      return lit > 50;
    } catch (e) { return false; }
  }, null, { timeout: 30000 });
  check('picture channel live (frames streaming from the host renderer)', true);

  // --- phone layout ---------------------------------------------------------
  const layout = await page.evaluate(() => {
    const root = document.getElementById('__glpe_vb_root');
    const btns = root.querySelectorAll('button');
    const heights = Array.from(btns).map((b) => Math.round(b.getBoundingClientRect().height));
    return {
      heights,
      kbd: !!document.getElementById('__glpe_vb_kbd'),
      kbdBtn: !!document.getElementById('__glpe_vb_kbd_btn'),
      foot: Array.from(root.children).filter((c) => c.tagName === 'DIV').pop().getBoundingClientRect().height
    };
  });
  check('service touch targets at the 44px phone standard', Math.min(...layout.heights) >= 44,
    'heights=' + JSON.stringify(layout.heights));
  check('service keyboard relay field + toggle shipped', layout.kbd && layout.kbdBtn);
  check('service phone footer compacts', Math.round(layout.foot) <= 34, 'footer=' + Math.round(layout.foot) + 'px');

  // canvas geometry — the destination top area maps to the canvas top
  const canvasBox = await page.evaluate(() => {
    const c = document.querySelector('#__glpe_vb_root canvas');
    const r = c.getBoundingClientRect();
    return { l: r.left, t: r.top, w: r.width, h: r.height, iw: c.width, ih: c.height };
  });
  const px = (x, y) => ({ x: Math.round(canvasBox.l + x), y: Math.round(canvasBox.t + y) });
  async function tap(x, y) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await sleep(70);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  }

  // --- tap: the big counter first, then the field gets the click and
  // typing rides 'text' messages --------------------------------------------
  const sends = () => page.evaluate(() => window.__vb_sends);
  const counterPt = px(canvasBox.w / 2, canvasBox.h * 0.11);
  const fieldPt = px(canvasBox.w / 2, canvasBox.h * 0.31);
  await tap(counterPt.x, counterPt.y);
  await sleep(1500);
  const afterTap = await sends();
  const tapDowns = afterTap.filter((s) => s.indexOf('"t":"down"') !== -1 || s.indexOf('"t":"up"') !== -1);
  check('tap rode the renderer as left press+release', tapDowns.length >= 2,
    JSON.stringify(tapDowns.slice(0, 2)));
  await tap(fieldPt.x, fieldPt.y);
  await sleep(1500);

  // arm the keyboard and type — text must ride as text messages
  await page.click('#__glpe_vb_kbd_btn');
  await page.keyboard.type('salam', { delay: 80 });
  await sleep(2500);
  const afterType = await sends();
  const texts = afterType.filter((s) => s.indexOf('"t":"text"') !== -1);
  check('typed text rides the renderer as text messages', texts.length >= 1,
    JSON.stringify(texts.slice(0, 2)));
  const backBefore = afterType.length;
  await page.keyboard.press('Backspace');
  await sleep(1200);
  const afterBs = (await sends()).slice(backBefore);
  check('relay backspace rides the renderer as a key message',
    afterBs.some((s) => s.indexOf('"t":"key"') !== -1 && s.indexOf('Backspace') !== -1),
    JSON.stringify(afterBs.slice(0, 2)));

  // --- pan: wheel messages with negated finger deltas ----------------------
  const pan0 = (await sends()).length;
  const start = px(canvasBox.w / 2, canvasBox.h * 0.7);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [start] });
  for (let i = 1; i <= 8; i++) {
    const p = { x: start.x, y: start.y - i * 20 };
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [p] });
    await sleep(35);
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(2000);
  const panMsgs = (await sends()).slice(pan0).filter((s) => s.indexOf('"t":"wheel"') !== -1);
  const negated = panMsgs.some((s) => JSON.parse(s).dy > 0); // finger UP → positive deltaY
  check('finger pan rides the renderer as wheel messages (content follows the finger)',
    panMsgs.length >= 1 && negated, JSON.stringify(panMsgs.slice(0, 2)));

  // --- long-press → right-button press+release -----------------------------
  const lp0 = (await sends()).length;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: fieldPt.x, y: fieldPt.y }] });
  await sleep(700);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await sleep(1200);
  const lpMsgs = (await sends()).slice(lp0).filter((s) => s.indexOf('"t":"down"') !== -1 || s.indexOf('"t":"up"') !== -1);
  check('long-press rides the renderer as right-button press+release',
    lpMsgs.some((s) => s.indexOf('"b":"right"') !== -1),
    JSON.stringify(lpMsgs.slice(0, 2)));

  // --- double tap → the dbl message (clickCount 2) --------------------------
  const dbl0 = (await sends()).length;
  await tap(fieldPt.x, fieldPt.y);
  await sleep(140);
  await tap(fieldPt.x, fieldPt.y);
  await sleep(1500);
  const dblMsgs = (await sends()).slice(dbl0).filter((s) => s.indexOf('"t":"dbl"') !== -1);
  check('double tap rides the renderer as the dbl message (native dblclick)',
    dblMsgs.length === 1, JSON.stringify(dblMsgs));

  // --- rotation → the size message ------------------------------------------
  const size0 = (await sends()).length;
  await page.setViewportSize({ width: 844, height: 390 });
  await sleep(2500);
  const sizeMsgs = (await sends()).slice(size0).filter((s) => s.indexOf('"t":"size"') !== -1);
  check('rotation rides the renderer as a size message', sizeMsgs.length >= 1,
    JSON.stringify(sizeMsgs.slice(0, 1)));

  // --- the picture actually reflects the typed text + scroll (VLM later) ----
  await sleep(1500);
  await page.screenshot({ path: '/home/z/my-project/wordpress-glpe/docs/vb-mobile-service-v750.png' });

  // --- close + errors -------------------------------------------------------
  await page.evaluate(() => { document.getElementById('__glpe_vb_root').querySelector('button').click(); });
  await sleep(800);
  check('closing tears the service overlay down', await page.evaluate(() => !document.getElementById('__glpe_vb_root')));
  const realErrors = errors.filter((e) => e.indexOf('favicon') === -1 && e.indexOf('WebSocket connection') === -1);
  check('zero page errors through the phone run', realErrors.length === 0,
    realErrors.slice(0, 3).join(' ;; '));

  await browser.close();
  console.log('\nE2E ' + (failures === 0 ? 'ALL GREEN' : failures + ' FAILURES'));
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('E2E crashed: ' + e.message); process.exit(2); });
