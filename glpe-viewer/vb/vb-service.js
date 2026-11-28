/**
 * GLPE Virtual Browser service — host-side renderer.
 *
 * Companion process for the viewer plugin. Each authorized visitor gets a
 * private, temporary Chromium renderer inside this host: the destination
 * site is fetched, executed and painted ENTIRELY on the server; the visitor
 * receives nothing but compressed picture frames, and every mouse/keyboard
 * gesture travels back and is replayed inside that remote renderer — a
 * remote-desktop experience where no page byte (HTML/CSS/JS) ever reaches
 * the visitor's machine.
 *
 * Runtime: Bun (bun vb/vb-service.js). Zero dependencies.
 * Environment:
 *   GLPE_VB_PORT         listen port            (default 3007)
 *   GLPE_VB_HOST         listen address         (default 127.0.0.1)
 *   GLPE_VB_KEYFILE       shared-key file path   (required for clients)
 *   GLPE_VB_CHROME        renderer binary        (default: auto-discover)
 *   GLPE_VB_MAX_SESSIONS concurrent renderers    (default 4)
 *   GLPE_VB_IDLE_MINUTES idle renderer expiry     (default 15)
 *   GLPE_VB_JPEG         frame JPEG quality      (default 70)
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const VERSION = '7.0.0';
const PORT = intEnv('GLPE_VB_PORT', 3007);
const HOSTNAME = process.env.GLPE_VB_HOST || '127.0.0.1';
const KEYFILE = process.env.GLPE_VB_KEYFILE || '';
const MAX_SESSIONS = intEnv('GLPE_VB_MAX_SESSIONS', 4);
const IDLE_MINUTES = intEnv('GLPE_VB_IDLE_MINUTES', 15);
const JPEG_QUALITY = intEnv('GLPE_VB_JPEG', 70);

function intEnv(name, fallback) {
  const v = parseInt(process.env[name] || '', 10);
  return Number.isFinite(v) && v > 0 ? v : fallback;
}

function log() {
  console.log(new Date().toISOString(), ...arguments);
}

// ---------------------------------------------------------------------------
// Shared key — the WordPress side writes the key file (uploads dir); every
// client must present the same key to open a renderer session.
// ---------------------------------------------------------------------------

let cachedKey = '';

function readKey() {
  if (KEYFILE) {
    try {
      const k = String(fs.readFileSync(KEYFILE, 'utf8') || '').trim();
      if (k) cachedKey = k;
    } catch (e) { /* file absent — keep last known */ }
  }
  return cachedKey;
}

// ---------------------------------------------------------------------------
// Renderer discovery.
// ---------------------------------------------------------------------------

function expandGlob(pattern) {
  const segs = pattern.split('/');
  let prefixes = segs[0] === '' ? [''] : [segs[0]];
  for (let i = 1; i < segs.length; i++) {
    const seg = segs[i];
    const next = [];
    for (const p of prefixes) {
      const dir = p === '' ? '/' : p;
      if (seg.indexOf('*') !== -1) {
        let names = [];
        try { names = fs.readdirSync(dir); } catch (e) { continue; }
        const re = new RegExp('^' + seg.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*') + '$');
        for (const n of names) {
          if (re.test(n)) next.push((dir === '/' ? '' : dir) + '/' + n);
        }
      } else {
        next.push(dir + '/' + seg);
      }
    }
    prefixes = next;
  }
  return prefixes.filter(p => {
    try { return fs.statSync(p).isFile() && (fs.statSync(p).mode & 0o111); } catch (e) { return false; }
  });
}

function findRenderer() {
  if (process.env.GLPE_VB_CHROME) return process.env.GLPE_VB_CHROME;
  const home = process.env.HOME || '/root';
  // Headless-shell builds are preferred: their window IS the capture
  // surface exactly. Full Chromium builds reserve ~192px of window UI
  // (compensated at spawn below).
  const patterns = [
    home + '/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux*/chrome-headless-shell',
    home + '/.cache/puppeteer/chrome-headless-shell/linux-*/chrome-linux*/chrome-headless-shell',
    home + '/.cache/ms-playwright/chromium-*/chrome-linux*/chrome',
    home + '/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome',
    '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable', '/usr/bin/chrome', '/usr/bin/chrome-headless-shell',
  ];
  for (const pat of patterns) {
    const hits = expandGlob(pat);
    if (hits.length) return hits[0];
  }
  return '';
}

// Window UI chrome reserved by full-browser builds in headless mode — the
// capture surface loses this much height when a full Chromium is used
// instead of a headless-shell build.
const FULL_BUILD_UI = 192;

// ---------------------------------------------------------------------------
// Chrome DevTools Protocol client (page session over WebSocket).
// ---------------------------------------------------------------------------

class CdpPage {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.nextId = 1;
    this.pending = new Map();
    this.handlers = new Map(); // method → fn(params)
    this.ready = new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('devtools socket timed out')), 12000);
      this._openResolve = () => { clearTimeout(timer); resolve(); };
      this._openReject = (e) => { clearTimeout(timer); reject(e); };
    });
  }

  connect() {
    this.ws = new WebSocket(this.wsUrl);
    this.ws.onopen = () => this._openResolve();
    this.ws.onerror = () => this._openReject(new Error('devtools socket failed'));
    this.ws.onclose = () => {
      const fn = this.handlers.get('__closed__');
      if (fn) fn();
    };
    this.ws.onmessage = (ev) => {
      let msg;
      try { msg = JSON.parse(String(ev.data)); } catch (e) { return; }
      if (msg.id !== undefined && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(msg.error.message || 'cdp error'));
        else resolve(msg.result || {});
        return;
      }
      if (msg.method && this.handlers.has(msg.method)) {
        this.handlers.get(msg.method)(msg.params || {});
      }
    };
    return this.ready;
  }

  on(method, fn) { this.handlers.set(method, fn); }
  onClosed(fn) { this.handlers.set('__closed__', fn); }

  send(method, params) {
    return new Promise((resolve, reject) => {
      const id = this.nextId++;
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params: params || {} }));
      const timer = setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error(method + ' timed out'));
        }
      }, 30000);
      if (timer && typeof timer.unref === 'function') timer.unref();
    });
  }

  close() {
    try { this.ws.close(); } catch (e) { /* already gone */ }
  }
}

// ---------------------------------------------------------------------------
// One virtual renderer session = one temporary Chromium profile + one page.
// ---------------------------------------------------------------------------

const sessions = new Set();
let rendererPath = '';

class VBSession {
  constructor(ws) {
    this.ws = ws;
    this.cdp = null;
    this.child = null;
    this.userDataDir = '';
    this.lastSeen = Date.now();
    this.width = 1280;
    this.height = 800;
    this.currentUrl = 'about:blank';
    this.dead = false;
    this.started = false;
    this.pendingAck = '';
    this.ackTimer = null;
  }

  safeSend(obj) {
    try { this.ws.send(JSON.stringify(obj)); } catch (e) { /* dropped client */ }
  }

  async start(url, width, height) {
    if (!rendererPath) rendererPath = findRenderer();
    if (!rendererPath) throw new Error('no renderer binary on this host');

    this.width = clampInt(width, 240, 3840, 1280);
    this.height = clampInt(height, 240, 2160, 800);
    this.userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'glpe-vb-'));

    const args = [
      '--remote-debugging-port=0',
      '--user-data-dir=' + this.userDataDir,
      '--no-sandbox',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      '--mute-audio',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-extensions',
      '--disable-component-update',
      '--disable-background-networking',
      '--disable-client-side-phishing-detection',
      '--disable-sync',
      '--force-device-scale-factor=1',
      'about:blank',
    ];
    const isShell = /headless[-_]shell/i.test(rendererPath);
    if (isShell) {
      // Headless-shell: the window is exactly the capture surface.
      args.unshift('--window-size=' + this.width + ',' + this.height);
    } else {
      // Full Chromium build: headless mode still reserves its own window UI
      // strip, so the window is spawned taller by that amount; the device
      // override below pins the layout viewport to the exact request.
      args.unshift('--headless=new', '--window-size=' + this.width + ',' + (this.height + FULL_BUILD_UI));
    }

    this.child = spawn(rendererPath, args, { stdio: ['ignore', 'ignore', 'pipe'] });
    this.child.on('exit', () => {
      if (!this.dead) {
        this.safeSend({ t: 'bye', reason: 'renderer-exited' });
        this.destroy();
      }
    });

    const devHttp = await this.waitForDevtools();
    const pageWs = await this.resolvePageTarget(devHttp);
    this.cdp = new CdpPage(pageWs);
    this.cdp.onClosed(() => { if (!this.dead) { this.safeSend({ t: 'bye', reason: 'renderer-closed' }); this.destroy(); } });
    await this.cdp.connect();

    this.cdp.on('Page.screencastFrame', (params) => this.onFrame(params));
    this.cdp.on('Page.loadEventFired', () => this.readLocation());
    this.cdp.on('Page.navigatedWithinDocument', () => this.readLocation());
    this.cdp.on('Page.javascriptDialogOpening', (params) => {
      this.safeSend({ t: 'dialog', message: String((params && params.message) || '') });
      this.cdp.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
    });
    this.cdp.on('Page.frameNavigated', (params) => {
      const frame = params && params.frame;
      if (frame && !frame.parentId && frame.url && /^https?:/i.test(frame.url)) {
        this.currentUrl = frame.url;
      }
    });

    await this.cdp.send('Page.enable');
    await this.cdp.send('Runtime.enable');
    // Exact viewport requested by the visitor.
    await this.cdp.send('Emulation.setDeviceMetricsOverride', {
      width: this.width, height: this.height, deviceScaleFactor: 1, mobile: false,
    });

    await this.navigate(url);

    await this.startScreencastWithRetry();
    this.rearmScreencastSoon();

    return true;
  }

  /**
   * The renderer refuses Page.startScreencast while a navigation is
   * swapping the main frame ("Not attached to an active page") — the
   * command is retried until the freshly navigated page settles.
   */
  async startScreencastWithRetry() {
    let lastErr = null;
    for (let attempt = 0; attempt < 25; attempt++) {
      try {
        await this.startScreencast();
        return true;
      } catch (e) {
        lastErr = e;
        await new Promise(r => setTimeout(r, 350));
      }
    }
    throw lastErr;
  }

  async startScreencast() {
    await this.cdp.send('Page.startScreencast', {
      format: 'jpeg',
      quality: JPEG_QUALITY,
      maxWidth: this.width,
      maxHeight: this.height,
      everyNthFrame: 1,
    });
  }

  /**
   * The first captured frame can carry a stale surface size (captured
   * before the viewport override settled). Re-arming the capture after
   * a beat guarantees one fresh, correctly-dimensioned frame even for
   * completely static pages.
   */
  rearmScreencastSoon() {
    setTimeout(() => {
      if (this.dead || !this.cdp) return;
      this.cdp.send('Page.stopScreencast', {}).catch(() => {});
      this.startScreencast().catch(() => {
        this.startScreencastWithRetry().catch(() => {});
      });
    }, 600);
  }

  waitForDevtools() {
    return new Promise((resolve, reject) => {
      let buf = '';
      const timer = setTimeout(() => {
        cleanup();
        reject(new Error('renderer did not expose its control socket'));
      }, 20000);
      const onData = (chunk) => {
        buf += String(chunk);
        const m = buf.match(/DevTools listening on (ws:\/\/\S+)/);
        if (m) {
          cleanup();
          // http://127.0.0.1:PORT — the browser's plain control endpoint.
          resolve(m[1].replace(/^ws:/, 'http:').replace(/\/devtools\/browser\/.*$/, ''));
        }
      };
      const onEnd = () => { cleanup(); reject(new Error('renderer exited during startup')); };
      const cleanup = () => {
        clearTimeout(timer);
        try { this.child.stderr.removeListener('data', onData); } catch (e) {}
        try { this.child.stderr.removeListener('end', onEnd); } catch (e) {}
      };
      this.child.stderr.on('data', onData);
      this.child.stderr.on('end', onEnd);
    });
  }

  async resolvePageTarget(devHttpBase) {
    const res = await fetch(devHttpBase + '/json/list');
    const targets = await res.json();
    const page = (targets || []).find(t => t && t.type === 'page' && t.webSocketDebuggerUrl);
    if (!page) throw new Error('no page target available');
    return page.webSocketDebuggerUrl;
  }

  async navigate(rawUrl) {
    let url = String(rawUrl || '').trim();
    if (/^about:blank$/i.test(url)) {
      this.currentUrl = url;
      await this.cdp.send('Page.navigate', { url });
      return;
    }
    if (!/^https?:/i.test(url)) {
      url = 'https://' + url.replace(/^[a-z]+:\/\//i, '');
    }
    if (!/^https?:/i.test(url)) {
      throw new Error('unsupported address');
    }
    this.currentUrl = url;
    await this.cdp.send('Page.navigate', { url });
  }

  async readLocation() {
    try {
      const r = await this.cdp.send('Runtime.evaluate', {
        expression: 'JSON.stringify([location.href, document.title])',
        returnByValue: true,
      });
      const pair = JSON.parse(String(r && r.result && r.result.value || '["",""]'));
      this.currentUrl = String(pair[0] || this.currentUrl);
      this.safeSend({ t: 'nav', url: this.currentUrl, title: String(pair[1] || '') });
    } catch (e) { /* page busy — next event retries */ }
  }

  // Frame flow control: forward the compressed picture to the visitor and
  // confirm receipt to the renderer ONLY once the visitor confirmed it drew
  // the frame (client 'ack') — a slow client throttles itself naturally.
  onFrame(params) {
    const meta = params.metadata || {};
    this.safeSend({
      t: 'frame',
      d: String(params.data || ''),
      m: {
        l: meta.offsetLeft || 0,
        tp: meta.offsetTop || 0,
        s: meta.pageScaleFactor || 1,
        w: meta.deviceWidth || this.width,
        h: meta.deviceHeight || this.height,
      },
    });
    if (this.ackTimer) clearTimeout(this.ackTimer);
    this.pendingAck = params.sessionId;
    this.ackTimer = setTimeout(() => this.ackFrame(), 2000);
  }

  ackFrame() {
    if (!this.pendingAck) return;
    const sid = this.pendingAck;
    this.pendingAck = '';
    if (this.ackTimer) { clearTimeout(this.ackTimer); this.ackTimer = null; }
    if (this.cdp) this.cdp.send('Page.screencastFrameAck', { sessionId: sid }).catch(() => {});
  }

  // --- input replay ---------------------------------------------------------

  mouse(params) {
    const x = clampInt(params.x, 0, 100000, 0);
    const y = clampInt(params.y, 0, 100000, 0);
    const button = ['left', 'right', 'middle'].indexOf(params.b) >= 0 ? params.b : 'left';
    let type = 'mouseMoved';
    if (params.a === 'down') type = 'mousePressed';
    else if (params.a === 'up') type = 'mouseReleased';
    else if (params.a === 'wheel') type = 'mouseWheel';
    const ev = { type: type, x: x, y: y, button: type === 'mouseMoved' || type === 'mouseWheel' ? 'none' : button, clickCount: 1 };
    if (type === 'mouseWheel') {
      ev.deltaX = Math.round(Number(params.dx) || 0);
      ev.deltaY = Math.round(Number(params.dy) || 0);
    }
    if (this.cdp) this.cdp.send('Input.dispatchMouseEvent', ev).catch(() => {});
  }

  dbl(params) {
    // Double tap from the touch classifier (v7.5.0): clickCount 2 makes
    // Chromium fire dblclick natively alongside the second press.
    const x = clampInt(params.x, 0, 100000, 0);
    const y = clampInt(params.y, 0, 100000, 0);
    if (this.cdp) {
      this.cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 2 }).catch(() => {});
      this.cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 2 }).catch(() => {});
    }
  }

  key(params) {
    const ev = {
      type: params.a === 'up' ? 'keyUp' : 'keyDown',
      key: String(params.key || ''),
      code: String(params.code || ''),
      windowsVirtualKeyCode: clampInt(params.vk, 0, 65535, 0),
      nativeVirtualKeyCode: clampInt(params.vk, 0, 65535, 0),
      modifiers: clampInt(params.mods, 0, 15, 0) | 0,
    };
    const text = String(params.text || '');
    if (params.a === 'down' && text) {
      ev.text = text;
      ev.unmodifiedText = text;
    }
    if (this.cdp) this.cdp.send('Input.dispatchKeyEvent', ev).catch(() => {});
  }

  text(params) {
    const v = String(params.v || '').slice(0, 5000);
    if (v && this.cdp) this.cdp.send('Input.insertText', { text: v }).catch(() => {});
  }

  async history(dir) {
    try {
      const h = await this.cdp.send('Page.getNavigationHistory', {});
      const idx = h.currentIndex + (dir === 'back' ? -1 : 1);
      if (h.entries && idx >= 0 && idx < h.entries.length) {
        await this.cdp.send('Page.navigateToHistoryEntry', { entryId: h.entries[idx].id });
      }
    } catch (e) { /* nothing to move to */ }
  }

  destroy() {
    if (this.dead) return;
    this.dead = true;
    sessions.delete(this);
    if (this.ackTimer) clearTimeout(this.ackTimer);
    if (this.cdp) { try { this.cdp.close(); } catch (e) {} }
    if (this.child) {
      try { this.child.kill('SIGKILL'); } catch (e) {}
    }
    if (this.userDataDir) {
      // Temporary profile removal — the whole environment is discarded.
      setTimeout(() => {
        try { fs.rmSync(this.userDataDir, { recursive: true, force: true }); } catch (e) {}
      }, 500);
    }
  }
}

function clampInt(v, min, max, fallback) {
  const n = parseInt(v, 10);
  if (!Number.isFinite(n)) return fallback;
  return Math.max(min, Math.min(max, n));
}

// ---------------------------------------------------------------------------
// Service socket: HTTP health + one renderer session per WebSocket.
// ---------------------------------------------------------------------------

function json(obj, status) {
  return new Response(JSON.stringify(obj), {
    status: status || 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function handleClientMessage(ws, session, raw) {
  let msg;
  try { msg = JSON.parse(String(raw)); } catch (e) { return; }
  if (!msg || typeof msg.t !== 'string') return;
  session.lastSeen = Date.now();

  switch (msg.t) {
    case 'start':
      if (session.started) return;
      if (sessions.size >= MAX_SESSIONS) {
        session.safeSend({ t: 'err', msg: 'busy' });
        try { ws.close(); } catch (e) {}
        return;
      }
      session.started = true;
      sessions.add(session);
      try {
        await session.start(msg.url, msg.w, msg.h);
        session.safeSend({ t: 'started', w: session.width, h: session.height, url: session.currentUrl, v: VERSION });
      } catch (e) {
        session.safeSend({ t: 'err', msg: String((e && e.message) || e) });
        session.destroy();
        try { ws.close(); } catch (e2) {}
      }
      return;

    case 'ack':
      session.ackFrame();
      return;

    case 'move':
    case 'down':
    case 'up':
    case 'wheel':
      session.mouse({ a: msg.t, x: msg.x, y: msg.y, b: msg.b, dx: msg.dx, dy: msg.dy });
      return;

    case 'dbl':
      session.dbl(msg);
      return;

    case 'key':
      session.key(msg);
      return;

    case 'text':
      session.text(msg);
      return;

    case 'nav':
      if (session.cdp) session.navigate(msg.url).catch(() => {});
      return;

    case 'size':
      if (session.cdp) {
        const w = clampInt(msg.w, 240, 3840, session.width);
        const h = clampInt(msg.h, 240, 2160, session.height);
        session.width = w; session.height = h;
        session.cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false }).catch(() => {});
        session.cdp.send('Page.stopScreencast', {}).catch(() => {});
        session.startScreencastWithRetry().catch(() => {});
      }
      return;

    case 'back':
    case 'fwd':
      session.history(msg.t);
      return;

    case 'ping':
      session.safeSend({ t: 'pong', ts: msg.ts });
      return;

    default:
      return;
  }
}

Bun.serve({
  port: PORT,
  hostname: HOSTNAME,

  fetch(req, server) {
    const url = new URL(req.url);
    if (url.pathname === '/health') {
      return json({
        ok: 1,
        service: 'glpe-vb',
        v: VERSION,
        renderer: findRenderer() ? 1 : 0,
        sessions: sessions.size,
      });
    }
    const key = url.searchParams.get('key') || '';
    if (key === '' || key !== readKey()) {
      return new Response('denied', { status: 403 });
    }
    if (server.upgrade(req, { data: {} })) {
      return; // upgraded
    }
    return new Response('upgrade required', { status: 426 });
  },

  websocket: {
    open(ws) {
      const s = new VBSession(ws);
      ws.data.session = s;
      s.safeSend({ t: 'hello', v: VERSION });
    },
    async message(ws, message) {
      const s = ws.data && ws.data.session;
      if (!s) return;
      try {
        await handleClientMessage(ws, s, message);
      } catch (e) {
        try { s.safeSend({ t: 'err', msg: String((e && e.message) || e) }); } catch (e2) {}
      }
    },
    close(ws) {
      const s = ws.data && ws.data.session;
      if (s) s.destroy();
    },
  },
});

log('glpe-vb service v' + VERSION + ' on ' + HOSTNAME + ':' + PORT + ' (renderer: ' + (findRenderer() || 'not found') + ')');

// Idle sweeper — abandoned sessions must not leave renderers running.
setInterval(() => {
  const cutoff = Date.now() - IDLE_MINUTES * 60 * 1000;
  for (const s of Array.from(sessions)) {
    if (s.lastSeen < cutoff) {
      log('idle session expired');
      s.safeSend({ t: 'bye', reason: 'idle' });
      s.destroy();
    }
  }
}, 30000);
