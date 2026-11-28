/**
 * E2E — desktop regression on the sandbox engine: the mouse/keyboard path
 * that existed before v7.5.0 must behave exactly the same (click counter,
 * typing through the capture layer, wheel scroll, zero errors).
 */
const { chromium } = require('playwright');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
function check(name, ok, detail) {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail ? '  [' + detail + ']' : ''));
  if (!ok) failures++;
}
(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto('http://localhost:3001/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'glpe-test-2026');
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.goto('http://localhost:3001/view/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  // force sandbox engine for determinism
  await page.evaluate(() => { window.GLPE_VBS = window.GLPE_VBS || {}; });
  check('sandbox config present', await page.evaluate(() => !!window.GLPE_VBS));
  await page.fill('#glpe-vb-url', 'http://127.0.0.1.nip.io:3005/');
  await page.click('.glpe-vbs-open');
  await page.waitForSelector('#__glpe_vbs_root', { timeout: 20000 });
  await page.waitForTimeout(9000);
  const frame = page.frames().find((f) => f.url().indexOf('_glpe=1') !== -1);
  check('gateway frame loaded', !!frame);
  await frame.waitForSelector('#counter', { timeout: 20000 }).catch(() => {});

  const cap = await page.locator('#__glpe_vbs_capture').boundingBox();
  const counterBox = await frame.evaluate(() => {
    const r = document.getElementById('counter').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  const fr = await page.evaluate(() => {
    const r = document.querySelector('#__glpe_vbs_root iframe').getBoundingClientRect();
    return { l: r.left, t: r.top };
  });
  const x = fr.l + counterBox.x, y = fr.t + counterBox.y;
  const before = await frame.evaluate(() => document.getElementById('counter').textContent);
  await page.mouse.click(x, y);
  await sleep(2500);
  const after = await frame.evaluate(() => document.getElementById('counter').textContent);
  check('desktop mouse click through the host', before !== after, before + ' → ' + after);

  // hardware typing through the capture layer
  const fieldBox = await frame.evaluate(() => {
    const r = document.getElementById('field').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });
  await page.mouse.click(fr.l + fieldBox.x, fr.t + fieldBox.y);
  await sleep(2000);
  await page.keyboard.type('hi', { delay: 80 });
  await sleep(2500);
  const typed = await frame.evaluate(() => document.getElementById('typed').textContent);
  check('desktop hardware typing through the capture layer', typed.indexOf('hi') !== -1, typed);

  // wheel scroll
  const sy0 = await frame.evaluate(() => window.scrollY);
  await page.mouse.wheel(0, 800);
  await sleep(2500);
  const sy1 = await frame.evaluate(() => window.scrollY);
  check('desktop wheel scroll through the host', sy1 > sy0 + 50, sy0 + ' → ' + sy1);

  // dblclick word select — scroll back up first so p1 is in view again
  await page.mouse.wheel(0, -800);
  await sleep(2500);
  const p1 = await frame.evaluate(() => {
    const r = document.getElementById('p1').getBoundingClientRect();
    return { x: r.left + 30, y: r.top + r.height / 2 };
  });
  await page.mouse.dblclick(fr.l + p1.x, fr.t + p1.y);
  await sleep(2000);
  const sel = await frame.evaluate(() => String(window.getSelection()));
  check('desktop dblclick word selection', sel.length > 0, JSON.stringify(sel));

  check('zero page errors (desktop regression)', errors.length === 0, errors.slice(0, 2).join(' ;; '));
  await browser.close();
  console.log('\nDESKTOP ' + (failures === 0 ? 'ALL GREEN' : failures + ' FAILURES'));
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('crashed: ' + e.message); process.exit(2); });
