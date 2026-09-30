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

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
