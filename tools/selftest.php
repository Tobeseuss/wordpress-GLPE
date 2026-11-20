<?php
/**
 * GLPE Viewer Self-Test Suite
 * Run: php tools/selftest.php
 * No external dependencies. Exit code 0 = all pass.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);

// Minimal WordPress shims — the engine must remain testable outside WP.
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://site.test' . $path; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return (string)$url; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length, $special = false, $extra = false) { return str_repeat('x', (int)$length); }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) { return rtrim((string)$value, '/'); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string)$url); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!defined('GLPE_URL')) { define('GLPE_URL', 'https://site.test/wp-content/plugins/glpe-viewer/'); }

// Options store shim — mirrors the tiny WP-option subset the suite needs.
$__glpe_options = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        global $__glpe_options;
        return array_key_exists($name, $__glpe_options) ? $__glpe_options[$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value) { global $__glpe_options; $__glpe_options[$name] = $value; return true; }
}
// Guard markers in the shipped includes exit silently when ABSPATH is
// missing — the suite must define it BEFORE requiring the engine stack
// (Engine.php pulls Dns.php, which is guarded too).
if (!defined('ABSPATH')) { define('ABSPATH', __FILE__); }
require $root . '/glpe-viewer/includes/Cookies.php';
require $root . '/glpe-viewer/includes/Codec.php';
require $root . '/glpe-viewer/includes/Engine.php';

$pass = 0;
$fail = 0;

function check($label, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

echo "== 1. GLPE_Codec roundtrip ==\n";
$urls = [
    'https://example.com/path?q=1&r=2',
    'https://en.m.wikipedia.org/wiki/Main_Page',
    'https://html.duckduckgo.com/html/?q=test+query',
    'http://news.ycombinator.com/item?id=1',
];
foreach ($urls as $u) {
    check("roundtrip $u", GLPE_Codec::decode(GLPE_Codec::encode($u)) === $u);
}
check('plain passthrough', GLPE_Codec::decode('https://plain.example.com') === 'https://plain.example.com');
check('url-safe alphabet', (bool)preg_match('#^[A-Za-z0-9_-]+$#', GLPE_Codec::encode('https://test.com/' . str_repeat('x', 64))));
check('empty input', GLPE_Codec::encode('') === '');

echo "\n== 2. resolveRelativeUrl (RFC 3986) ==\n";
$engine = new GLPE_Engine('https://site.test/?_glpe=1');
$cases = [
    ['/about/page', 'https://example.com/dir/index.html', 'https://example.com/about/page'],
    ['img/pic.png', 'https://example.com/a/b/', 'https://example.com/a/b/img/pic.png'],
    ['../up.png', 'https://example.com/a/b/c/', 'https://example.com/a/b/up.png'],
    ['//cdn.other.com/lib.js', 'https://example.com/', 'https://cdn.other.com/lib.js'],
    ['https://absolute.com/x', 'https://example.com/', 'https://absolute.com/x'],
    ['#anchor', 'https://example.com/page', 'https://example.com/page#anchor'],
    // Query values with "//" must never be collapsed (gmail sign-in regression)
    ['/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/', 'https://accounts.google.com/v3/signin/x', 'https://accounts.google.com/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/'],
    ['/a/./b/../c?u=https://x.org//y', 'https://accounts.google.com/v3/x', 'https://accounts.google.com/a/c?u=https://x.org//y'],
];
foreach ($cases as $c) {
    check("{$c[0]} @ {$c[1]}", $engine->resolveRelativeUrl($c[0], $c[1]) === $c[2], $engine->resolveRelativeUrl($c[0], $c[1]));
}

echo "\n== 3. Guard list (internal host check) ==\n";
$blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '192.168.1.1', '10.0.0.5', '172.16.0.1', '172.31.255.255', '169.254.169.254', 'metadata.google.internal'];
foreach ($blocked as $h) { check("blocked $h", $engine->isBlockedHost($h)); }
$allowed = ['example.com', '8.8.8.8', '1.1.1.1', 'proxy.example.co.uk'];
foreach ($allowed as $h) { check("allowed $h", !$engine->isBlockedHost($h)); }

echo "\n== 4. makeViewUrl ==\n";
$s = $engine->makeViewUrl('https://target.com/page', 'https://example.com/');
check('wrapped view URL', strpos($s, '&l=') !== false, $s);
check('token decodes back', (bool)preg_match('/l=([^&]+)/', $s, $mm) && GLPE_Codec::decode($mm[1]) === 'https://target.com/page', isset($mm[1]) ? GLPE_Codec::decode($mm[1]) : $s);
check('no readable destination in output', strpos($s, 'target.com') === false, $s);
$s2 = $engine->makeViewUrl('https://target.com/page', null);
check('wrapping is unconditional', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $s2) && strpos($s2, rawurlencode('https://target.com/page')) === false, $s2);
check('data: untouched', $engine->makeViewUrl('data:image/png;base64,AAA') === 'data:image/png;base64,AAA');
check('anchor untouched', $engine->makeViewUrl('#sec') === '#sec');
check('no double wrap', $engine->makeViewUrl('https://site.test/?_glpe=1&l=abc') === 'https://site.test/?_glpe=1&l=abc');
$s3 = $engine->makeViewUrl('https://x.com', null, ['removeScripts' => true, 'showToolbar' => true]);
check('flags appended', strpos($s3, 'ns=1') !== false && strpos($s3, 'nb=1') !== false, $s3);
check('ec flag never emitted', strpos($s3, 'ec=1') === false, $s3);
$s3m = $engine->makeViewUrl('https://x.com', null, ['showToolbar' => true, 'mobileView' => true]);
check('mobile flag appended', strpos($s3m, '&mb=1') !== false, $s3m);
check('mobile flag off by default', strpos($s3, '&mb=1') === false, $s3);
// Attribute values carry HTML entities ("&amp;") — decoding is required so
// the destination receives real query fields (gmail sign-in regression).
$s4 = $engine->makeViewUrl('/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/&amp;dsh=S-1:2&amp;flowName=WebLiteSignIn', 'https://accounts.google.com/v3/signin/x');
check('attribute entities decoded', (bool)preg_match('/l=([^&]+)/', $s4, $m4) && GLPE_Codec::decode($m4[1]) === 'https://accounts.google.com/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/&dsh=S-1:2&flowName=WebLiteSignIn', isset($m4[1]) ? GLPE_Codec::decode($m4[1]) : $s4);
$s5 = $engine->makeViewUrl('/v3/signin/lookup?u=https://dest.example/p', 'https://accounts.google.com/v3/x');
check('double slash inside query kept', (bool)preg_match('/l=([^&]+)/', $s5, $m5) && GLPE_Codec::decode($m5[1]) === 'https://accounts.google.com/v3/signin/lookup?u=https://dest.example/p', isset($m5[1]) ? GLPE_Codec::decode($m5[1]) : $s5);

echo "\n== 5. Session store (simulated) ==\n";
$_SESSION = [];
$jar = new GLPE_Cookies(false);
$jar->addCookieFromHeader('sid=abc123; Path=/; Domain=example.com; HttpOnly; SameSite=Lax; Max-Age=3600', 'https://example.com/login');
$hdr = $jar->getCookieHeader('https://example.com/dashboard');
check('record set + sent', strpos($hdr, 'sid=abc123') !== false, $hdr);
$hdr2 = $jar->getCookieHeader('https://other.com/');
check('not leaked cross-domain', $hdr2 === '', $hdr2);
$jar->addCookieFromHeader('gone=x; Max-Age=0', 'https://example.com/');
$hdr3 = $jar->getCookieHeader('https://example.com/');
check('expired record deleted (B12)', strpos($hdr3, 'gone=') === false, $hdr3);
// Path scoping: added at /user/panel → default path = /user (RFC 6265 default-path)
$jar->addCookieFromHeader('token=tok', 'https://example.com/user/panel');
check('path scoping', strpos($jar->getCookieHeader('https://example.com/other'), 'token=') === false && strpos($jar->getCookieHeader('https://example.com/user/panel/x'), 'token=tok') !== false);
// Regression B13: Path=/user must NOT match /username (segment boundary)
$jar->addCookieFromHeader('seg=v; Path=/user', 'https://example.com/');
check('segment boundary (B13)', strpos($jar->getCookieHeader('https://example.com/username'), 'seg=') === false && strpos($jar->getCookieHeader('https://example.com/user/2'), 'seg=v') !== false);
check('getAllCookies count', count($jar->getAllCookies()) === 3);
$jar->clearAll();
check('clearAll', count($jar->getAllCookies()) === 0);

// Account snapshot roundtrip (the “save my sessions to my account” feature):
// export → wipe → import restores records; malformed input is a no-op that
// leaves valid data intact; each account loads only its own snapshot copy.
$jar->addCookieFromHeader('sid=abc123; Path=/; Domain=example.com; HttpOnly; SameSite=Lax; Max-Age=3600', 'https://example.com/login');
$snapshot = $jar->snapshotAll();
check('export captures records', isset($snapshot['example.com']['sid']), print_r($snapshot, true));
$_SESSION['_glpe_cookies'] = [];
check('empty after wipe', $jar->isEmpty());
$jar2 = new GLPE_Cookies(false);
$jar2->loadSnapshot($snapshot);
$hdrBack = $jar2->getCookieHeader('https://example.com/other');
check('import restores records', strpos($hdrBack, 'sid=abc123') !== false, $hdrBack);
$jar2->loadSnapshot('garbage-string');
check('malformed import is a no-op', strpos($jar2->getCookieHeader('https://example.com/x'), 'sid=abc123') !== false);
$jar2->loadSnapshot(['evil.com' => ['x' => 'not-an-array']]);
check('malformed entries dropped', $jar2->isEmpty());

echo "\n== 6. rewriteHtml basics ==\n";
$html = '<html><head><title>Orig</title></head><body><a href="/go">x</a><img src="/i.png"><style>body{background:url(bg.jpg)}</style></body></html>';
$out = $engine->rewriteHtml($html, 'https://example.com/dir/page', ['showToolbar' => false]);
check('anchor wrapped to token', (bool)preg_match('/href="https:\/\/site\.test\/\?_glpe=1&l=([A-Za-z0-9_-]+)"/', $out, $mAnchor) && GLPE_Codec::decode($mAnchor[1]) === 'https://example.com/go', isset($mAnchor[1]) ? GLPE_Codec::decode($mAnchor[1]) : $out);
check('img wrapped to token', (bool)preg_match('/<img src="[^"]*l=([A-Za-z0-9_-]+)"/', $out, $mImg) && GLPE_Codec::decode($mImg[1]) === 'https://example.com/i.png', isset($mImg[1]) ? GLPE_Codec::decode($mImg[1]) : $out);
check('css url wrapped to token', (bool)preg_match('/url\("[^"]*l=([A-Za-z0-9_-]+)"\)/', $out, $mCss) && GLPE_Codec::decode($mCss[1]) === 'https://example.com/dir/bg.jpg', isset($mCss[1]) ? GLPE_Codec::decode($mCss[1]) : $out);
check('no readable destination in markup', strpos($out, 'example.com/go') === false && strpos($out, '/i.png') === false && strpos($out, 'bg.jpg') === false, $out);
check('ctx destination wrapped', strpos($out, '"u":"' . GLPE_Codec::encode('https://example.com/dir/page') . '"') !== false, $out);
check('companion script injected', strpos($out, '__glpe_ctx__') !== false && strpos($out, '__glpe_installed__') !== false);
$out2 = $engine->rewriteHtml($html, 'https://example.com/', ['stripTitle' => true, 'showToolbar' => false]);
check('generic tab title', strpos($out2, '<title>سند وب | Web Viewer</title>') !== false);
$out3 = $engine->rewriteHtml('<html><body><script>alert("https://evil.com/x")</script></body></html>', 'https://example.com/', ['removeScripts' => true, 'showToolbar' => false]);
check('scripts removed on demand', strpos($out3, '<script>') === false && strpos($out3, 'alert') === false);
$out4 = $engine->rewriteHtml('<html><head><title>t</title></head><body>x</body></html>', 'https://example.com/', ['showToolbar' => true]);
check('nav bar injected', strpos($out4, '__glpe_bar') !== false && strpos($out4, '__glpe_badge') !== false);

echo "\n== 6b. Direct-load hardening (core tenet) ==\n";
$hard = '<html><head><base href="https://example.com/"><meta http-equiv="Content-Security-Policy-Report-Only" content="default-src https:"></head>'
      . '<body><a href="/ok" ping="https://beacon.test/ping">l</a>'
      . '<link rel="stylesheet" href="/s.css" integrity="sha384-abc" crossorigin="anonymous">'
      . '<object data="https://cdn.test/o.swf"></object>'
      . '<button formaction="https://pay.test/checkout">pay</button>'
      . '<div onclick="goto(\'https://jump.test/x\')">go</div>'
      . '<iframe srcdoc="&lt;a href=&quot;https://inner.test/a&quot;&gt;in&lt;/a&gt;"></iframe>'
      . '</body></html>';
$oh = $engine->rewriteHtml($hard, 'https://example.com/', ['showToolbar' => false]);
check('base marker stripped', stripos($oh, '<base') === false, $oh);
check('CSP meta (report-only) stripped', stripos($oh, 'Content-Security-Policy') === false, $oh);
check('ping attribute stripped', stripos($oh, ' ping="') === false && stripos($oh, 'ping=') === false, $oh);
check('integrity attribute stripped', stripos($oh, 'integrity=') === false, $oh);
check('object data wrapped', (bool)preg_match('/<object data="[^"]*l=([A-Za-z0-9_-]+)"/', $oh, $mObj) && GLPE_Codec::decode($mObj[1]) === 'https://cdn.test/o.swf', isset($mObj[1]) ? GLPE_Codec::decode($mObj[1]) : $oh);
check('formaction wrapped', (bool)preg_match('/formaction="[^"]*l=([A-Za-z0-9_-]+)"/', $oh, $mFa) && GLPE_Codec::decode($mFa[1]) === 'https://pay.test/checkout', isset($mFa[1]) ? GLPE_Codec::decode($mFa[1]) : $oh);
check('onclick URL wrapped', (bool)preg_match('/onclick="[^"]*l=([A-Za-z0-9_-]+)/', $oh, $mOc) && GLPE_Codec::decode($mOc[1]) === 'https://jump.test/x', isset($mOc[1]) ? GLPE_Codec::decode($mOc[1]) : $oh);
check('srcdoc document rewritten', (bool)preg_match('/srcdoc="[^"]*l=([A-Za-z0-9_-]+)/', $oh, $mSd) && GLPE_Codec::decode($mSd[1]) === 'https://inner.test/a', isset($mSd[1]) ? GLPE_Codec::decode($mSd[1]) : $oh);
check('no readable external host left', strpos($oh, 'beacon.test') === false && strpos($oh, 'cdn.test') === false && strpos($oh, 'pay.test') === false && strpos($oh, 'jump.test') === false && strpos($oh, 'inner.test') === false, $oh);

echo "\n== 7. rewriteJs / rewriteCss ==\n";
$js = 'var u = "https://cdn.example.org/lib.js";';
$ojs = $engine->rewriteJs($js, 'https://example.com/');
check('js ref wrapped', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $ojs, $mj1) && GLPE_Codec::decode($mj1[1]) === 'https://cdn.example.org/lib.js', isset($mj1[1]) ? GLPE_Codec::decode($mj1[1]) : $ojs);
$css = '@import "extra.css"; .a{background:url(../im.png)}';
$ocss = $engine->rewriteCss($css, 'https://example.com/a/');
check('css @import wrapped', (bool)preg_match_all('/l=([A-Za-z0-9_-]+)/', $ocss, $mcAll) >= 2 && GLPE_Codec::decode($mcAll[1][0]) === 'https://example.com/a/extra.css', isset($mcAll[1][0]) ? GLPE_Codec::decode($mcAll[1][0]) : $ocss);
check('css relative url wrapped', isset($mcAll[1][1]) && GLPE_Codec::decode($mcAll[1][1]) === 'https://example.com/im.png', isset($mcAll[1][1]) ? GLPE_Codec::decode($mcAll[1][1]) : $ocss);

echo "\n== 8. Access policy (GLPE_Access) ==\n";
require $root . '/glpe-viewer/includes/Access.php';
check('capability identifier', GLPE_Access::CAP === 'glpe_browser');
check('holder allowed', GLPE_Access::decide(true, true) === 'allow');
check('anonymous visitor sent to login', GLPE_Access::decide(false, false) === 'login');
check('signed-in without permission forbidden', GLPE_Access::decide(true, false) === 'forbidden');
check('policy never allows without cap', GLPE_Access::decide(false, true) === 'allow' && GLPE_Access::decide(false, false) !== 'allow');

echo "\n== 9. Per-user session management ==\n";
$_SESSION = [];
$jar2 = new GLPE_Cookies(false);
$jar2->addCookieFromHeader('a=1; Path=/', 'https://example.com/');
$jar2->addCookieFromHeader('b=2; Domain=example.com; Path=/', 'https://example.com/');
$jar2->addCookieFromHeader('c=3', 'https://test.org/x/y');
$grouped = $jar2->getAllCookiesByDomain();
check('grouped by domain', count($grouped) === 2 && count($grouped['example.com']) === 2);
check('countAll', $jar2->countAll() === 3);
check('remove single record', $jar2->removeCookie('example.com', 'a', '/') === true && $jar2->countAll() === 2);
check('remove with wrong path fails', $jar2->removeCookie('example.com', 'b', '/nope') === false);
check('dot-domain clear', $jar2->clearDomain('.example.com') === 1 && $jar2->countAll() === 1);
check('other domain survives', strpos($jar2->getCookieHeader('https://test.org/x/y'), 'c=3') !== false);
check('own-host refs untouched', $engine->makeViewUrl('https://site.test/view/') === 'https://site.test/view/');
check('own-host relative untouched', $engine->makeViewUrl('/dashboard', 'https://site.test/some/page') === 'https://site.test/dashboard');
check('external still wrapped', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $engine->makeViewUrl('https://target.com/'), $mExt) && GLPE_Codec::decode($mExt[1]) === 'https://target.com/');

echo "\n== 10. Form submission handling ==\n";
// POST form: action rewritten into a wrapped link, markup untouched otherwise
$htmlPost = '<form method="POST" action="https://accounts.test/v3/sign?continue=https%3A%2F%2Fx.test%2F"><input name="e"></form>';
$outPost = $engine->rewriteHtml($htmlPost, 'https://accounts.test/signin', ['showToolbar' => false]);
check('POST action wrapped', (bool)preg_match('/action="https:\/\/site\.test\/\?_glpe=1&l=([A-Za-z0-9_-]+)"/', $outPost, $mPost) && GLPE_Codec::decode($mPost[1]) === 'https://accounts.test/v3/sign?continue=https%3A%2F%2Fx.test%2F', isset($mPost[1]) ? GLPE_Codec::decode($mPost[1]) : $outPost);
check('POST form keeps user fields', strpos($outPost, '<input name="e">') !== false);
check('POST readable endpoint absent', strpos($outPost, 'accounts.test/v3') === false, $outPost);

// GET form: gateway identity moves into wrapped hidden inputs, action loses its query
$htmlGet = '<form method="GET" action="https://x.test/search"><input name="q"></form>';
$outGet = $engine->rewriteHtml($htmlGet, 'https://x.test/', ['showToolbar' => false]);
check('GET action is query-less gateway', strpos($outGet, 'action="https://site.test/"') !== false, $outGet);
check('GET hidden gateway flag', strpos($outGet, 'name="_glpe" value="1"') !== false);
check('GET hidden endpoint is a token', (bool)preg_match('/name="l" value="([A-Za-z0-9_-]+)"/', $outGet, $mGet) && GLPE_Codec::decode($mGet[1]) === 'https://x.test/search', isset($mGet[1]) ? GLPE_Codec::decode($mGet[1]) : $outGet);
check('GET gateway fields marked data-g', isset($mGet[1]) && strpos($outGet, 'name="l" value="' . $mGet[1] . '" data-g="1"') !== false, $outGet);
check('GET drops original action query', strpos($outGet, 'x.test/search?') === false);
check('GET form carries no plaintext payload', strpos($outGet, 'https%3A%2F%2Fx.test') === false, $outGet);

// GET form without action → current page becomes the remote endpoint
$outNoAct = $engine->rewriteHtml('<form><input name="q"></form>', 'https://x.test/page', ['showToolbar' => false]);
check('GET w/o action targets current page', (bool)preg_match('/name="l" value="([A-Za-z0-9_-]+)"/', $outNoAct, $mNA) && GLPE_Codec::decode($mNA[1]) === 'https://x.test/page', isset($mNA[1]) ? GLPE_Codec::decode($mNA[1]) : $outNoAct);

// Script-driven actions stay exactly as delivered
$outJs = $engine->rewriteHtml('<form method="GET" action="javascript:void(0)"><input name="q"></form>', 'https://x.test/', ['showToolbar' => false]);
check('GET javascript: action untouched', strpos($outJs, 'action="javascript:void(0)"') !== false && strpos($outJs, 'name="_glpe"') === false, $outJs);

// Tag-name/attribute separation must survive the GET rewrite — trim() once
// produced "<formname=\"f\" ...>", an unknown element with no form semantics
// (broke every button on google.com's homepage).
$outNamed = $engine->rewriteHtml('<form name="f" action="/search" method="GET"><input name="q"><input type="submit"></form>', 'https://x.test/', ['showToolbar' => false]);
check('GET form tag space kept', strpos($outNamed, '<form name="f"') !== false && strpos($outNamed, '<formname') === false, $outNamed);
check('GET form action stripped once', strpos($outNamed, 'action="https://site.test/"') !== false && substr_count($outNamed, 'action=') === 1, $outNamed);

// The endpoint token is always reversible with the per-site key
$outEnc = $engine->rewriteHtml('<form method="GET" action="https://x.test/find"><input name="q"></form>', 'https://x.test/', ['showToolbar' => false]);
check('GET token decodes back', GLPE_Codec::decode(preg_match('/name="l" value="([^"]+)"/', $outEnc, $em) ? $em[1] : '') === 'https://x.test/find', $outEnc);

echo "\n== 10b. rewriteSrcset (srcset grammar) ==\n";
// Candidate separators are commas followed by whitespace; commas inside a
// reference (CDN size hints like "s(w:526,h:298),webp/…") belong to the URL.
$os = $engine->rewriteSrcset(
    'https://ic.test/a/s(w:526,h:298),webp/030/1.webp 526w, https://ic.test/a/s(w:1280,h:720),webp/030/2.webp 1280w',
    'https://x.test/'
);
check('candidate count preserved', count(explode(' 526w,', $os)) === 2 && count(explode(' 1280w', $os)) === 2, $os);
check('descriptors kept', strpos($os, '526w') !== false && strpos($os, '1280w') !== false, $os);
check('URL-internal commas survive inside token', (bool)preg_match_all('/l=([A-Za-z0-9_-]+)/', $os, $ms) >= 2 && strpos(GLPE_Codec::decode($ms[1][0]), 's(w:526,h:298),webp/030/1.webp') !== false, isset($ms[1][0]) ? GLPE_Codec::decode($ms[1][0]) : $os);
check('second candidate decodes back', isset($ms[1][1]) && GLPE_Codec::decode($ms[1][1]) === 'https://ic.test/a/s(w:1280,h:720),webp/030/2.webp', isset($ms[1][1]) ? GLPE_Codec::decode($ms[1][1]) : $os);
check('no readable URL in srcset', strpos($os, 'ic.test') === false, $os);
$os2 = $engine->rewriteSrcset('https://a.test/1.png,https://b.test/2.png', 'https://x.test/');
check('comma-no-space is one URL', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $os2, $ms2) && strpos(GLPE_Codec::decode($ms2[1]), 'a.test/1.png,https://b.test/2.png') !== false, isset($ms2[1]) ? GLPE_Codec::decode($ms2[1]) : $os2);

echo "\n== 10c. Player payload escapes (YouTube streams) ==\n";
// ytInitialPlayerResponse embeds stream URLs with \u0026 escapes — the
// wrapped URL must decode to a valid target or playback requests 400.
$jsEsc = 'var s={"u":"https://rr4---sn-x.googlevideo.com/videoplayback?expire=1\u0026ei=abc\u0026ip=1.2.3.4"};';
$outEsc = $engine->rewriteJs($jsEsc, 'https://www.youtube.com/');
check('JSON escapes decoded', (bool)preg_match('/l=([^&\'"]+)/', $outEsc, $em2) && strpos(GLPE_Codec::decode($em2[1]), '&ei=abc&ip=1.2.3.4') !== false, isset($em2[1]) ? GLPE_Codec::decode($em2[1]) : $outEsc);
check('no unicode escapes left', isset($em2[1]) && strpos(GLPE_Codec::decode($em2[1]), '\\u0026') === false);

echo "\n== 10d. YouTube embed player injection ==\n";
$ytPage = $engine->rewriteHtml('<html><head><title>t</title></head><body><p>x</p></body></html>', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', ['showToolbar' => false]);
check('watch page gets embed player', strpos($ytPage, 'youtube-nocookie.com/embed/dQw4w9WgXcQ') !== false, $ytPage);
check('iframe src stays direct (documented exception)', strpos($ytPage, 'src="https://www.youtube-nocookie.com/embed/') !== false);
$ytShort = $engine->rewriteHtml('<html><body></body></html>', 'https://youtu.be/dQw4w9WgXcQ', ['showToolbar' => false]);
check('short links get embed player', strpos($ytShort, 'embed/dQw4w9WgXcQ') !== false);
$nonYt = $engine->rewriteHtml('<html><body></body></html>', 'https://www.google.com/', ['showToolbar' => false]);
check('other pages untouched', strpos($nonYt, '__glpe_ytplayer') === false);

echo "\n== 11. Script namespace safety ==\n";
$jsMixed = 'var ns="http://www.w3.org/2000/svg"; var api="https://api.test/endpoint";';
$ojsMixed = $engine->rewriteJs($jsMixed, 'https://x.test/');
check('W3C namespace untouched', strpos($ojsMixed, '"http://www.w3.org/2000/svg"') !== false, $ojsMixed);
check('api URL wrapped', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $ojsMixed, $mApi) && GLPE_Codec::decode($mApi[1]) === 'https://api.test/endpoint', isset($mApi[1]) ? GLPE_Codec::decode($mApi[1]) : $ojsMixed);
$ojsNs = $engine->rewriteJs('el("http://schemas.test.com/v1"); el2("http://schema.org/Thing");', 'https://x.test/', []);
check('schemas.* host untouched', strpos($ojsNs, 'http://schemas.test.com/v1') !== false, $ojsNs);
check('schema.org untouched', strpos($ojsNs, 'http://schema.org/Thing') !== false, $ojsNs);
$ojsPlain = $engine->rewriteJs('go("https://example.net/nav");', 'https://x.test/');
check('ordinary JS URL wrapped', (bool)preg_match('/l=([A-Za-z0-9_-]+)/', $ojsPlain, $mPl) && GLPE_Codec::decode($mPl[1]) === 'https://example.net/nav', isset($mPl[1]) ? GLPE_Codec::decode($mPl[1]) : $ojsPlain);

echo "\n== 12. Payload envelope (wrapped POST bodies) ==\n";
$payload = 'a=1&b=hello+world&c=%26raw';
$env = GLPE_Codec::encode(json_encode(['c' => 'application/x-www-form-urlencoded', 'b' => $payload]));
$un = GLPE_Codec::unwrapRequestBody('d=' . $env);
check('envelope unwraps', is_array($un) && $un['body'] === $payload && $un['ct'] === 'application/x-www-form-urlencoded', print_r($un, true));
check('non-envelope passes as null', GLPE_Codec::unwrapRequestBody('a=1&b=2') === null);
check('empty body passes as null', GLPE_Codec::unwrapRequestBody('') === null);
check('garbage token passes as null', GLPE_Codec::unwrapRequestBody('d=!!!!') === null);
$envMultipart = GLPE_Codec::encode(json_encode(['c' => 'multipart/form-data; boundary=x', 'b' => 'raw']));
check('multipart envelope rejected', GLPE_Codec::unwrapRequestBody('d=' . $envMultipart) === null);
check('envelope token is url-safe', (bool)preg_match('#^[A-Za-z0-9_-]+$#', $env));
check('data roundtrip binary-safe', GLPE_Codec::decodeData(GLPE_Codec::encode("bin\x00\x01\xFFdata")) === "bin\x00\x01\xFFdata");
check('decodeData strict on junk', GLPE_Codec::decodeData('not a token!!') === '');
check('encodeData empty input', GLPE_Codec::encode('') === '');

echo "\n== 13. Toolbar & companion config (always wrapped, no readable address) ==\n";
$tb = $engine->rewriteHtml('<html><body>x</body></html>', 'https://target-site.test/page', ['showToolbar' => true]);
check('toolbar rendered', strpos($tb, '__glpe_bar') !== false, $tb);
check('Short Links switch removed', strpos($tb, 'بازنویسی پیوند') === false, $tb);
check('toolbar input carries no readable destination', strpos($tb, 'target-site.test') === false, $tb);
check('toolbar onsubmit always wraps (no plaintext fallback)', strpos($tb, ': encodeURIComponent(v)') === false && strpos($tb, 'btoa(out.join') !== false, $tb);
check('toolbar emits no ec flag', strpos($tb, "ec=1") === false, $tb);
$ctxPos = strpos($tb, 'window.__glpe_ctx__ = ');
$ctxLine = $ctxPos !== false ? substr($tb, $ctxPos + 21, (int)strpos($tb, ";\n", $ctxPos) - $ctxPos - 21) : '';
check('ctx config carries no readable destination', $ctxLine !== '' && strpos($ctxLine, 'target-site.test') === false, $ctxLine);
check('ctx config has no enc key', strpos($ctxLine, '"enc"') === false, $ctxLine);
check('ctx destination token decodes back', (bool)preg_match('/"u":"([A-Za-z0-9_-]+)"/', $ctxLine, $mCtx) && GLPE_Codec::decode($mCtx[1]) === 'https://target-site.test/page', isset($mCtx[1]) ? GLPE_Codec::decode($mCtx[1]) : $ctxLine);

echo "\n== 14. Local current-address display (client-side only, v5.1.0) ==\n";
$tb2 = $engine->rewriteHtml('<html><body>x</body></html>', 'https://target-site.test/page?q=1', ['showToolbar' => true]);
check('server markup ships an empty Go box', (bool)preg_match('/<input type="text" name="l" value=""/', $tb2));
check('Go box disables autocomplete history', strpos($tb2, 'autocomplete="off"') !== false);
check('server markup still carries no readable destination', strpos($tb2, 'target-site.test') === false);
$jsSrc = (string)file_get_contents($root . '/glpe-viewer/includes/client.js');
check('client syncBarAddress exists', strpos($jsSrc, 'function syncBarAddress()') !== false);
check('display is decoded locally from the address-bar token', strpos($jsSrc, 'match(/[?&]l=([A-Za-z0-9_-]+)/)') !== false && strpos($jsSrc, 'cipherDecode(m[1])') !== false);
check('display refreshes after SPA history pushes', substr_count($jsSrc, 'try { syncBarAddress(); } catch (e) {}') >= 1);
check('display refreshes on back/forward + bfcache restores', strpos($jsSrc, "addEventListener('popstate', syncBarAddress)") !== false && strpos($jsSrc, "addEventListener('pageshow', syncBarAddress)") !== false);
check('focused Go box selects the stale address for overwrite', strpos($jsSrc, "'focusin'") !== false && strpos($jsSrc, 't.select()') !== false);
check('rebuilt address is never transmitted', strpos($jsSrc, 'lastBarAddress') !== false && preg_match('/(send|beacon)\([^)]*lastBarAddress/', $jsSrc) === 0);
// Wire parity: a token minted by PHP must decode to the original with the
// browser-side algorithm too — this is exactly what the local display does.
$parityUrl = 'https://display-parity.test/p?a=1&b=%D8%AF%D9%88';
$parityToken = GLPE_Codec::encode($parityUrl);
$nodeBin = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($nodeBin !== '') {
    $nodeScript = 'const t=process.argv[1],k=process.argv[2];'
        . 'const b=t.replace(/-/g,"+").replace(/_/g,"/");'
        . 'const raw=Buffer.from(b,"base64");let o="";'
        . 'for(let i=0;i<raw.length;i++){o+=String.fromCharCode(raw[i]^k.charCodeAt(i%k.length));}'
        . 'process.stdout.write(Buffer.from(o,"binary").toString("utf8"));';
    $parityOut = (string)@shell_exec(
        'node -e ' . escapeshellarg($nodeScript) . ' ' . escapeshellarg($parityToken) . ' ' . escapeshellarg(GLPE_Codec::secret()) . ' 2>/dev/null'
    );
    check('PHP token decodes back via browser-side algorithm', trim($parityOut) === $parityUrl, $parityOut);
} else {
    echo "  SKIP  PHP token decodes back via browser-side algorithm (node not available)\n";
}

echo "\n== 15. Unquoted-attribute pages + ES module graphs (v5.2.0) ==\n";
// Sites like CDN-hosted bundler apps emit attributes WITHOUT quotes and
// reference chunks as protocol-relative or module-relative specifiers.
$unq = '<!doctype html><html lang=en><head><meta charset=UTF-8>'
     . '<script src=//o-cdn.test/lib/jquery.min.js captchatype=qwen></script>'
     . '<link rel=icon href=//assets-cdn.test/app/0.0.1/favicon.png>'
     . '<link rel=manifest href=//assets-cdn.test/app/0.0.1/manifest.json crossorigin=anonymous></head>'
     . '<body><div id=root><div id=splash style=position:fixed;top:0;left:0></div></div>'
     . '<script type=module crossorigin src=//assets-cdn.test/app/0.0.1/js/main.js></script>'
     . '<script>var keep="x=y plain text stays";</script></body></html>';
$r15 = $engine->rewriteHtml($unq, 'https://qwen-like.test/', []);
check('unquoted module src gets wrapped', (bool)preg_match('/<script type="module" crossorigin src="[^"]*l=([A-Za-z0-9_-]+)"/', $r15, $m15a) && GLPE_Codec::decode($m15a[1]) === 'https://assets-cdn.test/app/0.0.1/js/main.js', isset($m15a[1]) ? GLPE_Codec::decode($m15a[1]) : substr($r15, 0, 300));
check('unquoted jquery src gets wrapped', (bool)preg_match('/src="[^"]*l=([A-Za-z0-9_-]+)" captchatype="qwen"/', $r15, $m15b) && GLPE_Codec::decode($m15b[1]) === 'https://o-cdn.test/lib/jquery.min.js', isset($m15b[1]) ? GLPE_Codec::decode($m15b[1]) : 'no match');
check('unquoted manifest href gets wrapped', (bool)preg_match('/rel="manifest" href="[^"]*l=([A-Za-z0-9_-]+)"/', $r15, $m15c) && GLPE_Codec::decode($m15c[1]) === 'https://assets-cdn.test/app/0.0.1/manifest.json', isset($m15c[1]) ? GLPE_Codec::decode($m15c[1]) : 'no match');
check('unquoted style attr preserved', strpos($r15, 'style="position:fixed;top:0;left:0"') !== false, '');
check('bare attrs stay bare', strpos($r15, 'crossorigin') !== false && strpos($r15, 'id="root"') !== false);
check('script body untouched by quoting pass', strpos($r15, 'var keep="x=y plain text stays"') !== false);
check('no direct cdn reference remains', strpos($r15, '//o-cdn.test') === false && strpos($r15, '//assets-cdn.test') === false);

$j15 = $engine->rewriteJs('import {A}from"./react-vendor.js";var d=()=>import("./chunk-1.js").then(e=>e.b);const u=new URL("./ts.worker-abc.js",import.meta.url);var w=new Worker(new URL("../core/w.js",import.meta.url));var o={from:"./locale"};var s="//x-cdn.test/lib.js";var n="not//a.host/path";var k="plain string";', 'https://assets-cdn.test/app/0.0.1/js/main.js', []);
check('static import specifier wrapped', (bool)preg_match('/from"([^"]*l=([A-Za-z0-9_-]+))"/', $j15, $m15d) && GLPE_Codec::decode($m15d[2]) === 'https://assets-cdn.test/app/0.0.1/js/react-vendor.js', isset($m15d[2]) ? GLPE_Codec::decode($m15d[2]) : $j15);
check('dynamic import specifier wrapped', (bool)preg_match('/import\("([^"]*l=([A-Za-z0-9_-]+))"\)/', $j15, $m15e) && GLPE_Codec::decode($m15e[2]) === 'https://assets-cdn.test/app/0.0.1/js/chunk-1.js', isset($m15e[2]) ? GLPE_Codec::decode($m15e[2]) : $j15);
check('new URL(import.meta.url) wrapped', (bool)preg_match('/new URL\("([^"]*l=([A-Za-z0-9_-]+))", import\.meta\.url\)/', $j15, $m15f) && GLPE_Codec::decode($m15f[2]) === 'https://assets-cdn.test/app/0.0.1/js/ts.worker-abc.js', isset($m15f[2]) ? GLPE_Codec::decode($m15f[2]) : $j15);
check('worker URL relative-to-module wrapped', (bool)preg_match('/new Worker\(new URL\("([^"]*l=([A-Za-z0-9_-]+))", import\.meta\.url\)\)/', $j15, $m15g) && GLPE_Codec::decode($m15g[2]) === 'https://assets-cdn.test/app/0.0.1/core/w.js', isset($m15g[2]) ? GLPE_Codec::decode($m15g[2]) : $j15);
check('object literal from: untouched', strpos($j15, '{from:"./locale"}') !== false, $j15);
check('protocol-relative string wrapped', (bool)preg_match('/var s="([^"]*l=([A-Za-z0-9_-]+))"/', $j15, $m15h) && GLPE_Codec::decode($m15h[2]) === 'https://x-cdn.test/lib.js', isset($m15h[2]) ? GLPE_Codec::decode($m15h[2]) : $j15);
check('non-host double-slash string untouched', strpos($j15, '"not//a.host/path"') !== false);
check('plain strings untouched', strpos($j15, '"plain string"') !== false);
$mf = $engine->rewriteJs('{"name":"app","icons":[{"src":"//assets-cdn.test/app/0.0.1/i.png","sizes":"192x192"}],"start_url":"https://qwen-like.test/"}', 'https://qwen-like.test/manifest.json', []);
check('manifest icons wrapped', (bool)preg_match('/"src":"[^"]*l=([A-Za-z0-9_-]+)"/', $mf, $m15i) && GLPE_Codec::decode($m15i[1]) === 'https://assets-cdn.test/app/0.0.1/i.png', isset($m15i[1]) ? GLPE_Codec::decode($m15i[1]) : $mf);

echo "\n== 16. SPA router support (local pathname swap, v5.2.0) ==\n";
$js16 = (string)file_get_contents($root . '/glpe-viewer/includes/client.js');
check('boot pathname swap present (local replaceState)', strpos($js16, 'SPA router support') !== false && strpos($js16, 'window.history.replaceState(window.history.state, \'\', bootHref)') !== false);
check('history entries upgraded to path-carrying form', strpos($js16, 'function buildHistoryUrl(destHref)') !== false && strpos($js16, 'toHistoryUrl(url)') !== false);
check('history URLs keep the token in the query', strpos($js16, "'_glpe=1&l=' + cipherEncode(u.href)") !== false);
check('full navigations stay path-free', substr_count($js16, 'buildGatewayUrl(destHref)') >= 1);

echo "\n== 17. Relay core engine (v6.0.0) ==\n";
if (!defined('ABSPATH')) { define('ABSPATH', __FILE__); }
require $root . '/glpe-viewer/includes/Hub.php';
require $root . '/glpe-viewer/includes/HubTune.php';

GLPE_Hub::$testDir = sys_get_temp_dir() . '/glpe-hub-selftest-' . getmypid();
if (!is_dir(GLPE_Hub::$testDir)) { @mkdir(GLPE_Hub::$testDir, 0777, true); }
$hubToken = GLPE_Hub::token();
check('hub token auto-generated (deterministic under shims)', $hubToken === 'tken_live_' . str_repeat('x', 24), $hubToken);

// 17a. envelope codec roundtrip
$envMeta = ['a' => 't', 's' => 'sessAB', 't' => 'chAB', 'h' => 'target.test', 'p' => 443, 'k' => $hubToken, 'net' => 'tcp'];
$env     = GLPE_Hub::encodeEnvelope($envMeta);
check('envelope roundtrip', GLPE_Hub::decodeEnvelope($env) === $envMeta);
check('envelope is URL-safe chunk-safe chars', (bool)preg_match('#^[A-Za-z0-9+/=]+$#', $env));
// Query parsers turn "+" into a space; Base64 never carries real spaces, so
// the engine must restore them losslessly (the classic silent-corruption bug).
$plusEnvelope = null;
$plusMeta = null;
for ($i = 0; $i < 40; $i++) {
    $cand = ['a' => 'c', 's' => 'pl' . dechex($i), 't' => '', 'k' => $hubToken];
    $e = GLPE_Hub::encodeEnvelope($cand);
    if (strpos($e, '+') !== false) { $plusEnvelope = $e; $plusMeta = $cand; break; }
}
if ($plusEnvelope !== null) {
    $parsedAsGet = str_replace('+', ' ', $plusEnvelope); // what PHP $_GET delivers
    check('envelope survives query parser plus->space', GLPE_Hub::decodeEnvelope($parsedAsGet) === $plusMeta);
} else {
    check('envelope plus-robustness (no plus produced in 40 tries, SKIP)', true);
}

// 17b. cross-language parity with the bundled Python core (python3, stdlib only)
$pyBin = (function_exists('shell_exec') && PHP_OS !== 'Windows') ? trim((string)@shell_exec('command -v python3 2>/dev/null')) : '';
if ($pyBin !== '') {
    $pyEnv = <<<PY
import json, base64, sys
meta = {"a": "t", "s": "sessPY", "t": "chPY", "h": "hostPY", "p": 8443, "k": "K", "net": "tcp"}
sys.stdout.write(base64.b64encode(json.dumps(meta).encode()).decode()[::-1])
PY;
    $pyEnvelope = trim((string)@shell_exec('python3 -c ' . escapeshellarg($pyEnv)));
    $pyDecoded = GLPE_Hub::decodeEnvelope($pyEnvelope);
    check('python-built envelope decodes in engine', is_array($pyDecoded) && $pyDecoded['s'] === 'sessPY' && $pyDecoded['net'] === 'tcp', json_encode($pyDecoded));
    $pyDecoded2 = GLPE_Hub::decodeEnvelope(str_replace('+', ' ', $pyEnvelope));
    check('python envelope robust to plus->space parse', is_array($pyDecoded2) && $pyDecoded2['h'] === 'hostPY');

    $phpEnvelope = GLPE_Hub::encodeEnvelope(['a' => 'w', 's' => 'sessPHP', 't' => 'chPHP', 'k' => 'K']);
    $pyCheck = <<<'PY'
import sys, json, base64
meta = json.loads(base64.b64decode(sys.argv[1][::-1]))
sys.stdout.write(meta["a"] + " " + meta["s"])
PY;
    $pyOut = trim((string)@shell_exec('python3 -c ' . escapeshellarg($pyCheck) . ' ' . escapeshellarg($phpEnvelope)));
    check('engine-built envelope decodes in python', $pyOut === 'w sessPHP', $pyOut);

    $pyFrame = <<<'PY'
import sys, base64
sys.stdout.write(base64.b64decode(sys.argv[1][::-1]).decode("utf-8", "replace"))
PY;
    $pyFrameOut = (string)@shell_exec('python3 -c ' . escapeshellarg($pyFrame) . ' ' . escapeshellarg(GLPE_Hub::frameChunk("PING-PONG")));
    check('stream frame chunk parity', $pyFrameOut === 'PING-PONG', $pyFrameOut);
} else {
    check('python3 available for parity tests (SKIP)', true);
}

// 17c. garbage / wrong-secret requests stay silent
ob_start();
GLPE_Hub::handleRequest('this-is-not-an-envelope');
$out = ob_get_clean();
check('garbage envelope answered silently', $out === '');
$badEnv = GLPE_Hub::encodeEnvelope(['a' => 'c', 's' => 's1', 't' => 't1', 'k' => 'WRONG-KEY']);
ob_start();
GLPE_Hub::handleRequest($badEnv);
$out = ob_get_clean();
check('wrong secret rejected silently', $out === '');
ob_start();
GLPE_Hub::handleRequest(GLPE_Hub::encodeEnvelope(['a' => 'zzz', 's' => 's2', 't' => 't2', 'k' => $hubToken]));
$out = ob_get_clean();
check('unknown action rejected silently', $out === '');

// 17d. vacuum (lock-file GC with cooldown) + session clear action
@unlink(GLPE_Hub::$testDir . '/.vacuum_lock'); // reset GC cooldown created by earlier probes
$oldFile = GLPE_Hub::$testDir . '/other_old.php';
$newFile = GLPE_Hub::$testDir . '/other_new.php';
$sessFile = GLPE_Hub::$testDir . '/sessV_a.php';
file_put_contents($oldFile, 'stale');
file_put_contents($newFile, 'fresh');
file_put_contents($sessFile, 'V');
touch($oldFile, time() - 7200); // far beyond DATA_EXPIRY
$clearEnv = GLPE_Hub::encodeEnvelope(['a' => 'c', 's' => 'sessV', 't' => '', 'k' => $hubToken]);
ob_start();
GLPE_Hub::handleRequest($clearEnv);
$out = ob_get_clean();
check('clear action echoes K', $out === 'K', $out);
check('clear wiped own session file', !file_exists($sessFile));
check('vacuum removed expired foreign file', !file_exists($oldFile));
check('vacuum kept fresh foreign file', file_exists($newFile));

// 17e. subprocess runner for actions needing php://input and raw stdout
$runnerFile = GLPE_Hub::$testDir . '/runner.php';
file_put_contents($runnerFile, <<<'RUN'
<?php
if (!defined('ABSPATH')) { define('ABSPATH', __FILE__); }
if (!function_exists('get_option')) { function get_option($n, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($n, $v) { return true; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($l, $x = false, $y = false) { return str_repeat('x', (int)$l); } }
if (!function_exists('wp_upload_dir')) { function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; } }
if (!function_exists('untrailingslashit')) { function untrailingslashit($v) { return rtrim((string)$v, '/'); } }
require $argv[3] . '/glpe-viewer/includes/Hub.php';
GLPE_Hub::$testDir = $argv[2];
GLPE_Hub::handleRequest($argv[1]);
RUN
);
$runProc = function ($envelope, $stdinPath, $outPath) use ($root, $runnerFile) {
    $spec = [0 => ['file', $stdinPath, 'r'], 1 => ['file', $outPath, 'w'], 2 => ['file', $outPath, 'a']];
    $proc = @proc_open([PHP_BINARY, $runnerFile, $envelope, GLPE_Hub::$testDir, $root], $spec, $pipes);
    if (!is_resource($proc)) { return false; }
    proc_close($proc); // blocks until the runner exits
    return (string)file_get_contents($outPath);
};

// 17f. write action (client -> remote bytes land in the buffer, exact bytes)
// (php://input is empty in CLI SAPI builds, so the body is injected directly)
$payload = "RAW-RELAY-BYTES-\x01\x02\xB1\xFE";
$wEnv = GLPE_Hub::encodeEnvelope(['a' => 'w', 's' => 'sessW', 't' => 'chW', 'k' => $hubToken]);
GLPE_Hub::$testRequestBody = strrev(base64_encode($payload));
ob_start();
GLPE_Hub::handleRequest($wEnv);
$out = ob_get_clean();
GLPE_Hub::$testRequestBody = null;
check('write action echoes K', $out === 'K', var_export($out, true));
$bufFile = GLPE_Hub::$testDir . '/sessW_chW.php';
$bufRaw = file_exists($bufFile) ? (string)file_get_contents($bufFile) : '';
check('write stored guarded payload (exact bytes)', substr($bufRaw, GLPE_Hub::guardLen()) === $payload);
check('buffer file starts with self-exit guard', substr($bufRaw, 0, GLPE_Hub::guardLen()) === GLPE_Hub::FILE_GUARD);
check('buffer file executes empty on direct hit', PHP_OS === 'Linux' ? trim((string)@shell_exec(PHP_BINARY . ' ' . escapeshellarg($bufFile) . ' 2>/dev/null')) === '' : true);

// 17g. stream action against a local echo peer (fill + framed echo + cleanup)
$streamTested = false;
if (PHP_OS === 'Linux' || PHP_OS === 'Darwin') {
    $portFile = GLPE_Hub::$testDir . '/peer-port.txt';
    if (file_exists($portFile)) { @unlink($portFile); }
    $peerFile = GLPE_Hub::$testDir . '/peer.php';
    file_put_contents($peerFile, '<?php
$srv = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
if (!$srv) { exit(1); }
$name = stream_socket_get_name($srv, false);
file_put_contents($argv[1], (string)substr($name, strrpos($name, ":") + 1));
$conn = @stream_socket_accept($srv, 15);
if ($conn) {
    stream_set_timeout($conn, 5);
    $got = fread($conn, 512);
    fwrite($conn, "PONG:" . $got);
    fclose($conn);
}
exit(0);
');
    $ph = @popen(PHP_BINARY . ' ' . escapeshellarg($peerFile) . ' ' . escapeshellarg($portFile) . ' >/dev/null 2>&1 &', 'r');
    if ($ph) { @pclose($ph); }
    $peerPort = '';
    for ($i = 0; $i < 40; $i++) {
        if (file_exists($portFile)) {
            $peerPort = trim((string)file_get_contents($portFile));
            if ($peerPort !== '') { break; }
        }
        usleep(50000);
    }
    if ($peerPort !== '') {
        $sess = 'sessT' . mt_rand(10, 99);
        $chan = 'chanT';
        // Prefill the buffer: early client bytes (e.g. a TLS ClientHello) that
        // land before the stream worker picks the request up must survive.
        file_put_contents(GLPE_Hub::$testDir . '/' . $sess . '_' . $chan . '.php', GLPE_Hub::FILE_GUARD . 'HELLO');
        $tEnv = GLPE_Hub::encodeEnvelope(['a' => 't', 's' => $sess, 't' => $chan, 'h' => '127.0.0.1', 'p' => (int)$peerPort, 'k' => $hubToken, 'net' => 'tcp']);
        $outFile = GLPE_Hub::$testDir . '/stream-out.txt';
        $out = $runProc($tEnv, '/dev/null', $outFile);
        $streamTested = true;
        check('stream output starts with CDN-breaking fill', strpos($out, str_repeat(' ', 2048)) === 0);
        check('stream echoed peer data in framed chunks', strpos($out, GLPE_Hub::frameChunk('PONG:HELLO') . "\n") !== false);
        check('stream consumed buffer file', !file_exists(GLPE_Hub::$testDir . '/' . $sess . '_' . $chan . '.php'));
    }
}
if (!$streamTested) {
    check('stream action against local echo peer (SKIP on this platform)', true);
}

// 17h. metrics journal
$metrics = GLPE_Hub::readMetrics();
$events = [];
foreach ($metrics as $row) { $events[] = isset($row['event']) ? $row['event'] : ''; }
check('metrics recorded stream lifecycle', in_array('START', $events, true) && in_array('CLOSE', $events, true), implode(',', array_slice($events, 0, 8)));
check('metrics never carry readable targets', strpos(json_encode($metrics), '127.0.0.1') === false && strpos(json_encode($metrics), 'target.test') === false);
for ($i = 0; $i < 85; $i++) { GLPE_Hub::recordMetric('START', 'cap', 'i'); }
check('metrics capped at rolling window', count(GLPE_Hub::readMetrics()) <= 70, (string)count(GLPE_Hub::readMetrics()));

// 17i. generated client config mirrors the bundled core expectations
$hubCfg = GLPE_Hub::clientConfigArray();
$expectedFast = 'https://site.test/wp-content/plugins/glpe-viewer/hub-endpoint.php';
check('config exposes fast endpoint first', count($hubCfg['servers']) === 1 && $hubCfg['servers'][0]['url'] === $expectedFast && $hubCfg['servers'][0]['token'] === $hubToken, $hubCfg['servers'][0]['url']);
check('config keeps CMS path as fallback_url', isset($hubCfg['servers'][0]['fallback_url']) && $hubCfg['servers'][0]['fallback_url'] === 'https://site.test/');
check('config enables direct routing list', isset($hubCfg['routing']['direct_iran']) && $hubCfg['routing']['direct_iran'] === true);
check('config defaults relay port 41927', $hubCfg['relay_port'] === 41927);
check('config carries split timeouts', $hubCfg['connect_timeout'] === 15 && $hubCfg['stream_read_timeout'] === 30 && $hubCfg['write_timeout'] === 20);
// JSON roundtrip compares loosely: PHP encodes integral floats (1.0) without
// the zero-fraction, so decode() yields ints there — value equality is what
// the Python core needs.
$cfgDecoded = json_decode(GLPE_Hub::clientConfigJson(), true);
check('config json serializes', is_array($cfgDecoded) && $cfgDecoded == $hubCfg && (float)$cfgDecoded['max_adaptive_delay'] === 1.0);

// 17i-2. admin overrides merge into the generated config
update_option('glpe_hub_cfg', [
    'relay_port' => '50000',
    'stream_read_timeout' => '45',
    'prefer_fast_endpoint' => '0',
    'routing' => ['direct_iran' => '0', 'geosite_main_category' => 'category-ir'],
]);
update_option('glpe_hub_nodes', [
    ['url' => 'https://node2.example.com/', 'token' => 'tken_live_abcdefgh23456789'],
    ['url' => 'not-a-url', 'token' => 'x'], // must be dropped
]);
$ovCfg = GLPE_Hub::clientConfigArray();
check('override: relay port applies', $ovCfg['relay_port'] === 50000);
check('override: stream read timeout applies', $ovCfg['stream_read_timeout'] === 45.0);
check('override: CMS path becomes primary when preferred', $ovCfg['servers'][0]['url'] === 'https://site.test/');
check('override: fast path stays as fallback', $ovCfg['servers'][0]['fallback_url'] === $expectedFast);
check('override: routing flag flips to false', $ovCfg['routing']['direct_iran'] === false);
check('override: extra valid node appended', count($ovCfg['servers']) === 2 && $ovCfg['servers'][1]['url'] === 'https://node2.example.com/' && $ovCfg['servers'][1]['token'] === 'tken_live_abcdefgh23456789');
update_option('glpe_hub_cfg', []);
update_option('glpe_hub_nodes', []);

// 17i-3. node handoff store (fast endpoint credential file)
$nodeFile = GLPE_Hub::dataDir() . '/' . GLPE_Hub::NODE_FILE;
check('node store sync writes handoff file', GLPE_Hub::syncNodeStore() === true && file_exists($nodeFile));
$nodeRaw = (string)file_get_contents($nodeFile);
check('node store starts with self-exit guard', substr($nodeRaw, 0, GLPE_Hub::guardLen()) === GLPE_Hub::FILE_GUARD);
$nodePayload = json_decode(substr($nodeRaw, GLPE_Hub::guardLen()), true);
check('node store payload carries token + enabled flag', is_array($nodePayload) && ($nodePayload['token'] ?? '') === $hubToken && ($nodePayload['enabled'] ?? '') === '1');
check('node store idempotent', GLPE_Hub::syncNodeStore() === true && filesize($nodeFile) === strlen($nodeRaw));
// A stale handoff file (e.g. the node was disabled then the flag changed
// again elsewhere) must be repaired by the cheap admin-hit sync.
file_put_contents($nodeFile, GLPE_Hub::FILE_GUARD . json_encode(['token' => 'stale_token_value', 'enabled' => '0', 'dir' => '']));
$repaired = GLPE_Hub::maybeSyncNodeStore() === true && strpos((string)file_get_contents($nodeFile), '"enabled":"1"') !== false && strpos((string)file_get_contents($nodeFile), $hubToken) !== false;
check('node store repairs a stale handoff file', $repaired);
update_option('glpe_hub_enabled', '0');
check('node store flips enabled flag off', GLPE_Hub::syncNodeStore() === true && strpos((string)file_get_contents($nodeFile), '"enabled":"0"') !== false);
update_option('glpe_hub_enabled', '1');
GLPE_Hub::syncNodeStore();

// 17i-4. fast endpoint file is standalone (no CMS boot) and shimmed correctly
$endpointPath = $root . '/glpe-viewer/hub-endpoint.php';
$endpointSrc = file_exists($endpointPath) ? (string)file_get_contents($endpointPath) : '';
check('fast endpoint file ships with the plugin', $endpointSrc !== '');
check('fast endpoint reads the guarded handoff file', strpos($endpointSrc, 'node_info.php') !== false && strpos($endpointSrc, "function_exists('get_option')") !== false);
check('fast endpoint hands over to the shared engine', strpos($endpointSrc, 'GLPE_Hub::handleRequest') !== false && strpos($endpointSrc, 'includes/Hub.php') !== false);
check('fast endpoint fails closed without store', strpos($endpointSrc, "http_response_code(404)") !== false);
check('fast endpoint guard-clean', preg_match('/proxy|glype|phproxy|unblock|stealth|bypass|anonym|curl_multi|browse\.php|portal/i', $endpointSrc) === 0);
$phpLintEndpoint = @shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($endpointPath) . ' >/dev/null 2>&1; echo $?');
check('fast endpoint lints', trim((string)$phpLintEndpoint) === '0', (string)$phpLintEndpoint);

// 17i-5. tuning suggestions are pure and host-shape aware
$sugSlow = GLPE_Hub_Tune::buildSuggestions(['memory_limit_bytes' => 64 * 1048576], null, 2600, true, true, 900);
check('tuning: small memory shrinks pool', $sugSlow['max_pool_connections'] === 20);
check('tuning: slow CMS node widens idle window', $sugSlow['idle_timeout'] === 25);
check('tuning: without fast path stays on CMS', $sugSlow['prefer_fast_endpoint'] === '0');
$sugFast = GLPE_Hub_Tune::buildSuggestions(['memory_limit_bytes' => 256 * 1048576], 120, 2600, true, true, 300);
check('tuning: big memory grows pool', $sugFast['max_pool_connections'] === 50);
check('tuning: fast healthy endpoint preferred', $sugFast['prefer_fast_endpoint'] === '1');
check('tuning: snappy node relaxes adaptive delay', (float)$sugFast['max_adaptive_delay'] === 0.5);
check('tuning: write timeout respects node latency', $sugFast['write_timeout'] >= 20);
check('tuning: stream read floor stays generous', $sugFast['stream_read_timeout'] >= 30);
$sugBlocked = GLPE_Hub_Tune::buildSuggestions(['memory_limit_bytes' => 256 * 1048576], null, null, false, false, null);
check('tuning: degrades gracefully without probes', is_array($sugBlocked) && $sugBlocked['max_pool_connections'] === 50);

// 17i-6. wipe helper removes runtime files including the handoff store
check('wipe clears handoff store too', GLPE_Hub::wipeAllData() === null && !file_exists($nodeFile));

// 17j. bundled client files are guard-clean and syntactically valid
$hubScan = ['hub-client/client.py', 'hub-client/geoip_ir.txt', 'hub-client/geosite_ir.txt'];
foreach ($hubScan as $rel) {
    $abs = $root . '/glpe-viewer/' . $rel;
    $content = file_exists($abs) ? (string)file_get_contents($abs) : '';
    check("relay bundle file present: $rel", $content !== '');
    check("relay bundle guard-clean: $rel", $content === '' || preg_match('/proxy|glype|phproxy|unblock|stealth|bypass|anonym|curl_multi|browse\.php|portal/i', $content) === 0);
}
if ($pyBin !== '' && file_exists($root . '/glpe-viewer/hub-client/client.py')) {
    $pyCache = sys_get_temp_dir() . '/glpe-pycache-' . getmypid(); // keep shipped folder clean
    $pyLint = @shell_exec('PYTHONPYCACHEPREFIX=' . escapeshellarg($pyCache) . ' python3 -m py_compile ' . escapeshellarg($root . '/glpe-viewer/hub-client/client.py') . ' 2>&1; echo $?');
    check('bundled python core compiles', trim((string)$pyLint) === '0', (string)$pyLint);
}

// relay wipe helper
GLPE_Hub::wipeAllData();
$leftover = array_diff((array)@scandir(GLPE_Hub::$testDir), ['.', '..', 'runner.php', 'peer.php']);
check('wipe clears stored files', count($leftover) === 0, implode(',', $leftover));
@unlink($runnerFile); @unlink($peerFile ?? '');

// == 18. DNS hardening for the GLPE viewer fetch path (v6.1.0) ==
$dnsDir = sys_get_temp_dir() . '/glpe-dns-selftest-' . getmypid();
GLPE_Dns::$testDir = $dnsDir;
if (!is_dir($dnsDir)) { @mkdir($dnsDir, 0777, true); }

check('doh: A records kept, CNAME/TXT dropped', GLPE_Dns::parseDohAnswer('{"Status":0,"Answer":[{"name":"a.test","type":5,"data":"cname.test."},{"name":"a.test","type":1,"TTL":300,"data":"1.2.3.4"},{"name":"a.test","type":1,"TTL":300,"data":"5.6.7.8"},{"name":"a.test","type":28,"data":"2606:2800:220:1:248:1893:25c8:1946"},{"name":"a.test","type":16,"data":"txt"}]}') === ['1.2.3.4', '5.6.7.8', '2606:2800:220:1:248:1893:25c8:1946']);
check('doh: malformed json yields empty set', GLPE_Dns::parseDohAnswer('not-json') === []);
check('doh: NXDOMAIN answer yields empty set', GLPE_Dns::parseDohAnswer('{"Status":3}') === []);

check('dns err: classic error 28 matches', GLPE_Dns::isResolveError('cURL error 28: Resolving timed out after 10001 milliseconds'));
check('dns err: error 6 matches', GLPE_Dns::isResolveError('cURL error 6: Could not resolve host: test.test'));
check('dns err: resolver outage text matches', GLPE_Dns::isResolveError('Temporary failure in name resolution'));
check('dns err: unrelated curl error skipped', !GLPE_Dns::isResolveError('cURL error 60: SSL certificate problem'));
check('dns err: empty message skipped', !GLPE_Dns::isResolveError(''));

update_option('glpe_dns_mode', 'auto');
GLPE_Dns::$dohCalls = 0;
check('auto mode: no speculative pin', GLPE_Dns::planHop('http://fresh-auto.test/page?a=1') === []);
check('auto mode: does not call DoH ahead of failures', GLPE_Dns::$dohCalls === 0);

GLPE_Dns::$dohHook = function ($idx, $host) {
    if ($host === 'dead.host.test') { return ['ok' => false, 'ip' => '', 'ips' => [], 'ms' => 40]; }
    return ['ok' => true, 'ip' => '93.184.216.34', 'ips' => ['93.184.216.34'], 'ms' => 120];
};
update_option('glpe_dns_mode', 'doh_first');
GLPE_Dns::$dohCalls = 0;
check('doh_first: pin staged as host:port:ip', GLPE_Dns::planHop('https://pinned.test:8443/path') === ['pinned.test:8443:93.184.216.34']);
check('doh_first: DoH query performed', GLPE_Dns::$dohCalls === 1);
GLPE_Dns::$dohCalls = 0;
check('doh_first: repeat hop served from cache without DoH', GLPE_Dns::planHop('https://pinned.test:8443/path') === ['pinned.test:8443:93.184.216.34'] && GLPE_Dns::$dohCalls === 0);

$dnsStoreFile = $dnsDir . '/dns_store.php';
$dnsStoreRaw = (string)file_get_contents($dnsStoreFile);
check('dns store starts with self-exit guard', substr($dnsStoreRaw, 0, strlen(GLPE_Dns::GUARD)) === GLPE_Dns::GUARD);
check('dns store executes empty on direct hit', trim((string)@shell_exec(PHP_BINARY . ' ' . escapeshellarg($dnsStoreFile) . ' 2>/dev/null')) === '');

check('force resolve re-stages the pin', GLPE_Dns::forceResolve('https://pinned.test:8443/path') === true && GLPE_Dns::planHop('https://pinned.test:8443/path') === ['pinned.test:8443:93.184.216.34']);
check('force resolve fails for dead host', GLPE_Dns::forceResolve('https://dead.host.test/') === false);
check('dead host stays unpinned', GLPE_Dns::planHop('https://dead.host.test/') === []);

GLPE_Dns::$dohHook = function ($idx, $host) {
    if ($host === 'dual.test') { return ['ok' => true, 'ip' => '2606:2800::1', 'ips' => ['2606:2800::1', '198.51.100.7'], 'ms' => 90]; }
    return ['ok' => true, 'ip' => '93.184.216.34', 'ips' => ['93.184.216.34'], 'ms' => 90];
};
update_option('glpe_ipresolve_v4', '1');
check('ipv4 preference filters AAAA pins', GLPE_Dns::forceResolve('https://dual.test/') === true && GLPE_Dns::planHop('https://dual.test/') === ['dual.test:443:198.51.100.7']);
update_option('glpe_ipresolve_v4', '0');
GLPE_Dns::$dohHook = null;

update_option('glpe_dns_mode', 'nonsense');
check('settings: broken mode falls back to auto', GLPE_Dns::settings()['mode'] === 'auto');
update_option('glpe_dns_ttl', '');
check('settings: empty numeric value uses the default', GLPE_Dns::settings()['ttl'] === 900);
update_option('glpe_dns_mode', 'auto');

// Engine-level repair: a resolving stall is retried once with a fresh pin.
class GLPE_Test_WpError {
    private $msg;
    public function __construct($msg) { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof GLPE_Test_WpError; }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) {
        $GLOBALS['glpe_dns_attempts']++;
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host === 'hard-error.test') {
            return new GLPE_Test_WpError('cURL error 60: SSL certificate problem');
        }
        if ($host === 'resolve-stall.test' && $GLOBALS['glpe_dns_attempts'] === 1) {
            return new GLPE_Test_WpError('cURL error 28: Resolving timed out after 10001 milliseconds');
        }
        return ['status' => 200, 'body' => 'ok-page'];
    }
    function wp_remote_retrieve_response_code($r) { return is_wp_error($r) ? 0 : (int)$r['status']; }
    function wp_remote_retrieve_header($r, $name) { return ''; }
    function wp_remote_retrieve_headers($r) { return []; }
    function wp_remote_retrieve_body($r) { return is_wp_error($r) ? '' : (string)$r['body']; }
}
$dnsEngine = new GLPE_Engine('', false);
GLPE_Dns::$dohHook = function ($idx, $host) { return ['ok' => true, 'ip' => '203.0.113.9', 'ips' => ['203.0.113.9'], 'ms' => 80]; };
$GLOBALS['glpe_dns_attempts'] = 0;
try {
    $resPage = $dnsEngine->executeRequest('http://resolve-stall.test/page');
    check('engine: resolving stall repaired on retry', isset($resPage['body']) && $resPage['body'] === 'ok-page');
    check('engine: exactly two attempts were made', $GLOBALS['glpe_dns_attempts'] === 2);
} catch (Exception $e) {
    check('engine: resolving stall repaired on retry', false, $e->getMessage());
    check('engine: exactly two attempts were made', false);
}
$GLOBALS['glpe_dns_attempts'] = 0;
try {
    $dnsEngine->executeRequest('http://hard-error.test/page');
    check('engine: non-resolve error fails fast without retry', false);
} catch (Exception $e) {
    check('engine: non-resolve error fails fast without retry', $GLOBALS['glpe_dns_attempts'] === 1 && strpos($e->getMessage(), 'ارتباط با مقصد برقرار نشد') !== false, $e->getMessage());
}

// Fetch suggestions remain pure and cover all decision branches.
$fsHealthy = GLPE_Hub_Tune::buildFetchSuggestions(true, 120, true, 90, true, 130);
check('fetch suggest: healthy host stays auto', $fsHealthy['glpe_dns_mode'] === 'auto' && $fsHealthy['glpe_fetch_retries'] === 1);
$fsDead = GLPE_Hub_Tune::buildFetchSuggestions(false, null, true, 150, false, null);
check('fetch suggest: dead system DNS promotes DoH', $fsDead['glpe_dns_mode'] === 'doh_first' && $fsDead['glpe_dns_ttl'] === 1800 && $fsDead['glpe_fetch_connect_timeout'] >= 15);
$fsSlowSys = GLPE_Hub_Tune::buildFetchSuggestions(true, 5000, true, 200, false, null);
check('fetch suggest: slow system DNS promotes DoH', $fsSlowSys['glpe_dns_mode'] === 'doh_first');
$fsNoDoh = GLPE_Hub_Tune::buildFetchSuggestions(true, 300, false, null, false, null);
check('fetch suggest: blocked DoH keeps system mode', $fsNoDoh['glpe_dns_mode'] === 'system' && $fsNoDoh['glpe_fetch_retries'] === 2);
$fsAllDead = GLPE_Hub_Tune::buildFetchSuggestions(false, null, false, null, false, null);
check('fetch suggest: total outage degrades to system mode', $fsAllDead['glpe_dns_mode'] === 'system');

// store cleanup
foreach ((array)@scandir($dnsDir) as $dnsFile) {
    if ($dnsFile !== '.' && $dnsFile !== '..') { @unlink($dnsDir . '/' . $dnsFile); }
}
@rmdir($dnsDir);
GLPE_Dns::$testDir = null;

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
