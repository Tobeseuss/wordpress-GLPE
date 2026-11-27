/**
 * GLPE Virtual Browser — visitor-side remote surface.
 *
 * Loaded on the viewer page (not inside displayed documents). Opens a
 * picture-only channel to the host-side renderer: frames drawn on a canvas,
 * mouse/keyboard gestures shipped to the host, a virtual cursor drawn where
 * the host's renderer sees the pointer. Nothing from the destination page
 * runs locally — the canvas is the entire experience.
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
    canvas: 'display:block;background:#000;outline:none;cursor:none;'
  };

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
    urlInput = el('input', S.input); urlInput.type = 'text'; urlInput.placeholder = 'نشانی سایت — مثال: example.com';
    urlInput.setAttribute('aria-label', 'نشانی سایت');

    bar.appendChild(bClose);
    bar.appendChild(bReload);
    bar.appendChild(bBack);
    bar.appendChild(bFwd);
    bar.appendChild(urlInput);
    root.appendChild(bar);

    var stage = el('div', S.stage);
    canvas = el('canvas', S.canvas);
    canvas.tabIndex = 0;
    stage.appendChild(canvas);
    statusEl = el('div', S.pill);
    statusEl.id = '__glpe_vb_status';
    stage.appendChild(statusEl);
    root.appendChild(stage);

    var foot = el('div', S.foot);
    var state = el('span'); state.style.color = '#6ee7b7'; state.textContent = '🖥️ رندر کامل روی میزبان';
    var note = el('span'); note.style.flex = '1'; note.textContent = 'هیچ کد یا محتوایی از سایت مقصد وارد این دستگاه نمی‌شود — فقط تصویر فریم‌ها می‌رسد و حرکت موس/کیبورد شما به همان محیط ارسال می‌شود.';
    fpsEl = el('span'); fpsEl.dir = 'ltr'; fpsEl.textContent = '— fps';
    latencyEl = el('span'); latencyEl.dir = 'ltr'; latencyEl.textContent = '— ms';
    foot.appendChild(state); foot.appendChild(note); foot.appendChild(latencyEl); foot.appendChild(fpsEl);
    root.appendChild(foot);

    bClose.onclick = closeOverlay;
    bReload.onclick = function () { if (ws && currentUrl) send({ t: 'nav', url: currentUrl }); };
    bBack.onclick = function () { send({ t: 'back' }); };
    bFwd.onclick = function () { send({ t: 'fwd' }); };
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
    try { if (ws) ws.close(); } catch (e) {}
    if (root && root.parentNode) root.parentNode.removeChild(root);
    document.body.style.overflow = '';
    root = canvas = ctx = urlInput = ws = null;
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
        setStatus('نشست فعال — تصویر از میزبان می‌رسد');
        canvas.focus();
        break;

      case 'frame':
        drawFrame(msg);
        break;

      case 'nav':
        currentUrl = msg.url || currentUrl;
        urlInput.value = currentUrl;
        setStatus('صفحه بارگذاری شد' + (msg.title ? ' — ' + msg.title.slice(0, 60) : ''));
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
  }

  window.addEventListener('resize', function () { if (root && canvas && canvas.width) fitCanvas(); });

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
      canvas.focus();
      try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
      send({ t: 'down', x: p.x, y: p.y, b: buttonName(e.button) });
    });

    canvas.addEventListener('pointerup', function (e) {
      var p = canvasPoint(e);
      rememberPoint(p);
      send({ t: 'up', x: p.x, y: p.y, b: buttonName(e.button) });
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
