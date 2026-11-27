/**
 * GLPE Virtual Browser — sandbox engine, visitor-side virtual surface.
 *
 * Loaded on the viewer page when the virtual browser runs the SANDBOX engine
 * — the engine for hosts that offer nothing beyond plain PHP (free hosting
 * panels without any server access). The arrangement it drives:
 *
 *   - the destination renders inside a locked, temporary frame whose every
 *     exchange is fetched and re-served by the host through the encrypted
 *     gateway (the destination only ever sees the hosting server);
 *   - mouse, wheel and keyboard gestures NEVER reach that frame directly:
 *     they are captured here, wrapped into opaque tokens and sent to the
 *     HOST FIRST; only the host's echo — returned after the host has
 *     processed and journaled the batch — moves the virtual cursor and
 *     replays the gesture inside the sandbox. The visible round-trip lag
 *     is the honest signature of that path (remote-desktop semantics);
 *   - "pixel" mode shows the sandbox as a plain picture on a canvas: the
 *     visitor then watches an image of the page, not the page itself;
 *   - closing the surface throws the whole temporary environment away
 *     (its session, its jar, its records).
 */
(function () {
  if (window.__glpe_vbs_ui__) return;
  window.__glpe_vbs_ui__ = true;

  var CFG = window.GLPE_VBS || {};
  var ep = CFG.ep || '';
  var KEY = CFG.k || '';
  var root = null, stage = null, frame = null, capture = null, cursor = null;
  var pixelCanvas = null, pixelCtx = null, urlInput = null, statusEl = null;
  var latEl = null, evEl = null, modeBtn = null;
  var sess = null;            // {t, ttl}
  var sessW = 1024, sessH = 768;
  var currentUrl = '';
  var closing = false;
  var pending = [];           // gestures waiting for the host
  var pendingMove = null;     // coalesced latest mouse move
  var lastX = -1, lastY = -1;
  var lastFocusEl = null;
  var flushTimer = null, beatTimer = null, pixelTimer = null, syncTimer = null;
  var flushInterval = 120;    // ms — adaptive (rtt-aware)
  var lastRtt = 0;
  var replayed = 0;
  var pixelMode = false, pixFails = 0;
  var imgCache = {};          // raster image data-URL cache by src
  // v7.4.0 — interaction state: text-selection drag, the surface menu,
  // the clipboard affordances and the download flow's progress card.
  var dragSel = null;         // {node, off} anchor of an in-progress selection
  var vbMenu = null;
  var toastEl = null, toastTimer = null;
  var dlBusy = false;
  var DL_RE = /\.(zip|rar|7z|gz|tgz|xz|bz2|exe|msi|dmg|apk|ipa|iso|deb|rpm|bin|tar|epub|mobi|jar)(\?|$)/i;

  function txt(s) { return document.createTextNode(s); }

  function el(tag, style) {
    var e = document.createElement(tag);
    if (style) e.setAttribute('style', style);
    return e;
  }

  // Floating status chip (viewer-level affordances: clipboard feedback,
  // download progress notes) — never touches the sandboxed frame.
  function toast(msg) {
    if (!root) return;
    if (!toastEl) {
      toastEl = el('div', 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);' +
        'background:rgba(15,23,42,0.96);color:#f8fafc;border:1px solid #334155;border-radius:12px;' +
        'padding:10px 18px;font-size:12px;line-height:1.8;z-index:2147483647;direction:rtl;max-width:80%;text-align:center;');
      root.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.style.display = 'block';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      if (toastEl) toastEl.style.display = 'none';
    }, 2600);
  }

  // ---------------------------------------------------------------------------
  // Byte codec (XOR + URL-safe base64) — mirrors GLPE_Codec; every field that
  // travels between this surface and the host is an opaque token.
  // ---------------------------------------------------------------------------

  function enc(str) {
    if (!str || typeof str !== 'string') return '';
    try {
      var bytes = unescape(encodeURIComponent(str));
      var out = '';
      for (var i = 0; i < bytes.length; i++) {
        out += String.fromCharCode(bytes.charCodeAt(i) ^ KEY.charCodeAt(i % KEY.length));
      }
      return btoa(out).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    } catch (e) {
      return '';
    }
  }

  function dec(token) {
    if (!token || typeof token !== 'string') return '';
    try {
      var b64 = token.replace(/-/g, '+').replace(/_/g, '/');
      while (b64.length % 4) b64 += '=';
      var raw = atob(b64);
      var out = '';
      for (var i = 0; i < raw.length; i++) {
        out += String.fromCharCode(raw.charCodeAt(i) ^ KEY.charCodeAt(i % KEY.length));
      }
      return decodeURIComponent(escape(out));
    } catch (e) {
      return '';
    }
  }

  // ---------------------------------------------------------------------------
  // Host channel: one opaque 'p' token up, one opaque 'd' token back.
  // ---------------------------------------------------------------------------

  function post(mode, docObj, cb, failCb) {
    if (!ep) return;
    // Download endpoints ride the sandbox identity: vs=1 hands the request
    // the isolated jar, so a login made inside the virtual session carries
    // over to its file downloads.
    var vsMode = mode === 'vb_dlopen' || mode === 'vb_dlchunk';
    var body = 'p=' + encodeURIComponent(enc(JSON.stringify(docObj)));
    fetch(ep + '&mode=' + mode + (vsMode ? '&vs=1' : ''), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok && typeof j.d === 'string') {
        var payload = {};
        try { payload = JSON.parse(dec(j.d)) || {}; } catch (e) { payload = {}; }
        cb(payload);
      } else if (failCb) {
        failCb();
      }
    }).catch(function () {
      if (failCb) failCb();
    });
  }

  // ---------------------------------------------------------------------------
  // Overlay construction (dark card look, RTL — same family as the service
  // engine's surface so both engines feel like one feature).
  // ---------------------------------------------------------------------------

  var S = {
    root: 'position:fixed;inset:0;z-index:2147483647;background:#0f172a;display:flex;flex-direction:column;font-family:Tahoma,system-ui,sans-serif;direction:rtl;',
    bar: 'display:flex;align-items:center;gap:8px;padding:10px 14px;background:#1e293b;border-bottom:1px solid #334155;flex:none;flex-wrap:wrap;',
    btn: 'height:34px;min-width:34px;padding:0 10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#f8fafc;font-size:14px;cursor:pointer;line-height:1;flex:none;font-family:inherit;',
    btnClose: 'width:34px;height:34px;border-radius:10px;border:1px solid #7f1d1d;background:#450a0a;color:#fecaca;font-size:15px;cursor:pointer;line-height:1;flex:none;',
    input: 'flex:1;min-width:200px;padding:9px 12px;background:#0f172a;border:1px solid #334155;border-radius:10px;color:#f8fafc;font-size:12px;font-family:monospace;outline:none;direction:ltr;text-align:left;',
    stage: 'flex:1;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#000;',
    pill: 'position:absolute;top:10px;right:12px;background:rgba(15,23,42,0.85);border:1px solid #334155;color:#94a3b8;border-radius:9999px;padding:4px 12px;font-size:11px;line-height:1.8;z-index:6;max-width:70%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;',
    foot: 'display:flex;align-items:center;gap:14px;padding:8px 14px;background:#1e293b;border-top:1px solid #334155;color:#94a3b8;font-size:11px;flex:none;flex-wrap:wrap;',
    frame: 'border:0;background:#fff;display:block;flex:none;',
    pixelCanvas: 'position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);border:0;background:#000;display:none;flex:none;',
    capture: 'position:absolute;inset:0;z-index:4;background:transparent;cursor:none;outline:none;touch-action:none;',
    cursor: 'position:absolute;left:0;top:0;z-index:5;width:26px;height:26px;pointer-events:none;display:block;'
  };

  function buildOverlay() {
    root = el('div', S.root);
    root.id = '__glpe_vbs_root';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', 'مرورگر مجازی');

    var bar = el('div', S.bar);
    var bClose = el('button', S.btnClose); bClose.type = 'button'; bClose.title = 'بستن'; bClose.textContent = '✕';
    var bReload = el('button', S.btn); bReload.type = 'button'; bReload.title = 'بارگذاری دوباره'; bReload.textContent = '⟳';
    var bBack = el('button', S.btn); bBack.type = 'button'; bBack.title = 'پیشین'; bBack.textContent = '→'; bBack.id = '__glpe_vbs_back';
    var bFwd = el('button', S.btn); bFwd.type = 'button'; bFwd.title = 'بعدی'; bFwd.textContent = '←';
    modeBtn = el('button', S.btn); modeBtn.type = 'button'; modeBtn.title = 'تغییر حالت نمایش'; modeBtn.textContent = '🖼️ پیکسل';
    urlInput = el('input', S.input); urlInput.type = 'text'; urlInput.placeholder = 'نشانی سایت — مثال: example.com';
    urlInput.setAttribute('aria-label', 'نشانی سایت');

    bar.appendChild(bClose);
    bar.appendChild(bReload);
    bar.appendChild(bBack);
    bar.appendChild(bFwd);
    bar.appendChild(urlInput);
    bar.appendChild(modeBtn);
    root.appendChild(bar);

    stage = el('div', S.stage);
    // The locked temporary environment: scripts and forms run, same-origin
    // access lets the surface replay gestures; popups and top navigation are
    // forbidden by the sandbox attribute itself, while allow-downloads lets
    // tunneled file responses (Content-Disposition) reach the visitor's own
    // save dialog instead of dying silently inside the sandbox.
    frame = el('iframe', S.frame);
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-downloads');
    frame.setAttribute('aria-label', 'نمایشگر مجازی');
    frame.style.width = sessW + 'px';
    frame.style.height = sessH + 'px';
    frame.addEventListener('load', onFrameLoad);

    // Pixel mode canvas: absolutely centered and opaque, it COVERS the
    // still-running frame (painting, layout and hit-testing inside the
    // sandbox keep working — the visitor just watches the picture).
    pixelCanvas = el('canvas', S.pixelCanvas);
    pixelCanvas.style.display = 'none';

    capture = el('div', S.capture);
    capture.tabIndex = 0;
    capture.id = '__glpe_vbs_capture';

    cursor = el('img', S.cursor);
    cursor.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 26 26">' +
      '<path d="M3 1 L3 20 L8.2 14.8 L11.6 22 L15 20.4 L11.7 13.2 L19 12.6 Z" ' +
      'fill="#ffffff" stroke="#111827" stroke-width="1.6" stroke-linejoin="round"/></svg>');
    cursor.alt = '';

    stage.appendChild(frame);
    stage.appendChild(pixelCanvas);
    stage.appendChild(capture);
    stage.appendChild(cursor);
    statusEl = el('div', S.pill);
    statusEl.id = '__glpe_vbs_status';
    stage.appendChild(statusEl);
    root.appendChild(stage);

    var foot = el('div', S.foot);
    var state = el('span'); state.style.color = '#38bdf8'; state.textContent = '🖥️ موتور جعبه‌ای — بدون نیاز به سرویس روی سرور';
    var note = el('span'); note.style.flex = '1'; note.textContent = 'تمام تبادل‌های شبکه و ورودی‌های شما ابتدا به همین هاست ارسال و پس از پردازش همان‌جا در این نمایشگر بازپخش می‌شوند؛ صفحهٔ مقصد داخل جعبهٔ ایزولهٔ موقت نمایش می‌یابد و با بستن، دور ریخته می‌شود.';
    latEl = el('span'); latEl.dir = 'ltr'; latEl.textContent = '— ms';
    evEl = el('span'); evEl.dir = 'ltr'; evEl.textContent = '0 ev';
    foot.appendChild(state); foot.appendChild(note); foot.appendChild(latEl); foot.appendChild(evEl);
    root.appendChild(foot);

    bClose.onclick = closeSurface;
    bReload.onclick = function () { if (sess && currentUrl) navTo(currentUrl); };
    bBack.onclick = function () { try { frame.contentWindow.history.back(); } catch (e) {} };
    bFwd.onclick = function () { try { frame.contentWindow.history.forward(); } catch (e) {} };
    modeBtn.onclick = function () { pixelMode ? leavePixel() : enterPixel(); };
    urlInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var v = urlInput.value.trim();
        if (v && sess) navTo(v);
        capture.focus();
      }
      e.stopPropagation();
    });

    document.body.appendChild(root);
    document.body.style.overflow = 'hidden';
    setStatus('در حال ساختن محیط مجازی روی میزبان…');
  }

  function closeSurface() {
    closing = true;
    if (flushTimer) { clearInterval(flushTimer); flushTimer = null; }
    if (beatTimer) { clearInterval(beatTimer); beatTimer = null; }
    if (pixelTimer) { clearInterval(pixelTimer); pixelTimer = null; }
    if (syncTimer) { clearInterval(syncTimer); syncTimer = null; }
    hideVbMenu();
    dragSel = null;
    if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }
    toastEl = null;
    dlBusy = false;
    if (sess && sess.t) {
      // Best-effort teardown: the session TTL reaps whatever misses this.
      try {
        var body = 'p=' + encodeURIComponent(enc(JSON.stringify({ t: sess.t })));
        navigator.sendBeacon(ep + '&mode=vb_close', new Blob([body], { type: 'application/x-www-form-urlencoded' }));
      } catch (e) {
        post('vb_close', { t: sess.t }, function () {}, function () {});
      }
    }
    if (root && root.parentNode) root.parentNode.removeChild(root);
    document.body.style.overflow = '';
    root = stage = frame = capture = cursor = pixelCanvas = pixelCtx = urlInput = sess = null;
  }

  function setStatus(t) { if (statusEl) statusEl.textContent = t; }

  // ---------------------------------------------------------------------------
  // Session bootstrap.
  // ---------------------------------------------------------------------------

  function openSandbox(url) {
    closing = false;
    lastX = lastY = -1;
    replayed = 0;
    imgCache = {};
    dragSel = null;
    dlBusy = false;
    buildOverlay();
    bindInput();
    var rect = stage.getBoundingClientRect();
    var w = Math.max(320, Math.min(1920, Math.floor(rect.width - 4)));
    var h = Math.max(240, Math.min(1200, Math.floor(rect.height - 4)));
    post('vb_open', { u: url, w: w, h: h }, function (d) {
      if (d.e === 'busy') { setStatus('سقف نشست‌های همزمان روی میزبان پر است — کمی بعد دوباره'); return; }
      if (d.e || !d.t) { setStatus('خطا در ساختن محیط — دوباره تلاش کنید'); return; }
      sess = { t: d.t, ttl: d.ttl || 1800 };
      currentUrl = url;
      sessW = w; sessH = h;
      frame.style.width = w + 'px';
      frame.style.height = h + 'px';
      frame.src = d.g;
      setStatus('در حال بارگذاری صفحه از طریق میزبان…');
      fitFrame();
      startTimers();
    }, function () {
      setStatus('ارتباط با میزبان برقرار نشد — دکمهٔ بستن را بزنید و دوباره باز کنید');
    });
  }

  function startTimers() {
    if (flushTimer) clearInterval(flushTimer);
    flushTimer = setInterval(flush, flushInterval);
    if (beatTimer) clearInterval(beatTimer);
    beatTimer = setInterval(function () {
      if (!sess || closing) return;
      post('vb_beat', { t: sess.t }, function () {}, function () {});
    }, 120000);
    if (!syncTimer) {
      syncTimer = setInterval(syncAddress, 2000);
    }
    try {
      window.addEventListener('beforeunload', onLeave);
    } catch (e) {}
  }

  // SPA navigations inside the sandbox change the visible path without a
  // full load event — a light poll keeps the address bar honest.
  function syncAddress() {
    if (!sess || closing || !frame) return;
    try {
      var w = frame.contentWindow;
      if (document.activeElement === urlInput) return; // never clobber typing
      var m = String(w.location.href).match(/[?&]l=([A-Za-z0-9_-]+)/);
      var dest = m ? dec(m[1]) : '';
      if (dest) urlInput.value = dest;
    } catch (e) {}
  }

  function onLeave() {
    if (sess && sess.t && !closing) {
      try {
        var body = 'p=' + encodeURIComponent(enc(JSON.stringify({ t: sess.t })));
        navigator.sendBeacon(ep + '&mode=vb_close', new Blob([body], { type: 'application/x-www-form-urlencoded' }));
      } catch (e) {}
    }
  }

  // ---------------------------------------------------------------------------
  // Input capture → host round trip → replay.
  // ---------------------------------------------------------------------------

  function frameWin() { try { return frame.contentWindow; } catch (e) { return null; } }
  function frameDoc() { var w = frameWin(); return w ? w.document : null; }

  function stagePoint(e) {
    // Event on the capture layer → sandbox pixel coordinates.
    var r = frame.getBoundingClientRect();
    var x = Math.round((e.clientX - r.left) * (sessW / Math.max(1, r.width)));
    var y = Math.round((e.clientY - r.top) * (sessH / Math.max(1, r.height)));
    return { x: Math.max(0, Math.min(sessW - 1, x)), y: Math.max(0, Math.min(sessH - 1, y)) };
  }

  function modsBits(e) {
    return (e.altKey ? 1 : 0) | (e.ctrlKey ? 2 : 0) | (e.metaKey ? 4 : 0) | (e.shiftKey ? 8 : 0);
  }

  function push(ev) {
    if (!sess || closing) return;
    if (ev.t === 'mm') {
      pendingMove = ev; // coalesced — the host processes the latest position
      return;
    }
    pending.push(ev);
    flush(); // gestures other than plain movement ride immediately
  }

  function bindInput() {
    capture.addEventListener('pointermove', function (e) {
      var p = stagePoint(e);
      push({ t: 'mm', x: p.x, y: p.y });
    });

    capture.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      capture.focus();
      hideVbMenu();
      try { capture.setPointerCapture(e.pointerId); } catch (err) {}
      var p = stagePoint(e);
      push({ t: 'dn', x: p.x, y: p.y, b: buttonName(e.button) });
    });

    capture.addEventListener('pointerup', function (e) {
      var p = stagePoint(e);
      push({ t: 'up', x: p.x, y: p.y, b: buttonName(e.button) });
    });

    // Right-click rides the host round trip like every other gesture (rc);
    // the native menu of THIS browser cannot appear inside the locked
    // surface — the echo replays a synthetic contextmenu for the site's own
    // menu, and when the site does not claim it the surface's own menu opens.
    capture.addEventListener('contextmenu', function (e) {
      e.preventDefault();
      if (e.button === 2 || e.pointerType === 'mouse') {
        var p = stagePoint(e);
        push({ t: 'rc', x: p.x, y: p.y });
      }
    });

    capture.addEventListener('dblclick', function (e) {
      e.preventDefault();
      var p = stagePoint(e);
      push({ t: 'db', x: p.x, y: p.y });
    });

    capture.addEventListener('wheel', function (e) {
      e.preventDefault();
      var p = stagePoint(e);
      var mult = e.deltaMode === 1 ? 16 : (e.deltaMode === 2 ? 100 : 1);
      push({ t: 'wh', x: p.x, y: p.y, dx: Math.round(e.deltaX * mult), dy: Math.round(e.deltaY * mult) });
    }, { passive: false });

    capture.addEventListener('keydown', function (e) {
      e.preventDefault();
      // Clipboard affordances use THIS document's real user activation (a
      // synthetic echo cannot touch the visitor's clipboard): copying reads
      // the rendered frame's selection, select-all spans it, and the keydown
      // itself still rides the host round trip below like every gesture.
      if (e.ctrlKey || e.metaKey) {
        if (e.key === 'c' || e.key === 'C') { copySelectionToVisitor(); }
        else if (e.key === 'a' || e.key === 'A') { selectAllInFrame(); }
        else if (e.key === 'x' || e.key === 'X') { copySelectionToVisitor(true); }
      }
      var printable = e.key && e.key.length === 1;
      push({ t: 'kd', key: e.key, code: e.code, vk: e.keyCode || 0, text: printable ? e.key : '', mods: modsBits(e) });
    });

    capture.addEventListener('keyup', function (e) {
      e.preventDefault();
      push({ t: 'ku', key: e.key, code: e.code, vk: e.keyCode || 0, mods: modsBits(e) });
    });

    capture.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text');
      if (t) push({ t: 'tx', v: String(t).slice(0, 2000), p: 1 });
    });
  }

  function buttonName(b) {
    if (b === 2) return 'right';
    if (b === 1) return 'middle';
    return 'left';
  }

  function flush() {
    if (!sess || closing) return;
    if (flushBusy) return; // one round trip at a time — ordering is the contract
    var batch = [];
    if (pendingMove) { batch.push(pendingMove); pendingMove = null; }
    if (!batch.length && !pending.length) return;
    for (var i = 0; i < pending.length; i++) batch.push(pending[i]);
    pending = [];
    flushBusy = true;
    var t0 = Date.now();
    post('vb_in', { t: sess.t, ev: batch }, function (d) {
      flushBusy = false;
      lastRtt = Date.now() - t0;
      if (latEl) latEl.textContent = lastRtt + ' ms';
      // Adaptive pacing: a slow host throttles the move stream, a fast one
      // keeps the cursor tight — the honest round-trip stays either way.
      var want = lastRtt > 600 ? 300 : (lastRtt < 120 ? 90 : 140);
      if (want !== flushInterval && flushTimer) {
        flushInterval = want;
        clearInterval(flushTimer);
        flushTimer = setInterval(flush, flushInterval);
      }
      if (d.e === 'expired') {
        setStatus('نشست منقضی شد — پوشه را ببندید و دوباره باز کنید');
        sess = null;
        return;
      }
      var evs = d.ev || [];
      for (var i2 = 0; i2 < evs.length; i2++) applyEvent(evs[i2]);
      replayed += evs.length;
      if (evEl) evEl.textContent = replayed + ' ev';
      // The sandbox can pull REAL focus into itself (a focused field would
      // otherwise receive the visitor's next keystrokes directly — around
      // the host round trip). The capture layer takes focus back after every
      // batch so each gesture keeps riding the host first.
      try { if (capture && capture.focus) capture.focus({ preventScroll: true }); } catch (e) { try { capture.focus(); } catch (e2) {} }
    }, function () {
      flushBusy = false;
      setStatus('خطای ارتباط با میزبان — تلاش مجدد…');
    });
  }
  var flushBusy = false;

  // ---------------------------------------------------------------------------
  // Replay — only the HOST's echo drives the sandbox (synthetic, untrusted by
  // construction: nothing here ever touches the frame before the host said so).
  // ---------------------------------------------------------------------------

  // The frame's internal viewport can differ from the sandbox's nominal
  // size when fitFrame() scales the element — every frame-side hit test and
  // synthetic event coordinate is mapped through the live ratio so selection
  // and gestures land where the visitor actually pointed.
  function frameScale() {
    try {
      var w = frameWin();
      if (!w) return { sx: 1, sy: 1 };
      return {
        sx: (w.innerWidth || sessW) / sessW,
        sy: (w.innerHeight || sessH) / sessH
      };
    } catch (e) { return { sx: 1, sy: 1 }; }
  }

  function mapPoint(x, y) {
    var s = frameScale();
    return { x: Math.round(x * s.sx), y: Math.round(y * s.sy) };
  }

  function elAt(x, y) {
    var d = frameDoc();
    if (!d) return null;
    try {
      return d.elementFromPoint(x, y) || d.documentElement;
    } catch (e) { return null; }
  }

  function fireMouse(target, type, x, y, button, buttons) {
    if (!target) return;
    var w = frameWin();
    try {
      var e = new w.MouseEvent(type, {
        bubbles: true, cancelable: true, composed: true, view: w,
        clientX: x, clientY: y, button: button, buttons: buttons
      });
      target.dispatchEvent(e);
    } catch (err) {}
  }

  function positionCursor(x, y) {
    if (!cursor || !frame) return;
    try {
      var r = frame.getBoundingClientRect();
      var s = stage.getBoundingClientRect();
      var px = (r.left - s.left) + (x / sessW) * r.width;
      var py = (r.top - s.top) + (y / sessH) * r.height;
      cursor.style.transform = 'translate(' + px + 'px,' + py + 'px)';
      cursor.style.display = pixelMode ? 'none' : 'block';
    } catch (e) {}
  }

  function applyEvent(ev) {
    if (!ev || !ev.t || !frame) return;
    var fp = mapPoint(ev.x, ev.y);
    switch (ev.t) {
      case 'mm':
        lastX = ev.x; lastY = ev.y;
        positionCursor(ev.x, ev.y);
        fireMouse(elAt(fp.x, fp.y), 'mousemove', fp.x, fp.y, 0, 0);
        extendDragSelection(fp.x, fp.y);
        break;
      case 'dn': {
        lastX = ev.x; lastY = ev.y;
        positionCursor(ev.x, ev.y);
        var target = elAt(fp.x, fp.y);
        fireMouse(target, 'mousedown', fp.x, fp.y, buttonIndex(ev.b), 1);
        // Synthetic gestures do not focus like real ones — editable surfaces
        // must gain the caret explicitly so the next keystrokes land there.
        // A left press on anything ELSE anchors a text-selection drag.
        if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) {
          lastFocusEl = target;
          dragSel = null;
          try { target.focus(); } catch (e) {}
        } else if (target && target.isContentEditable) {
          lastFocusEl = target;
          dragSel = null;
          try { target.focus(); } catch (e) {}
        } else if (ev.b === 'left') {
          startDragSelection(fp.x, fp.y);
        }
        break;
      }
      case 'up': {
        fireMouse(elAt(fp.x, fp.y), 'mouseup', fp.x, fp.y, buttonIndex(ev.b), 0);
        fireMouse(elAt(fp.x, fp.y), 'click', fp.x, fp.y, buttonIndex(ev.b), 0);
        dragSel = null;
        break;
      }
      case 'wh': {
        var t = elAt(fp.x, fp.y);
        var w = frameWin();
        if (t && w) {
          try {
            t.dispatchEvent(new w.WheelEvent('wheel', {
              bubbles: true, cancelable: true, view: w,
              clientX: fp.x, clientY: fp.y, deltaX: ev.dx, deltaY: ev.dy, deltaMode: 0
            }));
          } catch (e) {}
          var sc = scrollableAt(t);
          if (sc) { sc.scrollTop += ev.dy; sc.scrollLeft += ev.dx; }
          else if (w.scrollBy) { w.scrollBy(ev.dx, ev.dy); }
        }
        break;
      }
      case 'rc': {
        // Right-click: replay the synthetic contextmenu into the frame so
        // the site's own menu logic runs; a site that did not claim the
        // gesture (no preventDefault) gets the surface's own menu instead.
        lastX = ev.x; lastY = ev.y;
        positionCursor(ev.x, ev.y);
        var rt = elAt(fp.x, fp.y);
        var rw = frameWin();
        var rce = null;
        if (rt && rw) {
          try {
            rce = new rw.MouseEvent('contextmenu', {
              bubbles: true, cancelable: true, composed: true, view: rw,
              clientX: fp.x, clientY: fp.y, button: 2, buttons: 2
            });
            rt.dispatchEvent(rce);
          } catch (e) {}
        }
        if (!rce || !rce.defaultPrevented) {
          setTimeout(function () {
            if (rce && rce.defaultPrevented) return;
            showVbMenu(ev.x, ev.y);
          }, 70);
        }
        break;
      }
      case 'db': {
        // Double-click: replay the synthetic dblclick (site handlers — e.g.
        // player fullscreen toggles — run) and select the word under the
        // point, mirroring what a native double-click would highlight.
        fireMouse(elAt(fp.x, fp.y), 'dblclick', fp.x, fp.y, 0, 0);
        selectWordAt(fp.x, fp.y);
        break;
      }
      case 'kd':
      case 'ku':
        applyKey(ev);
        break;
      case 'tx':
        if (ev.p) { pasteIntoFrame(String(ev.v || '')); }
        else { insertText(String(ev.v || '')); }
        break;
    }
  }

  function buttonIndex(b) {
    if (b === 'right') return 2;
    if (b === 'middle') return 1;
    return 0;
  }

  function scrollableAt(node) {
    var w = frameWin();
    if (!w) return null;
    while (node && node.nodeType === 1) {
      try {
        if (node.scrollHeight > node.clientHeight + 1) {
          var st = w.getComputedStyle(node);
          if (/(auto|scroll)/.test(st.overflowY)) return node;
        }
      } catch (e) {}
      node = node.parentElement;
    }
    return null;
  }

  function applyKey(ev) {
    var d = frameDoc();
    if (!d) return;
    var target = d.activeElement;
    if ((!target || target === d.body) && lastFocusEl && lastFocusEl.ownerDocument === d) {
      target = lastFocusEl;
    }
    if (!target) target = d.documentElement;
    var w = frameWin();
    var bits = ev.mods || 0;
    try {
      var ke = new w.KeyboardEvent(ev.t === 'kd' ? 'keydown' : 'keyup', {
        key: ev.key || '', code: ev.code || '',
        keyCode: ev.vk || 0, which: ev.vk || 0,
        ctrlKey: !!(bits & 2), altKey: !!(bits & 1),
        metaKey: !!(bits & 4), shiftKey: !!(bits & 8),
        bubbles: true, cancelable: true, composed: true
      });
      target.dispatchEvent(ke);
    } catch (e) {}
    if (ev.t === 'kd' && ev.key && ev.key.length === 1 && !(bits & 2) && !(bits & 4)) {
      // A character key with Ctrl/Cmd held is a shortcut, not typing.
      insertText(ev.key);
    } else if (ev.t === 'kd' && ev.key === 'Backspace') {
      backspace(target);
    }
  }

  // React-safe text insertion: the native value setter (from the FRAME's own
  // realm) plus a fresh 'input' event, so framework trackers register the
  // change exactly like a typed keystroke.
  function insertText(ch) {
    var d = frameDoc();
    var target = d ? d.activeElement : null;
    if ((!target || target === d.body) && lastFocusEl && lastFocusEl.ownerDocument === d) {
      target = lastFocusEl;
    }
    if (!target) return;
    var w = frameWin();
    if (/^(INPUT|TEXTAREA)$/.test(target.tagName)) {
      try {
        var proto = target.tagName === 'TEXTAREA' ? w.HTMLTextAreaElement.prototype : w.HTMLInputElement.prototype;
        var setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
        var start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
        var end = typeof target.selectionEnd === 'number' ? target.selectionEnd : start;
        var val = target.value.slice(0, start) + ch + target.value.slice(end);
        setter.call(target, val);
        var pos = start + ch.length;
        try { target.setSelectionRange(pos, pos); } catch (e) {}
        target.dispatchEvent(new w.Event('input', { bubbles: true }));
      } catch (e) {
        try { target.value += ch; } catch (e2) {}
      }
    } else if (target.isContentEditable) {
      try { d.execCommand('insertText', false, ch); } catch (e) {}
    }
  }

  function backspace(target) {
    if (!target || !/^(INPUT|TEXTAREA)$/.test(target.tagName)) return;
    var w = frameWin();
    try {
      var proto = target.tagName === 'TEXTAREA' ? w.HTMLTextAreaElement.prototype : w.HTMLInputElement.prototype;
      var setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
      var start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
      if (start === 0) return;
      var end = typeof target.selectionEnd === 'number' ? target.selectionEnd : start;
      if (start === end) start -= 1;
      var val = target.value.slice(0, start) + target.value.slice(end);
      setter.call(target, val);
      try { target.setSelectionRange(start, start); } catch (e) {}
      target.dispatchEvent(new w.Event('input', { bubbles: true }));
    } catch (e) {}
  }

  // ---------------------------------------------------------------------------
  // Text selection, clipboard and the surface menu (v7.4.0).
  //
  // Synthetic events never start native text selections, so the ECHO layer
  // emulates what a real drag would have produced: a caret range anchors on
  // the down, extends through the moves, and the frame's own Selection API
  // renders the highlight (the destination's ::selection styling included).
  // Copy/select-all/paste need THIS document's user activation to touch the
  // visitor's real clipboard, so they run at the capture layer while the
  // gesture itself still rides the host round trip first.
  // ---------------------------------------------------------------------------

  function caretRangeAt(x, y) {
    var d = frameDoc();
    if (!d) return null;
    try {
      if (typeof d.caretRangeFromPoint === 'function') return d.caretRangeFromPoint(x, y);
      if (d.caretPositionFromPoint) {
        var cp = d.caretPositionFromPoint(x, y);
        if (cp) {
          var r = d.createRange();
          r.setStart(cp.offsetNode, cp.offset);
          r.collapse(true);
          return r;
        }
      }
    } catch (e) {}
    return null;
  }

  function frameSelection() {
    try {
      var w = frameWin();
      if (w) return w.getSelection();
    } catch (e) {}
    return null;
  }

  function setSelection(anchorNode, anchorOff, node, off) {
    var sel = frameSelection();
    if (!sel) return;
    try { sel.setBaseAndExtent(anchorNode, anchorOff, node, off); } catch (e) {}
  }

  // Some destinations switch text selection off entirely through CSS; the
  // virtual surface's contract is that the visitor can always select, so a
  // one-line override rides along once per document — the first selection
  // gesture installs it.
  function ensureSelectStyle() {
    var d = frameDoc();
    if (!d) return;
    try {
      if (d.getElementById('__glpe_vb_sel')) return;
      var s = d.createElement('style');
      s.id = '__glpe_vb_sel';
      s.textContent = '*, *::before, *::after { -webkit-user-select: text !important; user-select: text !important; }';
      (d.head || d.documentElement).appendChild(s);
    } catch (e) {}
  }

  function startDragSelection(x, y) {
    ensureSelectStyle();
    var r = caretRangeAt(x, y);
    if (r && r.startContainer) {
      dragSel = { node: r.startContainer, off: r.startOffset };
    } else {
      dragSel = null;
    }
  }

  function extendDragSelection(x, y) {
    if (!dragSel) return;
    var r = caretRangeAt(x, y);
    if (!r || !r.startContainer) return;
    setSelection(dragSel.node, dragSel.off, r.startContainer, r.startOffset);
  }

  function selectWordAt(x, y) {
    ensureSelectStyle();
    var r = caretRangeAt(x, y);
    if (!r || !r.startContainer || r.startContainer.nodeType !== 3) return;
    var node = r.startContainer;
    try {
      var text = node.data;
      var off = r.startOffset;
      var isWord = function (ch) {
        return /[\w\u0600-\u06FF\u0400-\u04FF]/.test(ch);
      };
      var s = off, e = off;
      while (s > 0 && isWord(text.charAt(s - 1))) s--;
      while (e < text.length && isWord(text.charAt(e))) e++;
      if (s === e) return;
      setSelection(node, s, node, e);
    } catch (err) {}
  }

  function selectAllInFrame() {
    var d = frameDoc();
    if (!d || !d.body) return;
    ensureSelectStyle();
    var sel = frameSelection();
    if (sel && sel.selectAllChildren) {
      try { sel.selectAllChildren(d.body); } catch (e) {}
    }
  }

  function frameSelectionText() {
    var sel = frameSelection();
    if (!sel) return '';
    try { return String(sel); } catch (e) { return ''; }
  }

  function copySelectionToVisitor(cut) {
    var t = frameSelectionText();
    if (!t) {
      toast('متنی برای کپی انتخاب نشده است');
      return;
    }
    var done = function () { toast(cut ? 'متن کپی شد (حذف انتخاب در دست سایت مقصد است)' : 'متن انتخاب‌شده کپی شد'); };
    var legacy = function () {
      try {
        var ta = document.createElement('textarea');
        ta.value = t;
        ta.setAttribute('style', 'position:fixed;top:-9999px;opacity:0;');
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done();
      } catch (e) {}
    };
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(done, legacy);
      } else {
        legacy();
      }
    } catch (e) { legacy(); }
  }

  // Paste replay: hand the text to the frame as a clipboard-style paste so
  // the destination's own paste handlers (editors, comment boxes) run on
  // real data; a field that ignored the synthetic event gets a direct
  // insertion one tick later.
  function pasteIntoFrame(text) {
    if (!text) return;
    var w = frameWin();
    var d = frameDoc();
    if (!w || !d) { insertText(text); return; }
    var target = d.activeElement;
    if ((!target || target === d.body) && lastFocusEl && lastFocusEl.ownerDocument === d) {
      target = lastFocusEl;
    }
    if (!target || (!/^(INPUT|TEXTAREA)$/.test(target.tagName) && !target.isContentEditable)) {
      insertText(text);
      return;
    }
    var before = (target.value !== undefined) ? target.value : (target.textContent || '');
    var dispatched = false;
    try {
      var dt = new w.DataTransfer();
      dt.setData('text/plain', text);
      var pe = new w.ClipboardEvent('paste', {
        bubbles: true, cancelable: true, clipboardData: dt
      });
      target.dispatchEvent(pe);
      dispatched = true;
    } catch (e) {}
    setTimeout(function () {
      try {
        var cur = (target.value !== undefined) ? target.value : (target.textContent || '');
        if (!dispatched || cur === before) insertText(text);
      } catch (e) {}
    }, 60);
  }

  // ---------------------------------------------------------------------------
  // The surface's own context menu — what a remote desktop's client shows
  // when the remote side has no menu of its own to display.
  // ---------------------------------------------------------------------------

  function hideVbMenu() {
    if (vbMenu && vbMenu.parentNode) vbMenu.parentNode.removeChild(vbMenu);
    vbMenu = null;
  }

  function vbMenuItem(menu, label, fn) {
    var b = el('button', 'display:block;width:100%;text-align:right;background:none;border:0;color:#e2e8f0;' +
      'font:12px/1 Tahoma,sans-serif;padding:9px 16px;cursor:pointer;direction:rtl;');
    b.type = 'button';
    b.textContent = label;
    b.onclick = function (e) {
      e.stopPropagation();
      hideVbMenu();
      try { fn(); } catch (err) {}
    };
    menu.appendChild(b);
    return b;
  }

  function showVbMenu(sx, sy) {
    hideVbMenu();
    if (!root || !stage) return;
    vbMenu = el('div', 'position:absolute;background:#0f172a;border:1px solid #334155;border-radius:12px;' +
      'box-shadow:0 10px 30px rgba(0,0,0,0.55);padding:6px;min-width:190px;z-index:9;direction:rtl;');
    vbMenu.id = '__glpe_vbs_menu';
    vbMenuItem(vbMenu, 'عقب (back)', function () { try { frame.contentWindow.history.back(); } catch (e) {} });
    vbMenuItem(vbMenu, 'جلو (forward)', function () { try { frame.contentWindow.history.forward(); } catch (e) {} });
    vbMenuItem(vbMenu, 'بارگذاری دوباره', function () { if (sess && currentUrl) navTo(currentUrl); });
    vbMenu.appendChild(el('div', 'height:1px;background:#334155;margin:6px 0;'));
    vbMenuItem(vbMenu, 'انتخاب همه (Ctrl+A)', selectAllInFrame);
    vbMenuItem(vbMenu, 'کپی (Ctrl+C)', function () { copySelectionToVisitor(); });
    vbMenuItem(vbMenu, 'چسباندن (Ctrl+V)', function () {
      try {
        if (navigator.clipboard && navigator.clipboard.readText) {
          navigator.clipboard.readText().then(function (t) {
            if (t) push({ t: 'tx', v: String(t).slice(0, 2000), p: 1 });
          }, function () {
            toast('دسترسی به کلیپ‌بورد داده نشد — روی نمایشگر Ctrl+V را بزنید');
          });
        } else {
          toast('این مرورگر اجازهٔ خواندن کلیپ‌بورد را نمی‌دهد — روی نمایشگر Ctrl+V را بزنید');
        }
      } catch (e) {}
    });
    vbMenu.appendChild(el('div', 'height:1px;background:#334155;margin:6px 0;'));
    vbMenuItem(vbMenu, 'بستن مرورگر مجازی', closeSurface);
    // Place the menu under the sandbox point, clamped inside the stage.
    var r = frame.getBoundingClientRect();
    var s = stage.getBoundingClientRect();
    var px = (r.left - s.left) + (sx / sessW) * r.width + 8;
    var py = (r.top - s.top) + (sy / sessH) * r.height + 6;
    stage.appendChild(vbMenu);
    try {
      var mw = vbMenu.offsetWidth || 190;
      var mh = vbMenu.offsetHeight || 200;
      px = Math.max(8, Math.min(s.width - mw - 8, px));
      py = Math.max(8, Math.min(s.height - mh - 8, py));
    } catch (e) {}
    vbMenu.style.left = px + 'px';
    vbMenu.style.top = py + 'px';
  }

  // ---------------------------------------------------------------------------
  // File downloads (v7.4.0). The sandbox frame's in-page helper (client.js)
  // signals download-shaped link clicks through a document event — the locked
  // environment pins parent/top to itself, so a document CustomEvent is the
  // one channel the destination environment itself owns. THIS surface (same
  // origin by design) listens on the frame document and asks the host for
  // the delivery path: a direct tunnel URL the visitor's browser saves right
  // away, or the host-side chunked store with a progress card here and a
  // serving URL at the end.
  // ---------------------------------------------------------------------------

  function bindFrameDlChannel() {
    var d = frameDoc();
    if (!d) return;
    try {
      d.addEventListener('glpe-vb-dl', function (e) {
        var u = e && e.detail && typeof e.detail.u === 'string' ? e.detail.u : '';
        if (u !== '' && CFG.dl && sess && !closing) {
          startDownload(u);
        }
      });
    } catch (err) {}
  }

  function saveViaAnchor(url, name) {
    try {
      var a = document.createElement('a');
      a.href = url;
      if (name) a.download = name;
      a.rel = 'noopener';
      a.style.display = 'none';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { if (a.parentNode) a.parentNode.removeChild(a); }, 1000);
    } catch (e) {}
  }

  function startDownload(dest) {
    if (dlBusy) { toast('یک دریافت دیگر در جریان است — کمی صبر کنید'); return; }
    dlBusy = true;
    setStatus('در حال بررسی فایل دانلودی روی میزبان…');
    post('vb_dlopen', { u: dest }, function (d) {
      dlBusy = false;
      if (d.e === 'toolarge') { toast('حجم فایل از سقف مجاز تنظیمات بیشتر است'); return; }
      if (d.e === 'status') { toast('مقصد پاسخ ' + (d.sc || 'خطا') + ' داد'); return; }
      if (d.e === 'badurl') { toast('نشانی فایل نامعتبر است'); return; }
      if (d.mode === 'tunnel' && d.g) {
        saveViaAnchor(d.g, d.name || '');
        setStatus('دانلود آغاز شد — تونل مستقیم به مرورگر شما');
        toast('دانلود «' + (d.name || 'فایل') + '» آغاز شد');
        return;
      }
      if (d.mode === 'store' && d.t) {
        storeDownload(d);
        return;
      }
      toast('دریافت فایل ممکن نشد');
    }, function () {
      dlBusy = false;
      toast('ارتباط با میزبان برای دانلود برقرار نشد');
    });
  }

  function storeDownload(d) {
    var total = d.size || 0;
    var got = 0;
    var fails = 0;
    var done = false;
    var card = el('div', 'position:fixed;bottom:26px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,0.97);' +
      'border:1px solid #334155;border-radius:14px;padding:14px 18px;z-index:2147483647;direction:rtl;' +
      'min-width:340px;max-width:90%;font-size:12px;color:#e2e8f0;line-height:1.9;');
    var title = el('div'); title.style.fontWeight = 'bold';
    title.textContent = '📥 دریافت «' + (d.name || 'فایل') + '» روی میزبان';
    var sub = el('div'); sub.style.color = '#94a3b8';
    sub.textContent = 'تکه‌تکه از سایت مقصد، داخل پوشهٔ محافظت‌شدهٔ هاست…';
    var barWrap = el('div', 'height:8px;background:#1e293b;border-radius:9999px;overflow:hidden;margin:8px 0;');
    var bar = el('div', 'height:100%;width:0%;background:linear-gradient(to left,#0284c7,#38bdf8);border-radius:9999px;');
    var pct = el('div'); pct.dir = 'ltr'; pct.style.textAlign = 'center'; pct.style.color = '#94a3b8'; pct.textContent = '0%';
    var cancel = el('button', 'height:28px;padding:0 10px;border-radius:8px;border:1px solid #7f1d1d;background:#450a0a;color:#fecaca;font-size:11px;cursor:pointer;margin-top:6px;');
    cancel.type = 'button'; cancel.textContent = 'لغو';
    barWrap.appendChild(bar);
    card.appendChild(title); card.appendChild(sub); card.appendChild(barWrap); card.appendChild(pct); card.appendChild(cancel);
    root.appendChild(card);
    cancel.onclick = function () { done = true; if (card.parentNode) card.parentNode.removeChild(card); toast('دریافت لغو شد'); };
    setStatus('دریافت فایل روی میزبان (تکه‌تکه)…');

    (function step() {
      if (done) return;
      post('vb_dlchunk', { t: d.t }, function (r) {
        if (done) return;
        if (r.e === 'expired') { done = true; card.parentNode && card.parentNode.removeChild(card); toast('نشست دانلود منقضی شد'); return; }
        if (r.e === 'net') {
          fails++;
          if (fails >= 5) { done = true; card.parentNode && card.parentNode.removeChild(card); toast('خطای شبکه در دریافت فایل'); return; }
          setTimeout(step, 900);
          return;
        }
        if (r.e) { done = true; card.parentNode && card.parentNode.removeChild(card); toast('دریافت فایل ناکام ماند (' + r.e + ')'); return; }
        fails = 0;
        got = r.got || got;
        if (r.total) total = r.total;
        var p = total ? Math.min(100, Math.round((got / total) * 100)) : 0;
        bar.style.width = p + '%';
        pct.textContent = (total ? (got / 1048576).toFixed(1) + ' / ' + (total / 1048576).toFixed(1) + ' MB — ' : '') + p + '%';
        if (r.done && r.g) {
          done = true;
          bar.style.width = '100%';
          title.textContent = '✅ «' + (r.name || d.name || 'فایل') + '» آماده است — در حال رسیدن به دستگاه شما';
          pct.textContent = '100%';
          saveViaAnchor(r.g, r.name || d.name || '');
          setStatus('فایل روی هاست کامل شد — در حال سرو به دستگاه شما');
          setTimeout(function () { if (card.parentNode) card.parentNode.removeChild(card); }, 4000);
          return;
        }
        setTimeout(step, 120);
      }, function () {
        if (done) return;
        fails++;
        if (fails >= 5) { done = true; card.parentNode && card.parentNode.removeChild(card); toast('ارتباط با میزبان قطع شد'); return; }
        setTimeout(step, 900);
      });
    })();
  }

  // ---------------------------------------------------------------------------
  // Navigation.
  // ---------------------------------------------------------------------------

  function navTo(url) {
    if (!sess) return;
    var v = String(url).trim();
    if (!v) return;
    if (sess) {
      setStatus('در حال ناوبری از طریق میزبان…');
      post('vb_nav', { t: sess.t, u: v }, function (d) {
        if (d.e === 'expired') { setStatus('نشست منقضی شد — پوشه را ببندید و دوباره باز کنید'); sess = null; return; }
        if (!d.g) { setStatus('نشانی نامعتبر است'); return; }
        currentUrl = v;
        urlInput.value = v;
        imgCache = {};
        dragSel = null;
        hideVbMenu();
        // A typed address that IS a download (archive/executable/…):
        // the tunnel flag makes the gateway stream it to the visitor's
        // save dialog instead of buffering it as a page.
        frame.src = DL_RE.test(v) ? (d.g + '&dl=1') : d.g;
        setStatus('در حال بارگذاری صفحه از طریق میزبان…');
      }, function () { setStatus('خطای ارتباط با میزبان'); });
    }
  }

  function onFrameLoad() {
    try {
      var w = frame.contentWindow;
      var m = String(w.location.href).match(/[?&]l=([A-Za-z0-9_-]+)/);
      var dest = m ? dec(m[1]) : '';
      if (dest) urlInput.value = dest;
    } catch (e) {}
    bindFrameDlChannel();
    setStatus('صفحه بارگذاری شد — ورودی‌های شما ابتدا به هاست می‌رود و بعد بازپخش می‌شود');
    capture.focus();
    if (pixelMode) rasterize();
  }

  // ---------------------------------------------------------------------------
  // Pixel mode — the sandbox as a plain picture.
  // ---------------------------------------------------------------------------

  function enterPixel() {
    pixelMode = true;
    pixFails = 0;
    pixelCanvas.style.display = 'block';
    pixelCanvas.width = sessW;
    pixelCanvas.height = sessH;
    pixelCtx = pixelCanvas.getContext('2d');
    modeBtn.textContent = '🖥️ مانیتور';
    setStatus('حالت پیکسل — فقط تصویر صفحه نمایش داده می‌شود');
    fitFrame();
    rasterize();
    if (pixelTimer) clearInterval(pixelTimer);
    pixelTimer = setInterval(function () { if (pixelMode) rasterize(); }, 3000);
  }

  function leavePixel() {
    pixelMode = false;
    if (pixelTimer) { clearInterval(pixelTimer); pixelTimer = null; }
    if (pixelCanvas) pixelCanvas.style.display = 'none';
    if (modeBtn) modeBtn.textContent = '🖼️ پیکسل';
    if (cursor) cursor.style.display = 'block';
    fitFrame();
    setStatus('نشست فعال — نمایشگر مجازی (موتور جعبه‌ای)');
  }

  function pixelFailed() {
    pixFails++;
    if (pixelMode && pixFails >= 3) {
      setStatus('ساخت تصویر صفحه ممکن نشد — بازگشت به حالت مانیتور');
      leavePixel();
    }
  }

  function rasterize() {
    var d = frameDoc();
    if (!d || !pixelCtx) return;
    try {
      var cloneRoot = d.documentElement.cloneNode(true);
      // Scripts never run inside a serialized image — dropping them keeps
      // the payload small and the snapshot inert.
      var scripts = cloneRoot.querySelectorAll('script');
      for (var i = 0; i < scripts.length; i++) {
        if (scripts[i].parentNode) scripts[i].parentNode.removeChild(scripts[i]);
      }
      // Live stylesheets (same-origin through the gateway) become inline
      // <style> text; external references inside an image would not load.
      var styleText = '';
      var links = d.querySelectorAll('link[rel="stylesheet"]');
      for (var j = 0; j < links.length; j++) {
        try {
          var sheet = links[j].sheet;
          if (sheet && sheet.cssRules) {
            for (var r = 0; r < sheet.cssRules.length; r++) styleText += sheet.cssRules[r].cssText + '\n';
          }
        } catch (e) {}
      }
      var cloneLinks = cloneRoot.querySelectorAll('link[rel="stylesheet"]');
      for (var k = 0; k < cloneLinks.length; k++) {
        if (cloneLinks[k].parentNode) cloneLinks[k].parentNode.removeChild(cloneLinks[k]);
      }
      if (styleText) {
        var head = cloneRoot.querySelector('head');
        if (head) {
          var st = d.createElement('style');
          st.textContent = styleText;
          head.insertBefore(st, head.firstChild);
        }
      }
      // Inline the first images (cached by src so repeated snapshots do not
      // re-fetch): inside a serialized image no external resource loads.
      var liveImgs = Array.prototype.slice.call(d.querySelectorAll('img'), 0, 12);
      var cloneImgs = Array.prototype.slice.call(cloneRoot.querySelectorAll('img'), 0, 12);
      var jobs = [];
      for (var n = 0; n < liveImgs.length; n++) {
        (function (idx, liveSrc) {
          if (imgCache[liveSrc]) {
            try { cloneImgs[idx].setAttribute('src', imgCache[liveSrc]); } catch (e) {}
            return;
          }
          if (!liveSrc || liveSrc.indexOf('data:') === 0) return;
          jobs.push(
            fetch(liveSrc, { credentials: 'same-origin' }).then(function (r) { return r.blob(); }).then(function (b) {
              return new Promise(function (res, rej) {
                var fr = new FileReader();
                fr.onload = function () { res(fr.result); };
                fr.onerror = rej;
                fr.readAsDataURL(b);
              });
            }).then(function (dataUrl) {
              imgCache[liveSrc] = dataUrl;
              try { cloneImgs[idx].setAttribute('src', dataUrl); } catch (e) {}
            }).catch(function () {})
          );
        })(n, liveImgs[n].getAttribute('src') || '');
      }
      Promise.all(jobs).then(function () { drawRaster(cloneRoot); });
    } catch (e) {
      pixelFailed();
    }
  }

  function drawRaster(cloneRoot) {
    try {
      var xml = new XMLSerializer().serializeToString(cloneRoot);
      var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + sessW + '" height="' + sessH + '">' +
        '<foreignObject x="0" y="0" width="100%" height="100%">' + xml + '</foreignObject></svg>';
      var img = new Image();
      img.onload = function () {
        if (!pixelCtx) return;
        // Pages rarely carry their own background declarations (the browser
        // paints a default white sheet); the picture starts from one too.
        pixelCtx.fillStyle = '#fff';
        pixelCtx.fillRect(0, 0, sessW, sessH);
        pixelCtx.drawImage(img, 0, 0, sessW, sessH);
        drawCursorOnCanvas(pixelCtx);
        pixFails = 0;
      };
      img.onerror = function () { pixelFailed(); };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    } catch (e) {
      pixelFailed();
    }
  }

  function drawCursorOnCanvas(ctx2) {
    if (lastX < 0 || lastY < 0) return;
    try {
      ctx2.save();
      ctx2.translate(lastX, lastY);
      ctx2.scale(1.4, 1.4);
      ctx2.beginPath();
      ctx2.moveTo(0, 0);
      ctx2.lineTo(0, 16);
      ctx2.lineTo(4.6, 12.2);
      ctx2.lineTo(7.4, 17.8);
      ctx2.lineTo(9.8, 16.6);
      ctx2.lineTo(7, 11);
      ctx2.lineTo(12, 10.8);
      ctx2.closePath();
      ctx2.lineWidth = 1;
      ctx2.strokeStyle = '#111827';
      ctx2.fillStyle = '#ffffff';
      ctx2.fill();
      ctx2.stroke();
      ctx2.restore();
    } catch (e) {}
  }

  // ---------------------------------------------------------------------------
  // Layout.
  // ---------------------------------------------------------------------------

  function fitFrame() {
    if (!stage || !frame) return;
    var sw = stage.clientWidth - 2, sh = stage.clientHeight - 2;
    if (sw <= 0 || sh <= 0) return;
    var k = Math.min(sw / sessW, sh / sessH);
    if (pixelMode) {
      pixelCanvas.style.width = Math.floor(sessW * k) + 'px';
      pixelCanvas.style.height = Math.floor(sessH * k) + 'px';
    } else {
      frame.style.width = Math.floor(sessW * k) + 'px';
      frame.style.height = Math.floor(sessH * k) + 'px';
    }
  }

  window.addEventListener('resize', function () { if (root && frame) fitFrame(); });

  // ---------------------------------------------------------------------------
  // Launcher.
  // ---------------------------------------------------------------------------

  function startUrl() {
    var input = document.getElementById('glpe-vb-url');
    var v = input ? String(input.value || '').trim() : '';
    if (!v) v = 'https://example.com';
    return v;
  }

  function boot() {
    var buttons = document.querySelectorAll('.glpe-vbs-open');
    if (!buttons.length) return;
    for (var i = 0; i < buttons.length; i++) {
      (function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          openSandbox(startUrl());
        });
      })(buttons[i]);
    }
    var input = document.getElementById('glpe-vb-url');
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          openSandbox(startUrl());
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
