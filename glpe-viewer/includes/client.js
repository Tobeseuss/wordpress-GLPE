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

  // =====================================================================
  // Virtual-browser sandbox mode (ctx.vs): this page lives inside the
  // locked temporary environment of the sandbox engine. Before ANY
  // destination script boots, the environment gets (a) a host-consistent
  // identity — the profile the host used on the wire is mirrored into
  // navigator/Intl/screen/geolocation, so the JavaScript that runs here
  // measures the profile, never the visitor's own locale, timezone,
  // hardware or position signals; and (b) a hard frame lockdown — the
  // environment's window never reaches the surface page, opens no
  // windows, and its document.cookie is a virtual jar that never exposes
  // this site's own records.
  // =====================================================================
  var vsJar = '';
  (function virtualEnvironment() {
    if (!ctx.vs) return;

    // (a) host-consistent identity ----------------------------------
    var loc = ctx.loc || 'en-US';
    var lng = ctx.lng || 'en';
    var tz = ctx.tz || 'UTC';
    var ua = ctx.ua || '';
    var plat = ctx.plat || 'Win32';

    function maskNav(prop, value) {
      try {
        Object.defineProperty(window.navigator, prop, {
          get: function () { return value; },
          configurable: true
        });
      } catch (e) {}
    }
    maskNav('language', loc);
    maskNav('languages', [loc, lng]);
    if (ua) maskNav('userAgent', ua);
    maskNav('platform', plat);
    if (ctx.hc) maskNav('hardwareConcurrency', ctx.hc);
    if (ctx.dm) maskNav('deviceMemory', ctx.dm);

    try {
      Object.defineProperty(window.screen, 'width', { get: function () { return ctx.scrw || 1280; }, configurable: true });
      Object.defineProperty(window.screen, 'height', { get: function () { return ctx.scrh || 800; }, configurable: true });
      Object.defineProperty(window.screen, 'availWidth', { get: function () { return ctx.scrw || 1280; }, configurable: true });
      Object.defineProperty(window.screen, 'availHeight', { get: function () { return (ctx.scrh || 800) - 40; }, configurable: true });
    } catch (e) {}

    // Position requests fail like a denied permission — the environment
    // has no location, which is exactly the truth of the arrangement.
    try {
      var geoError = function (ok, err) {
        setTimeout(function () {
          if (err) err({ code: 1, message: 'denied', PERMISSION_DENIED: 1 });
        }, 0);
        return 0;
      };
      Object.defineProperty(window.navigator, 'geolocation', {
        get: function () {
          return {
            getCurrentPosition: geoError,
            watchPosition: geoError,
            clearWatch: function () {}
          };
        },
        configurable: true
      });
    } catch (e) {}

    // Timezone: both the formatter input and the resolved answer report the
    // profile zone, and Date offsets agree with it, so scripts measuring
    // the clock see the profile — never the visitor's own zone.
    try {
      if (window.Intl && Intl.DateTimeFormat) {
        var OrigDTF = Intl.DateTimeFormat;
        var PatchedDTF = function (locales, options) {
          options = Object.assign({}, options);
          if (options.timeZone === undefined) options.timeZone = tz;
          return new OrigDTF(locales, options);
        };
        PatchedDTF.prototype = Object.create(OrigDTF.prototype);
        PatchedDTF.supportedLocalesOf = function () {
          return OrigDTF.supportedLocalesOf.apply(OrigDTF, arguments);
        };
        try { Intl.DateTimeFormat = PatchedDTF; } catch (e) {}
        var origResolved = OrigDTF.prototype.resolvedOptions;
        OrigDTF.prototype.resolvedOptions = function () {
          var r = origResolved.call(this);
          try { r.timeZone = tz; } catch (e) {}
          return r;
        };

        var tzOffset = function (date) {
          try {
            var parts = new OrigDTF('en-US', {
              timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
              hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            }).formatToParts(date);
            var map = {};
            for (var i = 0; i < parts.length; i++) map[parts[i].type] = parts[i].value;
            var asUTC = Date.UTC(map.year, map.month - 1, map.day, map.hour, map.minute, map.second);
            return Math.round((date.getTime() - asUTC) / 60000);
          } catch (e) {
            return 0;
          }
        };
        Date.prototype.getTimezoneOffset = function () {
          return tzOffset(this);
        };
      }
    } catch (e) {}

    // (b) frame lockdown ---------------------------------------------
    // The sandbox attribute on the surface frame already forbids top
    // navigation and popups; these belt-and-suspenders guards make the
    // environment's own window agree: parent/top resolve to itself,
    // frameElement is absent, and window.open opens nothing.
    try {
      Object.defineProperty(window, 'parent', { get: function () { return window; }, configurable: false });
      Object.defineProperty(window, 'top', { get: function () { return window; }, configurable: false });
      Object.defineProperty(window, 'frameElement', { get: function () { return null; }, configurable: false });
    } catch (e) {}
    window.open = function () { return null; };

    // document.cookie becomes a virtual jar: reads return only what the
    // environment's own scripts stored, writes stay local — this site's
    // own records are never exposed to the destination. (The wrapper below
    // keeps the host-side jar in sync through the beacon.)
    vsJar = '';
  })();

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

  // SPA router support. Routers read location.pathname — which initially
  // shows the gateway path (e.g. /view/) — and 404 because that route does
  // not exist in the application. Swap the visible path to the destination's
  // own path BEFORE any application script boots. replaceState is purely
  // local (no exchange rides it); the host and all identity-bearing parts
  // stay inside the opaque token in the query. Destination query params are
  // placed first so our reserved params always win duplicate-name collisions
  // (PHP takes the last duplicate).
  try {
    if (ctx.u && isProxiedPage && window.history && window.history.replaceState) {
      var bootDest = new URL(currentTargetUrl, window.location.href);
      var bootDestQ = bootDest.search.replace(/^\?/, '');
      var bootOurs = window.location.search.replace(/^\?/, '');
      var bootMerged = bootDestQ ? (bootDestQ + '&' + bootOurs) : bootOurs;
      var bootHref = window.location.origin + bootDest.pathname + '?' + bootMerged + window.location.hash;
      if (window.location.pathname !== bootDest.pathname || bootDestQ) {
        window.history.replaceState(window.history.state, '', bootHref);
      }
    }
  } catch (e) {}

  function isOwnAppRef(absObj) {
    if (absObj.search.indexOf('_glpe=') !== -1) return true;
    return ownAppPathRe ? ownAppPathRe.test(absObj.pathname + absObj.search) : false;
  }

  // Builds the display navigation address for a destination: the destination
  // itself only ever appears inside the wrapped token.
  function buildGatewayUrl(destHref) {
    var flags = '';
    if (ctx.rs) flags += '&ns=1';
    if (ctx.ri) flags += '&ni=1';
    if (ctx.st) flags += '&nt=1';
    if (ctx.tb) flags += '&nb=1';
    if (ctx.mb) flags += '&mb=1';
    // Virtual-browser sandbox: every reference keeps riding the isolated
    // jar and the host-consistent identity of the temporary environment.
    if (ctx.vs) flags += '&vs=1';
    // Path-preserving entry (server verified the host routes /_glpe/...):
    // subresource references mirror the destination's own URL path under
    // the reserved namespace — destination-side code that validates its
    // script/stylesheet URLs by path shape keeps seeing what it expects.
    // The destination's host and query stay inside the token.
    if (ctx.pe) {
      try {
        var pu = new URL(destHref, currentTargetUrl);
        if ((pu.protocol === 'http:' || pu.protocol === 'https:') &&
            pu.pathname && pu.pathname.length <= 384 &&
            /^[A-Za-z0-9%.\-_~!$&'()*+,;=:@\/]+$/.test(pu.pathname.slice(1))) {
          return window.location.origin + '/_glpe' + pu.pathname +
            '?_glpe=1&l=' + cipherEncode(pu.href) + flags;
        }
      } catch (e) {}
    }
    var sep = viewScript.indexOf('?') !== -1 ? '&' : '?';
    return viewScript + sep + 'l=' + cipherEncode(destHref) + flags;
  }

  // History-entry variant: SPA routers read location.pathname + location.search,
  // so pushed/replaced entries carry the destination's PATH (and query) in the
  // visible URL while the host and full address remain inside the token.
  // pushState/replaceState never touch the wire — this form exists so routers
  // recognise their own routes; a reload of such an entry still dispatches
  // through the gateway (_glpe + token in the query, path ignored).
  function buildHistoryUrl(destHref) {
    try {
      var u = new URL(destHref, currentTargetUrl);
      var ours = '_glpe=1&l=' + cipherEncode(u.href);
      if (ctx.rs) ours += '&ns=1';
      if (ctx.ri) ours += '&ni=1';
      if (ctx.st) ours += '&nt=1';
      if (ctx.tb) ours += '&nb=1';
      if (ctx.mb) ours += '&mb=1';
      if (ctx.vs) ours += '&vs=1';
      var destQ = u.search.replace(/^\?/, '');
      var search = destQ ? destQ + '&' + ours : ours;
      return window.location.origin + u.pathname + '?' + search + u.hash;
    } catch (e) {
      return buildGatewayUrl(destHref);
    }
  }

  // True for references that carry the reserved /_glpe namespace: either
  // a relative path form or a same-origin absolute form. Destination-side
  // code constructs such URLs out of gateway URLs it READ from the DOM
  // (module loaders derive follow-up URLs from their script's prefix), so
  // every wrapper must re-anchor them instead of passing them through.
  function isGwPathUrl(v) {
    if (typeof v !== 'string' || !v) return false;
    if (v.indexOf('/_glpe') === 0) return true;
    try {
      return !!(window.location && window.location.origin &&
        v.indexOf(window.location.origin + '/_glpe') === 0);
    } catch (e) { return false; }
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
    if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('javascript:') || trimmed.startsWith('about:') || trimmed.startsWith('#')) {
      return trimmed;
    }
    // A reference built by the destination's own code out of a path-shaped
    // gateway URL (module loaders construct follow-up URLs from their
    // script's prefix) carries the /_glpe namespace — it must be
    // re-anchored below, gateway markers in its query and all, so it never
    // passes through as a half-gateway address.
    var gwShaped = isGwPathUrl(trimmed);
    if (!gwShaped && (trimmed.indexOf('_glpe=') !== -1 || trimmed.indexOf('?l=') !== -1 || trimmed.indexOf('&l=') !== -1)) {
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
        var isGwPath = absObj.pathname.indexOf('/_glpe') === 0;
        if (!isProxiedPage || (isOwnAppRef(absObj) && !isGwPath)) {
          return absolute;
        }
        if (isGwPath) {
          absolute = rebaseGatewayRef(absObj);
        } else {
          absolute = _ctxOrigin + absObj.pathname + absObj.search + absObj.hash;
        }
      }
      return buildGatewayUrl(absolute);
    } catch(e) {
      return trimmed;
    }
  }

  // Re-anchors a reference the destination's code constructed out of a
  // path-shaped gateway URL: strip the reserved namespace and every
  // gateway parameter from it, then put the remaining path (and any
  // non-gateway query values) back onto the destination origin — the
  // result is wrapped afresh by resolveViewUrl's caller flow, so the
  // reference rides the gateway with a token of its own.
  function rebaseGatewayRef(absObj) {
    var p = absObj.pathname.replace(/^\/_glpe/, '');
    var kept = [];
    try {
      absObj.searchParams.forEach(function(v, k) {
        if (k !== '_glpe' && k !== 'l' && k !== 'ns' && k !== 'ni' &&
            k !== 'nt' && k !== 'nb' && k !== 'mb' && k !== 'vs' &&
            k !== 'tp' && k !== 'mode') {
          kept.push(k + '=' + v);
        }
      });
    } catch (e) {}
    return _ctxOrigin + p + (kept.length ? '?' + kept.join('&') : '') + absObj.hash;
  }

  // 1. Frame-buster neutralization
  // NOTE: `window.top` / `window.parent` are [LegacyUnforgeable] —
  // non-configurable own properties on every browser — so they cannot be
  // redefined. Frame-buster scripts are instead neutralized server-side by
  // the HTML rewriter (top/parent location references are replaced before
  // the script ever reaches the browser).

  // 2. Intercept window.fetch (dynamic content, form endpoints, JSON APIs)
  if (window.fetch) {
    // Schema-identifier keys carry vocabulary URIs (validator meta-schemas)
    // — opaque identifiers, never fetchable documents; wrapping them breaks
    // the application's own schema compilation.
    var schemaIdKeys = { '$schema': 1, '$id': 1, '$ref': 1, 'id': 1 };
    function deepRewriteJson(obj, rootKey) {
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
          if (typeof val === 'string' && /^https?:\/\//i.test(val) && !schemaIdKeys[k]) {
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

  // 3a. Pre-insertion reference rewriting.
  // Dynamically spawned elements (application code bundles above all) get
  // their network fetch started at the moment of insertion — a rewrite that
  // only runs after insertion (MutationObserver) loses that race and the
  // request lands on this site's own paths. Rewriting AT ASSIGNMENT TIME
  // closes the race: the reference is already wrapped when the element is
  // inserted. Two assignment paths exist and both are covered:
  // (1) element.src/href/data property assignment — shadowed per instance
  //     at creation time with a resolving setter;
  // (2) element.setAttribute(...) — wrapped centrally.
  try {
    var urlAttrs = { src: 1, href: 1, action: 1, formaction: 1, poster: 1, data: 1 };
    var originalSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name, value) {
      try {
        var n = String(name).toLowerCase();
        // setAttribute stringifies per the spec — objects (e.g. trusted-URL
        // wrappers) must be coerced BEFORE the guard, or the native setter
        // would coerce the unwrapped value and the fetch would leave the
        // gateway path (live-caught on YouTube's player loader).
        if (urlAttrs[n] && value !== null && value !== undefined) {
          var sv = typeof value === 'string' ? value : String(value);
          if (sv && (sv.indexOf('_glpe=') === -1 || isGwPathUrl(sv))
              && !/^(data:|blob:|javascript:|about:|#)/i.test(sv) && !isOwnUi(this)) {
            value = resolveViewUrl(sv);
          }
        }
      } catch (e) {}
      return originalSetAttribute.call(this, name, value);
    };
    var originalCreateElement = document.createElement.bind(document);
    var propertyTargets = { script: 'src', img: 'src', iframe: 'src', source: 'src', video: 'src', audio: 'src', track: 'src', embed: 'data', input: 'src', link: 'href', a: 'href', area: 'href' };
    document.createElement = function(tag, opts) {
      var el = originalCreateElement(tag, opts);
      try {
        var name = String(tag || '').toLowerCase();
        var prop = propertyTargets[name];
        if (prop && el && el.nodeType === 1 && !isOwnUi(el)) {
          var desc = null;
          var proto = Object.getPrototypeOf(el);
          while (proto && !desc) {
            desc = Object.getOwnPropertyDescriptor(proto, prop);
            proto = Object.getPrototypeOf(proto);
          }
          if (desc && desc.set && desc.get && !Object.prototype.hasOwnProperty.call(el, prop)) {
            Object.defineProperty(el, prop, {
              get: function() { return desc.get.call(this); },
              set: function(v) {
                try {
                  // Property assignments coerce through the same path: a
                  // non-string value (trusted-URL object, URL instance) is
                  // stringified first — the native setter would coerce it
                  // anyway, so rewriting the coerced string keeps the fetch
                  // on the gateway instead of letting it slip out raw.
                  var sv = null;
                  if (typeof v === 'string') sv = v;
                  else if (v !== null && v !== undefined && typeof v.toString === 'function') {
                    try { sv = String(v); } catch (e2) { sv = null; }
                  }
                  if (sv && (sv.indexOf('_glpe=') === -1 || isGwPathUrl(sv)) && !/^(data:|blob:|javascript:|about:|#)/i.test(sv)) {
                    v = resolveViewUrl(sv);
                  }
                } catch (e) {}
                return desc.set.call(this, v);
              },
              configurable: true,
              enumerable: true
            });
          }
        }
      } catch (e) {}
      return el;
    };
  } catch (e) {}

  // 3b. Inline-style reference rewriting.
  // A reference can also enter the page through the CSS object model —
  // element.style.backgroundImage = 'url(...)' (image grids, challenge
  // tiles, preloaded artwork) — which skips both the attribute and the
  // markup rewriters entirely. The style declarations' url-bearing
  // properties are shadowed so every url(...) inside an assigned value is
  // re-anchored and wrapped at assignment time.
  try {
    var cssProto = window.CSSStyleDeclaration && CSSStyleDeclaration.prototype;
    if (cssProto) {
      function rewriteCssUrls(value) {
        try {
          if (typeof value === 'string' && value.indexOf('url(') !== -1 && value.indexOf('_glpe=') === -1) {
            return value.replace(/url\(\s*(['"]?)([^)'"]+)\1\s*\)/g, function(m, q, u) {
              if (/^(data:|blob:|#)/i.test(u)) return m;
              var wrapped;
              try { wrapped = resolveViewUrl(u); } catch (e) { return m; }
              return 'url(' + (q || '"') + wrapped + (q || '"') + ')';
            });
          }
        } catch (e) {}
        return value;
      }
      ['background', 'backgroundImage'].forEach(function(prop) {
        var desc = Object.getOwnPropertyDescriptor(cssProto, prop);
        if (desc && desc.set && desc.get && desc.configurable) {
          Object.defineProperty(cssProto, prop, {
            get: function() { return desc.get.call(this); },
            set: function(v) {
              return desc.set.call(this, rewriteCssUrls(v));
            },
            configurable: true,
            enumerable: true
          });
        }
      });
      var originalSetProperty = cssProto.setProperty;
      if (typeof originalSetProperty === 'function') {
        cssProto.setProperty = function(name, value, priority) {
          return originalSetProperty.call(this, name, rewriteCssUrls(value), priority);
        };
      }
    }
  } catch (e) {}

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
          // Sandbox environment: reads surface only the virtual jar — the
          // site's own records never reach the destination's scripts.
          if (ctx.vs) return vsJar;
          return originalCookieGet ? originalCookieGet.call(this) : '';
        },
        set: function(val) {
          var sep = viewScript.indexOf('?') !== -1 ? '&' : '?';
          var syncUrl = viewScript + sep + 'mode=sync';
          // The beacon keeps riding the sandbox's own jar when it came from
          // the temporary environment.
          if (ctx.vs) syncUrl += '&vs=1';
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
          if (ctx.vs) {
            // Virtual jar: keep the environment-local view coherent (last
            // assignment of a name wins; a bare expiry clears the name).
            try {
              var pair = String(val).split(';')[0];
              var eq = pair.indexOf('=');
              var name = eq > -1 ? pair.slice(0, eq).trim() : pair.trim();
              var kept = name === '' ? [] : String(vsJar).split('; ').filter(function (kv) {
                return kv.split('=')[0].trim() !== name;
              });
              var hasValue = eq > -1 && pair.slice(eq + 1).trim() !== '';
              if (hasValue) kept.push(pair.trim());
              vsJar = kept.join('; ');
            } catch (e) {}
            return;
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
  // A socket connection would carry traffic around the display pipeline
  // entirely (the site host cannot answer socket handshakes, and a direct
  // socket to the destination would leak the address in clear text), so
  // socket construction never opens a real connection. Construction used
  // to throw — that hard-crashes boot paths of stream-centric applications
  // that treat construction failure as fatal. It now yields a socket-shaped
  // object that fails exactly like a refused connection: applications with
  // stream fallbacks (server-sent events, polled exchanges) detect the
  // closed state, take their fallback route and boot normally; the rest
  // show their own connection error instead of a blank page.
  try {
    if (window.WebSocket) {
      var OriginalSocket = window.WebSocket;
      var GuardedSocket = function(url, protocols) {
        if (!(this instanceof GuardedSocket)) {
          return new GuardedSocket(url, protocols);
        }
        var self = this;
        this.url = String(url || '');
        this.readyState = 0; // CONNECTING
        this.bufferedAmount = 0;
        this.extensions = '';
        this.protocol = typeof protocols === 'string' ? protocols : (Array.isArray(protocols) ? protocols[0] || '' : '');
        this.binaryType = 'blob';
        this.onopen = null;
        this.onmessage = null;
        this.onerror = null;
        this.onclose = null;
        this.__listeners = {};
        this.__closed = false;
        function fire(type, event) {
          try {
            var handler = self['on' + type];
            if (typeof handler === 'function') handler.call(self, event);
            var list = self.__listeners[type] || [];
            for (var i = 0; i < list.length; i++) {
              try { list[i].call(self, event); } catch (e) {}
            }
          } catch (e) {}
        }
        // Asynchronous refusal — the exact shape a blocked handshake produces.
        setTimeout(function() {
          if (self.__closed) return;
          self.readyState = 3; // CLOSED
          fire('error', { type: 'error', target: self, message: 'GLPE: socket exchanges are disabled; every exchange must ride the gateway.' });
          fire('close', { type: 'close', wasClean: false, code: 1006, reason: '', target: self });
        }, 0);
        this.send = function(data) {
          // Refused sockets swallow outgoing frames like a dead connection.
          this.bufferedAmount = 0;
        };
        this.close = function(code, reason) {
          if (this.__closed || this.readyState === 3) return;
          this.__closed = true;
          this.readyState = 3;
          fire('close', { type: 'close', wasClean: true, code: typeof code === 'number' ? code : 1000, reason: String(reason || ''), target: self });
        };
        this.addEventListener = function(type, fn) {
          if (!this.__listeners[type]) this.__listeners[type] = [];
          this.__listeners[type].push(fn);
        };
        this.removeEventListener = function(type, fn) {
          var list = this.__listeners[type] || [];
          var idx = list.indexOf(fn);
          if (idx !== -1) list.splice(idx, 1);
        };
        this.dispatchEvent = function() { return true; };
      };
      try {
        GuardedSocket.CONNECTING = 0;
        GuardedSocket.OPEN = 1;
        GuardedSocket.CLOSING = 2;
        GuardedSocket.CLOSED = 3;
      } catch (e) {}
      window.WebSocket = GuardedSocket;
    }
  } catch (e) {}

  // 6b. Event streams — same-origin stream references resolve against THIS
  // site inside a displayed document (and die with a 404 against our own
  // paths), so the constructor re-anchors them onto the real target and
  // wraps them like every other dynamic reference.
  try {
    if (window.EventSource) {
      var OriginalEventSource = window.EventSource;
      var WrappedEventSource = function(url, cfg) {
        var resolved = url;
        try {
          resolved = resolveViewUrl(typeof url === 'string' ? url : String(url));
        } catch (e) {}
        return cfg === undefined
          ? new OriginalEventSource(resolved)
          : new OriginalEventSource(resolved, cfg);
      };
      WrappedEventSource.prototype = OriginalEventSource.prototype;
      try {
        WrappedEventSource.CONNECTING = OriginalEventSource.CONNECTING;
        WrappedEventSource.OPEN = OriginalEventSource.OPEN;
        WrappedEventSource.CLOSED = OriginalEventSource.CLOSED;
      } catch (e) {}
      window.EventSource = WrappedEventSource;
    }
  } catch (e) {}

  // 6c. Delivery beacons fire their own exchanges; same-origin addresses
  // would land on this site's own paths and vanish. Re-anchor them onto the
  // real target and wrap them so the ride stays on the gateway. The
  // companion's own sync channel (mode=sync) passes through untouched.
  try {
    if (navigator.sendBeacon) {
      var originalSendBeacon = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function(url, data) {
        var resolved = url;
        try {
          var raw = (typeof url === 'string') ? url : (url && typeof url.href === 'string') ? url.href : String(url);
          if (raw.indexOf('mode=sync') === -1) {
            resolved = resolveViewUrl(raw);
          }
        } catch (e) {}
        return originalSendBeacon(resolved, data);
      };
    }
  } catch (e) {}

  // 6d. Offline scripts — a registered worker would intercept every later
  // exchange with its own requests, and those requests never pass through
  // this companion (workers do not inherit page patches), silently routing
  // traffic around the display pipeline. Registration is denied in the
  // shape applications treat as "unsupported", and lookup helpers answer
  // empty; .ready stays pending so await-chains neither crash nor proceed.
  try {
    if (navigator.serviceWorker) {
      var sw = navigator.serviceWorker;
      try { sw.register = function() { return Promise.reject(new Error('GLPE: offline scripts are disabled inside the viewer.')); }; } catch (e) {}
      try { sw.getRegistration = function() { return Promise.resolve(undefined); }; } catch (e) {}
      try { sw.getRegistrations = function() { return Promise.resolve([]); }; } catch (e) {}
      try { sw.ready = new Promise(function() {}); } catch (e) {}
    }
  } catch (e) {}

  // 6e. Off-main-thread scripts spawned from blob addresses run without this
  // companion — every request they issue would ride straight against this
  // site and 404. Prepend a compact bootstrap to the blob payload so fetch
  // calls inside the worker re-anchor onto the target and ride the gateway.
  try {
    if (window.Worker && window.Blob && window.URL) {
      var OriginalWorker = window.Worker;
      var workerBootstrap =
        ';(function(){' +
        'var ctx=self.__glpe_worker_ctx__;if(!ctx||!self.fetch)return;' +
        'var KEY=ctx.k,GATE=ctx.g;' +
        'var TARGET="";try{var _b=ctx.u.replace(/-/g,"+").replace(/_/g,"/");while(_b.length%4)_b+="=";' +
        'var _r=atob(_b),_o="";for(var _i=0;_i<_r.length;_i++)_o+=String.fromCharCode(_r.charCodeAt(_i)^KEY.charCodeAt(_i%KEY.length));' +
        'TARGET=decodeURIComponent(escape(_o));}catch(e){}' +
        'var ORIGIN="";try{ORIGIN=new URL(TARGET).origin;}catch(e){}' +
        'function dec(t){try{var b=t.replace(/-/g,"+").replace(/_/g,"/");while(b.length%4)b+="=";' +
        'var raw=atob(b),o="";for(var i=0;i<raw.length;i++)o+=String.fromCharCode(raw.charCodeAt(i)^KEY.charCodeAt(i%KEY.length));' +
        'return decodeURIComponent(escape(o));}catch(e){return "";}}' +
        'function enc(s){try{var bytes=unescape(encodeURIComponent(s)),o="";' +
        'for(var i=0;i<bytes.length;i++)o+=String.fromCharCode(bytes.charCodeAt(i)^KEY.charCodeAt(i%KEY.length));' +
        'return btoa(o).replace(/\\+/g,"-").replace(/\\//g,"_").replace(/=+$/,"");}catch(e){return "";}}' +
        'var OWN=/^(\\/(wp-admin|wp-login|wp-json|wp-content|wp-includes|feed|view)(\\/|$)|\\/\\?)/i;' +
        'function wrap(u){try{if(!u||typeof u!=="string")return u;' +
        'if(/^(data:|blob:|javascript:|#)/i.test(u)||u.indexOf("_glpe=")!==-1)return u;' +
        'var a=new URL(u,ORIGIN||u);' +
        'if(a.origin===self.location.origin&&!OWN.test(a.pathname+a.search)){a=new URL(ORIGIN+a.pathname+a.search+a.hash);}' +
        'var sep=GATE.indexOf("?")!==-1?"&":"?";return GATE+sep+"l="+enc(a.href);}catch(e){return u;}}' +
        'var of=self.fetch.bind(self);' +
        'self.fetch=function(input,init){try{' +
        'if(typeof input==="string"){input=wrap(input);}' +
        'else if(input&&input.href){input=wrap(input.href);}' +
        'else if(input&&input.url){init=init||{};init.method=input.method||"GET";' +
        'if(input.headers)init.headers=input.headers;if(input.body!==undefined&&init.body===undefined)init.body=input.body;' +
        'if(input.credentials)init.credentials=input.credentials;if(input.signal)init.signal=input.signal;' +
        'input=wrap(input.url);}' +
        '}catch(e){}return of(input,init);};' +
        '})();';
      var ctxSnapshot = '';
      try {
        ctxSnapshot = 'self.__glpe_worker_ctx__=' + JSON.stringify({ k: encKey, g: viewScript, u: ctx.u || '' }) + ';';
      } catch (e) {}
      var WrappedWorker = function(url, opts) {
        var target = url;
        try {
          if (typeof url === 'string' && url.indexOf('blob:') === 0) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, false); // blob: — synchronous read is local and instant
            xhr.send(null);
            if (xhr.status === 200 && xhr.responseText && xhr.responseText.indexOf('__glpe_worker_ctx__') === -1) {
              var blob = new Blob([ctxSnapshot, workerBootstrap, xhr.responseText], { type: 'application/javascript' });
              target = URL.createObjectURL(blob);
            }
          }
        } catch (e) {}
        return opts === undefined ? new OriginalWorker(target) : new OriginalWorker(target, opts);
      };
      WrappedWorker.prototype = OriginalWorker.prototype;
      window.Worker = WrappedWorker;
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
    // History entries are upgraded to the path-carrying form so routers see
    // their own routes after the swap: the token (unchanged) validates the
    // destination, the pathname/search mirror the destination locally.
    function toHistoryUrl(url) {
      var resolved = normalizeNavUrl(url);
      try {
        if (typeof resolved === 'string') {
          var tm = resolved.match(/[?&]l=([A-Za-z0-9_-]+)/);
          if (tm) {
            var dest = cipherDecode(tm[1]);
            if (dest) return buildHistoryUrl(dest);
          }
        }
      } catch (e) {}
      return resolved;
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
          var r = nativeFn.call(this, state, title, toHistoryUrl(url));
          try { syncBarAddress(); } catch (e) {}
          return r;
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
          // Same-document transitions belong to the router (its pushState
          // is already wrapped and keeps the gateway identity inside the
          // history entry). The flag lives on the DESTINATION, not on
          // the event — reading it there (live-caught: reading
          // e.sameDocument yields undefined and the guard never fired,
          // so every SPA transition was turned into a full reload).
          if (e.destination && e.destination.sameDocument) return;
          // Self-canonicalization guard: applications "clean" their URL
          // after boot by navigating to the same route without the
          // gateway parameters (live-caught on YouTube's results router:
          // location.replace of the canonical form, once per boot). The
          // destination already IS this document — re-loading it through
          // the gateway lands on the same page and restarts the boot,
          // which re-issues the same canonical navigation: an endless
          // reload loop. Cancel and stay.
          if (dest.pathname === window.location.pathname) {
            try {
              var cur = new URL(window.location.href);
              var curP = new URLSearchParams(cur.search);
              var destP = new URLSearchParams(dest.search);
              var same = true;
              curP.forEach(function(v, k) {
                if (k === '_glpe' || k === 'l' || k === 'ns' || k === 'ni' ||
                    k === 'nt' || k === 'nb' || k === 'mb' || k === 'vs' ||
                    k === 'tp' || k === 'mode') return;
                if (destP.get(k) !== v) same = false;
              });
              destP.forEach(function(v, k) {
                if (curP.get(k) !== v) same = false;
              });
              if (same) {
                e.preventDefault();
                return;
              }
            } catch (ce) {}
          }
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

  // 8a. Current-address display — strictly local.
  // Refined tenet: the readable destination MAY be shown inside the user's
  // own browser, but it must NEVER ride an exchange between browser and
  // server. The server renders no readable destination into the markup;
  // instead the opaque token already sitting in the address bar is decoded
  // here, locally, and painted into the navigation box. The rebuilt string
  // never travels back — submissions from the box are re-wrapped by the
  // inline handler embedded in the bar itself.
  var lastBarAddress = '';
  function syncBarAddress() {
    try {
      var bar = document.getElementById('__glpe_bar');
      if (!bar) return;
      var input = bar.querySelector('input[name="l"]');
      if (!input) return;
      var m = String(window.location.href).match(/[?&]l=([A-Za-z0-9_-]+)/);
      var dest = m ? cipherDecode(m[1]) : '';
      if (!dest) dest = currentTargetUrl || '';
      if (!dest) return;
      if (document.activeElement === input) return; // never clobber typing
      input.value = dest;
      lastBarAddress = dest;
    } catch (e) {}
  }
  try {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', syncBarAddress);
    } else {
      syncBarAddress();
    }
    window.addEventListener('popstate', syncBarAddress);
    // Back/forward restores from the back-forward cache skip DOMContentLoaded
    // and popstate (different document) — pageshow is the only hook there.
    window.addEventListener('pageshow', syncBarAddress);
  } catch (e) {}
  // Typing aid: focusing the box while it still shows the current address
  // selects the whole value, so the visitor can overwrite it in one stroke.
  try {
    document.addEventListener('focusin', function(e) {
      try {
        var t = e.target;
        if (!t || t.tagName !== 'INPUT' || t.name !== 'l') return;
        if (!(t.closest && t.closest('#__glpe_bar'))) return;
        if (t.value === lastBarAddress) t.select();
      } catch (err) {}
    }, true);
  } catch (e) {}

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
