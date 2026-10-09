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
    private $srcdocDepth = 0;
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

        // Core tenet — every destination reference is wrapped into an opaque
        // per-site token. Readable (percent-encoded) destinations are never
        // emitted, unconditionally: there is no optional mode anymore.
        $payload = GLPE_Codec::encode($resolved);

        $sep = (strpos($this->viewScript, '?') !== false) ? '&' : '?';
        $viewUrl = $this->viewScript . $sep . 'l=' . $payload;

        // Keep display flags on child links
        if (!empty($options['removeScripts'])) $viewUrl .= '&ns=1';
        if (!empty($options['removeImages']))  $viewUrl .= '&ni=1';
        if (!empty($options['stripTitle']))    $viewUrl .= '&nt=1';
        if (!empty($options['showToolbar']))   $viewUrl .= '&nb=1';
        if (!empty($options['mobileView']))    $viewUrl .= '&mb=1';

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
        // Candidate separators are commas FOLLOWED BY whitespace (or the end
        // of the attribute). Commas inside a reference (e.g. a CDN size hint
        // like "s(w:526,h:298),webp/…") belong to the URL itself and must
        // never split it — per the srcset grammar the reference ends at
        // whitespace, not at a bare comma.
        $parts = preg_split('/,(?=\s|$)/', $srcset);
        $rewritten = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
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
        // 1. Absolute AND protocol-relative references in string literals.
        // Bundler-era sites (e.g. CDN-hosted module graphs) reference their
        // chunks as "//host/path" — protocol-relative strings would resolve
        // against the page origin inside the browser and leave the gateway
        // entirely, so they are wrapped exactly like absolute URLs.
        $js = preg_replace_callback('/([\'"])(https?:\/\/[^\'"]+|\/\/[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}[^\'"]*)\1/i', function($matches) use ($baseUrl, $options) {
            $quote = $matches[1];
            $url = $matches[2];
            // Inline JSON payloads embed ampersands as \u0026 escapes (e.g.
            // YouTube's ytInitialPlayerResponse stream URLs). The escapes are
            // JSON text, not URL characters — decoding them here keeps the
            // wrapped target URL valid when the player later requests it.
            if (strpos($url, '\\u') !== false) {
                $unescaped = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($um) {
                    return mb_convert_encoding(pack('n', hexdec($um[1])), 'UTF-8', 'UTF-16BE');
                }, $url);
                if (is_string($unescaped) && $unescaped !== '') {
                    $url = $unescaped;
                }
            }
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

        // 2. ES module graph — relative specifiers only.
        // The browser's module loader resolves import/export specifiers
        // against the module's own URL and ignores every client-side hook,
        // so once a module rides the gateway its specifiers must ride it
        // too — otherwise the next chunk resolves against this site's own
        // paths and 404s, killing the whole app boot. Static form
        // (from"./x.js") and dynamic form (import("./x.js")) are covered;
        // the specifier must end in a module-ish extension so plain object
        // literals like {from:"./locale"} stay untouched.
        $js = preg_replace_callback(
            '/\b(from|import)\s*(\(\s*)?([\'"])(\.{1,2}\/[^\'"]{2,2048}|\/[^\'"]{2,2048})\3/',
            function($m) use ($baseUrl, $options) {
                $spec = $m[4];
                if (!preg_match('/\.(?:js|mjs|cjs|json|css|wasm|svg|png|jpe?g|webp|gif|woff2?|ttf|mp4|webm|m3u8)(?:[?#][^\'"()]*)?$/i', $spec)) {
                    return $m[0];
                }
                $link = $this->makeViewUrl($spec, $baseUrl, $options);
                return $m[1] . $m[2] . $m[3] . $link . $m[3];
            },
            $js
        );

        // 3. Bundler worker/asset pattern — new URL("./x.js", import.meta.url)
        // resolves against the module's own URL exactly like a specifier.
        $js = preg_replace_callback(
            '/new\s+URL\(\s*([\'"])(\.{1,2}\/[^\'"]{2,2048}|\/[^\'"]{2,2048})\1\s*,\s*import\.meta\.url\s*(?:,\s*([\'"])\w+\3)?\)/i',
            function($m) use ($baseUrl, $options) {
                $spec = $m[2];
                if (!preg_match('/\.(?:js|mjs|cjs|wasm)(?:[?#][^\'"]*)?$/i', $spec)) {
                    return $m[0];
                }
                $link = $this->makeViewUrl($spec, $baseUrl, $options);
                return 'new URL(' . $m[1] . $link . $m[1] . ', import.meta.url' . (isset($m[3]) ? ',' . $m[3] . $m[3] : '') . ')';
            },
            $js
        );
        return $js;
    }

    /**
     * Generates the floating top navigation bar.
     */
    private function generateToolbarHtml($targetUrl, $options = []) {
        $slug = trim((string)(function_exists('get_option') ? get_option('glpe_slug', 'view') : 'view'), '/');
        $homeUrl = home_url('/' . ($slug !== '' ? $slug : 'view') . '/');
        // Core tenet: the readable destination is never rendered into the
        // markup — no readable address may ride any exchange between browser
        // and server. The Go box starts empty server-side; client.js decodes
        // the token already sitting in the address bar and fills the box
        // locally, so the current-address display lives only in the user's
        // browser and the rebuilt string never travels back. Every submission
        // from the box is wrapped by the inline handler below.
        $nsChecked = !empty($options['removeScripts']) ? 'checked' : '';
        $niChecked = !empty($options['removeImages']) ? 'checked' : '';
        $ntChecked = !empty($options['stripTitle']) ? 'checked' : '';
        $mbChecked = !empty($options['mobileView']) ? 'checked' : '';
        $gw = esc_attr($this->viewScript);

        return '
        <!-- Floating Navigation Bar -->
        <div id="__glpe_bar" style="position:fixed; top:0; left:0; right:0; height:42px; background:#0f172a; color:#f8fafc; font-family:tahoma,sans-serif; font-size:12px; z-index:2147483647; display:flex; align-items:center; justify-content:space-between; padding:0 12px; box-shadow:0 2px 10px rgba(0,0,0,0.3); border-bottom:1px solid #334155; direction:rtl;">
            <div style="display:flex; align-items:center; gap:8px; flex:1; max-width:700px;">
                <a href="' . esc_attr($homeUrl) . '" style="color:#38bdf8; text-decoration:none; font-weight:bold; display:flex; align-items:center; gap:4px; padding:4px 8px; border-radius:6px; background:#1e293b; white-space:nowrap;">
                    🏠 صفحه اصلی
                </a>
                <form action="' . $gw . '" method="GET" style="display:flex; gap:6px; flex:1; margin:0;" onsubmit="event.preventDefault(); var gw=\'' . $gw . '\'; var sep = gw.indexOf(\'?\') !== -1 ? \'&\' : \'?\'; var v = this.l.value; if(!v.match(/^https?:/i)) v=\'https://\'+v; var bytes = unescape(encodeURIComponent(v)); var out = []; var key = (window.__glpe_ctx__ && window.__glpe_ctx__.k) || \'glpe-local-key\'; for (var i = 0; i < bytes.length; i++) { out.push(String.fromCharCode(bytes.charCodeAt(i) ^ key.charCodeAt(i % key.length))); } var payload = btoa(out.join(\'\')).replace(/\\+/g, \'-\').replace(/\\//g, \'_\').replace(/=+$/, \'\'); var q = sep + \'l=\' + payload + \'&nb=1\'; ' . ($nsChecked ? 'q+=\'&ns=1\';' : '') . ' ' . ($niChecked ? 'q+=\'&ni=1\';' : '') . ' ' . ($ntChecked ? 'q+=\'&nt=1\';' : '') . ' ' . ($mbChecked ? 'q+=\'&mb=1\';' : '') . ' window.location.href = gw + q;">
                    <input type="text" name="l" value="" autocomplete="off" autocapitalize="off" spellcheck="false" style="flex:1; background:#1e293b; border:1px solid #475569; color:#f8fafc; padding:4px 10px; border-radius:6px; font-size:12px; font-family:monospace; outline:none;" placeholder="https://...">
                    <input type="hidden" name="_glpe" value="1">
                    <input type="hidden" name="nb" value="1">
                    ' . ($mbChecked ? '<input type="hidden" name="mb" value="1">' : '') . '
                    <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:4px 12px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; white-space:nowrap;">
                        برو ↵
                    </button>
                </form>
            </div>
            <div style="display:flex; align-items:center; gap:12px; font-size:11px; color:#cbd5e1; margin-right:12px;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $ntChecked . ' onclick="window.__glpeToggle(this,\'nt\')"> عنوان عمومی
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $nsChecked . ' onclick="window.__glpeToggle(this,\'ns\')"> بدون اسکریپت
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $niChecked . ' onclick="window.__glpeToggle(this,\'ni\')"> بدون تصویر
                </label>
                <label style="cursor:pointer; display:flex; align-items:center; gap:3px;">
                    <input type="checkbox" ' . $mbChecked . ' onclick="window.__glpeToggle(this,\'mb\')"> 📱 نسخه موبایل
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
        <script>
        window.__glpeToggle = function(el, flag) {
            try {
                var h = window.location.href;
                var re = new RegExp(\'([?&])\' + flag + \'=[^&]*\');
                var pair = flag + \'=\' + (el.checked ? \'1\' : \'0\');
                if (re.test(h)) {
                    h = h.replace(re, \'$1\' + pair);
                } else {
                    h += (h.indexOf(\'?\') === -1 ? \'?\' : \'&\') + pair;
                }
                window.location.href = h;
            } catch (e) {}
        };
        document.body.style.marginTop = "42px";
        </script>
        ';
    }

    /**
     * Normalises unquoted attribute values to quoted form before the
     * rewriting passes run. Script/style bodies and comments are split out
     * first — quoting values inside code text would change program
     * semantics (e.g. an HTML string literal compared verbatim).
     */
    private function quoteUnquotedAttributes($html) {
        if (strpos($html, '=') === false || strpos($html, '<') === false) {
            return $html;
        }
        $parts = preg_split(
            '/(<script\b[^>]*>[\s\S]*?<\/script\s*>|<style\b[^>]*>[\s\S]*?<\/style\s*>|<!--[\s\S]*?-->)/i',
            $html, -1, PREG_SPLIT_DELIM_CAPTURE
        );
        if (count($parts) === 1) {
            return $this->quoteUnquotedInTags($html);
        }
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // Protected span. Quote ONLY its opening tag (attributes must
                // reach the downstream quote-anchored rewriters) and keep the
                // code body plus closing tag byte-identical.
                if (preg_match('/^((?:<script|<style)\b[^>]*>)([\s\S]*)$/i', $part, $pm)) {
                    $out .= $this->quoteUnquotedInTags($pm[1]) . $pm[2];
                } else {
                    $out .= $part;
                }
                continue;
            }
            $out .= $this->quoteUnquotedInTags($part);
        }
        return $out;
    }

    /**
     * Walks tag openings inside one markup chunk and quotes any bare
     * attr=value pair. The tag scanner crosses quoted attribute values in
     * one step (they may legally contain >) and stops at the first unquoted
     * >, so only genuine tag interiors are touched — never plain text.
     */
    private function quoteUnquotedInTags($chunk) {
        if (strpos($chunk, '=') === false) {
            return $chunk;
        }
        return preg_replace_callback(
            '/<[a-zA-Z](?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/',
            function($tag) {
                return preg_replace_callback(
                    '/([^\s"\'>=]+)=("[^"]*"|\'[^\']*\'|[^\s"\'>=]+)/',
                    function($m) {
                        $v = $m[2];
                        if ($v === '' || $v[0] === '"' || $v[0] === "'") {
                            return $m[0];
                        }
                        return $m[1] . '="' . $v . '"';
                    },
                    $tag[0]
                );
            },
            $chunk
        );
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
        // Some sites emit attributes without quotes (e.g.
        // <script type=module crossorigin src=//cdn.example.com/app.js>).
        // Every downstream pass is quote-anchored, so those references would
        // ride out of the viewer untouched and the browser would load them
        // directly around the gateway. Normalising to quoted form up front
        // makes the whole pipeline cover this page style as well.
        $html = $this->quoteUnquotedAttributes($html);
        $removeScripts = !empty($options['removeScripts']);
        $removeImages  = !empty($options['removeImages']);
        $stripTitle    = !empty($options['stripTitle']);
        $showToolbar   = !empty($options['showToolbar']);

        // 1. Neutralize frame-busting code
        $html = preg_replace('/(\btop\.location|\bparent\.location|\bwindow\.top\.location)/i', 'window.__safe_loc', $html);

        // 2. Remove framing restrictions declared in the document itself
        $html = preg_replace('/<meta[^>]+http-equiv=[\'"]?(Content-Security-Policy(-Report-Only)?|X-Frame-Options)[\'"]?[^>]*>/i', '', $html);

        // 2a. Core tenet — nothing may load directly from a remote origin.
        // A <base> marker would re-anchor unresolved references onto the real
        // destination, so it is stripped outright.
        $html = preg_replace('/<base\b[^>]*>/i', '', $html);

        // 2b. Hyperlink audit beacons fire their own requests straight at the
        // destination when a link is clicked — the attribute is removed.
        $html = preg_replace('/\s+ping=(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+ping=[^\s>]+/i', '', $html);

        // 2c. Integrity digests describe the ORIGINAL file, while rewritten
        // documents legitimately differ — keeping the attribute would get the
        // resource discarded by the browser, so it is stripped.
        $html = preg_replace('/\s+integrity=(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+integrity=[^\s>]+/i', '', $html);

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

        // 7a. <object data="..."> resolves exactly like a src attribute —
        // left alone it would fetch the resource straight from the origin.
        $html = preg_replace_callback('/<(object|embed)\b([^>]*?)\bdata=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . 'data=' . $m[3] . $this->makeViewUrl($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
        }, $html);

        $html = preg_replace_callback('/<video\b([^>]*?)\bposter=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<video' . $m[1] . 'poster=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 8. Anchors
        $html = preg_replace_callback('/<a\b([^>]*?)\bhref=([\'"])(.*?)\2([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<a' . $m[1] . 'href=' . $m[2] . $this->makeViewUrl($m[3], $targetUrl, $options) . $m[2] . $m[4] . '>';
        }, $html);

        // 8a. Button/image submission overrides (formaction) steer the
        // submission to a different endpoint — they must ride the gateway too.
        $html = preg_replace_callback('/<(button|input)\b([^>]*?)\bformaction=([\'"])(.*?)\3([^>]*)>/i', function($m) use ($targetUrl, $options) {
            return '<' . $m[1] . $m[2] . 'formaction=' . $m[3] . $this->makeViewUrl($m[4], $targetUrl, $options) . $m[3] . $m[5] . '>';
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

            // Core tenet — the endpoint travels as an opaque token only.
            // Gateway-injected fields carry data-g so the client-side
            // submission wrapper never forwards them to the destination.
            $payload = GLPE_Codec::encode($remote);

            $hidden = '<input type="hidden" name="_glpe" value="1" data-g="1">'
                . '<input type="hidden" name="l" value="' . esc_attr($payload) . '" data-g="1">';
            if (!empty($options['removeScripts'])) $hidden .= '<input type="hidden" name="ns" value="1" data-g="1">';
            if (!empty($options['removeImages']))  $hidden .= '<input type="hidden" name="ni" value="1" data-g="1">';
            if (!empty($options['stripTitle']))    $hidden .= '<input type="hidden" name="nt" value="1" data-g="1">';
            if (!empty($options['showToolbar']))   $hidden .= '<input type="hidden" name="nb" value="1" data-g="1">';
            if (!empty($options['tempSession']))   $hidden .= '<input type="hidden" name="tp" value="1" data-g="1">';

            $attrs = trim(preg_replace('/\s+action=([\'"]).*?\1/i', '', $attrs));
            // Keep one separating space between the tag name and the first
            // remaining attribute — trim() alone would produce
            // "<formname=\"f\" ...>", an unknown element with no form
            // semantics (broke every button on google.com's homepage).
            return '<form' . ($attrs !== '' ? ' ' . $attrs : '') . ' action="' . esc_attr($gatewayBase) . '">' . $hidden;
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

        // 15a. Inline event handlers (onclick="location='https://…'") carry
        // references the browser would request directly — rewrite the URLs
        // inside them exactly like inline script bodies.
        $html = preg_replace_callback('/\s(on[a-z]+)=([\'"])(.*?)\2/is', function($m) use ($targetUrl, $options) {
            return ' ' . $m[1] . '=' . $m[2] . $this->rewriteJs($m[3], $targetUrl, $options) . $m[2];
        }, $html);

        // 15b. srcdoc frames carry a whole nested document — its references
        // must ride the gateway as well. The nested document is rewritten
        // through the same pipeline (one level deep, without the toolbar).
        if ($this->srcdocDepth === 0 && strpos($html, 'srcdoc=') !== false) {
            $this->srcdocDepth++;
            $html = preg_replace_callback('/<iframe\b([^>]*?)\bsrcdoc=(["\'])(.*?)\2/is', function($m) use ($targetUrl, $options) {
                $inner = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!is_string($inner) || $inner === '' || stripos($inner, '<') === false) {
                    return $m[0];
                }
                $innerOptions = $options;
                $innerOptions['showToolbar'] = false;
                $rewritten = $this->rewriteHtml($inner, $targetUrl, $innerOptions);
                return '<iframe' . $m[1] . 'srcdoc=' . $m[2] . esc_attr($rewritten) . $m[2];
            }, $html);
            $this->srcdocDepth--;
        }

        // 16. Client-side companion script
        if (!$removeScripts) {
            $hookScript = file_get_contents(__DIR__ . '/client.js');
            $ctxConfig = [
                // Core tenet — even the companion configuration carries the
                // destination as an opaque token; the client unwraps it.
                'u'   => GLPE_Codec::encode($targetUrl),
                'g'   => $this->viewScript,
                'k'   => GLPE_Codec::secret(),
                'tb'  => $showToolbar,
                'rs'  => $removeScripts,
                'ri'  => $removeImages,
                'st'  => $stripTitle,
                'mb'  => !empty($options['mobileView']),
            ];
            $injection = "\n<script>\n" .
                "window.__glpe_ctx__ = " . json_encode($ctxConfig) . ";\n" .
                $hookScript .
                "\n</script>\n";

            if (stripos($html, '<head>') !== false) {
                // Callback injection: replacement strings would interpret $/
                // sequences inside the script payload as backreferences.
                $html = preg_replace_callback('/<head>/i', function($hm) use ($injection) {
                    return $hm[0] . $injection;
                }, $html, 1);
            } else {
                $html = $injection . $html;
            }
        }

        // 17. Floating navigation bar
        if ($showToolbar) {
            $toolbarHtml = $this->generateToolbarHtml($targetUrl, $options);
            if (stripos($html, '<body') !== false) {
                // Callback injection — a replacement string here would treat
                // every $N inside the toolbar markup (e.g. the "$1" regex
                // backreference in the toggle helper) as a backreference and
                // silently erase it.
                $html = preg_replace_callback('/<body\b([^>]*)>/i', function($bm) use ($toolbarHtml) {
                    return '<body' . $bm[1] . '>' . $toolbarHtml;
                }, $html, 1);
            } else {
                $html = $toolbarHtml . $html;
            }
        }

        // 18. YouTube watch pages: the in-page player cannot operate inside
        // the viewer — YouTube's server-side streaming stack (SABR/PoToken)
        // rejects streams whose session was not opened by the real client.
        // Give every watch page the official embed player instead: it plays
        // video and advertisements natively, exactly like the original site.
        // The iframe is injected verbatim (never rewritten) and loads from
        // YouTube directly in the visitor's own browser.
        $ytVideoId = '';
        if (preg_match('#youtube(?:-nocookie)?\.com/watch\?(?:[^\#]*&)?v=([A-Za-z0-9_-]{6,20})#i', $targetUrl, $vm)) {
            $ytVideoId = $vm[1];
        } elseif (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,20})#i', $targetUrl, $vm)) {
            $ytVideoId = $vm[1];
        }
        if ($ytVideoId !== '' && preg_match('#^[A-Za-z0-9_-]+$#', $ytVideoId)) {
            $playerHtml = '<style>'
                . 'ytd-player,#movie_player,#player-container-01,.html5-video-player,#player-wrap{display:none!important}'
                . '</style>'
                . '<div id="__glpe_ytplayer" style="position:relative;z-index:2147483646;background:#000;'
                . 'margin:52px auto 0 auto;max-width:960px;width:calc(100% - 24px);aspect-ratio:16/9;">'
                . '<iframe src="https://www.youtube-nocookie.com/embed/' . $ytVideoId . '" '
                . 'style="position:absolute;inset:0;width:100%;height:100%;border:0;" '
                . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
                . 'allowfullscreen title="Video player"></iframe></div>';
            if (stripos($html, '<body') !== false) {
                $html = preg_replace_callback('/<body\b([^>]*)>/i', function($bm) use ($playerHtml) {
                    return '<body' . $bm[1] . '>' . $playerHtml;
                }, $html, 1);
            } else {
                $html = $playerHtml . $html;
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

    /**
     * Streams a media target (video/audio bytes) straight to the client
     * without buffering it in memory — a full video would exhaust PHP's
     * memory limit and stall playback. Status and the range-relevant
     * response headers (Content-Type / Content-Length / Content-Range /
     * Accept-Ranges) are relayed before the first byte, so seeking and
     * partial requests (206) behave like a direct download.
     * Returns true once the bytes have been flushed; the caller must exit.
     */
    public function streamMediaRequest($targetUrl, $customHeaders = []) {
        $parsed = parse_url($targetUrl);
        if (!isset($parsed['host'])) {
            throw new Exception("آدرس وارد شده نامعتبر است.");
        }
        if ($this->isBlockedHost($parsed['host'])) {
            throw new Exception("دسترسی به آدرس‌های داخلی و شبکه محلی مجاز نیست.");
        }
        if (!function_exists('curl_init') || headers_sent()) {
            return false;
        }

        $currentUrl = $targetUrl;
        $outStream = null;
        for ($hop = 0; $hop < 4; $hop++) {
            $headers = $this->hopHeaders($customHeaders, $currentUrl, 'GET');
            $cookieHeader = $this->cookies->getCookieHeader($currentUrl);
            $raw = [];
            foreach ($headers as $k => $v) {
                $raw[] = $k . ': ' . $v;
            }
            if ($cookieHeader !== '') {
                $raw[] = 'Cookie: ' . $cookieHeader;
            }

            $statusLine = null;
            $relay = [];
            $headersSent = false;
            $sendHeaders = function() use (&$statusLine, &$relay, &$headersSent) {
                if ($headersSent) return;
                http_response_code($statusLine ? $statusLine : 200);
                header('Cache-Control: no-store, max-age=0');
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Headers: *');
                header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
                foreach ($relay as $name => $value) {
                    header($name . ': ' . $value);
                }
                $headersSent = true;
            };

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $currentUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $raw);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 0);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            $sslVerify = function_exists('get_option') ? (get_option('glpe_ssl_verify', '1') === '1') : false;
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $line) use (&$statusLine, &$relay) {
                $trim = trim($line);
                if ($trim === '') return strlen($line);
                if (stripos($trim, 'HTTP/') === 0) {
                    if (preg_match('#HTTP/\S+\s+(\d{3})#i', $trim, $m)) {
                        $statusLine = (int)$m[1];
                    }
                    $relay = []; // redirect hops reset the collected headers
                    return strlen($line);
                }
                $parts = explode(':', $trim, 2);
                if (count($parts) === 2) {
                    $name = trim($parts[0]);
                    $lower = strtolower($name);
                    if (in_array($lower, ['content-type', 'content-length', 'content-range', 'accept-ranges', 'content-disposition', 'last-modified', 'etag'], true)) {
                        $relay[$name] = str_replace(["\r", "\n", "\0"], '', trim($parts[1]));
                    }
                }
                return strlen($line);
            });
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use ($sendHeaders, &$outStream) {
                $sendHeaders();
                if ($outStream === null) {
                    $outStream = fopen('php://output', 'wb');
                }
                $n = fwrite($outStream, $chunk);
                if (function_exists('flush')) @flush();
                return ($n === false) ? -1 : strlen($chunk);
            });

            curl_exec($ch);
            $errNo   = curl_errno($ch);
            $nextUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $status  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($status >= 300 && $status < 400 && $nextUrl && $hop < 3) {
                $currentUrl = $nextUrl; // follow the hop with fresh identity headers
                continue;
            }
            if ($errNo !== 0 && !$headersSent) {
                return false; // let the caller fall back to the buffered path
            }
            $sendHeaders();
            return true;
        }
        return true;
    }
}
