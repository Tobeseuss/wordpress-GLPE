/**
 * GLPE Client-Side Companion
 * Injected into displayed documents for seamless dynamic request handling,
 * SPA navigation support, frame-buster neutralization and data synchronization.
 */
(function() {
  if (window.__glpe_installed__) return;
  window.__glpe_installed__ = true;

  var ctx = window.__glpe_ctx__ || {};
  var currentTargetUrl = ctx.u || window.location.href;
  var viewScript = ctx.g || '/';
  var isEncoded = !!ctx.enc;
  var encKey = ctx.k || 'glpe-local-key';

  // Bi-directional byte codec matching the server-side GLPE_Codec
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

  function resolveViewUrl(url) {
    if (!url) return url;
    if (typeof url !== 'string') {
        if (url.toString) url = url.toString();
        else return url;
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
      var sep = viewScript.indexOf('?') !== -1 ? '&' : '?';
      var payload = isEncoded ? cipherEncode(absolute) : encodeURIComponent(absolute);
      var flags = '';
      if (ctx.rs) flags += '&ns=1';
      if (ctx.ri) flags += '&ni=1';
      if (ctx.st) flags += '&nt=1';
      if (ctx.tb) flags += '&nb=1';
      if (ctx.enc) flags += '&ec=1';
      return viewScript + sep + 'l=' + payload + flags;
    } catch(e) {
      return trimmed;
    }
  }

  // 1. Frame-buster neutralization
  try {
    Object.defineProperty(window, 'top', {
      get: function() { return window.self; },
      set: function() {}
    });
    Object.defineProperty(window, 'parent', {
      get: function() { return window.self; },
      set: function() {}
    });
  } catch(e) {}

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
    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
      if (this.action) {
        this.action = resolveViewUrl(this.action);
      }
      return originalSubmit.call(this);
    };

    window.addEventListener('submit', function(e) {
      var form = e.target;
      if (form && form.action && !form.dataset.rewritten) {
        form.action = resolveViewUrl(form.action);
        form.dataset.rewritten = '1';
      }
    }, true);
  }

  // 5. Sync client-side session data with the server-side store
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
          if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('url', currentTargetUrl);
            data.append('cookie', val);
            navigator.sendBeacon(syncUrl, data);
          } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', syncUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('url=' + encodeURIComponent(currentTargetUrl) + '&cookie=' + encodeURIComponent(val));
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

  // 7. Intercept SPA navigation (pushState & replaceState)
  if (window.history && window.history.pushState) {
    var origPush = window.history.pushState;
    window.history.pushState = function(state, title, url) {
      if (url && typeof url === 'string') {
        url = resolveViewUrl(url);
      }
      return origPush.call(this, state, title, url);
    };
    var origReplace = window.history.replaceState;
    window.history.replaceState = function(state, title, url) {
      if (url && typeof url === 'string') {
        url = resolveViewUrl(url);
      }
      return origReplace.call(this, state, title, url);
    };
  }

  // 7a. Location / redirect interception
  try {
    var _origLocation = window.location;
    var locProto = window.Location ? window.Location.prototype : null;
    if (locProto) {
      var origAssign = locProto.assign;
      if (origAssign) {
        locProto.assign = function(url) {
          return origAssign.call(this, resolveViewUrl(url));
        };
      }
      var origReplaceLoc = locProto.replace;
      if (origReplaceLoc) {
        locProto.replace = function(url) {
          return origReplaceLoc.call(this, resolveViewUrl(url));
        };
      }
    }

    Object.defineProperty(window, 'location', {
      get: function() { return _origLocation; },
      set: function(val) {
        if (typeof val === 'string') {
          _origLocation.href = resolveViewUrl(val);
        } else {
          _origLocation.href = val;
        }
      },
      configurable: true
    });
  } catch(e) {}

  // 7b. MutationObserver for dynamically injected elements
  try {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node && node.nodeType === 1) {
            var tag = node.tagName;
            if (tag === 'IMG' || tag === 'VIDEO' || tag === 'AUDIO' || tag === 'SOURCE' || tag === 'IFRAME') {
              var s = node.getAttribute('src');
              if (s && !s.startsWith('#') && !s.startsWith('javascript:') && s.indexOf('_glpe=') === -1) {
                node.setAttribute('src', resolveViewUrl(s));
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
