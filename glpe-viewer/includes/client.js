/**
 * GLPE Client-Side Companion
 * Injected into displayed documents for seamless dynamic request handling,
 * SPA navigation support, frame-buster neutralization and data synchronization.
 */
(function() {
  if (window.__glpe_installed__) return;
  window.__glpe_installed__ = true;

  var ctx = window.__glpe_ctx__ || {};
  var viewScript = ctx.g || '/';
  var encKey = ctx.k || 'glpe-local-key';

  // Bi-directional byte codec matching the server-side GLPE_Codec.
  // Core tenet: EVERY destination reference and request payload that leaves
  // the browser is wrapped into an opaque token — readable addresses and
  // readable GET/POST payloads never travel on the wire, unconditionally.
  function cipherEncode(str, key) {
    if (!str || typeof str !== 'string') return '';
    key = key || encKey;
    var bytes = unescape(encodeURIComponent(str));
    var out = '';
    for (var i = 0; i < bytes.length; i++) {
      out += String.fromCharCode(bytes.charCodeAt(i) ^ key.charCodeAt(i % key.length));
    }
    try {
      return btoa(out).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    } catch(e) {
      return '';
    }
  }

  function cipherDecode(token, key) {
    if (!token || typeof token !== 'string') return '';
    key = key || encKey;
    try {
      var b64 = token.replace(/-/g, '+').replace(/_/g, '/');
      while (b64.length % 4) b64 += '=';
      var raw = atob(b64);
      var out = '';
      for (var i = 0; i < raw.length; i++) {
        out += String.fromCharCode(raw.charCodeAt(i) ^ key.charCodeAt(i % key.length));
      }
      return decodeURIComponent(escape(out));
    } catch (e) {
      return '';
    }
  }

  // The destination reference arrives as an opaque token — never readable.
  var currentTargetUrl = cipherDecode(ctx.u) || window.location.href;

  // Inside a proxied document, same-origin references are usually TARGET
  // references that the browser (or the Request constructor) already resolved
  // against OUR origin — e.g. new Request('/youtubei/v1/guide') becomes
  // http://this-site/youtubei/v1/guide. They must be re-anchored onto the
  // real target and wrapped. Only this app's own endpoints stay untouched.
  var isProxiedPage = false;
  var ownAppPathRe = null;
  try {
    var _ctxOrigin = new URL(currentTargetUrl, window.location.href).origin;
    isProxiedPage = !!(window.location && _ctxOrigin && _ctxOrigin !== window.location.origin);
    ownAppPathRe = /^(\/(wp-admin|wp-login|wp-json|wp-content|wp-includes|feed|view)(\/|$)|\/\?)/i;
  } catch (e) {}

  function isOwnAppRef(absObj) {
    if (absObj.search.indexOf('_glpe=') !== -1) return true;
    return ownAppPathRe ? ownAppPathRe.test(absObj.pathname + absObj.search) : false;
  }

  // Builds the display navigation address for a destination: the destination
  // itself only ever appears inside the wrapped token.
  function buildGatewayUrl(destHref) {
    var sep = viewScript.indexOf('?') !== -1 ? '&' : '?';
    var flags = '';
    if (ctx.rs) flags += '&ns=1';
    if (ctx.ri) flags += '&ni=1';
    if (ctx.st) flags += '&nt=1';
    if (ctx.tb) flags += '&nb=1';
    if (ctx.mb) flags += '&mb=1';
    return viewScript + sep + 'l=' + cipherEncode(destHref) + flags;
  }

  function resolveViewUrl(url) {
    if (!url) return url;
    if (typeof url !== 'string') {
      // Only unwrap genuine URL-like objects; anything else (e.g. a DOM
      // element such as a submit button named "action" shadowing the
      // form.action property) must NOT be stringified into a fake URL.
      if (url instanceof URL || Object.prototype.toString.call(url) === '[object Location]') {
        url = url.href;
      } else {
        return url;
      }
    }
    var trimmed = url.trim();
    if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('javascript:') || trimmed.startsWith('#')) {
      return trimmed;
    }
    if (trimmed.indexOf('_glpe=') !== -1 || trimmed.indexOf('?l=') !== -1 || trimmed.indexOf('&l=') !== -1) {
      return trimmed;
    }
    try {
      var absolute = new URL(trimmed, currentTargetUrl).href;
      var absObj = new URL(absolute);
      if (window.location && absObj.origin === window.location.origin) {
        // On the viewer page itself, own links stay untouched. Inside a
        // proxied page, same-origin references are re-anchored onto the
        // target site — otherwise SPA fetches (Request objects, service
        // style relative URLs) silently hit this site and die with a 404.
        if (!isProxiedPage || isOwnAppRef(absObj)) {
          return absolute;
        }
        absolute = _ctxOrigin + absObj.pathname + absObj.search + absObj.hash;
      }
      return buildGatewayUrl(absolute);
    } catch(e) {
      return trimmed;
    }
  }

  // 1. Frame-buster neutralization
  // NOTE: `window.top` / `window.parent` are [LegacyUnforgeable] —
  // non-configurable own properties on every browser — so they cannot be
  // redefined. Frame-buster scripts are instead neutralized server-side by
  // the HTML rewriter (top/parent location references are replaced before
  // the script ever reaches the browser).

  // 2. Intercept window.fetch (dynamic content, form endpoints, JSON APIs)
  if (window.fetch) {
    function deepRewriteJson(obj) {
      if (!obj || typeof obj !== 'object') {
        if (typeof obj === 'string' && /^https?:\/\//i.test(obj)) {
          return resolveViewUrl(obj);
        }
        return obj;
      }
      if (Array.isArray(obj)) {
        return obj.map(deepRewriteJson);
      }
      var res = {};
      for (var k in obj) {
        if (Object.prototype.hasOwnProperty.call(obj, k)) {
          var val = obj[k];
          if (typeof val === 'string' && /^https?:\/\//i.test(val)) {
            res[k] = resolveViewUrl(val);
          } else {
            res[k] = deepRewriteJson(val);
          }
        }
      }
      return res;
    }

    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
      var isRequestObj = false;
      var newUrl = '';
      if (typeof input === 'string' || input instanceof URL) {
        input = resolveViewUrl(input.toString());
      } else if (input && typeof input.url === 'string') {
        isRequestObj = true;
        newUrl = resolveViewUrl(input.url);
      }

      var fetchPromise;
      if (isRequestObj) {
         if (!init) {
            fetchPromise = originalFetch.call(this, newUrl, input);
         } else {
            try {
              input = new Request(newUrl, input);
            } catch(e) {}
            fetchPromise = originalFetch.call(this, input, init);
         }
      } else {
         fetchPromise = originalFetch.call(this, input, init);
      }

      return fetchPromise.then(function(response) {
        try {
          var ct = response.headers.get('content-type') || '';
          if (ct.indexOf('json') !== -1) {
            var clone = response.clone();
            return clone.json().then(function(json) {
              var rewrittenJson = deepRewriteJson(json);
              return new Response(JSON.stringify(rewrittenJson), {
                status: response.status,
                statusText: response.statusText,
                headers: response.headers
              });
            }).catch(function() {
              return response;
            });
          }
        } catch(e) {}
        return response;
      });
    };
  }

  // 3. Intercept XMLHttpRequest
  if (window.XMLHttpRequest) {
    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
      var resolved = resolveViewUrl(url);
      if (arguments.length >= 5) return originalOpen.call(this, method, resolved, async, user, password);
      if (arguments.length === 4) return originalOpen.call(this, method, resolved, async, user);
      if (arguments.length === 3) return originalOpen.call(this, method, resolved, async);
      return originalOpen.call(this, method, resolved);
    };
  }

  // 4. Intercept dynamic form submits
  if (window.HTMLFormElement) {
    // Forms containing a control named "action" (e.g. Google sign-in's
    // <button type="submit" name="action">) shadow the form.action URL
    // property per the HTMLFormElement named-property rules. Reading the
    // property directly would return that control element instead of the
    // URL, so go through the prototype accessor explicitly.
    var formActionDesc = null;
    try {
      formActionDesc = Object.getOwnPropertyDescriptor(HTMLFormElement.prototype, 'action');
    } catch (e) {}
    function realFormAction(form) {
      try {
        if (formActionDesc && formActionDesc.get) {
          var v = formActionDesc.get.call(form);
          if (typeof v === 'string') return v;
        }
      } catch (e) {}
      return form.getAttribute('action') || '';
    }
    function setFormAction(form, val) {
      try {
        if (formActionDesc && formActionDesc.set) {
          formActionDesc.set.call(form, val);
          return;
        }
      } catch (e) {}
      form.setAttribute('action', val);
    }
    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
      var actionStr = realFormAction(this);
      if (actionStr && typeof actionStr === 'string') {
        setFormAction(this, resolveViewUrl(actionStr));
      }
      return originalSubmit.call(this);
    };

    // Determines the REAL destination a submission points at:
    // 1. Rebuilt (server-rendered) forms carry the endpoint as a wrapped
    //    token in a hidden "l" input — decode it.
    // 2. Actions already rewritten to the gateway carry the token in their
    //    query string — decode it.
    // 3. Anything else is resolved onto the real target and unwrapped back
    //    to the bare destination.
    function decodeFormDestination(form) {
      var lInput = null;
      try { lInput = form.querySelector('input[name="l"]'); } catch (e) {}
      if (lInput && lInput.value) {
        var lv = String(lInput.value).trim();
        if (/^https?:/i.test(lv)) return lv; // legacy plain value
        var decodedInput = cipherDecode(lv);
        if (decodedInput) return decodedInput;
      }
      var act = String(realFormAction(form) || window.location.href);
      var tokenMatch = act.match(/[?&]l=([A-Za-z0-9_-]+)/);
      if (tokenMatch) {
        var decoded = cipherDecode(tokenMatch[1]);
        if (decoded) return decoded;
      }
      var wrapped = String(resolveViewUrl(act));
      var wrappedToken = wrapped.match(/[?&]l=([A-Za-z0-9_-]+)/);
      if (wrappedToken) {
        var decodedWrapped = cipherDecode(wrappedToken[1]);
        if (decodedWrapped) return decodedWrapped;
      }
      return wrapped;
    }

    // Collects the visitor's own fields for a submission. Fields injected by
    // the gateway itself (hidden inputs marked data-g) are excluded so they
    // never reach the destination, and file controls fall back to the native
    // pass-through path.
    function collectOwnFields(form, apply) {
      var els = form.elements;
      for (var i = 0; i < els.length; i++) {
        var el = els[i];
        if (!el || !el.name || el.disabled) continue;
        if (el.hasAttribute && el.hasAttribute('data-g')) continue;
        var t = el.type;
        if (t === 'file' || t === 'submit' || t === 'button' || t === 'image' || t === 'reset') continue;
        if ((t === 'checkbox' || t === 'radio') && !el.checked) continue;
        if (el.tagName === 'SELECT' && el.multiple) {
          for (var o = 0; o < el.options.length; o++) {
            if (el.options[o].selected) apply(el.name, el.options[o].value);
          }
          continue;
        }
        if (typeof el.value === 'string') apply(el.name, el.value);
      }
    }

    window.addEventListener('submit', function(e) {
      var form = e.target;
      if (!form || form.dataset.rewritten) return;
      if (form.closest && form.closest('#__glpe_bar')) return;
      var actionStr = realFormAction(form);
      if (actionStr && typeof actionStr === 'string') {
        setFormAction(form, resolveViewUrl(actionStr));
      }
      form.dataset.rewritten = '1';
      var formMethod = (form.getAttribute('method') || 'get').toLowerCase();
      var actionTrimmed = String(realFormAction(form) || '').trim();
      var scriptDriven = /^(javascript|data|blob):/i.test(actionTrimmed);
      if (scriptDriven) return;

      // GET submissions: the user's fields must not ride the query string in
      // readable form. The full destination (endpoint + merged fields) is
      // wrapped into a single token and the navigation carries ONLY that
      // token — a full page load through the gateway renders reliably
      // (server-side rewriting is complete; SPA hijacks would drop the
      // gateway identity, verified live with YouTube).
      if (formMethod === 'get' && !e.defaultPrevented) {
        try {
          var dest = decodeFormDestination(form);
          var destUrl = new URL(dest, window.location.href);
          var merged = new URLSearchParams(destUrl.search);
          collectOwnFields(form, function(k, v) { merged.set(k, v); });
          destUrl.search = merged.toString();
          e.preventDefault();
          e.stopPropagation();
          window.location.href = buildGatewayUrl(destUrl.href);
        } catch (err) {}
        return;
      }

      // POST submissions without file payloads: the whole body (field names
      // and values included) is wrapped into an opaque envelope — the wire
      // carries only d=<token> next to the wrapped endpoint in the action.
      if (formMethod === 'post' && !e.defaultPrevented) {
        try {
          var hasFiles = form.querySelector('input[type="file"]');
          var encType = (form.getAttribute('enctype') || '').toLowerCase();
          if (hasFiles || encType.indexOf('multipart') !== -1) return; // native pass-through
          var body = new URLSearchParams();
          collectOwnFields(form, function(k, v) { body.append(k, v); });
          var envelope = cipherEncode(JSON.stringify({
            c: 'application/x-www-form-urlencoded',
            b: body.toString()
          }));
          if (!envelope) return;
          e.preventDefault();
          e.stopPropagation();
          var wrapper = document.createElement('form');
          wrapper.method = 'POST';
          wrapper.action = actionTrimmed;
          wrapper.style.display = 'none';
          var dInput = document.createElement('input');
          dInput.type = 'hidden';
          dInput.name = 'd';
          dInput.value = envelope;
          wrapper.appendChild(dInput);
          document.body.appendChild(wrapper);
          wrapper.submit(); // programmatic submit fires no submit event → no loop
        } catch (err) {}
      }
    }, true);
  }

  // 5. Sync client-side session data with the server-side store.
  // The destination reference and the record line travel as wrapped tokens —
  // the beacon body carries no readable address (core tenet).
  try {
    var cookieDesc = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie') ||
                     Object.getOwnPropertyDescriptor(HTMLDocument.prototype, 'cookie');
    if (cookieDesc && cookieDesc.configurable) {
      var originalCookieGet = cookieDesc.get;
      var originalCookieSet = cookieDesc.set;
      Object.defineProperty(document, 'cookie', {
        get: function() {
          return originalCookieGet ? originalCookieGet.call(this) : '';
        },
        set: function(val) {
          var sep = viewScript.indexOf('?') !== -1 ? '&' : '?';
          var syncUrl = viewScript + sep + 'mode=sync';
          var encUrl = cipherEncode(currentTargetUrl);
          var encVal = cipherEncode(String(val));
          if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('u', encUrl);
            data.append('c', encVal);
            navigator.sendBeacon(syncUrl, data);
          } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', syncUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('u=' + encodeURIComponent(encUrl) + '&c=' + encodeURIComponent(encVal));
          }
          if (originalCookieSet) {
            originalCookieSet.call(this, val);
          }
        },
        configurable: true
      });
    }
  } catch(e) {}

  // 6. Intercept window.open
  var originalWindowOpen = window.open;
  window.open = function(url, target, features) {
    if (url) url = resolveViewUrl(url);
    return originalWindowOpen.call(this, url, target, features);
  };

  // 6a. Socket guard — core tenet: no exchange may leave the gateway path.
  // A socket connection to an external origin would carry traffic around the
  // display pipeline entirely, so constructor calls pointing at another
  // origin are rejected up front (socket-centric apps are documented as
  // structurally incompatible anyway).
  try {
    if (window.WebSocket) {
      var OriginalSocket = window.WebSocket;
      var GuardedSocket = function(url, protocols) {
        var origin = '';
        try { origin = new URL(url, window.location.href).origin; } catch (e) {}
        if (origin && origin !== window.location.origin) {
          throw new Error('GLPE: socket exchanges with external origins are disabled; every exchange must ride the gateway.');
        }
        return protocols === undefined
          ? new OriginalSocket(url)
          : new OriginalSocket(url, protocols);
      };
      GuardedSocket.prototype = OriginalSocket.prototype;
      try {
        GuardedSocket.CONNECTING = OriginalSocket.CONNECTING;
        GuardedSocket.OPEN = OriginalSocket.OPEN;
        GuardedSocket.CLOSING = OriginalSocket.CLOSING;
        GuardedSocket.CLOSED = OriginalSocket.CLOSED;
      } catch (e) {}
      window.WebSocket = GuardedSocket;
    }
  } catch (e) {}

  // 7. Intercept SPA navigation (pushState & replaceState)
  if (window.history && window.history.pushState) {
    // The url argument can be a string OR a URL object — frameworks (e.g.
    // YouTube's SPA) pass URL instances, which must be unwrapped to href
    // before resolving, otherwise they skip rewriting entirely.
    function normalizeNavUrl(url) {
      if (!url) return url;
      if (typeof url === 'string') return resolveViewUrl(url);
      if (url instanceof URL) return resolveViewUrl(url.href);
      return url;
    }
    // Patch BOTH the prototype and the instance with the SAME wrapper:
    // frameworks call history.replaceState(...) directly AND make
    // prototype-level calls (History.prototype.replaceState.call(history,
    // …) — verified live with YouTube), which skip instance patches.
    function patchHistoryKey(key) {
      try {
        var protoDesc = Object.getOwnPropertyDescriptor(History.prototype, key);
        if (!protoDesc || !protoDesc.configurable || typeof protoDesc.value !== 'function') return;
        var nativeFn = protoDesc.value;
        var wrapped = function(state, title, url) {
          return nativeFn.call(this, state, title, normalizeNavUrl(url));
        };
        Object.defineProperty(History.prototype, key, {
          value: wrapped, writable: protoDesc.writable !== false, configurable: true
        });
        Object.defineProperty(window.history, key, {
          value: wrapped, writable: true, configurable: true
        });
      } catch (e) {}
    }
    patchHistoryKey('pushState');
    patchHistoryKey('replaceState');
  }

  // 7a. Location interception — intentional no-op.
  // `window.location` (and every Location property such as href/assign/
  // replace) is [LegacyUnforgeable]: a non-configurable own property that
  // cannot be shadowed, patched or proxied (verified live: defineProperty
  // throws, Location.prototype has no own descriptors). Pages that navigate
  // via `location.href = …` therefore CAN leave the viewer; this is a
  // browser-enforced limit, documented in the readme.

  // 7c. Navigation API — modern SPAs route through the navigate EVENT:
  // a form submit / link click fires navigation's "navigate" event and the
  // app calls e.intercept() with its own canonical URL, which (a) drops the
  // gateway identity from the address bar and (b) fails to render inside
  // the viewer (YouTube search is the canonical case). client.js is the
  // first script on the page, so our listener registers first: unwrapped
  // same-origin target navigations are cancelled and re-issued as FULL
  // gateway navigations (server-side rendering is complete), while already
  // wrapped destinations and this app's own endpoints pass through.
  try {
    if (window.navigation && window.navigation.addEventListener) {
      window.navigation.addEventListener('navigate', function(e) {
        try {
          if (!e.canIntercept) return;
          var dest = new URL(e.destination.url, window.location.href);
          if (dest.origin !== window.location.origin) return;
          if (dest.search.indexOf('_glpe=') !== -1) return;
          if (isOwnAppRef(dest)) return;
          // Leave boot-time canonicalization and in-page state updates
          // alone: intercepting them mid-boot leaves an uninitialized
          // shell (verified live). Only real route changes (/results,
          // /watch, channel pages…) are converted to gateway loads.
          if (dest.pathname === '/' || dest.pathname === '') return;
          if (e.sameDocument === true) return;
          e.preventDefault();
          window.location.href = resolveViewUrl(dest.href);
        } catch (err) {}
      });
    }
  } catch (e) {}

  // 7b. MutationObserver for dynamically injected elements
  // Own UI (navigation bar / reopen badge) is excluded from rewriting.
  function isOwnUi(node) {
    if (!node || node.nodeType !== 1) return false;
    if (node.id === '__glpe_bar' || node.id === '__glpe_badge') return true;
    return !!(node.closest && (node.closest('#__glpe_bar') || node.closest('#__glpe_badge')));
  }
  try {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node && node.nodeType === 1 && !isOwnUi(node)) {
            var tag = node.tagName;
            if (tag === 'IMG' || tag === 'VIDEO' || tag === 'AUDIO' || tag === 'SOURCE' || tag === 'IFRAME') {
              var s = node.getAttribute('src');
              if (s && !s.startsWith('#') && !s.startsWith('javascript:') && s.indexOf('_glpe=') === -1) {
                node.setAttribute('src', resolveViewUrl(s));
              }
            } else if (tag === 'SCRIPT' || tag === 'LINK') {
              // Dynamically injected player/stylesheet/loader nodes (e.g.
              // YouTube's /s/player/.../base.js) must also travel through
              // the gateway or they 404 against this site.
              var rl = node.tagName === 'SCRIPT' ? node.getAttribute('src') : node.getAttribute('href');
              if (rl && !rl.startsWith('#') && !rl.startsWith('javascript:') && !rl.startsWith('data:') && rl.indexOf('_glpe=') === -1) {
                if (node.tagName === 'SCRIPT') {
                  node.setAttribute('src', resolveViewUrl(rl));
                } else {
                  node.setAttribute('href', resolveViewUrl(rl));
                }
              }
            } else if (tag === 'A') {
              var h = node.getAttribute('href');
              if (h && !h.startsWith('#') && !h.startsWith('javascript:') && h.indexOf('_glpe=') === -1) {
                node.setAttribute('href', resolveViewUrl(h));
              }
            } else if (tag === 'FORM') {
              var a = node.getAttribute('action');
              if (a && !a.startsWith('#') && !a.startsWith('javascript:') && a.indexOf('_glpe=') === -1) {
                node.setAttribute('action', resolveViewUrl(a));
              }
            }
            // Data attributes and nested elements
            var dataEls = node.querySelectorAll ? node.querySelectorAll('form, a, [data-src], [data-thumb], [data-background], [data-poster], [data-url], [data-image], [data-original], [data-bg]') : [];
            for (var i = 0; i < dataEls.length; i++) {
              var el = dataEls[i];
              if (isOwnUi(el)) continue;
              if (el.tagName === 'FORM') {
                 var fa = el.getAttribute('action');
                 if (fa && !fa.startsWith('#') && !fa.startsWith('javascript:') && fa.indexOf('_glpe=') === -1) {
                   el.setAttribute('action', resolveViewUrl(fa));
                 }
              } else if (el.tagName === 'A') {
                 var fh = el.getAttribute('href');
                 if (fh && !fh.startsWith('#') && !fh.startsWith('javascript:') && fh.indexOf('_glpe=') === -1) {
                   el.setAttribute('href', resolveViewUrl(fh));
                 }
              } else {
                 ['data-src', 'data-thumb', 'data-background', 'data-poster', 'data-url', 'data-image', 'data-original', 'data-bg'].forEach(function(attr) {
                   var val = el.getAttribute(attr);
                   if (val && val.indexOf('_glpe=') === -1) {
                     el.setAttribute(attr, resolveViewUrl(val));
                   }
                 });
              }
            }
          }
        });
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  } catch(e) {}

  // 8. Navigation bar toggle
  window.__toggleGlpeBar = function() {
    var bar = document.getElementById('__glpe_bar');
    var badge = document.getElementById('__glpe_badge');
    if (bar && badge) {
      if (bar.style.display === 'none') {
        bar.style.display = 'block';
        badge.style.display = 'none';
        document.body.style.marginTop = '42px';
      } else {
        bar.style.display = 'none';
        badge.style.display = 'flex';
        document.body.style.marginTop = '0px';
      }
    }
  };
})();
