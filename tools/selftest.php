<?php
/**
 * WordPress-GLPE Self-Test Suite
 * Run: php tools/selftest.php
 * No external dependencies. Exit code 0 = all pass.
 */

error_reporting(E_ALL);
$root = dirname(__DIR__);
require $root . '/cloud-portal/includes/StealthCipher.php';
require $root . '/cloud-portal/includes/CookieJar.php';
require $root . '/cloud-portal/includes/ProxyEngine.php';

$pass = 0;
$fail = 0;

function check($label, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

echo "== 1. StealthCipher roundtrip ==\n";
$urls = [
    'https://example.com/path?q=1&r=2',
    'https://en.m.wikipedia.org/wiki/Main_Page',
    'https://html.duckduckgo.com/html/?q=test+query',
    'http://news.ycombinator.com/item?id=1',
];
foreach ($urls as $u) {
    check("roundtrip $u", StealthCipher::decode(StealthCipher::encode($u)) === $u);
}
check('plain passthrough', StealthCipher::decode('https://plain.example.com') === 'https://plain.example.com');
check('url-safe alphabet', (bool)preg_match('#^[A-Za-z0-9_-]+$#', StealthCipher::encode('https://test.com/' . str_repeat('x', 64))));
check('empty input', StealthCipher::encode('') === '');

echo "\n== 2. resolveRelativeUrl (RFC 3986) ==\n";
$engine = new StealthPortalEngine('https://site.test/?_portal=1');
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

echo "\n== 3. SSRF guard ==\n";
$blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '192.168.1.1', '10.0.0.5', '172.16.0.1', '172.31.255.255', '169.254.169.254', 'metadata.google.internal'];
foreach ($blocked as $h) { check("blocked $h", $engine->isBlockedHost($h)); }
$allowed = ['example.com', '8.8.8.8', '1.1.1.1', 'proxy.example.co.uk'];
foreach ($allowed as $h) { check("allowed $h", !$engine->isBlockedHost($h)); }

echo "\n== 4. makeStreamUrl ==\n";
$s = $engine->makeStreamUrl('https://target.com/page', 'https://example.com/', ['encodeURL' => true]);
check('encoded stream URL', strpos($s, 'b=') !== false && strpos($s, 'enc=1') !== false, $s);
$s2 = $engine->makeStreamUrl('https://target.com/page', null, ['encodeURL' => false]);
check('plain stream URL', strpos($s2, 'b=' . rawurlencode('https://target.com/page')) !== false, $s2);
check('data: untouched', $engine->makeStreamUrl('data:image/png;base64,AAA') === 'data:image/png;base64,AAA');
check('anchor untouched', $engine->makeStreamUrl('#sec') === '#sec');
$s3 = $engine->makeStreamUrl('https://x.com', null, ['encodeURL' => true, 'removeScripts' => true, 'showToolbar' => true]);
check('flags appended', strpos($s3, 'rs=1') !== false && strpos($s3, 'tb=1') !== false, $s3);

echo "\n== 5. CookieJar (simulated session) ==\n";
// Simulate $_SESSION
$_SESSION = [];
$jar = new StealthCookieJar(false);
$jar->addCookieFromHeader('sid=abc123; Path=/; Domain=example.com; HttpOnly; SameSite=Lax; Max-Age=3600', 'https://example.com/login');
$hdr = $jar->getCookieHeader('https://example.com/dashboard');
check('cookie set + sent', strpos($hdr, 'sid=abc123') !== false, $hdr);
$hdr2 = $jar->getCookieHeader('https://other.com/');
check('cookie not leaked cross-domain', $hdr2 === '', $hdr2);
$jar->addCookieFromHeader('gone=x; Max-Age=0', 'https://example.com/');
$hdr3 = $jar->getCookieHeader('https://example.com/');
check('expired cookie deleted', strpos($hdr3, 'gone=') === false, $hdr3);
$jar->addCookieFromHeader('token=tok; Path=/member', 'https://example.com/user/panel');
check('path scoping', strpos($jar->getCookieHeader('https://example.com/other'), 'token=') === false && strpos($jar->getCookieHeader('https://example.com/user/panel/x'), 'token=tok') !== false);
check('getAllCookies count', count($jar->getAllCookies()) === 2);
$jar->clearAll();
check('clearAll', count($jar->getAllCookies()) === 0);

echo "\n== 6. rewriteHtml basics ==\n";
$html = '<html><head><title>Orig</title></head><body><a href="/go">x</a><img src="/i.png"><style>body{background:url(bg.jpg)}</style></body></html>';
$out = $engine->rewriteHtml($html, 'https://example.com/dir/page', ['encodeURL' => false, 'showToolbar' => false]);
check('anchor rewritten', strpos($out, 'b=' . rawurlencode('https://example.com/dir/go')) !== false, $out);
check('img rewritten', strpos($out, 'https%3A%2F%2Fexample.com%2Fdir%2Fi.png') !== false);
check('css url rewritten', strpos($out, 'https%3A%2F%2Fexample.com%2Fdir%2Fbg.jpg') !== false);
$out2 = $engine->rewriteHtml($html, 'https://example.com/', ['encodeURL' => false, 'stripTitle' => true, 'showToolbar' => false]);
check('stripTitle', strpos($out2, '<title>سند وب | Web Viewer</title>') !== false);
$out3 = $engine->rewriteHtml('<html><body><script>alert("https://evil.com/x")</script></body></html>', 'https://example.com/', ['encodeURL' => false, 'removeScripts' => true, 'showToolbar' => false]);
check('removeScripts', strpos($out3, '<script>') === false && strpos($out3, 'alert') === false);

echo "\n== 7. rewriteJs / rewriteCss ==\n";
$js = 'var u = "https://cdn.example.org/lib.js";';
$ojs = $engine->rewriteJs($js, 'https://example.com/', ['encodeURL' => false]);
check('js url rewritten', strpos($ojs, rawurlencode('https://cdn.example.org/lib.js')) !== false, $ojs);
$css = '@import "extra.css"; .a{background:url(../im.png)}';
$ocss = $engine->rewriteCss($css, 'https://example.com/a/', ['encodeURL' => false]);
check('css @import rewritten', strpos($ocss, rawurlencode('https://example.com/a/extra.css')) !== false, $ocss);
check('css relative url', strpos($ocss, rawurlencode('https://example.com/im.png')) !== false, $ocss);

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
