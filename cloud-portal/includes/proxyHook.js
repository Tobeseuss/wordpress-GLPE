/**
 * NewGlype Universal Client-Side Interceptor Hook for PHP Standalone
 * Injected into every proxied web page.
 * Overrides fetch, XHR, document.cookie, and form submits to route requests
 * through proxy.php even on traditional PHP hosting.
 */
(function() {
  if (window.__NEWGLYPE_HOOK_INSTALLED__) return;
  window.__NEWGLYPE_HOOK_INSTALLED__ = true;

  var currentTargetUrl = window.__NEWGLYPE_TARGET_URL__ || window.location.href;
  var proxyGatewayUrl = window.__NEWGLYPE_GATEWAY_URL__ || 'proxy.php';

  function resolveUrl(url) {
    if (!url || typeof url !== 'string') return url;
    var trimmed = url.trim();
    if (trimmed.startsWith('data:') || trimmed.startsWith('blob:') || trimmed.startsWith('javascript:') || trimmed.startsWith('#')) {
      return trimmed;
    }
    if (trimmed.indexOf(proxyGatewayUrl) !== -1) {
      return trimmed;
    }
    try {
      var absolute = new URL(trimmed, currentTargetUrl).href;
      return proxyGatewayUrl + '?url=' + encodeURIComponent(absolute);
    } catch(e) {
      return trimmed;
    }
  }

  // 1. Intercept window.fetch (Crucial for Google Login, SPA dynamic buttons)
  if (window.fetch) {
    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
      if (typeof input === 'string') {
        input = resolveUrl(input);
      } else if (input && typeof input.url === 'string') {
        try {
          var newUrl = resolveUrl(input.url);
          input = new Request(newUrl, input);
        } catch (e) {}
      }
      return originalFetch.call(this, input, init);
    };
  }

  // 2. Intercept XMLHttpRequest (Crucial for AJAX calls)
  if (window.XMLHttpRequest) {
    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
      var proxied = resolveUrl(url);
      return originalOpen.call(this, method, proxied, async !== false, user, password);
    };
  }

  // 3. Intercept dynamic HTMLFormElement submit
  if (window.HTMLFormElement) {
    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function() {
      if (this.action) {
        this.action = resolveUrl(this.action);
      }
      return originalSubmit.call(this);
    };
  }

  // 4. Intercept document.cookie setter and sync with proxy.php
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
          if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('url', currentTargetUrl);
            data.append('cookie', val);
            navigator.sendBeacon(proxyGatewayUrl + '?action=sync_cookie', data);
          } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', proxyGatewayUrl + '?action=sync_cookie', true);
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

  // 5. Intercept window.open
  var originalWindowOpen = window.open;
  window.open = function(url, target, features) {
    if (url) url = resolveUrl(url);
    return originalWindowOpen.call(this, url, target, features);
  };
})();
