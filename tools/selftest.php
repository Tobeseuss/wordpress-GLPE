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
$s = $engine->makeViewUrl('https://target.com/page', 'https://example.com/', ['encodeURL' => true]);
check('encoded view URL', strpos($s, '&l=') !== false && strpos($s, 'ec=1') !== false, $s);
$s2 = $engine->makeViewUrl('https://target.com/page', null, ['encodeURL' => false]);
check('plain view URL', strpos($s2, '&l=' . rawurlencode('https://target.com/page')) !== false, $s2);
check('data: untouched', $engine->makeViewUrl('data:image/png;base64,AAA') === 'data:image/png;base64,AAA');
check('anchor untouched', $engine->makeViewUrl('#sec') === '#sec');
check('no double wrap', $engine->makeViewUrl('https://site.test/?_glpe=1&l=abc') === 'https://site.test/?_glpe=1&l=abc');
$s3 = $engine->makeViewUrl('https://x.com', null, ['encodeURL' => true, 'removeScripts' => true, 'showToolbar' => true]);
check('flags appended', strpos($s3, 'ns=1') !== false && strpos($s3, 'nb=1') !== false, $s3);
// Attribute values carry HTML entities ("&amp;") — decoding is required so
// the destination receives real query fields (gmail sign-in regression).
$s4 = $engine->makeViewUrl('/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/&amp;dsh=S-1:2&amp;flowName=WebLiteSignIn', 'https://accounts.google.com/v3/signin/x', ['encodeURL' => true]);
check('attribute entities decoded', (bool)preg_match('/l=([^&]+)/', $s4, $m4) && GLPE_Codec::decode($m4[1]) === 'https://accounts.google.com/v3/signin/identifier?continue=https://mail.google.com/mail/u/0/&dsh=S-1:2&flowName=WebLiteSignIn', isset($m4[1]) ? GLPE_Codec::decode($m4[1]) : $s4);
$s5 = $engine->makeViewUrl('/v3/signin/lookup?u=https://dest.example/p', 'https://accounts.google.com/v3/x', ['encodeURL' => true]);
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
$snapshot = $jar->exportAll();
check('export captures records', isset($snapshot['example.com']['sid']), print_r($snapshot, true));
$_SESSION['_glpe_cookies'] = [];
check('empty after wipe', $jar->isEmpty());
$jar2 = new GLPE_Cookies(false);
$jar2->importAll($snapshot);
$hdrBack = $jar2->getCookieHeader('https://example.com/other');
check('import restores records', strpos($hdrBack, 'sid=abc123') !== false, $hdrBack);
$jar2->importAll('garbage-string');
check('malformed import is a no-op', strpos($jar2->getCookieHeader('https://example.com/x'), 'sid=abc123') !== false);
$jar2->importAll(['evil.com' => ['x' => 'not-an-array']]);
check('malformed entries dropped', $jar2->isEmpty());

echo "\n== 6. rewriteHtml basics ==\n";
$html = '<html><head><title>Orig</title></head><body><a href="/go">x</a><img src="/i.png"><style>body{background:url(bg.jpg)}</style></body></html>';
$out = $engine->rewriteHtml($html, 'https://example.com/dir/page', ['encodeURL' => false, 'showToolbar' => false]);
check('anchor rewritten', strpos($out, '&l=' . rawurlencode('https://example.com/go')) !== false, 'abs path /go resolves to /go');
check('img rewritten', strpos($out, 'https%3A%2F%2Fexample.com%2Fi.png') !== false);
check('css url rewritten', strpos($out, 'https%3A%2F%2Fexample.com%2Fdir%2Fbg.jpg') !== false);
check('companion script injected', strpos($out, '__glpe_ctx__') !== false && strpos($out, '__glpe_installed__') !== false);
$out2 = $engine->rewriteHtml($html, 'https://example.com/', ['encodeURL' => false, 'stripTitle' => true, 'showToolbar' => false]);
check('generic tab title', strpos($out2, '<title>سند وب | Web Viewer</title>') !== false);
$out3 = $engine->rewriteHtml('<html><body><script>alert("https://evil.com/x")</script></body></html>', 'https://example.com/', ['encodeURL' => false, 'removeScripts' => true, 'showToolbar' => false]);
check('scripts removed on demand', strpos($out3, '<script>') === false && strpos($out3, 'alert') === false);
$out4 = $engine->rewriteHtml('<html><head><title>t</title></head><body>x</body></html>', 'https://example.com/', ['encodeURL' => false, 'showToolbar' => true]);
check('nav bar injected', strpos($out4, '__glpe_bar') !== false && strpos($out4, '__glpe_badge') !== false);

echo "\n== 7. rewriteJs / rewriteCss ==\n";
$js = 'var u = "https://cdn.example.org/lib.js";';
$ojs = $engine->rewriteJs($js, 'https://example.com/', ['encodeURL' => false]);
check('js ref rewritten', strpos($ojs, rawurlencode('https://cdn.example.org/lib.js')) !== false, $ojs);
$css = '@import "extra.css"; .a{background:url(../im.png)}';
$ocss = $engine->rewriteCss($css, 'https://example.com/a/', ['encodeURL' => false]);
check('css @import rewritten', strpos($ocss, rawurlencode('https://example.com/a/extra.css')) !== false, $ocss);
check('css relative url', strpos($ocss, rawurlencode('https://example.com/im.png')) !== false, $ocss);

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
check('external still wrapped', strpos($engine->makeViewUrl('https://target.com/', null, ['encodeURL' => true]), '&l=') !== false);

echo "\n== 10. Form submission handling ==\n";
// POST form: action rewritten into an internal link, markup untouched otherwise
$htmlPost = '<form method="POST" action="https://accounts.test/v3/sign?continue=https%3A%2F%2Fx.test%2F"><input name="e"></form>';
$outPost = $engine->rewriteHtml($htmlPost, 'https://accounts.test/signin', ['encodeURL' => false, 'showToolbar' => false]);
check('POST action wrapped', strpos($outPost, 'action="https://site.test/?_glpe=1&l=') !== false, $outPost);
check('POST form keeps user fields', strpos($outPost, '<input name="e">') !== false);

// GET form: gateway identity moves into hidden inputs, action loses its query
$htmlGet = '<form method="GET" action="https://x.test/search"><input name="q"></form>';
$outGet = $engine->rewriteHtml($htmlGet, 'https://x.test/', ['encodeURL' => false, 'showToolbar' => false]);
check('GET action is query-less gateway', strpos($outGet, 'action="https://site.test/"') !== false, $outGet);
check('GET hidden gateway flag', strpos($outGet, 'name="_glpe" value="1"') !== false);
check('GET hidden remote endpoint', strpos($outGet, 'name="l" value="' . rawurlencode('https://x.test/search') . '"') !== false, $outGet);
check('GET drops original action query', strpos($outGet, 'x.test/search?') === false);

// GET form without action → current page becomes the remote endpoint
$outNoAct = $engine->rewriteHtml('<form><input name="q"></form>', 'https://x.test/page', ['encodeURL' => false, 'showToolbar' => false]);
check('GET w/o action targets current page', strpos($outNoAct, 'name="l" value="' . rawurlencode('https://x.test/page') . '"') !== false, $outNoAct);

// Script-driven actions stay exactly as delivered
$outJs = $engine->rewriteHtml('<form method="GET" action="javascript:void(0)"><input name="q"></form>', 'https://x.test/', ['encodeURL' => false, 'showToolbar' => false]);
check('GET javascript: action untouched', strpos($outJs, 'action="javascript:void(0)"') !== false && strpos($outJs, 'name="_glpe"') === false, $outJs);

// Tag-name/attribute separation must survive the GET rewrite — trim() once
// produced "<formname=\"f\" ...>", an unknown element with no form semantics
// (broke every button on google.com's homepage).
$outNamed = $engine->rewriteHtml('<form name="f" action="/search" method="GET"><input name="q"><input type="submit"></form>', 'https://x.test/', ['encodeURL' => false, 'showToolbar' => false]);
check('GET form tag space kept', strpos($outNamed, '<form name="f"') !== false && strpos($outNamed, '<formname') === false, $outNamed);
check('GET form action stripped once', strpos($outNamed, 'action="https://site.test/"') !== false && substr_count($outNamed, 'action=') === 1, $outNamed);

// Encoded-mode payload uses the reversible codec
$outEnc = $engine->rewriteHtml('<form method="GET" action="https://x.test/find"><input name="q"></form>', 'https://x.test/', ['encodeURL' => true, 'showToolbar' => false]);
check('GET encoded payload decodes back', GLPE_Codec::decode(preg_match('/name="l" value="([^"]+)"/', $outEnc, $em) ? $em[1] : '') === 'https://x.test/find', $outEnc);

echo "\n== 10b. rewriteSrcset (srcset grammar) ==\n";
// Candidate separators are commas followed by whitespace; commas inside a
// reference (CDN size hints like "s(w:526,h:298),webp/…") belong to the URL.
$os = $engine->rewriteSrcset(
    'https://ic.test/a/s(w:526,h:298),webp/030/1.webp 526w, https://ic.test/a/s(w:1280,h:720),webp/030/2.webp 1280w',
    'https://x.test/',
    ['encodeURL' => false]
);
check('URL-internal commas kept', strpos($os, 's%28w%3A526%2Ch%3A298%29%2Cwebp%2F030%2F1.webp') !== false && strpos($os, 's%28w%3A1280%2Ch%3A720%29%2Cwebp%2F030%2F2.webp') !== false, $os);
check('candidate count preserved', count(explode(' 526w,', $os)) === 2 && count(explode(' 1280w', $os)) === 2, $os);
check('descriptors kept', strpos($os, '526w') !== false && strpos($os, '1280w') !== false, $os);
$os2 = $engine->rewriteSrcset('https://a.test/1.png,https://b.test/2.png', 'https://x.test/', ['encodeURL' => false]);
check('comma-no-space is one URL', strpos($os2, 'a.test%2F1.png%2Chttps%3A%2F%2Fb.test%2F2.png') !== false, $os2);

echo "\n== 11. Script namespace safety ==\n";
$jsMixed = 'var ns="http://www.w3.org/2000/svg"; var api="https://api.test/endpoint";';
$ojsMixed = $engine->rewriteJs($jsMixed, 'https://x.test/', ['encodeURL' => false]);
check('W3C namespace untouched', strpos($ojsMixed, '"http://www.w3.org/2000/svg"') !== false, $ojsMixed);
check('api URL still rewritten', strpos($ojsMixed, rawurlencode('https://api.test/endpoint')) !== false, $ojsMixed);
$ojsNs = $engine->rewriteJs('el("http://schemas.test.com/v1"); el2("http://schema.org/Thing");', 'https://x.test/', []);
check('schemas.* host untouched', strpos($ojsNs, 'http://schemas.test.com/v1') !== false, $ojsNs);
check('schema.org untouched', strpos($ojsNs, 'http://schema.org/Thing') !== false, $ojsNs);
$ojsPlain = $engine->rewriteJs('go("https://example.net/nav");', 'https://x.test/', ['encodeURL' => false]);
check('ordinary JS URL rewritten', strpos($ojsPlain, rawurlencode('https://example.net/nav')) !== false, $ojsPlain);

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
