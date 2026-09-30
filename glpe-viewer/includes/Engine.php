<?php
/**
 * GLPE Display Engine
 * Fetches a requested web document over the standard WordPress HTTP layer,
 * then rewrites its internal references so navigation keeps working inside the site.
 * Zero external dependencies. Compatible with PHP 7.2 - 8.3.
 */

require_once __DIR__ . '/Cookies.php';
require_once __DIR__ . '/Codec.php';

class GLPE_Engine {
    private $cookies;
    private $viewScript;
    private $ownHost = '';
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function __construct($viewScript = '', $isTempCookies = false) {
        $this->cookies = new GLPE_Cookies($isTempCookies);
        $this->viewScript = $viewScript;
        $own = strtolower((string)parse_url((string)$viewScript, PHP_URL_HOST));
        if ($own !== '') {
            $this->ownHost = $own;
        }
    }

    public function getCookies() {
        return $this->cookies;
    }

    public function isBlockedHost($host) {
        $host = strtolower(trim($host));
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'metadata.google.internal', '169.254.169.254'])) {
            return true;
        }
        $ip = ip2long($host);
        if ($ip !== false) {
            if (($ip & 0xFF000000) === 0x7F000000) return true;   // loopback
            if (($ip & 0xFF000000) === 0x0A000000) return true;   // 10/8
            if (($ip & 0xFFF00000) === 0xAC100000) return true;   // 172.16/12
            if (($ip & 0xFFFF0000) === 0xC0A80000) return true;   // 192.168/16
            if (($ip & 0xFFFF0000) === 0xA9FE0000) return true;   // link-local
        }
        return false;
    }

    /**
     * Converts relative or absolute references into internal short links.
     */
    public function makeViewUrl($targetUrl, $baseUrl = null, $options = []) {
        if (empty($targetUrl)) return $targetUrl;
        $trimmed = trim($targetUrl);

        // Attribute values arrive as raw HTML text where ampersands are
        // entity-encoded ("&amp;" for "&"). Decode them so the wrapped
        // reference matches the URL a browser would actually request.
        if (strpos($trimmed, '&') !== false) {
            $decoded = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $trimmed = $decoded;
            }
        }

        if (
            strpos($trimmed, 'data:') === 0 ||
            strpos($trimmed, 'blob:') === 0 ||
            strpos($trimmed, 'javascript:') === 0 ||
            strpos($trimmed, '#') === 0 ||
            strpos($trimmed, '_glpe=') !== false ||
            strpos($trimmed, '?l=') !== false ||
            strpos($trimmed, '&l=') !== false
        ) {
            return $trimmed;
        }

        $resolved = $trimmed;
        if ($baseUrl) {
            $resolved = $this->resolveRelativeUrl($trimmed, $baseUrl);
        }

        // References that already point back at this site stay untouched,
        // so own pages (viewer form, toolbar home link) never get wrapped.
        if ($this->ownHost !== '' && preg_match('#^https?://#i', $resolved)) {
            $refHost = strtolower((string)parse_url($resolved, PHP_URL_HOST));
            if ($refHost === $this->ownHost) {
                return $resolved;
            }
        }

        $isEncoded = !empty($options['encodeURL']);
        $payload = $isEncoded ? GLPE_Codec::encode($resolved) : rawurlencode($resolved);

        $sep = (strpos($this->viewScript, '?') !== false) ? '&' : '?';
        $viewUrl = $this->viewScript . $sep . 'l=' . $payload;

        // Keep display flags on child links
        if (!empty($options['removeScripts'])) $viewUrl .= '&ns=1';
        if (!empty($options['removeImages']))  $viewUrl .= '&ni=1';
        if (!empty($options['stripTitle']))    $viewUrl .= '&nt=1';
        if (!empty($options['showToolbar']))   $viewUrl .= '&nb=1';
        if (!empty($options['encodeURL']))     $viewUrl .= '&ec=1';

        return $viewUrl;
    }

    /**
     * Resolves a relative reference against a base URL according to RFC 3986.
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

        if ($rel[0] == '/') {
            $abs = $host . $rel;
        } else {
            $abs = $host . $path . '/' . $rel;
        }

        // Dot-segment removal (RFC 3986 §5.2.4) — query/fragment parts are
        // split off first: a "//" or "/./" inside a query value (e.g.
        // "?continue=https://mail.example/x") must never be collapsed.
        $queryFrag = '';
        $qpos = strpos($abs, '?');
        $fpos = strpos($abs, '#');
        if ($qpos !== false && ($fpos === false || $qpos < $fpos)) {
            $queryFrag = substr($abs, $qpos);
            $abs = substr($abs, 0, $qpos);
        } elseif ($fpos !== false) {
            $queryFrag = substr($abs, $fpos);
            $abs = substr($abs, 0, $fpos);
        }
        $re = array('#/\./#', '#/(?!\.\.)[^/]+/\.\./#');
        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

        $scheme = isset($scheme) ? $scheme : 'https';
        $portStr = (isset($port) && $port != 80 && $port != 443) ? ':' . $port : '';
        return $scheme . '://' . $abs . $queryFrag;
    }

    /**
     * Rewrites CSS url(...) and @import references.
     */
    public function rewriteCss($css, $baseUrl, $options = []) {
        $css = preg_replace_callback('/@import\s+[\'"](.*?)[\'"]/i', function($m) use ($baseUrl, $options) {
            return '@import "' . $this->makeViewUrl($m[1], $baseUrl, $options) . '"';
        }, $css);

        return preg_replace_callback('/url\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', function($matches) use ($baseUrl, $options) {
            $url = trim($matches[1]);
            if (strpos($url, 'data:') === 0 || strpos($url, 'blob:') === 0 || strpos($url, '#') === 0) {
                return $matches[0];
            }
            $link = $this->makeViewUrl($url, $baseUrl, $options);
            return 'url("' . $link . '")';
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
            $rewritten[] = $this->makeViewUrl($url, $baseUrl, $options) . $descriptor;
        }
        return implode(', ', $rewritten);
    }

    /**
     * Rewrites inline script content (replaces hardcoded absolute references).
     */
    public function rewriteJs($js, $baseUrl, $options = []) {
        if (empty($js)) return $js;
        return preg_replace_callback('/([\'"])(https?:\/\/[^\'"]+)\1/i', function($matches) use ($baseUrl, $options) {
            $quote = $matches[1];
            $url = $matches[2];
            // Specification and namespace URIs embedded in code (SVG factories,
            // markup vocabularies, document type definitions) are opaque
            // identifiers, not fetchable documents — rewriting them breaks the
            // page's own scripts, so they always pass through untouched.
            if (preg_match('#^https?://(www\\.)?(w3\\.org|whatwg\\.org|schema\\.org|ogp\\.me|gmpg\\.org|purl\\.org|xmlsoap\\.org|openxmlformats\\.org|ns\\.adobe\\.com|java\\.sun\\.com|xmlns\\.jcp\\.org|xml\\.apache\\.org|ietf\\.org|rfc-editor\\.org)(/|$)#i', $url)
                || preg_match('#^https?://schemas\\.#i', $url)
                || preg_match('#\\.dtd$#i', $url)) {
                return $matches[0];
            }
            $link = $this->makeViewUrl($url, $baseUrl, $options);
            return $quote . $link . $quote;
        }, $js);
    }

    /**
     * Generates the floating top navigation bar.
     */
    private function generateToolbarHtml($targetUrl, $options = []) {
        $slug = trim((string)(function_exists('get_option') ? get_option('glpe_slug', 'view') : 'view'), '/');
        $homeUrl = home_url('/' . ($slug !== '' ? $slug : 'view') . '/');
        $rawTarget = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
        $ecChecked = !empty($options['encodeURL']) ? 'checked' : '';
        $nsChecked = !empty($options['removeScripts']) ? 'checked' : '';
        $niChecked = !empty($options['removeImages']) ? 'checked' : '';
        $ntChecked = !empty($options['stripTitle']) ? 'checked' : '';
        $gw = esc_attr($this->viewScript);

        return '
        <!-- Floating Navigation Bar -->
        <div id="__glpe_bar" style="position:fixed; top:0; left:0; right:0; height:42px; background:#0f172a; color:#f8fafc; font-family:tahoma,sans-serif; font-size:12px; z-index:2147483647; display:flex; align-items:center; justify-content:space-between; padding:0 12px; box-shadow:0 2px 10px rgba(0,0,0,0.3); border-bottom:1px solid #334155; direction:rtl;">
            <div style="display:flex; align-items:center; gap:8px; flex:1; max-width:700px;">
                <a href="' . esc_attr($homeUrl) . '" style="color:#38bdf8; text-decoration:none; font-weight:bold; display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:6px; background:#1e293b; white-space:nowrap;">
                    🏠 صفحه اصلی
                </a>
                <form action="' . $gw . '" method="GET" style="display:flex; gap:6px; flex:1; margin:0;" onsubmit="event.preventDefault(); var gw=\'' . $gw . '\'; var sep = gw.indexOf(\'?\') !== -1 ? \'&\' : \'?\'; var v = this.l.value; if(!v.match(/^https?:/i)) v=\'https://\'+v; var enc = this.ec && this.ec.value==\'1\'; var bytes = unescape(encodeURIComponent(v)); var out = []; var key = (window.__glpe_ctx__ && window.__glpe_ctx__.k) || \'glpe-local-key\'; for (var i = 0; i < bytes.length; i++) { out.push(String.fromCharCode(bytes.charCodeAt(i) ^ key.charCodeAt(i % key.length))); } var payload = enc ? btoa(out.join(\'\')).replace(/\\+/g, \'-\').replace(/\\//g, \'_\').replace(/=+$/, \'\') : encodeURIComponent(v); var q = sep + \'l=\' + payload + \'&nb=1\' + (enc ? \'&ec=1\' : \'\'); ' . ($nsChecked ? 'q+=\'&ns=1\';' : '') . ' ' . ($niChecked ? 'q+=\'&ni=1\';' : '') . ' ' . ($ntChecked ? 'q+=\'&nt=1\';' : '') . ' window.location.href = gw + q;">
                    <input type="text" name="l" value="' . $rawTarget . '" style="flex:1; background:#1e293b; border:1px solid #475569; color:#f8fafc; padding:4px 10px; border-radius:6px; font-size:12px; font-family:monospace; outline:none;" placeholder="https://...">
                    <input type="hidden" name="_glpe" value="1">
                    <input type="hidden" name="nb" value="1">
                    ' . ($ecChecked ? '<input type="hidden" name="ec" value="1">' : '') . '
                    <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:4px 12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; white-space:nowrap;">
                        برو ↵
                    </button>
                </form>
            </div>
            <div style="display:flex; align-items:center; gap:12px; font-size:11px; color:#cbd5e1; margin-right:12px;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $ecChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'ec\',\'1\'):u.searchParams.set(\'ec\',\'0\'); window.location.href=u.href;"> بازنویسی پیوند
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $ntChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'nt\',\'1\'):u.searchParams.set(\'nt\',\'0\'); window.location.href=u.href;"> عنوان عمومی
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $nsChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'ns\',\'1\'):u.searchParams.set(\'ns\',\'0\'); window.location.href=u.href;"> بدون اسکریپت
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $niChecked . ' onclick="var u=new URL(window.location.href); this.checked?u.searchParams.set(\'ni\',\'1\'):u.searchParams.set(\'ni\',\'0\'); window.location.href=u.href;"> بدون تصویر
                </label>
                <button type="button" onclick="window.__toggleGlpeBar()" style="background:#334155; color:#94a3b8; border:none; padding:3px 8px; border-radius:4px; cursor:pointer;" title="بستن نوار ناوبری">
                    ✕
                </button>
            </div>
        </div>
        <!-- Floating Reopen Badge -->
        <div id="__glpe_badge" onclick="window.__toggleGlpeBar()" style="position:fixed; top:10px; right:10px; width:28px; height:28px; background:#0f172a; color:#38bdf8; border:1px solid #334155; border-radius:50%; display:none; align-items:center; justify-content:center; cursor:pointer; z-index:2147483647; font-size:14px; box-shadow:0 2px 8px rgba(0,0,0,0.3);" title="نمایش نوار ناوبری">
            ⚡
        </div>
        <script>document.body.style.marginTop = "42px";</script>
        ';
    }

    /**
     * Full document rewriter:
     * - internal short links for references, forms, styles, sources
     * - frame-buster defeat
     * - generic tab title
     * - responsive srcset support
     * - client-side companion script injection
     * - floating navigation bar injection
     */
    public function rewriteHtml($html, $targetUrl, $options = []) {
        $removeScripts = !empty($options['removeScripts']);
        $removeImages  = !empty($options['removeImages']);
        $stripTitle    = !empty($options['stripTitle']);
        $showToolbar   = !empty($options['showToolbar']);

        // 1. Neutralize frame-busting code
        $html = preg_replace('/(\btop\.location|\bparent\.location|\bwindow\.top\.location)/i', 'window.__safe_loc', $html);

        // 2. Remove framing restrictions declared in the document itself
        $html = preg_replace('/<meta[^>]+http-equiv=[\'"]?(Content-Security-Policy|X-Frame-Options)[\'"]?[^>]*>/i', '', $html);

        // 3. Generic tab title
        if ($stripTitle) {
            $html = preg_replace('/<title\b[^>]*>(.*?)<\/title>/is', '<title>سند وب | Web Viewer</title>', $html);
        }

        // 4. Meta refresh redirects
        $html = preg_replace_callback('/<meta[^>]+http-equiv=[\'"]?refresh[\'"]?[^>]*content=([\'"])(.*?)\1[^>]*>/i', function($m) use ($targetUrl, $options) {
            if (preg_match('/url=(.*?)$/i', $m[2], $urlMatch)) {
                $refreshed = $this->makeViewUrl($urlMatch[1], $targetUrl, $options);
                return preg_replace('/url=.*?$/i', 'url=' . $refreshed, $m[0]);
            }
            return $m[0];
        }, $html);

        // 5. Remove inline behaviors if requested
        if ($removeScripts) {
            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
            $html = preg_replace('/\son\w+=["\'][^"\']*["\']/i', '', $html);
        }

        // 6. Remove or rewrite images & responsive srcset
        if ($removeImages) {
            $html = preg_replace('/<img\b[^>]*>/i', '<span style="display:inline-block; padding:4px 8px; font-size:11px; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:4px;">[تصویر حذف شد]</span>', $html);
            $html = preg_replace('/<picture\b[^>]*>(.*?)<\/picture>/is', '', $html);
        } else {
            $html = preg_replace_callback('/<img\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<img' . $m[1] . 'src=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
            }, $html);

            $html = preg_replace_callback('/<(img|source)\b([^>]*?)\bsrcset=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<' . $m[1] . $m[2] . 'srcset=' . $m[3] . $this->rewriteSrcset($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
            }, $html);

            $html = preg_replace_callback('/<(img|source)\b([^>]*?)\b(data-src|data-url|data-original)=([\'"])(.*?)\4([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<' . $m[1] . $m[2] . $m[3] . '=' . $m[4] . $this->makeViewUrl($m[5], $targetUrl, $options) . $m[4] . $m[6] . '>';
            }, $html);
        }

        // 7. Media elements
        $html = preg_replace_callback('/<(video|audio|track|embed|object)\b([^>]*?)\bsrc=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . 'src=' . $m[3] . $this->makeViewUrl($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
        }, $html);

        $html = preg_replace_callback('/<video\b([^>]*?)\bposter=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<video' . $m[1] . 'poster=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 8. Anchors
        $html = preg_replace_callback('/<a\b([^>]*?)\bhref=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<a' . $m[1] . 'href=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 9. Forms.
        //    POST keeps the gateway query string (browsers preserve it on
        //    POST), so rewriting the action attribute is enough. GET
        //    submissions instead replace the action's whole query string,
        //    which would drop the gateway identity — therefore GET forms are
        //    pointed at the query-less gateway and the remote endpoint travels
        //    in hidden inputs next to the user's own fields.
        $gatewayBase = preg_replace('/\?.*$/', '', $this->viewScript);
        $html = preg_replace_callback('/<form\b([^>]*)>/i', function($m) use ($targetUrl, $options, $gatewayBase) {
            $attrs = $m[1];

            $formMethod = 'GET';
            if (preg_match('/\bmethod=([\'"])([a-zA-Z]+)\1/i', $attrs, $mm)) {
                $formMethod = strtoupper($mm[2]);
            }

            $rawAction = '';
            if (preg_match('/\baction=([\'"])(.*?)\1/i', $attrs, $am)) {
                $rawAction = $am[2];
                $attrs = preg_replace_callback('/\baction=([\'"])(.*?)\1/i', function($am2) use ($targetUrl, $options) {
                    return 'action=' . $am2[1] . $this->makeViewUrl($am2[2], $targetUrl, $options) . $am2[1];
                }, $attrs);
            }

            // Script-driven endpoints handle their own navigation; leave the
            // markup exactly as delivered.
            $trimmedAction = trim($rawAction);
            if ($formMethod !== 'GET'
                || stripos($trimmedAction, 'javascript:') === 0
                || stripos($trimmedAction, 'data:') === 0
                || stripos($trimmedAction, 'blob:') === 0) {
                return '<form' . $attrs . '>';
            }

            // A missing or "#" action targets the current page; a real one is
            // resolved against it. Either way the query part is dropped,
            // mirroring how browsers build GET submissions.
            $remote = ($trimmedAction !== '' && $trimmedAction !== '#')
                ? $this->resolveRelativeUrl($trimmedAction, $targetUrl)
                : $targetUrl;
            $remote = preg_replace('/[?#].*$/s', '', $remote);

            $payload = !empty($options['encodeURL'])
                ? GLPE_Codec::encode($remote)
                : rawurlencode($remote);

            $hidden = '<input type="hidden" name="_glpe" value="1">'
                . '<input type="hidden" name="l" value="' . esc_attr($payload) . '">';
            if (!empty($options['removeScripts'])) $hidden .= '<input type="hidden" name="ns" value="1">';
            if (!empty($options['removeImages']))  $hidden .= '<input type="hidden" name="ni" value="1">';
            if (!empty($options['stripTitle']))    $hidden .= '<input type="hidden" name="nt" value="1">';
            if (!empty($options['showToolbar']))   $hidden .= '<input type="hidden" name="nb" value="1">';
            if (!empty($options['encodeURL']))     $hidden .= '<input type="hidden" name="ec" value="1">';
            if (!empty($options['tempSession']))   $hidden .= '<input type="hidden" name="tp" value="1">';

            $attrs = trim(preg_replace('/\s+action=([\'"]).*?\1/i', '', $attrs));
            return '<form' . $attrs . ' action="' . esc_attr($gatewayBase) . '">' . $hidden;
        }, $html);

        // 10. External references (sheets, icons, fonts)
        $html = preg_replace_callback('/<link\b([^>]*?)\bhref=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<link' . $m[1] . 'href=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 11. Source files
        if (!$removeScripts) {
            $html = preg_replace_callback('/<script\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
                return '<script' . $m[1] . 'src=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
            }, $html);

            $html = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function($m) use ($targetUrl, $options) {
                $innerJs = $m[2];
                if (strpos($innerJs, '__glpe_bar') !== false || strpos($innerJs, 'resolveViewUrl') !== false || trim($innerJs) === '') {
                    return $m[0];
                }
                $rewrittenJs = $this->rewriteJs($innerJs, $targetUrl, $options);
                return '<script' . $m[1] . '>' . $rewrittenJs . '</script>';
            }, $html);
        }

        // 12. Embedded frames
        $html = preg_replace_callback('/<iframe\b([^>]*?)\bsrc=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<iframe' . $m[1] . 'src=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 13. Inline style sheets
        $html = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function($m) use ($targetUrl, $options) {
            return '<style' . $m[1] . '>' . $this->rewriteCss($m[2], $targetUrl, $options) . '</style>';
        }, $html);

        // 14. Inline style attributes
        $html = preg_replace_callback('/\bstyle=([\'"])(.*?)\1/is', function($m) use ($targetUrl, $options) {
            return 'style=' . $m[1] . $this->rewriteCss($m[2], $targetUrl, $options) . $m[1];
        }, $html);

        // 15. SVG references
        $html = preg_replace_callback('/<(use|image)\b([^>]*?)\b(href|xlink:href)=([\'"])(.*?)\4([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . $m[3] . '=' . $m[4] . $this->makeViewUrl($m[5], $targetUrl, $options) . $m[4] . $m[6] . '>';
        }, $html);

        // 16. Client-side companion script
        if (!$removeScripts) {
            $hookScript = file_get_contents(__DIR__ . '/client.js');
            $ctxConfig = [
                'u'   => $targetUrl,
                'g'   => $this->viewScript,
                'enc' => !empty($options['encodeURL']),
                'k'   => GLPE_Codec::secret(),
                'tb'  => $showToolbar,
                'rs'  => $removeScripts,
                'ri'  => $removeImages,
                'st'  => $stripTitle,
            ];
            $injection = "\n<script>\n" .
                "window.__glpe_ctx__ = " . json_encode($ctxConfig) . ";\n" .
                $hookScript .
                "\n</script>\n";

            if (stripos($html, '<head>') !== false) {
                $html = preg_replace('/<head>/i', '<head>' . $injection, $html, 1);
            } else {
                $html = $injection . $html;
            }
        }

        // 17. Floating navigation bar
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
     * Per-hop request headers: identity references always describe the
     * destination host (never this site — remote endpoints reject mismatched
     * cross-origin submissions), and the matching session records are
     * attached for the exact URL being requested.
     */
    private function hopHeaders($baseHeaders, $url, $method) {
        $parsed = parse_url($url);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host   = isset($parsed['host'])   ? $parsed['host']   : '';
        $origin = $scheme . '://' . $host;

        $headers = $baseHeaders;
        // Drop case-variants the client may have injected.
        unset($headers['referer'], $headers['origin'], $headers['cookie']);

        $headers['Referer'] = $origin . '/';
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['Origin'] = $origin;
        }

        $cookieHeader = $this->cookies->getCookieHeader($url);
        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }
        return $headers;
    }

    /**
     * Executes the fetch through the standard WordPress HTTP layer
     * (identical transport signature to core/theme/plugin updates),
     * with a raw client fallback for non-WP contexts.
     * Redirects are followed one hop at a time so session records returned
     * by intermediate responses are kept and the final effective address is
     * reported back for correct link rewriting.
     */
    public function executeRequest($targetUrl, $method = 'GET', $postData = null, $customHeaders = []) {
        $parsed = parse_url($targetUrl);
        if (!isset($parsed['host'])) {
            throw new Exception("آدرس وارد شده نامعتبر است.");
        }

        if ($this->isBlockedHost($parsed['host'])) {
            throw new Exception("دسترسی به آدرس‌های داخلی و شبکه محلی مجاز نیست.");
        }

        $cookieHeader = $this->cookies->getCookieHeader($targetUrl);

        $headers = [];
        $headerNames = [];
        foreach ($customHeaders as $k => $v) {
            $headers[$k] = $v;
            $headerNames[] = strtolower($k);
        }

        if (!in_array('user-agent', $headerNames)) {
            $headers['User-Agent'] = $this->userAgent;
            $headers['Sec-Ch-Ua'] = '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"';
            $headers['Sec-Ch-Ua-Mobile'] = '?0';
            $headers['Sec-Ch-Ua-Platform'] = '"Windows"';
        }
        if (!in_array('accept', $headerNames)) {
            $headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
        }
        if (!in_array('accept-language', $headerNames)) {
            $headers['Accept-Language'] = 'fa,en-US;q=0.9,en;q=0.8';
        }
        if (!in_array('referer', $headerNames)) {
            $headers['Referer'] = $parsed['scheme'] . '://' . $parsed['host'] . '/';
        }
        if (!in_array('sec-fetch-dest', $headerNames)) {
            $headers['Sec-Fetch-Dest'] = 'document';
            $headers['Sec-Fetch-Mode'] = 'navigate';
            $headers['Sec-Fetch-Site'] = 'none';
            $headers['Sec-Fetch-User'] = '?1';
        }
        $headers['Upgrade-Insecure-Requests'] = '1';

        if (!empty($cookieHeader)) {
            $headers['Cookie'] = $cookieHeader;
        }

        // --- Primary transport: WordPress HTTP API ---
        if (function_exists('wp_remote_request')) {
            $sslVerify = function_exists('get_option') ? (get_option('glpe_ssl_verify', '1') === '1') : true;

            $currentUrl    = $targetUrl;
            $currentMethod = strtoupper($method);
            $currentBody   = ($currentMethod === 'POST') ? $postData : null;
            $result        = null;

            for ($hop = 0; $hop < 6 && $result === null; $hop++) {
                $hopHeaders = $this->hopHeaders($headers, $currentUrl, $currentMethod);

                $response = wp_remote_request($currentUrl, [
                    'method'             => $currentMethod,
                    'body'               => (!empty($currentBody)) ? $currentBody : null,
                    'headers'            => $hopHeaders,
                    'timeout'            => 15,
                    'redirection'        => 0,
                    'sslverify'          => $sslVerify,
                    'reject_unsafe_urls' => true,
                    'user-agent'         => isset($hopHeaders['User-Agent']) ? $hopHeaders['User-Agent'] : $this->userAgent,
                ]);

                if (is_wp_error($response)) {
                    throw new Exception("ارتباط با مقصد برقرار نشد: " . $response->get_error_message());
                }

                $status      = (int) wp_remote_retrieve_response_code($response);
                $contentType = wp_remote_retrieve_header($response, 'content-type');
                $location    = wp_remote_retrieve_header($response, 'location');
                if (is_array($location)) {
                    $location = array_shift($location);
                }

                $headersObj = wp_remote_retrieve_headers($response);
                $all = (is_object($headersObj) && method_exists($headersObj, 'getAll')) ? $headersObj->getAll() : (array)$headersObj;
                if (isset($all['set-cookie'])) {
                    foreach ((array)$all['set-cookie'] as $line) {
                        $this->cookies->addCookieFromHeader($line, $currentUrl);
                    }
                }

                if ($status >= 300 && $status < 400 && (string)$location !== '') {
                    $nextUrl = $this->resolveRelativeUrl(trim((string)$location), $currentUrl);
                    if ($nextUrl === $currentUrl || !preg_match('#^https?://#i', $nextUrl)) {
                        break; // self-redirect or non-web scheme — stop hopping
                    }
                    $nextHost = strtolower((string)parse_url($nextUrl, PHP_URL_HOST));
                    if ($nextHost === '' || $this->isBlockedHost($nextHost)) {
                        throw new Exception("دسترسی به آدرس‌های داخلی و شبکه محلی مجاز نیست.");
                    }
                    // Per spec 303 always switches to GET; 301/302 switch a
                    // POST as well, matching what browsers actually do.
                    if ($currentMethod === 'POST' && in_array($status, [301, 302, 303], true)) {
                        $currentMethod = 'GET';
                        $currentBody   = null;
                    }
                    $currentUrl = $nextUrl;
                    continue;
                }

                $result = [
                    'status'      => $status ? $status : 200,
                    'contentType' => $contentType ? $contentType : 'text/html; charset=UTF-8',
                    'body'        => wp_remote_retrieve_body($response),
                    'finalUrl'    => $currentUrl,
                ];
            }

            if ($result === null) {
                throw new Exception("تعداد تغییرمسیرهای پیاپی مقصد بیش از حد مجاز است.");
            }

            return $result;
        }

        // --- Fallback transport: raw client ---
        if (function_exists('curl_init')) {
            $currentUrl    = $targetUrl;
            $currentMethod = strtoupper($method);
            $currentBody   = ($currentMethod === 'POST') ? $postData : null;
            $result        = null;

            for ($hop = 0; $hop < 6 && $result === null; $hop++) {
                $hopHeaders = $this->hopHeaders($headers, $currentUrl, $currentMethod);

                $rawHeaders = [];
                foreach ($hopHeaders as $k => $v) {
                    $rawHeaders[] = $k . ': ' . $v;
                }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $currentUrl);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $currentMethod);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $rawHeaders);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $sslVerify = function_exists('get_option') ? (get_option('glpe_ssl_verify', '1') === '1') : false;
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
                curl_setopt($ch, CURLOPT_ENCODING, '');

                if ($currentMethod === 'POST' && !empty($currentBody)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $currentBody);
                }

                $rawResponse = curl_exec($ch);
                if ($rawResponse === false) {
                    $err = curl_error($ch);
                    curl_close($ch);
                    throw new Exception("خطای اتصال شبکه: " . $err);
                }

                $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);

                $respHeaders = substr($rawResponse, 0, $headerSize);
                $body = substr($rawResponse, $headerSize);

                $location = '';
                $lines = explode("\r\n", $respHeaders);
                foreach ($lines as $line) {
                    if (stripos($line, 'Set-Cookie:') === 0) {
                        $this->cookies->addCookieFromHeader(trim(substr($line, 11)), $currentUrl);
                    }
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                }

                if ($httpCode >= 300 && $httpCode < 400 && $location !== '') {
                    $nextUrl = $this->resolveRelativeUrl($location, $currentUrl);
                    if ($nextUrl === $currentUrl || !preg_match('#^https?://#i', $nextUrl)) {
                        break;
                    }
                    $nextHost = strtolower((string)parse_url($nextUrl, PHP_URL_HOST));
                    if ($nextHost === '' || $this->isBlockedHost($nextHost)) {
                        throw new Exception("دسترسی به آدرس‌های داخلی و شبکه محلی مجاز نیست.");
                    }
                    if ($currentMethod === 'POST' && in_array($httpCode, [301, 302, 303], true)) {
                        $currentMethod = 'GET';
                        $currentBody   = null;
                    }
                    $currentUrl = $nextUrl;
                    continue;
                }

                $result = [
                    'status'      => $httpCode ? $httpCode : 200,
                    'contentType' => $contentType ? $contentType : 'text/html; charset=UTF-8',
                    'body'        => $body,
                    'finalUrl'    => $currentUrl,
                ];
            }

            if ($result === null) {
                throw new Exception("تعداد تغییرمسیرهای پیاپی مقصد بیش از حد مجاز است.");
            }

            return $result;
        }

        // --- Last resort: stream context ---
        $streamHeaders = $this->hopHeaders($headers, $targetUrl, $method);
        $opts = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", array_map(function($k, $v) { return $k . ': ' . $v; }, array_keys($streamHeaders), array_values($streamHeaders))) . "\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        if (strtoupper($method) === 'POST' && !empty($postData)) {
            $opts['http']['content'] = is_array($postData) ? http_build_query($postData) : $postData;
        }

        $context = stream_context_create($opts);
        $body = @file_get_contents($targetUrl, false, $context);
        if ($body === false) {
            throw new Exception("عدم امکان دریافت اطلاعات از سرور مقصد.");
        }

        $status = 200;
        $contentType = 'text/html; charset=UTF-8';
        if (isset($http_response_header)) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
            foreach ($http_response_header as $hdr) {
                if (stripos($hdr, 'Set-Cookie:') === 0) {
                    $this->cookies->addCookieFromHeader(trim(substr($hdr, 11)), $targetUrl);
                }
                if (stripos($hdr, 'Content-Type:') === 0) {
                    $contentType = trim(substr($hdr, 13));
                }
            }
        }

        return [
            'status'      => $status,
            'contentType' => $contentType,
            'body'        => $body,
            'finalUrl'    => $targetUrl,
        ];
    }
}
