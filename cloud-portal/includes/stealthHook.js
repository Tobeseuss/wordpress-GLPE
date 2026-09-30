/**
 * Stealth Client-Side Engine Hook
 * Injected into browsed documents for seamless dynamic request interception,
 * SPA routing, frame-buster neutralization, and cookie synchronization.
 */
(function() {
  if (window.__vault_installed__) return;
  window.__vault_installed__ = true;

  var ctx = window.__portal_ctx__ || {};
  var currentTargetUrl = ctx.u || window.location.href;
  var gatewayScript = ctx.g || 'browse.php';
  var isEncoded = !!ctx.enc;
  var encKey = ctx.k || 'cp_vault_key';

  // Bi-directional XOR URL Cipher matching PHP StealthCipher
  function cipherEncode(str, key) {
    if (!str || typeof str !== 'string') return '';
    key = key || encKey;
    var out = '';
    for (var i = 0; i < str.length; i++) {
      out += String.fromCharCode(str.charCodeAt(i) ^ key.charCodeAt(i % key.length));
    }
    try {
      var b64 = btoa(unescape(encodeURIComponent(out)));
      return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    } catch(e) {
      return btoa(out).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
  }

  function resolveStreamUrl(url) {
    if (!url) return url;
    if (typeof url !== 'string') {
        if (url.toString) url = url.toString();
        else return url;
    }
    var trimmed = url.trim();
    if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('javascript:') || trimmed.startsWith('#')) {
      return trimmed;
    }
    if (trimmed.indexOf(gatewayScript) !== -1 || trimmed.indexOf('?b=') !== -1 || trimmed.indexOf('&b=') !== -1) {
      return trimmed;
    }
    try {
      var absolute = new URL(trimmed, currentTargetUrl).href;
      var sep = gatewayScript.indexOf('?') !== -1 ? '&' : '?';
      var payload = isEncoded ? cipherEncode(absolute) : encodeURIComponent(absolute);
      var flags = '';
      if (ctx.rs) flags += '&rs=1';
      if (ctx.ri) flags += '&ri=1';
      if (ctx.st) flags += '&st=1';
      if (ctx.tb) flags += '&tb=1';
      if (ctx.enc) flags += '&enc=1';
      return gatewayScript + sep + 'b=' + payload + flags;
    } catch(e) {
      return trimmed;
    }
  }

  // 1. Frame-Buster Breaker (Prevents target sites from breaking out of view/iframe)
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

  // 2. Intercept window.fetch (Crucial for modern dynamic buttons, Google Auth, AJAX)
  if (window.fetch) {
    // Recursive JSON URL rewriter for API / InnerTube / Player config responses
    function deepRewriteJson(obj) {
      if (!obj || typeof obj !== 'object') {
        if (typeof obj === 'string' && /^https?:\/\//i.test(obj)) {
          return resolveStreamUrl(obj);
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
            res[k] = resolveStreamUrl(val);
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
        input = resolveStreamUrl(input.toString());
      } else if (input && typeof input.url === 'string') {
        isRequestObj = true;
        newUrl = resolveStreamUrl(input.url);
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
      var proxied = resolveStreamUrl(url);
      if (arguments.length >= 5) return originalOpen.call(this, method, proxied, async, user, password);
      if (arguments.length === 4) return originalOpen.call(this, method, proxied, async, user);
      if (arguments.length === 3) return originalOpen.call(this, method, proxied, async);
      return originalOpen.call(this, method, proxied);
    };
  }

  // 4. Intercept dynamic HTMLFormElement submit and capture event
  if (window.HTMLFormElement) {
    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
      if (this.action) {
        this.action = resolveStreamUrl(this.action);
      }
      return originalSubmit.call(this);
    };

    window.addEventListener('submit', function(e) {
      var form = e.target;
      if (form && form.action && !form.dataset.rewritten) {
        form.action = resolveStreamUrl(form.action);
        form.dataset.rewritten = '1';
      }
    }, true);
  }

  // 5. Intercept document.cookie setter and sync with server vault
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
          var sep = gatewayScript.indexOf('?') !== -1 ? '&' : '?';
          var syncUrl = gatewayScript + sep + 'action=sync_cookie';
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
    if (url) url = resolveStreamUrl(url);
    return originalWindowOpen.call(this, url, target, features);
  };

  // 7. Intercept SPA history navigation (pushState & replaceState)
  if (window.history && window.history.pushState) {
    var origPush = window.history.pushState;
    window.history.pushState = function(state, title, url) {
      if (url && typeof url === 'string') {
        url = resolveStreamUrl(url);
      }
      return origPush.call(this, state, title, url);
    };
    var origReplace = window.history.replaceState;
    window.history.replaceState = function(state, title, url) {
      if (url && typeof url === 'string') {
        url = resolveStreamUrl(url);
      }
      return origReplace.call(this, state, title, url);
    };
  }

  // 7a. Window.location / redirect interception
  try {
    var _origLocation = window.location;
    var locProto = window.Location ? window.Location.prototype : null;
    if (locProto) {
      var origAssign = locProto.assign;
      if (origAssign) {
        locProto.assign = function(url) {
          return origAssign.call(this, resolveStreamUrl(url));
        };
      }
      var origReplaceLoc = locProto.replace;
      if (origReplaceLoc) {
        locProto.replace = function(url) {
          return origReplaceLoc.call(this, resolveStreamUrl(url));
        };
      }
    }

    Object.defineProperty(window, 'location', {
      get: function() { return _origLocation; },
      set: function(val) {
        if (typeof val === 'string') {
          _origLocation.href = resolveStreamUrl(val);
        } else {
          _origLocation.href = val;
        }
      },
      configurable: true
    });
  } catch(e) {}

  // 7b. MutationObserver to automatically proxy newly injected DOM assets and thumbnails (xHamster, YouTube, etc.)
  try {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (node && node.nodeType === 1) {
            var tag = node.tagName;
            if (tag === 'IMG' || tag === 'VIDEO' || tag === 'AUDIO' || tag === 'SOURCE' || tag === 'IFRAME') {
              var s = node.getAttribute('src');
              if (s && !s.startsWith('#') && !s.startsWith('javascript:') && s.indexOf(gatewayScript) === -1) {
                node.setAttribute('src', resolveStreamUrl(s));
              }
            } else if (tag === 'A') {
              var h = node.getAttribute('href');
              if (h && !h.startsWith('#') && !h.startsWith('javascript:') && h.indexOf(gatewayScript) === -1) {
                node.setAttribute('href', resolveStreamUrl(h));
              }
            } else if (tag === 'FORM') {
              var a = node.getAttribute('action');
              if (a && !a.startsWith('#') && !a.startsWith('javascript:') && a.indexOf(gatewayScript) === -1) {
                node.setAttribute('action', resolveStreamUrl(a));
              }
            }
            // Check data attributes and child forms/links
            var dataEls = node.querySelectorAll ? node.querySelectorAll('form, a, [data-src], [data-thumb], [data-background], [data-poster], [data-url], [data-image], [data-original], [data-bg]') : [];
            for (var i = 0; i < dataEls.length; i++) {
              var el = dataEls[i];
              if (el.tagName === 'FORM') {
                 var fa = el.getAttribute('action');
                 if (fa && !fa.startsWith('#') && !fa.startsWith('javascript:') && fa.indexOf(gatewayScript) === -1) {
                   el.setAttribute('action', resolveStreamUrl(fa));
                 }
              } else if (el.tagName === 'A') {
                 var fh = el.getAttribute('href');
                 if (fh && !fh.startsWith('#') && !fh.startsWith('javascript:') && fh.indexOf(gatewayScript) === -1) {
                   el.setAttribute('href', resolveStreamUrl(fh));
                 }
              } else {
                 ['data-src', 'data-thumb', 'data-background', 'data-poster', 'data-url', 'data-image', 'data-original', 'data-bg'].forEach(function(attr) {
                   var val = el.getAttribute(attr);
                   if (val && val.indexOf(gatewayScript) === -1) {
                     el.setAttribute(attr, resolveStreamUrl(val));
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

  // 8. Toolbar UI Toggle Handler
  window.__togglePortalToolbar = function() {
    var tb = document.getElementById('__ptb_wrap');
    var badge = document.getElementById('__ptb_badge');
    if (tb && badge) {
      if (tb.style.display === 'none') {
        tb.style.display = 'block';
        badge.style.display = 'none';
        document.body.style.marginTop = '42px';
      } else {
        tb.style.display = 'none';
        badge.style.display = 'flex';
        document.body.style.marginTop = '0px';
      }
    }
  };
})();
