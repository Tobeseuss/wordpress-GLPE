<?php
/**
 * GLPE Remote Diagnostics — standalone key-guarded endpoint.
 *
 * Answers ONLY with the correct access key ("site address + key" that the
 * site owner hands to a trusted assistant). It is deliberately fully
 * self-contained: no CMS boot for the read-only actions, no plugin file
 * is required — so it keeps working even when a broken update has taken
 * the plugin itself down. WordPress is loaded on demand for the few
 * actions that really need it (wp / set / resync / clear).
 *
 * What it exists for: hosts where the web-server / firewall layer
 * answers 403 for the node endpoints before any PHP runs. The blocker
 * can only be identified from the inside — by reading the real response
 * bodies, headers, .htaccess rules and logs, and by re-issuing the
 * blocked requests with controlled variations (user agent, method,
 * loopback pinning) to isolate the trigger.
 *
 * Behavior:
 *   - no handoff file / feature disabled / wrong key  → bare 404, silent;
 *   - repeated wrong keys                             → 30-minute lockout
 *     (the correct key then gets an honest 429 with retry_after);
 *   - every mutation (set/clear/resync) is journaled to the guarded
 *     audit file shown on the settings card.
 *
 * Actions (GET or POST, key via the X-GLPE-Key header or the "k" param):
 *   info      — PHP/server environment + the exact request headers seen
 *   wp        — CMS profile + all plugin options (secrets redacted)
 *   probe     — controlled re-issue of the node requests (variation matrix)
 *   fetch     — one arbitrary http(s) request from the host (capped)
 *   htaccess  — the .htaccess files that govern this site
 *   logs      — tails of the CMS/PHP error logs + the fatal journal
 *   set       — whitelisted option writes (validated, journaled)
 *   resync    — rewrite both guarded handoff files
 *   clear     — drop cached state (dns store / metrics / fatal journal)
 *   help      — action list
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// ---------------------------------------------------------------- location
// wp-content/plugins/glpe-viewer/diag-endpoint.php → wp-content/uploads/
// The constant override exists for the selftest only (never set in prod).
if (defined('GLPE_DIAG_STORE_DIR')) {
    $glpeDiagStoreDir = GLPE_DIAG_STORE_DIR;
} else {
    $glpeDiagStoreDir = dirname(__DIR__, 2) . '/uploads/glpe-relay-tmp';
}

// ------------------------------------------------------------- tiny helpers
/** Sends a JSON payload and stops. */
function glpe_diag_out($payload, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Bare, silent 404 — indistinguishable from a missing file. */
function glpe_diag_silent404() {
    http_response_code(404);
    exit;
}

/** One parameter from the query string, then from the form body. */
function glpe_diag_in($name, $default = null) {
    if (isset($_GET[$name])) {
        return $_GET[$name];
    }
    if (isset($_POST[$name])) {
        return $_POST[$name];
    }
    return $default;
}

// ------------------------------------------------- guarded handoff + locks
/** Reads the guarded handoff written by the admin side (array or null). */
function glpe_diag_store() {
    global $glpeDiagStoreDir;
    foreach (['diag_key.php', 'diag_key.json'] as $name) {
        $file = $glpeDiagStoreDir . '/' . $name;
        if (!is_readable($file)) {
            continue;
        }
        $raw = (string)file_get_contents($file);
        // strip a leading self-exit PHP tag if present
        $raw = preg_replace('/^<\?php[^;\n]*;[^\n]*\n?/', '', $raw, 1);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function glpe_diag_lock_file() {
    global $glpeDiagStoreDir;
    return $glpeDiagStoreDir . '/diag_lock.php';
}

/** @return array{fails:int,win:int,until:int} */
function glpe_diag_lock_state() {
    $file = glpe_diag_lock_file();
    if (!is_readable($file)) {
        return ['fails' => 0, 'win' => 0, 'until' => 0];
    }
    $raw = (string)file_get_contents($file);
    $raw = preg_replace('/^<\?php[^;\n]*;[^\n]*\n?/', '', $raw, 1);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['fails' => 0, 'win' => 0, 'until' => 0];
    }
    return [
        'fails' => isset($data['fails']) ? (int)$data['fails'] : 0,
        'win'   => isset($data['win']) ? (int)$data['win'] : 0,
        'until' => isset($data['until']) ? (int)$data['until'] : 0,
    ];
}

function glpe_diag_lock_write($state) {
    @file_put_contents(
        glpe_diag_lock_file(),
        "<?php exit; ?>\n" . (string)json_encode($state),
        LOCK_EX
    );
}

/** Counts one failed attempt; 10 failures inside 10 minutes lock 30 minutes. */
function glpe_diag_fail() {
    $st = glpe_diag_lock_state();
    if ($st['until'] > time()) {
        return; // already locked — do not extend for noise
    }
    $now = time();
    if ($st['win'] < $now - 600) {
        $st = ['fails' => 0, 'win' => $now, 'until' => 0];
    }
    $st['fails']++;
    if ($st['fails'] >= 10) {
        $st['until'] = $now + 1800;
        $st['fails'] = 0;
        $st['win'] = $now;
    }
    glpe_diag_lock_write($st);
}

/** Resets the failure counter after a successful authentication. */
function glpe_diag_unlock() {
    $st = glpe_diag_lock_state();
    if ($st['fails'] > 0 || $st['win'] !== 0) {
        glpe_diag_lock_write(['fails' => 0, 'win' => 0, 'until' => 0]);
    }
}

// ------------------------------------------------------------ HTTP fetcher
/**
 * One raw HTTP request from this host. Options: method, ua, headers
 * (name=>value), body, timeout, connect_timeout, resolve (host:port:ip),
 * max_body. Returns code/ms/err/headers/body — never throws.
 */
function glpe_diag_http($url, $opts = []) {
    $r = [
        'code' => 0, 'ms' => null, 'err' => '',
        'headers' => [], 'body' => '', 'body_len' => 0, 'via' => '',
    ];
    $method = isset($opts['method']) ? strtoupper((string)$opts['method']) : 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        $r['err'] = 'method not allowed';
        return $r;
    }
    $timeout = isset($opts['timeout']) ? (float)$opts['timeout'] : 10.0;
    $connect = isset($opts['connect_timeout']) ? (float)$opts['connect_timeout'] : 8.0;
    $maxBody = isset($opts['max_body']) ? (int)$opts['max_body'] : 65536;
    $ua = isset($opts['ua']) ? (string)$opts['ua'] : 'GLPE-Diag/1.0';
    $headers = [];
    if (isset($opts['headers']) && is_array($opts['headers'])) {
        foreach ($opts['headers'] as $hn => $hv) {
            $hn = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$hn);
            if ($hn !== '') {
                $headers[] = $hn . ': ' . str_replace(["\r", "\n"], ' ', (string)$hv);
            }
        }
    }
    $body = isset($opts['body']) ? (string)$opts['body'] : null;
    if ($body !== null && strlen($body) > 32768) {
        $body = substr($body, 0, 32768);
    }

    $t0 = microtime(true);
    if (function_exists('curl_init')) {
        $r['via'] = 'curl';
        $ch = curl_init((string)$url);
        $curlopts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_TIMEOUT_MS     => (int)round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int)round($connect * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method !== 'HEAD') {
            $curlopts[CURLOPT_NOBODY] = false;
        }
        if ($body !== null && $method === 'POST') {
            $curlopts[CURLOPT_POSTFIELDS] = $body;
        }
        if (isset($opts['resolve']) && is_string($opts['resolve']) && $opts['resolve'] !== '') {
            $curlopts[CURLOPT_RESOLVE] = [(string)$opts['resolve']];
        }
        curl_setopt_array($ch, $curlopts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        if ($raw === false) {
            $r['err'] = $err !== '' ? $err : 'curl failed';
            return $r;
        }
        $r['code'] = $code;
        $r['headers'] = glpe_diag_parse_headers((string)substr((string)$raw, 0, $hdrSize));
        $r['body'] = (string)substr((string)$raw, $hdrSize, $maxBody);
        $r['body_len'] = strlen((string)$raw) - $hdrSize;
        return $r;
    }

    // Streams fallback (no cURL on this host).
    $r['via'] = 'streams';
    $hdrLines = $headers;
    $hdrLines[] = 'User-Agent: ' . $ua;
    $ctx = stream_context_create(['http' => [
        'method'        => $method === 'HEAD' ? 'HEAD' : $method,
        'header'        => implode("\r\n", $hdrLines),
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'user_agent'    => $ua,
    ]]);
    $raw = @file_get_contents((string)$url, false, $ctx, 0, $maxBody + 1);
    $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
    $respHdrs = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    if ($raw === false && empty($respHdrs)) {
        $r['err'] = 'request failed (no response headers)';
        return $r;
    }
    if (!empty($respHdrs) && preg_match('#\s(\d{3})\s#', (string)$respHdrs[0], $m)) {
        $r['code'] = (int)$m[1];
    }
    $r['headers'] = glpe_diag_parse_headers(implode("\r\n", $respHdrs));
    $r['body'] = (string)$raw;
    $r['body_len'] = $raw === false ? 0 : strlen((string)$raw);
    return $r;
}

/** Raw header block → [[name, value], ...]. */
function glpe_diag_parse_headers($block) {
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$block) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }
        $out[] = [trim(substr($line, 0, strpos($line, ':'))), trim(substr($line, strpos($line, ':') + 1))];
    }
    return $out;
}

/** Body fragment safe for JSON: UTF-8 as text, binary as base64. */
function glpe_diag_body_safe($body, $cap = 512) {
    $body = (string)$body;
    $truncated = strlen($body) > $cap;
    $frag = $truncated ? substr($body, 0, $cap) : $body;
    // PCRE 'u' modifier validates UTF-8 without needing mbstring.
    if (preg_match('//u', $frag) === 1) {
        return ['text' => $frag, 'b64' => false, 'truncated' => $truncated];
    }
    return ['text' => base64_encode($frag), 'b64' => true, 'truncated' => $truncated];
}

/** Tail of a text file: last $maxBytes kept, last $maxLines returned. */
function glpe_diag_tail($file, $maxBytes = 65536, $maxLines = 50) {
    if (!is_file($file) || !is_readable($file) || filesize($file) === 0) {
        return null;
    }
    $size = (int)filesize($file);
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return null;
    }
    $start = max(0, $size - $maxBytes);
    fseek($fh, $start);
    $chunk = (string)fread($fh, $maxBytes);
    fclose($fh);
    $lines = preg_split('/\r\n|\r|\n/', $chunk);
    if ($start > 0) {
        array_shift($lines); // first line is likely cut mid-way
    }
    $lines = array_values(array_filter($lines, function ($l) { return trim((string)$l) !== ''; }));
    return [
        'size'     => $size,
        'from_byte' => $start,
        'lines'    => array_slice($lines, -$maxLines),
    ];
}

// ---------------------------------------------------------- CMS boot (lazy)
/** Loads the full CMS stack once; false when wp-load.php is unreachable. */
function glpe_diag_boot_wp($store) {
    if (defined('GLPE_DIAG_WP_BOOTED')) {
        return true;
    }
    $base = '';
    if (is_array($store) && isset($store['abspath']) && is_string($store['abspath']) && $store['abspath'] !== '') {
        $base = rtrim($store['abspath'], '/');
    } else {
        $base = dirname(__DIR__, 2); // wp-content/plugins/glpe-viewer → docroot
    }
    $loader = $base . '/wp-load.php';
    if (!file_exists($loader) || !is_readable($loader)) {
        return false;
    }
    require_once $loader;
    define('GLPE_DIAG_WP_BOOTED', true);
    return true;
}

/** Makes sure the admin-side class exists even if the plugin did not load it. */
function glpe_diag_load_admin_class($store) {
    if (class_exists('GLPE_Diag_Api')) {
        return true;
    }
    $dir = is_array($store) && isset($store['plugin_dir']) && is_string($store['plugin_dir']) && $store['plugin_dir'] !== ''
        ? rtrim($store['plugin_dir'], '/')
        : __DIR__;
    $file = $dir . '/includes/DiagApi.php';
    if (!file_exists($file)) {
        return false;
    }
    if (!defined('ABSPATH')) {
        return false; // the class file is guarded by ABSPATH
    }
    require_once $file;
    return class_exists('GLPE_Diag_Api');
}

/** Redacts a secret: first 6 chars + length. */
function glpe_diag_redact($value) {
    $value = (string)$value;
    return substr($value, 0, 6) . '…(' . strlen($value) . ')';
}

// ================================================================== flow ==
$glpeDiagStore = glpe_diag_store();
if (!is_array($glpeDiagStore) || empty($glpeDiagStore['enabled']) || empty($glpeDiagStore['key'])) {
    glpe_diag_silent404();
}

$glpeDiagMethod = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
if (!in_array($glpeDiagMethod, ['GET', 'POST'], true)) {
    http_response_code(405);
    exit;
}

$glpeDiagAction = strtolower((string)glpe_diag_in('action', ''));
if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $glpeDiagAction)) {
    glpe_diag_silent404();
}
$glpeDiagActions = ['info', 'wp', 'probe', 'fetch', 'htaccess', 'logs', 'set', 'resync', 'clear', 'help'];
if (!in_array($glpeDiagAction, $glpeDiagActions, true)) {
    glpe_diag_silent404();
}

$glpeDiagKey = '';
if (isset($_SERVER['HTTP_X_GLPE_KEY'])) {
    $glpeDiagKey = trim((string)$_SERVER['HTTP_X_GLPE_KEY']);
}
if ($glpeDiagKey === '') {
    $glpeDiagKey = trim((string)glpe_diag_in('k', ''));
}
if ($glpeDiagKey === '' || strlen($glpeDiagKey) > 200 || !hash_equals((string)$glpeDiagStore['key'], $glpeDiagKey)) {
    glpe_diag_fail();
    glpe_diag_silent404();
}

$glpeDiagLock = glpe_diag_lock_state();
if ($glpeDiagLock['until'] > time()) {
    glpe_diag_out(['ok' => false, 'error' => 'locked', 'retry_after' => $glpeDiagLock['until'] - time()], 429);
}
glpe_diag_unlock();

@set_time_limit(240);

// --------------------------------------------------------------- actions ==
if ($glpeDiagAction === 'help') {
    glpe_diag_out([
        'ok' => true,
        'action' => 'help',
        'data' => [
            'actions' => $glpeDiagActions,
            'auth'    => 'X-GLPE-Key header or k param',
            'ver'     => isset($glpeDiagStore['ver']) ? $glpeDiagStore['ver'] : '',
            'note'    => 'probe: add target=fast|home|self or v=<id> for one row; fetch: url method ua hdr[name]=value body max timeout resolve; set: key value; clear: what=fatals|dns|metrics|all',
        ],
    ]);
}

if ($glpeDiagAction === 'info') {
    $ext = [
        'curl'     => function_exists('curl_version') ? curl_version() : null,
        'openssl'  => extension_loaded('openssl'),
        'mbstring' => extension_loaded('mbstring'),
        'zlib'     => extension_loaded('zlib'),
        'sockets'  => extension_loaded('sockets'),
        'json'     => function_exists('json_encode'),
    ];
    $reqHeaders = [];
    foreach ($_SERVER as $hk => $hv) {
        if (strpos((string)$hk, 'HTTP_') === 0) {
            $name = str_replace('_', '-', substr((string)$hk, 5));
            $reqHeaders[] = [$name, is_string($hv) ? $hv : ''];
        }
    }
    glpe_diag_out([
        'ok' => true,
        'action' => 'info',
        'data' => [
            'server' => [
                'software'     => isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : '',
                'sapi'         => PHP_SAPI,
                'php'          => PHP_VERSION,
                'int_size'     => PHP_INT_SIZE,
                'uname'        => function_exists('php_uname') ? php_uname('s r v') : '',
                'docroot'      => isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '',
                'server_addr'  => isset($_SERVER['SERVER_ADDR']) ? (string)$_SERVER['SERVER_ADDR'] : '',
                'remote_addr'  => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
                'https'        => !empty($_SERVER['HTTPS']),
                'gateway'      => isset($_SERVER['GATEWAY_INTERFACE']) ? (string)$_SERVER['GATEWAY_INTERFACE'] : '',
                'admin_email'  => '', // never leaked here
            ],
            'ini' => [
                'memory_limit'          => (string)ini_get('memory_limit'),
                'max_execution_time'    => (string)ini_get('max_execution_time'),
                'post_max_size'         => (string)ini_get('post_max_size'),
                'upload_max_filesize'   => (string)ini_get('upload_max_filesize'),
                'max_input_vars'        => (string)ini_get('max_input_vars'),
                'display_errors'        => (string)ini_get('display_errors'),
                'allow_url_fopen'       => (string)ini_get('allow_url_fopen'),
                'open_basedir'          => (string)ini_get('open_basedir'),
                'disable_functions'     => (string)ini_get('disable_functions'),
                'error_log'             => (string)ini_get('error_log'),
                'zlib_output_compression' => (string)ini_get('zlib.output_compression'),
                'output_buffering'      => (string)ini_get('output_buffering'),
            ],
            'extensions' => $ext,
            'request' => [
                'method'  => $glpeDiagMethod,
                'host'    => isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '',
                'uri'     => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
                'headers' => $reqHeaders,
            ],
            'store' => [
                'ver'  => isset($glpeDiagStore['ver']) ? (string)$glpeDiagStore['ver'] : '',
                'gen'  => isset($glpeDiagStore['gen']) ? (int)$glpeDiagStore['gen'] : 0,
                'age'  => isset($glpeDiagStore['gen']) ? (time() - (int)$glpeDiagStore['gen']) : null,
                'home' => isset($glpeDiagStore['home']) ? (string)$glpeDiagStore['home'] : '',
                'plugin_url' => isset($glpeDiagStore['plugin_url']) ? (string)$glpeDiagStore['plugin_url'] : '',
            ],
        ],
    ]);
}

if ($glpeDiagAction === 'probe') {
    $home = isset($glpeDiagStore['home']) ? rtrim((string)$glpeDiagStore['home'], '/') : '';
    $pu   = isset($glpeDiagStore['plugin_url']) ? rtrim((string)$glpeDiagStore['plugin_url'], '/') : '';
    if ($home === '' || $pu === '') {
        glpe_diag_out(['ok' => false, 'error' => 'handoff incomplete']);
    }
    $fast = $pu . '/hub-endpoint.php';
    $self = $pu . '/diag-endpoint.php';
    // A well-formed but wrongly-authenticated envelope: if the request
    // reaches the engine it must answer 404 with an empty body — anything
    // else (403, html error pages) happens BEFORE the application.
    $env  = strrev(base64_encode('{"a":"c","s":"diagsample","t":"","k":"glpe_diag_sample_invalid_secret_value"}'));
    $envq = rawurlencode($env);
    $uaWp     = 'WordPress/6.7; ' . $home;
    $uaChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    $host = parse_url($home, PHP_URL_HOST);
    $scheme = parse_url($home, PHP_URL_SCHEME);
    $port = (int)parse_url($home, PHP_URL_PORT);
    if ($port <= 0) { $port = ($scheme === 'https') ? 443 : 80; }

    $matrix = [
        ['id' => 'fast_plain_wp',      'label' => 'پایانهٔ سریع، بدون پارامتر، هویت وردپرس', 'url' => $fast, 'method' => 'GET',  'ua' => $uaWp],
        ['id' => 'fast_v_wp',          'label' => 'پایانهٔ سریع با پاکت _v، هویت وردپرس (بازتولید دقیق ارزیاب)', 'url' => $fast . '?_v=' . $envq, 'method' => 'GET', 'ua' => $uaWp],
        ['id' => 'fast_v_chrome',      'label' => 'پایانهٔ سریع با پاکت _v، هویت مرورگر', 'url' => $fast . '?_v=' . $envq, 'method' => 'GET', 'ua' => $uaChrome],
        ['id' => 'fast_v_post',        'label' => 'پایانهٔ سریع با پاکت _v و بدنهٔ POST', 'url' => $fast . '?_v=' . $envq, 'method' => 'POST', 'ua' => $uaWp, 'body' => strrev(base64_encode('x=1'))],
        ['id' => 'home_v_wp',          'label' => 'صفحهٔ اصلی با پاکت _v، هویت وردپرس (بازتولید مسیر وردپرسی)', 'url' => $home . '/?_v=' . $envq, 'method' => 'GET', 'ua' => $uaWp],
        ['id' => 'home_plain_chrome',  'label' => 'صفحهٔ اصلی، هویت مرورگر', 'url' => $home . '/', 'method' => 'GET', 'ua' => $uaChrome],
        ['id' => 'fast_v_loopback',    'label' => 'پایانهٔ سریع با پاکت _v از مسیر درون‌سرور (127.0.0.1)', 'url' => $fast . '?_v=' . $envq, 'method' => 'GET', 'ua' => $uaWp, 'resolve' => $host . ':' . $port . ':127.0.0.1'],
        ['id' => 'self_plain_wp',      'label' => 'خودِ این پایانهٔ تشخیص (شاهد)', 'url' => $self, 'method' => 'GET', 'ua' => $uaWp],
    ];

    $only = (string)glpe_diag_in('v', '');
    $rows = [];
    foreach ($matrix as $m) {
        if ($only !== '' && $m['id'] !== $only) {
            continue;
        }
        $opts = ['method' => $m['method'], 'ua' => $m['ua'], 'timeout' => 8, 'connect_timeout' => 6, 'max_body' => 2048];
        if (isset($m['body'])) {
            $opts['body'] = $m['body'];
        }
        if (!empty($m['resolve'])) {
            $opts['resolve'] = $m['resolve'];
        }
        $res = glpe_diag_http($m['url'], $opts);
        $rows[] = [
            'id'   => $m['id'],
            'label' => $m['label'],
            'url'  => $m['url'],
            'code' => $res['code'],
            'ms'   => $res['ms'],
            'err'  => $res['err'],
            'via'  => $res['via'],
            'body_len' => $res['body_len'],
            'body_snippet' => glpe_diag_body_safe($res['body'], 240),
            'headers' => $res['headers'],
        ];
    }
    glpe_diag_out(['ok' => true, 'action' => 'probe', 'data' => ['rows' => $rows]]);
}

if ($glpeDiagAction === 'fetch') {
    $url = trim((string)glpe_diag_in('url', ''));
    if ($url === '') {
        glpe_diag_out(['ok' => false, 'error' => 'url required'], 400);
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        glpe_diag_out(['ok' => false, 'error' => 'unparsable url'], 400);
    }
    $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
    if (!in_array($scheme, ['http', 'https'], true)) {
        glpe_diag_out(['ok' => false, 'error' => 'scheme must be http or https'], 400);
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443, 8080, 8443], true)) {
        glpe_diag_out(['ok' => false, 'error' => 'port not allowed'], 400);
    }
    $method = strtoupper((string)glpe_diag_in('method', 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        glpe_diag_out(['ok' => false, 'error' => 'method not allowed'], 400);
    }
    $max = (int)glpe_diag_in('max', 65536);
    if ($max < 256) { $max = 256; }
    if ($max > 262144) { $max = 262144; }
    $timeout = (float)glpe_diag_in('timeout', 10);
    if ($timeout < 1) { $timeout = 1; }
    if ($timeout > 20) { $timeout = 20; }
    $hdrIn = glpe_diag_in('hdr', []);
    $headers = is_array($hdrIn) ? $hdrIn : [];
    $resolve = (string)glpe_diag_in('resolve', '');
    $body = glpe_diag_in('body', null);

    $res = glpe_diag_http($url, [
        'method' => $method,
        'ua'     => (string)glpe_diag_in('ua', 'GLPE-Diag/1.0'),
        'headers'=> $headers,
        'body'   => $body,
        'timeout'=> $timeout,
        'connect_timeout' => min(10, $timeout),
        'max_body' => $max,
        'resolve'=> $resolve !== '' ? $resolve : null,
    ]);
    glpe_diag_out([
        'ok' => true,
        'action' => 'fetch',
        'data' => [
            'code' => $res['code'],
            'ms'   => $res['ms'],
            'err'  => $res['err'],
            'via'  => $res['via'],
            'headers' => $res['headers'],
            'body_len' => $res['body_len'],
            'body' => glpe_diag_body_safe($res['body'], $max),
        ],
    ]);
}

if ($glpeDiagAction === 'htaccess') {
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
        ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/')
        : (isset($glpeDiagStore['abspath']) ? rtrim((string)$glpeDiagStore['abspath'], '/') : dirname(__DIR__, 2));
    $uploads = isset($glpeDiagStore['uploads_dir']) ? rtrim((string)$glpeDiagStore['uploads_dir'], '/') : '';
    $pluginDir = isset($glpeDiagStore['plugin_dir']) ? rtrim((string)$glpeDiagStore['plugin_dir'], '/') : __DIR__;
    $candidates = [
        'docroot'         => $docroot . '/.htaccess',
        'docroot_index_html' => $docroot . '/index.html', // some hosts park a static guard page
        'wp_content'      => dirname($pluginDir) . '/.htaccess',
        'wp_admin'        => $docroot . '/wp-admin/.htaccess',
        'uploads'         => $uploads !== '' ? $uploads . '/.htaccess' : '',
        'plugin'          => $pluginDir . '/.htaccess',
    ];
    $found = [];
    foreach ($candidates as $label => $file) {
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            continue;
        }
        $content = (string)file_get_contents($file, false, null, 0, 32768);
        $found[] = ['label' => $label, 'path' => $file, 'size' => filesize($file), 'content' => $content];
    }
    glpe_diag_out(['ok' => true, 'action' => 'htaccess', 'data' => ['docroot' => $docroot, 'files' => $found]]);
}

if ($glpeDiagAction === 'logs') {
    $docroot = isset($glpeDiagStore['abspath']) && $glpeDiagStore['abspath'] !== ''
        ? rtrim((string)$glpeDiagStore['abspath'], '/')
        : (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : dirname(__DIR__, 2));
    $uploads = isset($glpeDiagStore['uploads_dir']) ? rtrim((string)$glpeDiagStore['uploads_dir'], '/') : '';
    $errLogIni = (string)ini_get('error_log');

    $candidates = [
        'wp_debug_log' => $docroot . '/wp-content/debug.log',
        'docroot_error_log' => $docroot . '/error_log',
        'docroot_php_errorlog' => $docroot . '/php_errorlog',
    ];
    if ($errLogIni !== '' && $errLogIni !== 'syslog' && strpos($errLogIni, '/') !== false) {
        $candidates['ini_error_log'] = $errLogIni;
    }
    $logs = [];
    foreach ($candidates as $label => $file) {
        $tail = glpe_diag_tail($file);
        if ($tail !== null) {
            $logs[] = ['label' => $label, 'path' => $file, 'size' => $tail['size'], 'tail' => $tail['lines']];
        }
    }

    // Guarded fatal journal (no CMS boot needed — read + strip the tag).
    $fatals = [];
    if ($uploads !== '') {
        $fj = $uploads . '/glpe-viewer-tmp/fatal_log.php';
        if (is_readable($fj)) {
            $raw = (string)file_get_contents($fj);
            $raw = preg_replace('/^<\?php[^;\n]*;[^\n]*\n?/', '', $raw, 1);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $fatals = array_slice($data, -12);
            }
        }
    }
    glpe_diag_out(['ok' => true, 'action' => 'logs', 'data' => ['logs' => $logs, 'fatal_journal' => $fatals]]);
}

// ---- CMS-boot actions below ----

if ($glpeDiagAction === 'wp') {
    if (!glpe_diag_boot_wp($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'wp-load.php not reachable from the handoff abspath']);
    }
    $optionNames = [
        'glpe_toolbar', 'glpe_blank_title', 'glpe_no_scripts', 'glpe_no_images', 'glpe_ssl_verify',
        'glpe_ipresolve_v4', 'glpe_dns_mode', 'glpe_dns_ttl', 'glpe_fetch_connect_timeout',
        'glpe_fetch_timeout', 'glpe_fetch_retries', 'glpe_hub_enabled', 'glpe_hub_max_streams',
        'glpe_page_id', 'glpe_slug', 'glpe_db_version',
    ];
    $options = [];
    foreach ($optionNames as $on) {
        $v = get_option($on, null);
        $options[$on] = ($v === null) ? null : (is_array($v) ? $v : (string)$v);
    }
    $options['glpe_secret'] = glpe_diag_redact((string)get_option('glpe_secret', ''));
    $options['glpe_hub_token'] = glpe_diag_redact((string)get_option('glpe_hub_token', ''));

    // The plugin may be deactivated while the handoff file still exists —
    // every plugin class is optional here.
    $hubBlock = [];
    if (class_exists('GLPE_Hub')) {
        $hubBlock['enabled'] = GLPE_Hub::isEnabled() ? '1' : '0';
        $hubBlock['cap'] = GLPE_Hub::streamCap();
        $hubBlock['fast_url'] = GLPE_Hub::fastNodeUrl();
        $hubBlock['wp_url'] = GLPE_Hub::wpNodeUrl();
        $nodeFile = GLPE_Hub::dataDir() . '/' . GLPE_Hub::NODE_FILE;
        $nodeRaw = GLPE_Hub::readGuarded($nodeFile);
        $nodeStore = json_decode($nodeRaw, true);
        if (is_array($nodeStore) && isset($nodeStore['token'])) {
            $nodeStore['token'] = glpe_diag_redact((string)$nodeStore['token']);
        }
        $hubBlock['node_store'] = is_array($nodeStore) ? $nodeStore : ($nodeRaw === '' ? 'missing' : 'unparsable');
        $cfg = GLPE_Hub::clientConfigArray();
        if (isset($cfg['servers']) && is_array($cfg['servers'])) {
            foreach ($cfg['servers'] as $i => $srv) {
                if (isset($cfg['servers'][$i]['token'])) {
                    $cfg['servers'][$i]['token'] = glpe_diag_redact((string)$cfg['servers'][$i]['token']);
                }
            }
        }
        $hubBlock['client_config'] = $cfg;
    } else {
        $hubBlock['note'] = 'plugin classes not loaded (plugin deactivated?)';
    }
    $tuning = get_option('glpe_hub_tuning', null);
    $tuningSummary = null;
    if (is_array($tuning)) {
        $tuningSummary = [
            'at' => isset($tuning['at']) ? (int)$tuning['at'] : 0,
            'fast_endpoint' => isset($tuning['probes']['fast_endpoint']) ? $tuning['probes']['fast_endpoint'] : null,
            'wp_endpoint' => isset($tuning['probes']['wp_endpoint']) ? $tuning['probes']['wp_endpoint'] : null,
            'calibration' => isset($tuning['calibration']) ? $tuning['calibration'] : null,
            'warnings' => isset($tuning['warnings']) ? $tuning['warnings'] : [],
        ];
    }

    $viewer = ['missing_required' => [], 'missing_optional' => []];
    if (class_exists('GLPE_Diag')) {
        $viewer['missing_required'] = GLPE_Diag::missingRequired();
        $viewer['missing_optional'] = GLPE_Diag::missingOptional();
        $viewer['fatals'] = GLPE_Diag::lastFatals();
    }
    $uploadsDir = isset($glpeDiagStore['uploads_dir']) ? rtrim((string)$glpeDiagStore['uploads_dir'], '/') : '';
    $dnsBlock = null;
    if ($uploadsDir !== '' && is_readable($uploadsDir . '/glpe-viewer-tmp/dns_store.php')) {
        $raw = (string)file_get_contents($uploadsDir . '/glpe-viewer-tmp/dns_store.php');
        $raw = preg_replace('/^<\?php[^;\n]*;[^\n]*\n?/', '', $raw, 1);
        $dnsData = json_decode($raw, true);
        if (is_array($dnsData) && isset($dnsData['hosts']) && is_array($dnsData['hosts'])) {
            $dnsBlock = ['entries' => count($dnsData['hosts']), 'sample' => array_slice($dnsData['hosts'], 0, 10, true)];
        } elseif (is_array($dnsData)) {
            $dnsBlock = ['entries' => count($dnsData), 'sample' => array_slice($dnsData, 0, 10, true)];
        }
    }

    global $wpdb;
    $db = ['class' => is_object($wpdb) ? get_class($wpdb) : ''];
    if (is_object($wpdb) && method_exists($wpdb, 'db_version')) {
        $db['version'] = $wpdb->db_version();
    }

    glpe_diag_out([
        'ok' => true,
        'action' => 'wp',
        'data' => [
            'wp' => [
                'version'   => get_bloginfo('version'),
                'siteurl'   => (string)get_option('siteurl', ''),
                'home'      => (string)get_option('home', ''),
                'locale'    => get_locale(),
                'debug'     => (bool)WP_DEBUG,
                'debug_log' => defined('WP_DEBUG_LOG') ? (bool)WP_DEBUG_LOG : false,
                'debug_display' => defined('WP_DEBUG_DISPLAY') ? (bool)WP_DEBUG_DISPLAY : false,
                'ssl'       => is_ssl(),
                'multisite' => is_multisite(),
                'permalink' => (string)get_option('permalink_structure', ''),
                'active_plugins' => (array)get_option('active_plugins', []),
                'theme'     => function_exists('wp_get_theme') ? wp_get_theme()->get('Name') : '',
                'php_max_input_vars' => (int)ini_get('max_input_vars'),
            ],
            'db' => $db,
            'options' => $options,
            'hub' => $hubBlock,
            'viewer' => $viewer,
            'dns_store' => $dnsBlock,
            'tuning' => $tuningSummary,
        ],
    ]);
}

if ($glpeDiagAction === 'set') {
    if (!glpe_diag_boot_wp($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'wp-load.php not reachable from the handoff abspath']);
    }
    if (!glpe_diag_load_admin_class($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'admin class missing']);
    }
    $key = (string)glpe_diag_in('key', '');
    $value = glpe_diag_in('value', null);
    if ($key === '' || $value === null) {
        glpe_diag_out(['ok' => false, 'error' => 'key and value required'], 400);
    }
    if (is_array($value)) {
        glpe_diag_out(['ok' => false, 'error' => 'value must be scalar'], 400);
    }
    list($ok, $old, $new, $err) = GLPE_Diag_Api::applySet($key, $value);
    glpe_diag_out(['ok' => $ok, 'action' => 'set', 'data' => ['key' => $key, 'old' => $old, 'new' => $new, 'error' => $err]], $ok ? 200 : 400);
}

if ($glpeDiagAction === 'resync') {
    if (!glpe_diag_boot_wp($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'wp-load.php not reachable from the handoff abspath']);
    }
    if (!glpe_diag_load_admin_class($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'admin class missing']);
    }
    $nodeOk = class_exists('GLPE_Hub') ? GLPE_Hub::syncNodeStore() : false;
    $diagOk = GLPE_Diag_Api::syncStore();
    GLPE_Diag_Api::appendAudit('resync', 'node=' . ($nodeOk ? 'ok' : 'fail') . ' diag=' . ($diagOk ? 'ok' : 'fail'));
    $nodeFile = class_exists('GLPE_Hub') ? GLPE_Hub::dataDir() . '/' . GLPE_Hub::NODE_FILE : '';
    $diagFile = GLPE_Diag_Api::storeDir() . '/' . GLPE_Diag_Api::KEY_FILE;
    glpe_diag_out([
        'ok' => true,
        'action' => 'resync',
        'data' => [
            'node_store' => ['ok' => $nodeOk, 'exists' => $nodeFile !== '' ? file_exists($nodeFile) : false, 'mtime' => $nodeFile !== '' && file_exists($nodeFile) ? (int)filemtime($nodeFile) : 0],
            'diag_store' => ['ok' => $diagOk, 'exists' => file_exists($diagFile), 'mtime' => file_exists($diagFile) ? (int)filemtime($diagFile) : 0],
        ],
    ]);
}

if ($glpeDiagAction === 'clear') {
    if (!glpe_diag_boot_wp($glpeDiagStore)) {
        glpe_diag_out(['ok' => false, 'error' => 'wp-load.php not reachable from the handoff abspath']);
    }
    $what = strtolower((string)glpe_diag_in('what', 'all'));
    if (!in_array($what, ['fatals', 'dns', 'metrics', 'all'], true)) {
        glpe_diag_out(['ok' => false, 'error' => 'what must be fatals|dns|metrics|all'], 400);
    }
    $done = [];
    if ($what === 'fatals' || $what === 'all') {
        if (class_exists('GLPE_Diag')) {
            GLPE_Diag::clearFatals();
            $done[] = 'fatals';
        }
    }
    if ($what === 'dns' || $what === 'all') {
        $uploads = isset($glpeDiagStore['uploads_dir']) ? rtrim((string)$glpeDiagStore['uploads_dir'], '/') : '';
        if ($uploads !== '') {
            foreach (['dns_store.php', 'dns_store.json'] as $f) {
                if (@unlink($uploads . '/glpe-viewer-tmp/' . $f)) {
                    $done[] = 'dns';
                }
            }
        }
    }
    if ($what === 'metrics' || $what === 'all') {
        if (class_exists('GLPE_Hub')) {
            GLPE_Hub::clearMetrics();
            $done[] = 'metrics';
        }
    }
    if (glpe_diag_load_admin_class($glpeDiagStore)) {
        GLPE_Diag_Api::appendAudit('clear', implode(',', array_unique($done)));
    }
    glpe_diag_out(['ok' => true, 'action' => 'clear', 'data' => ['cleared' => array_values(array_unique($done))]]);
}

// Unreachable by construction — the whitelist above is closed.
glpe_diag_silent404();
