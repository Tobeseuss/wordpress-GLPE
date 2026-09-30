<?php
/**
 * Modernized Stealth Portal Engine
 * Full feature parity with classic Glype proxy + modern HTML5/ES6/RFC6265 capabilities.
 * Zero external dependencies. Compatible with PHP 7.2 - 8.3.
 */

require_once __DIR__ . '/CookieJar.php';
require_once __DIR__ . '/StealthCipher.php';

class StealthPortalEngine {
    private $cookieJar;
    private $gatewayScript;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function __construct($gatewayScript = 'browse.php', $isTempCookies = false) {
        $this->cookieJar = new StealthCookieJar($isTempCookies);
        $this->gatewayScript = $gatewayScript;
    }

    public function getCookieJar() {
        return $this->cookieJar;
    }

    public function isBlockedHost($host) {
        $host = strtolower(trim($host));
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal', '169.254.169.254'])) {
            return true;
        }
        $ip = ip2long($host);
        if ($ip !== false) {
            if (($ip & 0xFF000000) === 0x7F000000) return true;
            if (($ip & 0xFF000000) === 0x0A000000) return true;
            if (($ip & 0xFFF00000) === 0xAC100000) return true;
            if (($ip & 0xFFFF0000) === 0xC0A80000) return true;
            if (($ip & 0xFFFF0000) === 0xA9FE0000) return true;
        }
        return false;
    }

    /**
     * Converts relative or absolute URLs into disguised stream URLs.
     */
    public function makeStreamUrl($targetUrl, $baseUrl = null, $options = []) {
        if (empty($targetUrl)) return $targetUrl;
        $trimmed = trim($targetUrl);

        if (
            strpos($trimmed, 'data:') === 0 ||
            strpos($trimmed, 'blob:') === 0 ||
            strpos($trimmed, 'javascript:') === 0 ||
            strpos($trimmed, '#') === 0 ||
            strpos($trimmed, 'browse.php') !== false ||
            strpos($trimmed, '?b=') !== false ||
            strpos($trimmed, '&b=') !== false
        ) {
            return $trimmed;
        }

        $resolved = $trimmed;
        if ($baseUrl) {
            $resolved = $this->resolveRelativeUrl($trimmed, $baseUrl);
        }

        $isEncoded = !empty($options['encodeURL']);
        $payload = $isEncoded ? StealthCipher::encode($resolved) : urlencode($resolved);

        $sep = (strpos($this->gatewayScript, '?') !== false) ? '&' : '?';
        $streamUrl = $this->gatewayScript . $sep . 'b=' . $payload;

        // Preserve flags in child links
        if (!empty($options['removeScripts'])) $streamUrl .= '&rs=1';
        if (!empty($options['removeImages']))  $streamUrl .= '&ri=1';
        if (!empty($options['stripTitle']))    $streamUrl .= '&st=1';
        if (!empty($options['showToolbar']))   $streamUrl .= '&tb=1';
        if (!empty($options['encodeURL']))     $streamUrl .= '&enc=1';

        return $streamUrl;
    }

    /**
     * Resolves a relative URL against a base URL according to RFC 3986.
     */
    public function resolveRelativeUrl($rel, $base) {
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
        if (strpos($rel, '//') === 0) {
            $baseScheme = parse_url($base, PHP_URL_SCHEME);
            return ($baseScheme ? $baseScheme : 'https') . ':' . $rel;
        }
        if ($rel[0] == '#' || $rel[0] == '?') return $base . $rel;

        extract(parse_url($base));
        $path = isset($path) ? preg_replace('#/[^/]*$#', '', $path) : '';
        if ($rel[0] == '/') $path = '';

        $abs = "$host$path/$rel";
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

        $scheme = isset($scheme) ? $scheme : 'https';
        $portStr = (isset($port) && $port != 80 && $port != 443) ? ':' . $port : '';
        return $scheme . '://' . $abs;
    }

    /**
     * Rewrites CSS url(...) and @import references.
     */
    public function rewriteCss($css, $baseUrl, $options = []) {
        // 1. Rewrite @import '...' and @import "..."
        $css = preg_replace_callback('/@import\s+[\'"](.*?)[\'"]/i', function($m) use ($baseUrl, $options) {
            return '@import "' . $this->makeStreamUrl($m[1], $baseUrl, $options) . '"';
        }, $css);

        // 2. Rewrite url(...)
        return preg_replace_callback('/url\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', function($matches) use ($baseUrl, $options) {
            $url = trim($matches[1]);
            if (strpos($url, 'data:') === 0 || strpos($url, 'blob:') === 0 || strpos($url, '#') === 0) {
                return $matches[0];
            }
            $stream = $this->makeStreamUrl($url, $baseUrl, $options);
            return 'url("' . $stream . '")';
        }, $css);
    }

    /**
     * Rewrites responsive srcset attributes (comma-separated URL descriptor pairs).
     */
    public function rewriteSrcset($srcset, $baseUrl, $options = []) {
        $parts = explode(',', $srcset);
        $rewritten = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            $chunks = preg_split('/\s+/', $part, 2);
            $url = $chunks[0];
            $descriptor = isset($chunks[1]) ? ' ' . $chunks[1] : '';
            $rewritten[] = $this->makeStreamUrl($url, $baseUrl, $options) . $descriptor;
        }
        return implode(', ', $rewritten);
    }

    /**
     * Rewrites JS content (replaces hardcoded URLs)
     */
    public function rewriteJs($js, $baseUrl, $options = []) {
        if (empty($js)) return $js;
        return preg_replace_callback('/([\'"])(https?:\/\/[^\'"]+)\1/i', function($matches) use ($baseUrl, $options) {
            $quote = $matches[1];
            $url = $matches[2];
            $stream = $this->makeStreamUrl($url, $baseUrl, $options);
            return $quote . $stream . $quote;
        }, $js);
    }

    /**
     * Generates the Glype-style Floating Top Navigation Toolbar.
     */
    private function generateToolbarHtml($targetUrl, $options = []) {
        $homeUrl = (strpos($this->gatewayScript, 'browse.php') !== false) ? 'index.php' : home_url('/portal/');
        $rawTarget = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
        $encChecked = !empty($options['encodeURL']) ? 'checked' : '';
        $rsChecked  = !empty($options['removeScripts']) ? 'checked' : '';
        $riChecked  = !empty($options['removeImages']) ? 'checked' : '';
        $stChecked  = !empty($options['stripTitle']) ? 'checked' : '';

        return '
        <!-- Portal Floating Navigation Toolbar -->
        <div id="__ptb_wrap" style="position:fixed; top:0; left:0; right:0; height:42px; background:#0f172a; color:#f8fafc; font-family:tahoma,sans-serif; font-size:12px; z-index:2147483647; display:flex; align-items:center; justify-content:space-between; padding:0 12px; box-shadow:0 2px 10px rgba(0,0,0,0.3); border-bottom:1px solid #334155; direction:rtl;">
            <div style="display:flex; align-items:center; gap:8px; flex:1; max-width:700px;">
                <a href="' . esc_attr($homeUrl) . '" style="color:#38bdf8; text-decoration:none; font-weight:bold; display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:6px; background:#1e293b; white-space:nowrap;">
                    🏠 صفحه اصلی
                </a>
                <form action="' . esc_attr($this->gatewayScript) . '" method="GET" style="display:flex; gap:6px; flex:1; margin:0;" onsubmit="event.preventDefault(); var v = this.b.value; if(!v.match(/^https?:/i)) v=\'https://\'+v; var enc = this.enc && this.enc.value==\'1\'; var q = \'?b=\' + (enc ? window.btoa(v).replace(/\+/g, \'-\').replace(/\//g, \'_\').replace(/=/g, \'\') : encodeURIComponent(v)) + \'&tb=\' + (this.tb.value) + (enc ? \'&enc=1\' : \'\'); ' . ($rsChecked ? 'q+=\'&rs=1\';' : '') . ' ' . ($riChecked ? 'q+=\'&ri=1\';' : '') . ' ' . ($stChecked ? 'q+=\'&st=1\';' : '') . ' window.location.href = \'' . esc_attr($this->gatewayScript) . '\' + q;">
                    <input type="text" name="b" value="' . $rawTarget . '" style="flex:1; background:#1e293b; border:1px solid #475569; color:#f8fafc; padding:4px 10px; border-radius:6px; font-size:12px; font-family:monospace; outline:none;" placeholder="https://...">
                    <input type="hidden" name="tb" value="1">
                    ' . ($encChecked ? '<input type="hidden" name="enc" value="1">' : '') . '
                    <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:4px 12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; white-space:nowrap;">
                        برو ↵
                    </button>
                </form>
            </div>
            <div style="display:flex; align-items:center; gap:12px; font-size:11px; color:#cbd5e1; margin-right:12px;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $encChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'enc\',\'1\'):u.searchParams.delete(\'enc\'); window.location.href=u.href;"> کدگذاری آدرس
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $stChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'st\',\'1\'):u.searchParams.delete(\'st\'); window.location.href=u.href;"> پنهان‌سازی عنوان
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $rsChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'rs\',\'1\'):u.searchParams.delete(\'rs\'); window.location.href=u.href;"> حذف اسکریپت
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $riChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'ri\',\'1\'):u.searchParams.delete(\'ri\'); window.location.href=u.href;"> حذف تصویر
                </label>
                <button type="button" onclick="window.__togglePortalToolbar()" style="background:#334155; color:#94a3b8; border:none; padding:3px 8px; border-radius:4px; cursor:pointer;" title="بستن نوار ابزار">
                    ✕
                </button>
            </div>
        </div>
        <!-- Floating Reopen Badge -->
        <div id="__ptb_badge" onclick="window.__togglePortalToolbar()" style="position:fixed; top:10px; right:10px; width:28px; height:28px; background:#0f172a; color:#38bdf8; border:1px solid #334155; border-radius:50%; display:none; align-items:center; justify-content:center; cursor:pointer; z-index:2147483647; font-size:14px; box-shadow:0 2px 8px rgba(0,0,0,0.3);" title="نمایش نوار ابزار پرتال">
            ⚡
        </div>
        <script>document.body.style.marginTop = "42px";</script>
        ';
    }

    /**
     * Full HTML Rewriter with Glype Parity:
     * - Disguised links, assets, forms, styles, scripts
     * - Frame-Buster defeat
     * - Strip title (preventing destination site from showing in tab/history)
     * - Responsive image srcset rewriting
     * - Client-side stealth hook injection
     * - Floating toolbar injection
     */
    public function rewriteHtml($html, $targetUrl, $options = []) {
        $removeScripts = !empty($options['removeScripts']);
        $removeImages  = !empty($options['removeImages']);
        $stripTitle    = !empty($options['stripTitle']);
        $showToolbar   = !empty($options['showToolbar']);

        // 1. Neutralize Frame-Busting Code (e.g. if(top!=self) top.location = self.location)
        $html = preg_replace('/(\btop\.location|\bparent\.location|\bwindow\.top\.location)/i', 'window.__safe_loc', $html);

        // 2. Remove meta CSP and X-Frame-Options
        $html = preg_replace('/<meta[^>]+http-equiv=[\'"]?(Content-Security-Policy|X-Frame-Options)[\'"]?[^>]*>/i', '', $html);

        // 3. Strip or Disguise Page Title (Glype stripTitle feature)
        if ($stripTitle) {
            $html = preg_replace('/<title\b[^>]*>(.*?)<\/title>/is', '<title>سند وب | Web Viewer</title>', $html);
        }

        // 4. Handle meta refresh redirects
        $html = preg_replace_callback('/<meta[^>]+http-equiv=[\'"]?refresh[\'"]?[^>]*content=([\'"])(.*?)\1[^>]*>/i', function($m) use ($targetUrl, $options) {
            if (preg_match('/url=(.*?)$/i', $m[2], $urlMatch)) {
                $refreshed = $this->makeStreamUrl($urlMatch[1], $targetUrl, $options);
                return preg_replace('/url=.*?$/i', 'url=' . $refreshed, $m[0]);
            }
            return $m[0];
        }, $html);

        // 5. Remove scripts if requested
        if ($removeScripts) {
            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
            $html = preg_replace('/\son\w+=["\'][^"\']*["\']/i', '', $html);
        }

        // 6. Remove or Rewrite images & responsive srcset
        if ($removeImages) {
            $html = preg_replace('/<img\b[^>]*>/i', '<span style="display:inline-block; padding:4px 8px; font-size:11px; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:4px;">[تصویر حذف شد]</span>', $html);
            $html = preg_replace('/<picture\b[^>]*>(.*?)<\/picture>/is', '', $html);
        } else {
            // Rewrite <img> src
            $html = preg_replace_callback('/<img\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<img' . $m[1] . 'src=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
            }, $html);

            // Rewrite <img> and <source> srcset
            $html = preg_replace_callback('/<(img|source)\b([^>]*?)\bsrcset=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<' . $m[1] . $m[2] . 'srcset=' . $m[3] . $this->rewriteSrcset($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
            }, $html);

            // Rewrite data-src and data-url for lazy loaders
            $html = preg_replace_callback('/<(img|source)\b([^>]*?)\b(data-src|data-url|data-original)=([\'"])(.*?)\4([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<' . $m[1] . $m[2] . $m[3] . '=' . $m[4] . $this->makeStreamUrl($m[5], $targetUrl, $options) . $m[4] . $m[6] . '>';
            }, $html);
        }

        // 7. Rewrite <video poster="..." src="..."> and <audio src="...">
        $html = preg_replace_callback('/<(video|audio|track|embed|object)\b([^>]*?)\bsrc=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . 'src=' . $m[3] . $this->makeStreamUrl($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
        }, $html);

        $html = preg_replace_callback('/<video\b([^>]*?)\bposter=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<video' . $m[1] . 'poster=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 8. Rewrite <a href="...">
        $html = preg_replace_callback('/<a\b([^>]*?)\bhref=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<a' . $m[1] . 'href=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 9. Rewrite <form action="...">
        $html = preg_replace_callback('/<form\b([^>]*?)\baction=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<form' . $m[1] . 'action=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 10. Rewrite <link href="..."> (stylesheets, icons, fonts)
        $html = preg_replace_callback('/<link\b([^>]*?)\bhref=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<link' . $m[1] . 'href=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 11. Rewrite <script src="...">
        if (!$removeScripts) {
            $html = preg_replace_callback('/<script\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<script' . $m[1] . 'src=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
            }, $html);

            // Rewrite inline scripts
            $html = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function($m) use ($targetUrl, $options) {
                $innerJs = $m[2];
                if (strpos($innerJs, '__ptb_wrap') !== false || strpos($innerJs, 'resolveStreamUrl') !== false || trim($innerJs) === '') {
                    return $m[0];
                }
                $rewrittenJs = $this->rewriteJs($innerJs, $targetUrl, $options);
                return '<script' . $m[1] . '>' . $rewrittenJs . '</script>';
            }, $html);
        }

        // 12. Rewrite <iframe src="...">
        $html = preg_replace_callback('/<iframe\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<iframe' . $m[1] . 'src=' . $m[2] . $this->makeStreamUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 13. Rewrite inline <style>...</style>
        $html = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function($m) use ($targetUrl, $options) {
            return '<style' . $m[1] . '>' . $this->rewriteCss($m[2], $targetUrl, $options) . '</style>';
        }, $html);

        // 14. Rewrite inline style="..." attributes
        $html = preg_replace_callback('/\bstyle=([\'"])(.*?)\1/is', function($m) use ($targetUrl, $options) {
            return 'style=' . $m[1] . $this->rewriteCss($m[2], $targetUrl, $options) . $m[1];
        }, $html);

        // 15. Rewrite SVG <use href="..."> and <image href="...">
        $html = preg_replace_callback('/<(use|image)\b([^>]*?)\b(href|xlink:href)=([\'"])(.*?)\4([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . $m[3] . '=' . $m[4] . $this->makeStreamUrl($m[5], $targetUrl, $options) . $m[4] . $m[6] . '>';
        }, $html);

        // 16. Inject Stealth Client-Side Hook
        if (!$removeScripts) {
            $hookScript = file_get_contents(__DIR__ . '/stealthHook.js');
            $ctxConfig = [
                'u'   => $targetUrl,
                'g'   => $this->gatewayScript,
                'enc' => !empty($options['encodeURL']),
                'k'   => 'cp_vault_key',
                'tb'  => $showToolbar,
                'rs'  => $removeScripts,
                'ri'  => $removeImages,
                'st'  => $stripTitle,
            ];
            $injection = "\n<script>\n" .
                "window.__portal_ctx__ = " . json_encode($ctxConfig) . ";\n" .
                $hookScript .
                "\n</script>\n";

            if (stripos($html, '<head>') !== false) {
                $html = preg_replace('/<head>/i', '<head>' . $injection, $html, 1);
            } else {
                $html = $injection . $html;
            }
        }

        // 17. Inject Floating Top Navigation Bar (Toolbar)
        if ($showToolbar) {
            $toolbarHtml = $this->generateToolbarHtml($targetUrl, $options);
            if (stripos($html, '<body') !== false) {
                $html = preg_replace('/<body\b([^>]*)>/i', '<body$1>' . $toolbarHtml, $html, 1);
            } else {
                $html = $toolbarHtml . $html;
            }
        }

        return $html;
    }

    /**
     * Executes the HTTP request with realistic desktop browser footprint and SSRF protection.
     */
    public function executeRequest($targetUrl, $method = 'GET', $postData = null, $customHeaders = []) {
        $parsed = parse_url($targetUrl);
        if (!isset($parsed['host'])) {
            throw new Exception("آدرس وارد شده نامعتبر است.");
        }

        if ($this->isBlockedHost($parsed['host'])) {
            throw new Exception("دسترسی به آدرس‌های داخلی و شبکه محلی مسدود است (SSRF Guard).");
        }

        $cookieHeader = $this->cookieJar->getCookieHeader($targetUrl);

        $headers = [];
        $headerNames = [];
        foreach($customHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
            $headerNames[] = strtolower($k);
        }
        
        if (!in_array('user-agent', $headerNames)) {
            $headers[] = 'User-Agent: ' . $this->userAgent;
            $headers[] = 'Sec-Ch-Ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"';
            $headers[] = 'Sec-Ch-Ua-Mobile: ?0';
            $headers[] = 'Sec-Ch-Ua-Platform: "Windows"';
        }
        if (!in_array('accept', $headerNames)) {
            $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
        }
        if (!in_array('accept-language', $headerNames)) {
            $headers[] = 'Accept-Language: fa,en-US;q=0.9,en;q=0.8';
        }
        if (!in_array('referer', $headerNames)) {
            $headers[] = 'Referer: ' . $parsed['scheme'] . '://' . $parsed['host'] . '/';
        }
        if (!in_array('sec-fetch-dest', $headerNames)) {
            $headers[] = 'Sec-Fetch-Dest: document';
            $headers[] = 'Sec-Fetch-Mode: navigate';
            $headers[] = 'Sec-Fetch-Site: none';
            $headers[] = 'Sec-Fetch-User: ?1';
        }
        $headers[] = 'Upgrade-Insecure-Requests: 1';

        if (!empty($cookieHeader)) {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Auto decompress gzip/deflate/br

            if ($method === 'POST' && !empty($postData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }

            $rawResponse = curl_exec($ch);
            if ($rawResponse === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new Exception("خطای اتصال شبکه: " . $err);
            }

            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $body = substr($rawResponse, $headerSize);

            $headerLines = explode("\r\n", $rawHeaders);
            foreach ($headerLines as $line) {
                if (stripos($line, 'Set-Cookie:') === 0) {
                    $cookieVal = trim(substr($line, 11));
                    $this->cookieJar->addCookieFromHeader($cookieVal, $targetUrl);
                }
            }

            return [
                'status' => $httpCode ? $httpCode : 200,
                'contentType' => $contentType ? $contentType : 'text/html; charset=UTF-8',
                'body' => $body,
            ];
        } else {
            // Fallback: stream context
            $opts = [
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ];
            if ($method === 'POST' && !empty($postData)) {
                $opts['http']['content'] = is_array($postData) ? http_build_query($postData) : $postData;
            }

            $context = stream_context_create($opts);
            $body = @file_get_contents($targetUrl, false, $context);
            if ($body === false) {
                throw new Exception("عدم امکان دریافت اطلاعات از سرور مقصد.");
            }

            $contentType = 'text/html; charset=UTF-8';
            if (isset($http_response_header)) {
                foreach ($http_response_header as $hdr) {
                    if (stripos($hdr, 'Set-Cookie:') === 0) {
                        $this->cookieJar->addCookieFromHeader(trim(substr($hdr, 11)), $targetUrl);
                    }
                    if (stripos($hdr, 'Content-Type:') === 0) {
                        $contentType = trim(substr($hdr, 13));
                    }
                }
            }

            return [
                'status' => 200,
                'contentType' => $contentType,
                'body' => $body,
            ];
        }
    }
}
