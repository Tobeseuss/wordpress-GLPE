/**
 * GLPE Virtual Browser — visitor-side remote surface.
 *
 * Loaded on the viewer page (not inside displayed documents). Opens a
 * picture-only channel to the host-side renderer: frames drawn on a canvas,
 * mouse/keyboard gestures shipped to the host, a virtual cursor drawn where
 * the host's renderer sees the pointer. Nothing from the destination page
 * runs locally — the canvas is the entire experience.
 *
 * v7.5.0 — phone optimization: finger gestures are classified locally
 *   (tap, long-press, drag-pan with fling, double tap) and each rides the
 *   host renderer as the SAME mouse/keyboard messages the desktop sends;
 *   a hidden typing field (⌨ toggle) opens the device keyboard and its
 *   characters travel as text/key messages; touch targets grow to phone
 *   size and a rotation asks the renderer for a new viewport.
 *
 * v7.6.0 — phone polish: a double tap magnifies the picture around the
 *   tap (the dblclick still rides so the renderer's own handlers run) and
 *   a second double tap restores; a long-press asks the renderer whether
 *   the finger sits on ground text (selstart/selmove/selend — a held
 *   double-click press word-selects and the drag extends the selection,
 *   with a 'selno' fallback to the classic right-click pair); and the
 *   soft keyboard's height-only shrink never rides as a rotation.
 */
(function () {
  if (window.__glpe_vb_ui__) return;
  window.__glpe_vb_ui__ = true;

  var CFG = window.GLPE_VB || {};
  var ep = CFG.ep || '';
  var key = CFG.key || '';
  var root = null, canvas = null, ctx = null, urlInput = null;
  var statusEl = null, fpsEl = null, latencyEl = null;
  var ws = null, closing = false, retries = 0;
  var currentUrl = '';
  var lastX = -1, lastY = -1;
  var frames = 0, fpsTime = 0, pingTimer = null, reconnectTimer = null;
  var lastMoveSend = 0;
  var curImg = null;
  // v7.5.0 — touch + on-screen keyboard state (mirrors the sandbox
  // engine's surface so both engines feel like one feature on phones).
  var TOUCH = (navigator.maxTouchPoints || 0) > 0 || ('ontouchstart' in window);
  var kbdInput = null, kbdBtn = null, kbdPrev = '';
  var tPtr = null;
  var tSecond = 0;
  var lastTap = null;
  var lpTimer = null;
  var panAccX = 0, panAccY = 0, panT = null;
  var flingT = null;
  var rsT = null;
  // v7.6.0 — the double-tap zoom state (the picture magnifies visually
  // around the tap; canvasPoint reads the live rect so hit-testing stays
  // exact under the transform) and the last-seen stage size (the soft
  // keyboard shrinks the height only — that must never ride as a
  // rotation).
  var zoomZ = 1, zoomSx = -1, zoomSy = -1;
  var lastStageW = 0, lastStageH = 0;

  function txt(s) { return document.createTextNode(s); }

  function el(tag, style) {
    var e = document.createElement(tag);
    if (style) e.setAttribute('style', style);
    return e;
  }

  // ---------------------------------------------------------------------------
  // Overlay construction (dark card look matching the viewer widget).
  // ---------------------------------------------------------------------------

  var S = {
    root: 'position:fixed;inset:0;z-index:2147483647;background:#0f172a;display:flex;flex-direction:column;font-family:Tahoma,system-ui,sans-serif;direction:rtl;',
    bar: 'display:flex;align-items:center;gap:8px;padding:10px 14px;background:#1e293b;border-bottom:1px solid #334155;flex:none;flex-wrap:wrap;',
    btn: 'width:34px;height:34px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#f8fafc;font-size:15px;cursor:pointer;line-height:1;flex:none;',
    btnClose: 'width:34px;height:34px;border-radius:10px;border:1px solid #7f1d1d;background:#450a0a;color:#fecaca;font-size:15px;cursor:pointer;line-height:1;flex:none;',
    input: 'flex:1;min-width:220px;padding:9px 12px;background:#0f172a;border:1px solid #334155;border-radius:10px;color:#f8fafc;font-size:12px;font-family:monospace;outline:none;direction:ltr;text-align:left;',
    stage: 'flex:1;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#000;',
    pill: 'position:absolute;top:10px;right:12px;background:rgba(15,23,42,0.85);border:1px solid #334155;color:#94a3b8;border-radius:9999px;padding:4px 12px;font-size:11px;line-height:1.8;z-index:5;max-width:70%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;',
    foot: 'display:flex;align-items:center;gap:14px;padding:8px 14px;background:#1e293b;border-top:1px solid #334155;color:#94a3b8;font-size:11px;flex:none;flex-wrap:wrap;',
    footCompact: 'display:flex;align-items:center;gap:10px;padding:5px 10px;background:#1e293b;border-top:1px solid #334155;color:#94a3b8;font-size:10px;flex:none;white-space:nowrap;overflow:hidden;',
    canvas: 'display:block;background:#000;outline:none;cursor:none;touch-action:none;-webkit-touch-callout:none;-webkit-user-select:none;user-select:none;'
  };

  // Phone-sized ergonomics: 44px touch targets and 16px text fields (a
  // smaller font and iOS zooms the whole surface on focus).
  if (TOUCH) {
    S.btn = 'width:44px;height:44px;border-radius:12px;border:1px solid #334155;background:#0f172a;color:#f8fafc;font-size:16px;cursor:pointer;line-height:1;flex:none;';
    S.btnClose = 'width:44px;height:44px;border-radius:12px;border:1px solid #7f1d1d;background:#450a0a;color:#fecaca;font-size:17px;cursor:pointer;line-height:1;flex:none;';
    S.input = 'flex:1;min-width:140px;padding:10px 12px;background:#0f172a;border:1px solid #334155;border-radius:12px;color:#f8fafc;font-size:16px;font-family:monospace;outline:none;direction:ltr;text-align:left;';
  }

  // ---------------------------------------------------------------------------
  // v7.5.0 — the on-screen keyboard relay field (same arrangement as the
  // sandbox engine's, so both engines behave identically on phones): a
  // hidden text field focused within a real user gesture (the ⌨ button —
  // the only window a mobile OS allows the keyboard in) opens the device
  // keyboard; printable text and IME words ride as text messages, other
  // keys ride as key messages. 16px font so iOS never zooms on focus.
  // ---------------------------------------------------------------------------

  function buildKbdField() {
    kbdInput = el('input', 'position:absolute;bottom:2px;right:2px;width:26px;height:26px;opacity:0.03;border:0;outline:none;background:transparent;color:transparent;caret-color:transparent;font-size:16px;line-height:1;padding:0;margin:0;z-index:3;direction:ltr;text-align:left;pointer-events:none;');
    kbdInput.type = 'text';
    kbdInput.id = '__glpe_vb_kbd';
    kbdInput.setAttribute('autocapitalize', 'off');
    kbdInput.setAttribute('autocorrect', 'off');
    kbdInput.setAttribute('autocomplete', 'off');
    kbdInput.setAttribute('spellcheck', 'false');
    kbdInput.setAttribute('aria-label', 'تایپ در مرورگر مجازی');
    kbdInput.addEventListener('keydown', function (e) {
      var printable = e.key && e.key.length === 1;
      if (!printable || e.ctrlKey || e.metaKey) {
        e.preventDefault(); // the key rides the host; the field stays inert
        send({ t: 'key', a: 'down', key: e.key, code: e.code, vk: e.keyCode || 0, text: '', mods: modsBits(e) });
      }
      // Printable characters without modifiers fall through to the input
      // event — IME-safe — and ride as text below.
    });
    kbdInput.addEventListener('keyup', function (e) {
      send({ t: 'key', a: 'up', key: e.key, code: e.code, vk: e.keyCode || 0, mods: modsBits(e) });
    });
    kbdInput.addEventListener('input', function () {
      var v = kbdInput.value;
      if (v.indexOf(kbdPrev) === 0 && v.length > kbdPrev.length) {
        send({ t: 'text', v: v.slice(kbdPrev.length).slice(0, 400) });
      } else if (kbdPrev.indexOf(v) === 0 && kbdPrev.length > v.length) {
        var dels = Math.min(200, kbdPrev.length - v.length);
        for (var i = 0; i < dels; i++) {
          send({ t: 'key', a: 'down', key: 'Backspace', code: 'Backspace', vk: 8, mods: 0 });
        }
      } else if (v !== kbdPrev) {
        var olds = Math.min(200, kbdPrev.length);
        for (var j = 0; j < olds; j++) {
          send({ t: 'key', a: 'down', key: 'Backspace', code: 'Backspace', vk: 8, mods: 0 });
        }
        if (v) send({ t: 'text', v: v.slice(0, 400) });
      }
      kbdPrev = v;
      if (v.length > 3000) { kbdInput.value = ''; kbdPrev = ''; }
    });
    kbdInput.addEventListener('focus', syncKbdBtn);
    kbdInput.addEventListener('blur', syncKbdBtn);
  }

  function syncKbdBtn() {
    if (!kbdBtn) return;
    var on = !!(kbdInput && document.activeElement === kbdInput);
    kbdBtn.style.background = on ? '#047857' : '#0f172a';
    kbdBtn.style.borderColor = on ? '#059669' : '#334155';
  }

  function armKeyboard() {
    if (kbdInput && document.activeElement !== kbdInput) {
      try { kbdInput.focus(); } catch (e) {}
    }
    syncKbdBtn();
  }

  function disarmKeyboard() {
    if (kbdInput && document.activeElement === kbdInput) {
      try { kbdInput.blur(); } catch (e) {}
    }
    syncKbdBtn();
  }

  function buildOverlay() {
    root = el('div', S.root);
    root.id = '__glpe_vb_root';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', 'مرورگر مجازی');

    var bar = el('div', S.bar);
    var bBack = el('button', S.btn); bBack.type = 'button'; bBack.title = 'Back'; bBack.textContent = '→'; bBack.id = '__glpe_vb_back';
    var bFwd = el('button', S.btn); bFwd.type = 'button'; bFwd.title = 'Forward'; bFwd.textContent = '←'; bFwd.id = '__glpe_vb_fwd';
    var bReload = el('button', S.btn); bReload.type = 'button'; bReload.title = 'Reload'; bReload.textContent = '⟳';
    var bClose = el('button', S.btnClose); bClose.type = 'button'; bClose.title = 'Close'; bClose.textContent = '✕';
    // On-screen keyboard toggle — the picture channel cannot hit-test the
    // destination, so the button (plus a hint) is the honest affordance.
    kbdBtn = el('button', S.btn); kbdBtn.type = 'button'; kbdBtn.id = '__glpe_vb_kbd_btn'; kbdBtn.title = 'کیبورد دستگاه (نمایش/پنهان)'; kbdBtn.textContent = '⌨';
    urlInput = el('input', S.input); urlInput.type = 'text'; urlInput.placeholder = 'نشانی سایت — مثال: example.com';
    urlInput.setAttribute('aria-label', 'نشانی سایت');

    bar.appendChild(bClose);
    bar.appendChild(bReload);
    bar.appendChild(bBack);
    bar.appendChild(bFwd);
    bar.appendChild(urlInput);
    bar.appendChild(kbdBtn);
    root.appendChild(bar);

    var stage = el('div', S.stage);
    canvas = el('canvas', S.canvas);
    canvas.tabIndex = 0;
    stage.appendChild(canvas);
    buildKbdField();
    stage.appendChild(kbdInput);
    statusEl = el('div', S.pill);
    statusEl.id = '__glpe_vb_status';
    stage.appendChild(statusEl);
    root.appendChild(stage);

    var foot = el('div', TOUCH ? S.footCompact : S.foot);
    var state = el('span'); state.style.color = '#6ee7b7'; state.textContent = TOUCH ? '🖥️ رندر روی میزبان' : '🖥️ رندر کامل روی میزبان';
    var note = el('span'); note.style.flex = '1'; note.textContent = TOUCH
      ? 'برای تایپ، دکمهٔ ⌨ را بزنید'
      : 'هیچ کد یا محتوایی از سایت مقصد وارد این دستگاه نمی‌شود — فقط تصویر فریم‌ها می‌رسد و حرکت موس/کیبورد شما به همان محیط ارسال می‌شود.';
    fpsEl = el('span'); fpsEl.dir = 'ltr'; fpsEl.textContent = '— fps';
    latencyEl = el('span'); latencyEl.dir = 'ltr'; latencyEl.textContent = '— ms';
    foot.appendChild(state); if (!TOUCH) foot.appendChild(note); foot.appendChild(latencyEl); foot.appendChild(fpsEl);
    root.appendChild(foot);

    bClose.onclick = closeOverlay;
    bReload.onclick = function () { if (ws && currentUrl) send({ t: 'nav', url: currentUrl }); };
    bBack.onclick = function () { send({ t: 'back' }); };
    bFwd.onclick = function () { send({ t: 'fwd' }); };
    kbdBtn.onclick = function () {
      if (kbdInput && document.activeElement === kbdInput) disarmKeyboard();
      else armKeyboard();
    };
    // iOS page-pinch would zoom the whole document out from under the
    // fixed surface — one guard keeps the virtual surface alone on screen.
    root.addEventListener('gesturestart', function (e) { e.preventDefault(); });
    urlInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var v = urlInput.value.trim();
        if (v) send({ t: 'nav', url: v });
        canvas.focus();
      }
      e.stopPropagation();
    });

    ctx = canvas.getContext('2d');
    document.body.appendChild(root);
    document.body.style.overflow = 'hidden';
    setStatus('در حال اتصال به میزبان…');
  }

  function closeOverlay() {
    closing = true;
    if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
    if (flingT) { clearTimeout(flingT); flingT = null; }
    if (rsT) { clearTimeout(rsT); rsT = null; }
    if (panT) { clearTimeout(panT); panT = null; }
    tPtr = null; tSecond = 0; lastTap = null;
    panAccX = 0; panAccY = 0;
    zoomZ = 1; zoomSx = zoomSy = -1;
    lastStageW = 0; lastStageH = 0;
    try { if (kbdInput && document.activeElement === kbdInput) kbdInput.blur(); } catch (e) {}
    try { if (ws) ws.close(); } catch (e) {}
    if (root && root.parentNode) root.parentNode.removeChild(root);
    document.body.style.overflow = '';
    root = canvas = ctx = urlInput = ws = kbdInput = kbdBtn = null;
  }

  function setStatus(t) { if (statusEl) statusEl.textContent = t; }

  // ---------------------------------------------------------------------------
  // Connection.
  // ---------------------------------------------------------------------------

  function wsUrl() {
    var u;
    if (ep.indexOf('/') === 0) {
      u = new URL(ep, location.href);
    } else {
      u = new URL(ep);
    }
    u.protocol = u.protocol === 'https:' ? 'wss:' : 'ws:';
    u.searchParams.set('key', key);
    return u.href;
  }

  function connect(url) {
    currentUrl = url;
    try {
      ws = new WebSocket(wsUrl());
    } catch (e) {
      setStatus('نشانی سرویس میزبان نامعتبر است');
      return;
    }
    ws.onopen = function () {
      retries = 0;
      var rect = stageSize();
      lastStageW = rect.w; lastStageH = rect.h;
      send({ t: 'start', url: url, w: rect.w, h: rect.h });
      setStatus('راه‌اندازی محیط رندر روی میزبان…');
      if (pingTimer) clearInterval(pingTimer);
      pingTimer = setInterval(function () { send({ t: 'ping', ts: Date.now() }); }, 4000);
    };
    ws.onmessage = function (ev) { handleMessage(String(ev.data)); };
    ws.onclose = function () {
      if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }
      if (closing) return;
      setStatus('قطع اتصال — تلاش مجدد…');
      if (reconnectTimer) clearTimeout(reconnectTimer);
      if (retries < 30) {
        retries++;
        reconnectTimer = setTimeout(function () { connect(currentUrl); }, 2200);
      } else {
        setStatus('اتصال برقرار نشد — دکمهٔ بستن را بزنید و دوباره باز کنید');
      }
    };
    ws.onerror = function () { /* close handler reports */ };
  }

  function send(obj) {
    if (ws && ws.readyState === 1) {
      try { ws.send(JSON.stringify(obj)); } catch (e) {}
    }
  }

  function handleMessage(data) {
    var msg;
    try { msg = JSON.parse(data); } catch (e) { return; }
    switch (msg.t) {
      case 'hello':
        setStatus('متصل به سرویس میزبان — آمادهٔ راه‌اندازی محیط');
        break;

      case 'started':
        urlInput.value = msg.url && msg.url !== 'about:blank' ? msg.url : currentUrl;
        resetZoom(); // a fresh renderer viewport drops any stale magnification
        setStatus(TOUCH
          ? 'نشست فعال — لمس = کلیک — نگه‌داشتن روی متن = انتخاب — کشیدن = اسکرول — ⌨ = تایپ'
          : 'نشست فعال — تصویر از میزبان می‌رسد');
        if (!(kbdInput && document.activeElement === kbdInput)) canvas.focus();
        break;

      case 'frame':
        drawFrame(msg);
        break;

      case 'nav':
        currentUrl = msg.url || currentUrl;
        urlInput.value = currentUrl;
        resetZoom(); // a new document drops the stale magnification
        setStatus('صفحه بارگذاری شد' + (msg.title ? ' — ' + msg.title.slice(0, 60) : ''));
        break;

      case 'selno':
        // v7.6.0 — the renderer answered that the long-press point is NOT
        // ground text: the classic right-click pair rides now (the site's
        // own contextmenu path), and the live tracker becomes the menu
        // ground so the release never rides a second gesture.
        if (tPtr && tPtr.mode === 'sel') {
          tPtr.mode = 'menu';
        }
        send({ t: 'down', x: msg.x, y: msg.y, b: 'right' });
        send({ t: 'up', x: msg.x, y: msg.y, b: 'right' });
        break;

      case 'pong':
        if (latencyEl) latencyEl.textContent = (Date.now() - msg.ts) + ' ms';
        break;

      case 'dialog':
        setStatus('پیام صفحه (تأیید خودکار): ' + String(msg.message || '').slice(0, 80));
        break;

      case 'err':
        if (msg.msg === 'busy') setStatus('حداکثر نشست‌های همزمان روی میزبان پر است — کمی بعد دوباره باز کنید');
        else if (String(msg.msg).indexOf('renderer') !== -1) setStatus('رندرکننده روی میزبان در دسترس نیست — با مدیر سایت هماهنگ کنید');
        else setStatus('خطای میزبان: ' + String(msg.msg).slice(0, 100));
        break;

      case 'bye':
        setStatus('نشست میزبان بسته شد (بی‌کاری یا پایان عمر محیط) — تلاش مجدد…');
        break;
    }
  }

  // ---------------------------------------------------------------------------
  // Frame drawing + virtual cursor.
  // ---------------------------------------------------------------------------

  function drawFrame(msg) {
    var m = msg.m || {};
    var w = m.w | 0, h = m.h | 0;
    if (w < 2 || h < 2) return;
    if (canvas.width !== w || canvas.height !== h) {
      canvas.width = w;
      canvas.height = h;
      resetZoom(); // the renderer moved to a new viewport — magnification restarts
      fitCanvas();
    }
    var img = new Image();
    img.onload = function () {
      try {
        ctx.drawImage(img, 0, 0, w, h);
      } catch (e) { /* frame raced a resize */ }
      drawCursor();
      send({ t: 'ack' });
      frames++;
    };
    img.onerror = function () { send({ t: 'ack' }); };
    img.src = 'data:image/jpeg;base64,' + msg.d;
  }

  function drawCursor() {
    if (lastX < 0 || lastY < 0) return;
    try {
      ctx.save();
      ctx.translate(lastX, lastY);
      ctx.scale(1.3, 1.3);
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.lineTo(0, 16);
      ctx.lineTo(4.6, 12.2);
      ctx.lineTo(7.4, 17.8);
      ctx.lineTo(9.8, 16.6);
      ctx.lineTo(7, 11);
      ctx.lineTo(12, 10.8);
      ctx.closePath();
      ctx.lineWidth = 1;
      ctx.strokeStyle = '#111';
      ctx.fillStyle = '#fff';
      ctx.fill();
      ctx.stroke();
      ctx.restore();
    } catch (e) { /* context lost mid-frame */ }
  }

  function stageSize() {
    var stage = root.childNodes[1];
    var r = stage.getBoundingClientRect();
    return { w: Math.max(240, Math.floor(r.width - 2)), h: Math.max(240, Math.floor(r.height - 2)) };
  }

  function fitCanvas() {
    var stage = root.childNodes[1];
    var sw = stage.clientWidth - 2, sh = stage.clientHeight - 2;
    if (sw <= 0 || sh <= 0) return;
    var k = Math.min(sw / canvas.width, sh / canvas.height);
    canvas.style.width = Math.floor(canvas.width * k) + 'px';
    canvas.style.height = Math.floor(canvas.height * k) + 'px';
    canvasVisual();
  }

  // v7.6.0 — the double-tap zoom: the picture magnifies visually around
  // the tap (a CSS transform on the canvas element — canvasPoint() reads
  // the live rect, so hit-testing stays exact under it and pan deltas ride
  // through the same rect, which keeps the content following the finger).
  function canvasVisual() {
    if (!canvas) return;
    if (zoomZ > 1 && zoomSx >= 0 && canvas.width && canvas.height) {
      var cssW = parseFloat(canvas.style.width) || canvas.width;
      var cssH = parseFloat(canvas.style.height) || canvas.height;
      canvas.style.transformOrigin = Math.round((zoomSx / canvas.width) * cssW) + 'px ' +
        Math.round((zoomSy / canvas.height) * cssH) + 'px';
      canvas.style.transform = 'scale(' + zoomZ + ')';
    } else {
      canvas.style.transform = '';
      canvas.style.transformOrigin = '';
    }
  }

  function resetZoom() {
    zoomZ = 1; zoomSx = zoomSy = -1;
    canvasVisual();
  }

  function toggleZoom(px, py) {
    zoomZ = zoomZ > 1 ? 1 : 2;
    zoomSx = px; zoomSy = py;
    canvasVisual();
    setStatus(zoomZ > 1
      ? 'بزرگ‌نمایی ۲× — دوباره دوبار ضربه بزنید تا برداشته شود'
      : 'بزرگ‌نمایی برداشته شد');
  }

  window.addEventListener('resize', function () {
    if (root && canvas && canvas.width) fitCanvas();
    queueViewportSync();
  });

  // A significantly resized stage (rotation, fold) asks the host renderer
  // for a matching viewport — the same 'size' message the desktop sends at
  // start, now kept honest for the whole phone session.
  // v7.6.0 — the soft keyboard only SHRINKS the stage's height; that is
  // not a geometry change worth a host ride (a collapsed viewport would
  // fold the picture mid-typing). Only a WIDTH change (rotation) or a
  // GROWN height (keyboard closed, fold opened) rides.
  function queueViewportSync() {
    if (!root || closing) return;
    if (rsT) clearTimeout(rsT);
    rsT = setTimeout(function () {
      rsT = null;
      if (!root || closing || !canvas || !canvas.width) return;
      var stage = root.childNodes[1];
      if (!stage) return;
      var r = stage.getBoundingClientRect();
      var rawW = Math.floor(r.width - 2), rawH = Math.floor(r.height - 2);
      if (Math.abs(rawW - lastStageW) > 140 || rawH - lastStageH > 140) {
        var w = Math.max(240, Math.min(3840, rawW));
        var h = Math.max(240, Math.min(2160, rawH));
        send({ t: 'size', w: w, h: h });
      }
      lastStageW = rawW; lastStageH = rawH;
    }, 350);
  }

  // ---------------------------------------------------------------------------
  // Input capture → host replay.
  // ---------------------------------------------------------------------------

  function canvasPoint(e) {
    var r = canvas.getBoundingClientRect();
    var x = Math.round((e.clientX - r.left) * (canvas.width / r.width));
    var y = Math.round((e.clientY - r.top) * (canvas.height / r.height));
    return {
      x: Math.max(0, Math.min(canvas.width - 1, x)),
      y: Math.max(0, Math.min(canvas.height - 1, y))
    };
  }

  function rememberPoint(p) { lastX = p.x; lastY = p.y; }

  function bindInput() {
    canvas.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') { touchMove(e); return; }
      var now = Date.now();
      if (now - lastMoveSend < 15) return;
      lastMoveSend = now;
      var p = canvasPoint(e);
      rememberPoint(p);
      send({ t: 'move', x: p.x, y: p.y });
    });

    canvas.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      var p = canvasPoint(e);
      rememberPoint(p);
      if (e.pointerType === 'touch') { touchStart(e, p); return; }
      if (!(kbdInput && document.activeElement === kbdInput)) canvas.focus();
      try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
      send({ t: 'down', x: p.x, y: p.y, b: buttonName(e.button) });
    });

    canvas.addEventListener('pointerup', function (e) {
      var p = canvasPoint(e);
      rememberPoint(p);
      if (e.pointerType === 'touch') { touchEnd(e, p); return; }
      send({ t: 'up', x: p.x, y: p.y, b: buttonName(e.button) });
    });

    canvas.addEventListener('pointercancel', function (e) {
      if (e.pointerType === 'touch') touchCancel();
    });

    canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    canvas.addEventListener('wheel', function (e) {
      e.preventDefault();
      var p = canvasPoint(e);
      var mult = e.deltaMode === 1 ? 16 : (e.deltaMode === 2 ? 100 : 1);
      send({ t: 'wheel', x: p.x, y: p.y, dx: Math.round(e.deltaX * mult), dy: Math.round(e.deltaY * mult) });
    }, { passive: false });

    canvas.addEventListener('keydown', function (e) {
      e.preventDefault();
      var printable = e.key && e.key.length === 1;
      send({
        t: 'key', a: 'down', key: e.key, code: e.code,
        vk: e.keyCode || 0, text: printable ? e.key : '',
        mods: modsBits(e)
      });
    });

    canvas.addEventListener('keyup', function (e) {
      e.preventDefault();
      send({ t: 'key', a: 'up', key: e.key, code: e.code, vk: e.keyCode || 0, mods: modsBits(e) });
    });

    canvas.addEventListener('paste', function (e) {
      e.preventDefault();
      var t = (e.clipboardData || window.clipboardData).getData('text');
      if (t) send({ t: 'text', v: String(t).slice(0, 5000) });
    });
  }

  function buttonName(b) {
    if (b === 2) return 'right';
    if (b === 1) return 'middle';
    return 'left';
  }

  // ---------------------------------------------------------------------------
  // v7.5.0 — the touch classifier (mirrors the sandbox engine):
  //   tap → down/up; double tap → 'dbl' (clickCount 2 in the renderer,
  //   so dblclick fires natively) + the picture magnifies 2× around the
  //   tap (v7.6.0); long-press → 'selstart' — the renderer probes ground
  //   text and word-selects with a HELD press ('selmove' drags extend
  //   the selection, 'selend' releases), or answers 'selno' and the
  //   classic right-button pair rides (the site's own contextmenu);
  //   drag → wheel-style pan with fling. A second finger voids the live
  //   gesture.
  // ---------------------------------------------------------------------------

  function clearLp() {
    if (lpTimer) { clearTimeout(lpTimer); lpTimer = null; }
  }

  function noteTap(p) {
    var now = Date.now();
    if (lastTap && now - lastTap.t < 330 && Math.abs(lastTap.x - p.x) < 48 && Math.abs(lastTap.y - p.y) < 48) {
      // v7.6.0 — the double tap magnifies the picture around the tap; the
      // dblclick still rides so the renderer's own handlers run. A second
      // double tap restores the unmagnified picture.
      toggleZoom(p.x, p.y);
      send({ t: 'dbl', x: p.x, y: p.y });
      lastTap = null;
      return;
    }
    lastTap = { t: now, x: p.x, y: p.y };
  }

  function touchStart(e, p) {
    if (tPtr) {
      tSecond++;
      cancelTouchGesture();
      return;
    }
    tPtr = {
      id: e.pointerId, x0: p.x, y0: p.y, x: p.x, y: p.y,
      t0: Date.now(), lt: Date.now(), vx: 0, vy: 0,
      mode: null, decided: false
    };
    clearLp();
    lpTimer = setTimeout(function () {
      if (!tPtr || tPtr.id !== e.pointerId || tPtr.mode === 'menu') return;
      if (Math.abs(tPtr.x - tPtr.x0) < 12 && Math.abs(tPtr.y - tPtr.y0) < 12) {
        // v7.6.0 — the long-press asks the renderer whether the finger
        // sits on ground text ('selstart'): a held double-click press
        // word-selects there and the finger drag extends it; a 'selno'
        // answer rides the classic right-click pair instead (the site's
        // own contextmenu — the picture channel cannot hit-test locally,
        // so the renderer decides).
        tPtr.mode = 'sel';
        try { if (navigator.vibrate) navigator.vibrate(28); } catch (e2) {}
        send({ t: 'selstart', x: tPtr.x, y: tPtr.y });
      }
    }, 470);
  }

  function touchMove(e) {
    if (!tPtr || tPtr.id !== e.pointerId) return;
    var p = canvasPoint(e);
    var fdx = p.x - tPtr.x, fdy = p.y - tPtr.y;
    var now = Date.now();
    if (fdx || fdy) {
      var dt = Math.max(8, now - tPtr.lt);
      tPtr.vx = fdx / dt; tPtr.vy = fdy / dt;
    }
    tPtr.lt = now;
    tPtr.x = p.x; tPtr.y = p.y;
    if (!tPtr.decided) {
      if (Math.abs(p.x - tPtr.x0) < 12 && Math.abs(p.y - tPtr.y0) < 12) return;
      tPtr.decided = true;
      if (tPtr.mode === 'menu') tPtr.mode = 'menudrag';
      else if (tPtr.mode === 'sel') tPtr.mode = 'seldrag';
      else tPtr.mode = 'pan';
    }
    if (tPtr.mode === 'seldrag') {
      // the word-selection drag: every finger position rides as a selection
      // move — the renderer extends the selection from the word's edge.
      send({ t: 'selmove', x: p.x, y: p.y });
      return;
    }
    if (tPtr.mode === 'pan') {
      // content follows the finger — negated deltas, batched 40ms
      panAccX += fdx; panAccY += fdy;
      if (!panT) {
        panT = setTimeout(function () {
          panT = null;
          if (panAccX || panAccY) {
            send({ t: 'wheel', x: tPtr ? tPtr.x : lastX, y: tPtr ? tPtr.y : lastY, dx: Math.round(-panAccX), dy: Math.round(-panAccY) });
            panAccX = 0; panAccY = 0;
          }
        }, 40);
      }
    }
  }

  function touchEnd(e, p) {
    if (tPtr && tPtr.id === e.pointerId) {
      var g = tPtr;
      tPtr = null;
      clearLp();
      if (g.mode === 'pan') {
        if (panT) { clearTimeout(panT); panT = null; }
        if (panAccX || panAccY) {
          send({ t: 'wheel', x: p.x, y: p.y, dx: Math.round(-panAccX), dy: Math.round(-panAccY) });
          panAccX = 0; panAccY = 0;
        }
        startFling(g);
      } else if (g.mode === 'sel' || g.mode === 'seldrag') {
        // the word-selection drag ends with the release; the selection
        // stays highlighted in the picture (the copy affordance for the
        // service engine rides a later round).
        send({ t: 'selend', x: p.x, y: p.y });
      } else if (!g.decided && g.mode !== 'menu' && g.mode !== 'menudrag') {
        send({ t: 'down', x: p.x, y: p.y, b: 'left' });
        send({ t: 'up', x: p.x, y: p.y, b: 'left' });
        noteTap(p);
      }
      // 'menu'/'menudrag': the long-press already rode as right press/release
    }
    if (tSecond > 0) tSecond--;
  }

  function touchCancel() {
    if (tPtr && (tPtr.mode === 'sel' || tPtr.mode === 'seldrag')) {
      // a canceled selection still releases the renderer's held button.
      send({ t: 'selend', x: tPtr.x, y: tPtr.y });
    }
    tPtr = null;
    tSecond = 0;
    panAccX = 0; panAccY = 0;
    if (panT) { clearTimeout(panT); panT = null; }
  }

  function cancelTouchGesture() {
    if (!tPtr) return;
    if (tPtr.mode === 'sel' || tPtr.mode === 'seldrag') {
      send({ t: 'selend', x: tPtr.x, y: tPtr.y });
    }
    tPtr = null;
    clearLp();
    panAccX = 0; panAccY = 0;
  }

  function startFling(g) {
    var vx = g.vx || 0, vy = g.vy || 0; // px per ms, finger direction
    if (Math.sqrt(vx * vx + vy * vy) < 0.22) return;
    if (flingT) { clearTimeout(flingT); flingT = null; }
    var stepFn = function () {
      vx *= 0.84; vy *= 0.84;
      var dx = Math.round(vx * 100), dy = Math.round(vy * 100);
      if (!dx && !dy) { flingT = null; return; }
      if (!root || closing) { flingT = null; return; }
      send({ t: 'wheel', x: lastX, y: lastY, dx: Math.round(-Math.max(-80, Math.min(80, dx))), dy: Math.round(-Math.max(-80, Math.min(80, dy))) });
      flingT = setTimeout(stepFn, 100);
    };
    flingT = setTimeout(stepFn, 100);
  }

  function modsBits(e) {
    return (e.altKey ? 1 : 0) | (e.ctrlKey ? 2 : 0) | (e.metaKey ? 4 : 0) | (e.shiftKey ? 8 : 0);
  }

  // ---------------------------------------------------------------------------
  // Launcher.
  // ---------------------------------------------------------------------------

  function openVirtualBrowser(url) {
    closing = false;
    retries = 0;
    lastX = lastY = -1;
    frames = 0;
    fpsTime = Date.now();
    tPtr = null; tSecond = 0; lastTap = null;
    panAccX = 0; panAccY = 0;
    kbdPrev = '';
    buildOverlay();
    bindInput();
    connect(url);
    setIntervalIfAlive();
  }

  function setIntervalIfAlive() {
    // FPS reporter (kept outside connect() so it survives reconnects).
    if (!window.__glpe_vb_fps_timer) {
      window.__glpe_vb_fps_timer = setInterval(function () {
        if (!fpsEl || !root) return;
        var now = Date.now();
        if (now - fpsTime >= 1000) {
          fpsEl.textContent = frames + ' fps';
          frames = 0;
          fpsTime = now;
        }
      }, 1000);
    }
  }

  function startUrl() {
    var input = document.getElementById('glpe-vb-url');
    var v = input ? String(input.value || '').trim() : '';
    if (!v) v = 'https://example.com';
    return v;
  }

  function boot() {
    var buttons = document.querySelectorAll('.glpe-vb-open');
    if (!buttons.length) return;
    for (var i = 0; i < buttons.length; i++) {
      (function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          openVirtualBrowser(startUrl());
        });
      })(buttons[i]);
    }
    // Enter in the launcher input starts the virtual surface too.
    var input = document.getElementById('glpe-vb-url');
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          openVirtualBrowser(startUrl());
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
