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
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $default; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return (string)$url; }
}

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

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
