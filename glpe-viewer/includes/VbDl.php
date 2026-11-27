<?php
/**
 * GLPE Virtual Browser — host-side file downloads (v7.4.0).
 *
 * Clicking a download link inside the virtual browser (or the viewer)
 * must not pull a multi-megabyte file through PHP's buffered pipeline —
 * free hosting kills that on memory or on the ~30 s execution window.
 * Two delivery paths ship instead, both riding the encrypted gateway:
 *
 *   1. Tunnel (preferred, always tried first): the gateway streams the
 *      destination's own response straight to the visitor's browser —
 *      chunked read/flush, nothing buffered in memory. Works whenever
 *      the file completes within the host's execution window; the
 *      browser's own save dialog receives it.
 *
 *   2. Host store (the fallback for big files): the file is fetched in
 *      ranged chunks — each chunk one ordinary gateway request inside
 *      the host's execution window — appended into a guarded runtime
 *      folder, and when complete the visitor receives a short-lived
 *      opaque serving URL that streams the stored bytes from disk.
 *      Folder name, per-file ceiling, total quota and retention are all
 *      admin settings; retention reaping is lazy and request-driven
 *      (no cron on free hosting).
 *
 * Zero external dependencies. PHP 7.2 - 8.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GLPE_VbDl {

    const OPT_THRESHOLD = 'glpe_dl_threshold';  // MiB — tunnel ceiling
    const OPT_FOLDER    = 'glpe_dl_folder';     // guarded folder under uploads
    const OPT_RETENTION = 'glpe_dl_retention';  // minutes a stored file lives
    const OPT_MAXSIZE   = 'glpe_dl_maxsize';    // MiB per stored file
    const OPT_QUOTA     = 'glpe_dl_quota';       // MiB total across stored files

    const DIR_DEF   = 'glpe-downloads';
    const THRESH_DEF = 8;     // MiB
    const RET_DEF   = 120;   // minutes
    const MAX_DEF   = 256;   // MiB
    const QUOTA_DEF = 512;   // MiB
    const CHUNK     = 4194304; // 4 MiB per round trip
    const PREFIX    = 'glpe_vbdl_';

    /** @var string|null test override for the storage directory. */
    public static $testDir = null;
    /** @var callable|null test stub for upstream fetches: (url, method, headers) => result|Exception. */
    public static $fetchStub = null;
    /** @var array|null test store for download sessions: [token => record]. */
    public static $storeShim = null;

    // ------------------------------------------------------------------
    // Settings (all clamped — a typo in the settings form can never
    // produce a dangerous value on a shared host).
    // ------------------------------------------------------------------

    public static function threshold() { // MiB
        $v = function_exists('get_option') ? (int)get_option(self::OPT_THRESHOLD, 0) : 0;
        return ($v >= 1 && $v <= 512) ? $v : self::THRESH_DEF;
    }

    public static function folder() {
        $v = function_exists('get_option') ? trim((string)get_option(self::OPT_FOLDER, '')) : '';
        if ($v === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $v)) {
            return self::DIR_DEF;
        }
        return $v;
    }

    public static function retention() { // minutes
        $v = function_exists('get_option') ? (int)get_option(self::OPT_RETENTION, 0) : 0;
        return ($v >= 5 && $v <= 1440) ? $v : self::RET_DEF;
    }

    public static function maxSize() { // MiB
        $v = function_exists('get_option') ? (int)get_option(self::OPT_MAXSIZE, 0) : 0;
        return ($v >= 1 && $v <= 2048) ? $v : self::MAX_DEF;
    }

    public static function quota() { // MiB
        $v = function_exists('get_option') ? (int)get_option(self::OPT_QUOTA, 0) : 0;
        return ($v >= 16 && $v <= 4096) ? $v : self::QUOTA_DEF;
    }

    // ------------------------------------------------------------------
    // Storage — guarded runtime folder under uploads.
    // ------------------------------------------------------------------

    /** Guarded runtime directory (created on demand). */
    public static function dir() {
        if (is_string(self::$testDir) && self::$testDir !== '') {
            $dir = self::$testDir;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            self::ensureDirGuards($dir);
            return $dir;
        }
        if (!function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
        if ($base === '') {
            return '';
        }
        $dir = rtrim($base, '/\\') . '/' . self::folder();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return '';
            }
        }
        self::ensureDirGuards($dir);
        return $dir;
    }

    /**
     * Stored artifacts are binary — the PHP exit guard the cache files
     * carry cannot be prepended here, so protection is the deny-all
     * .htaccess plus the empty index plus serving ONLY through the
     * opaque gateway token.
     */
    private static function ensureDirGuards($dir) {
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents(
                $ht,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
            );
        }
    }

    /** Removes every stored file (admin purge). */
    public static function purge() {
        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        foreach (glob($dir . '/*.bin') ?: [] as $file) {
            @unlink($file);
        }
        return true;
    }

    /** File count / byte total for the settings card. */
    public static function stats() {
        $dir = self::dir();
        $out = ['files' => 0, 'bytes' => 0, 'dir' => $dir];
        if ($dir === '' || !is_dir($dir)) {
            return $out;
        }
        foreach (glob($dir . '/*.bin') ?: [] as $file) {
            $size = (int)@filesize($file);
            if ($size > 0) {
                $out['files']++;
                $out['bytes'] += $size;
            }
        }
        return $out;
    }

    /**
     * Lazy keeper: files older than the retention setting leave, and when
     * the total volume is over quota the oldest ones go first. Called
     * probabilistically on the download paths (no cron exists on free
     * hosting — every request is a housekeeping chance).
     */
    public static function cleanup() {
        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $retentionS = self::retention() * 60;
        $quotaB = self::quota() * 1048576;
        $entries = [];
        $total = 0;
        $now = time();
        foreach (glob($dir . '/*.bin') ?: [] as $file) {
            $size = (int)@filesize($file);
            $mtime = (int)@filemtime($file);
            if ($mtime > 0 && ($now - $mtime) > $retentionS) {
                @unlink($file);
                continue;
            }
            $entries[] = ['f' => $file, 's' => $size, 'm' => $mtime];
            $total += $size;
        }
        if ($total <= $quotaB) {
            return;
        }
        usort($entries, function ($a, $b) {
            return $a['m'] <=> $b['m'];
        });
        $target = (int)floor($quotaB * 0.9);
        foreach ($entries as $entry) {
            if ($total <= $target) {
                break;
            }
            if (@unlink($entry['f'])) {
                $total -= $entry['s'];
            }
        }
    }

    // ------------------------------------------------------------------
    // Session records (transients — self-reaping by TTL).
    // ------------------------------------------------------------------

    private static function sessGet($token) {
        $token = (string)$token;
        if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return false;
        }
        if (is_array(self::$storeShim)) {
            return isset(self::$storeShim[$token]) ? self::$storeShim[$token] : false;
        }
        $s = function_exists('get_transient') ? get_transient(self::PREFIX . $token) : false;
        return is_array($s) ? $s : false;
    }

    private static function sessSet($token, $rec) {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }
        $ttl = self::retention() * 60;
        $rec['exp'] = time() + $ttl;
        if (is_array(self::$storeShim)) {
            self::$storeShim[$token] = $rec;
            return true;
        }
        return function_exists('set_transient') ? set_transient(self::PREFIX . $token, $rec, $ttl) : false;
    }

    private static function sessDel($token) {
        $token = (string)$token;
        if (is_array(self::$storeShim)) {
            unset(self::$storeShim[$token]);
            return true;
        }
        return function_exists('delete_transient') ? delete_transient(self::PREFIX . $token) : false;
    }

    // ------------------------------------------------------------------
    // Upstream fetching through the gateway engine (same jar, identity
    // and relay pipeline as the sandbox display itself).
    // ------------------------------------------------------------------

    /**
     * Executes one upstream exchange with the sandbox profile applied.
     * Returns the engine's buffered result array or throws the engine's
     * own error. HEAD and tiny ranged probes use this; so do the chunks.
     */
    private static function fetchWith($engine, $url, $method, $range = null) {
        if (is_callable(self::$fetchStub)) {
            $res = call_user_func(self::$fetchStub, $url, $method, $range);
            if ($res instanceof Exception) {
                throw $res;
            }
            return $res;
        }
        if (!is_object($engine) || !method_exists($engine, 'executeRequest')) {
            throw new Exception('gateway engine unavailable');
        }
        $headers = [
            'accept'          => '*/*',
            'accept-language' => 'en-US,en;q=0.9',
        ];
        if (class_exists('GLPE_Vb_Sandbox')) {
            GLPE_Vb_Sandbox::applyRequestProfile($headers);
        }
        if ($range !== null) {
            $headers['range'] = 'bytes=' . $range;
        }
        return $engine->executeRequest($url, $method, null, $headers);
    }

    /** Finds one header value inside an engine result (case-insensitive). */
    private static function hdr($result, $name) {
        if (!isset($result['headers']) || !is_array($result['headers'])) {
            return '';
        }
        $name = strtolower($name);
        foreach ($result['headers'] as $k => $v) {
            if (strtolower(trim((string)$k)) === $name) {
                if (is_array($v)) {
                    $v = array_shift($v);
                }
                return str_replace(["\r", "\n", "\0"], '', trim((string)$v));
            }
        }
        return '';
    }

    /**
     * Learns what the destination would serve: size, filename, content
     * type and whether ranged requests work. HEAD first; servers that
     * reject HEAD get a one-byte ranged GET (its Content-Range carries
     * the total). A refused probe degrades gracefully — the caller may
     * still tunnel blindly.
     */
    private static function probe($engine, $url) {
        try {
            $head = self::fetchWith($engine, $url, 'HEAD');
            if ((int)$head['status'] < 400 && (int)$head['status'] !== 0) {
                return self::parseProbe($head, $url);
            }
        } catch (Exception $e) {
            // fall through to the ranged probe
        }
        try {
            $ranged = self::fetchWith($engine, $url, 'GET', '0-0');
            return self::parseProbe($ranged, $url);
        } catch (Exception $e) {
            return null;
        }
    }

    /** Normalizes one probe result into the decision inputs. */
    private static function parseProbe($result, $url) {
        $size = null;
        $cl = self::hdr($result, 'content-length');
        $cr = self::hdr($result, 'content-range');
        // A ranged probe answers 206 with Content-Range "0-0/TOTAL" while
        // Content-Length only carries the one byte — the range wins.
        if ($cr !== '' && preg_match('#bytes\s+\d+-\d+/(\d+)#i', $cr, $m)) {
            $size = (int)$m[1];
        } elseif ($cl !== '' && preg_match('/^\d+$/', $cl)) {
            $size = (int)$cl;
        }
        $ranges = false;
        $ar = self::hdr($result, 'accept-ranges');
        if ($ar !== '' && stripos($ar, 'bytes') !== false) {
            $ranges = true;
        }
        $name = self::filenameFrom($url, self::hdr($result, 'content-disposition'));
        return [
            'size'   => $size,
            'name'   => $name,
            'ct'     => self::hdr($result, 'content-type') ?: 'application/octet-stream',
            'ranges' => $ranges,
            'status' => (int)$result['status'],
        ];
    }

    /**
     * A display-safe filename: the destination's own Content-Disposition
     * first (filename*=UTF-8 preferred, then filename="..."), else the
     * URL's basename, else 'download.bin'. Control characters, path
     * separators and markup never survive.
     */
    public static function filenameFrom($url, $disposition) {
        $name = '';
        $disp = str_replace(["\r", "\n", "\0"], '', (string)$disposition);
        if ($disp !== '' && preg_match("#filename\\*=UTF-8''([A-Za-z0-9%._~-]+)#i", $disp, $m)) {
            $name = rawurldecode($m[1]);
        } elseif ($disp !== '' && preg_match('#filename="?([^";]+)"?#i', $disp, $m)) {
            $name = trim($m[1]);
        }
        if ($name === '') {
            $path = (string)parse_url($url, PHP_URL_PATH);
            if ($path !== '') {
                $base = rawurldecode(basename($path));
                if ($base !== '' && $base !== '/' && $base !== '.') {
                    $name = $base;
                }
            }
        }
        $name = preg_replace('#[\x00-\x1f\x7f/\\\\<>:"|?*]+#u', ' ', $name);
        $name = trim((string)$name);
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 96);
        } else {
            $name = substr($name, 0, 96);
        }
        if ($name === '' || $name === '.') {
            $name = 'download.bin';
        }
        return $name;
    }

    // ------------------------------------------------------------------
    // Endpoints (opaque-token in / opaque-token out, like the sandbox's
    // own vb_* family).
    // ------------------------------------------------------------------

    /**
     * Decides the delivery path for a destination URL:
     *   ['mode' => 'tunnel', 'g' => gateway URL, 'name' => ...] — stream
     *       straight to the visitor (file fits the host's window);
     *   ['mode' => 'store', 't' => session token, 'size', 'name'] — host
     *       side chunked store, the visitor drives progress;
     *   ['e' => 'toolarge'/'badurl'/'probe'] — refusals.
     */
    public static function handleDlOpen($doc, $engine) {
        $url = isset($doc['u']) ? trim((string)$doc['u']) : '';
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            self::respond(['e' => 'badurl']);
            return;
        }
        if (mt_rand(1, 8) === 1) {
            self::cleanup();
        }

        $info = self::probe($engine, $url);
        if (!is_array($info)) {
            // The destination never answered the probe — blind tunnel is
            // still the honest best effort.
            self::respond(['mode' => 'tunnel', 'g' => self::tunnelUrl($url), 'name' => self::filenameFrom($url, '')]);
            return;
        }
        if ((int)$info['status'] >= 400) {
            self::respond(['e' => 'status', 'sc' => (int)$info['status']]);
            return;
        }
        $size = $info['size'];
        if ($size !== null && $size > self::maxSize() * 1048576) {
            self::respond(['e' => 'toolarge', 'max' => self::maxSize()]);
            return;
        }

        // Tunnel when the file plausibly completes inside one execution
        // window; store when it cannot and the origin honours ranges.
        $fitsWindow = ($size !== null && $size <= self::threshold() * 1048576);
        if ($fitsWindow || !$info['ranges']) {
            self::respond(['mode' => 'tunnel', 'g' => self::tunnelUrl($url), 'name' => $info['name']]);
            return;
        }

        $token = function_exists('wp_generate_password') ? wp_generate_password(24, false, false) : '';
        if ($token === '') {
            $token = bin2hex(random_bytes(12));
        }
        $hex = function_exists('wp_generate_password') ? wp_generate_password(32, false, false) : bin2hex(random_bytes(16));
        $ok = self::sessSet($token, [
            'u'    => $url,
            'hex'  => $hex,
            'name' => $info['name'],
            'ct'   => $info['ct'],
            'size' => $size,
            'off'  => 0,
            'done' => 0,
        ]);
        if (!$ok) {
            self::respond(['mode' => 'tunnel', 'g' => self::tunnelUrl($url), 'name' => $info['name']]);
            return;
        }
        self::respond([
            'mode' => 'store',
            't'    => $token,
            'size' => $size,
            'name' => $info['name'],
        ]);
    }

    /**
     * Pulls ONE ranged chunk into the guarded folder. The visitor's
     * browser drives the loop (every free-host request must stay inside
     * the execution window); each call appends up to 4 MiB and reports
     * progress, until the file is complete and the serving URL is issued.
     */
    public static function handleDlChunk($doc, $engine) {
        $token = isset($doc['t']) ? (string)$doc['t'] : '';
        $s = self::sessGet($token);
        if (!$s || empty($s['hex'])) {
            self::respond(['e' => 'expired']);
            return;
        }
        if (!empty($s['done'])) {
            self::respond(['done' => 1, 'g' => self::serveUrl($s), 'name' => $s['name']]);
            return;
        }
        $dir = self::dir();
        if ($dir === '') {
            self::respond(['e' => 'store']);
            return;
        }
        $file = $dir . '/' . $s['hex'] . '.bin';
        $off = (int)$s['off'];
        $end = $off + self::CHUNK - 1;
        $size = ($s['size'] !== null) ? (int)$s['size'] : null;
        if ($size !== null) {
            $end = min($end, $size - 1);
        }
        if ($size !== null && $off >= $size) {
            $s['done'] = 1;
            self::sessSet($token, $s);
            self::respond(['done' => 1, 'g' => self::serveUrl($s), 'name' => $s['name'], 'got' => $off, 'total' => $size]);
            return;
        }

        try {
            $result = self::fetchWith($engine, $s['u'], 'GET', $off . '-' . max($off, $end));
        } catch (Exception $e) {
            self::respond(['e' => 'net']);
            return;
        }
        $status = (int)$result['status'];
        if ($status >= 400) {
            self::respond(['e' => 'status', 'sc' => $status]);
            return;
        }
        $body = (string)$result['body'];
        if ($body === '') {
            self::respond(['e' => 'net']);
            return;
        }
        $n = @file_put_contents($file, $body, FILE_APPEND | LOCK_EX);
        if ($n === false) {
            self::respond(['e' => 'store']);
            return;
        }
        $off += strlen($body);
        $s['off'] = $off;
        if ($status !== 206 || strlen($body) < self::CHUNK || ($size !== null && $off >= $size)) {
            $s['done'] = 1;
        }
        self::sessSet($token, $s);
        if (!empty($s['done'])) {
            self::respond(['done' => 1, 'g' => self::serveUrl($s), 'name' => $s['name'], 'got' => $off, 'total' => $size ?: $off]);
        } else {
            self::respond(['got' => $off, 'total' => $size, 'name' => $s['name']]);
        }
    }

    // ------------------------------------------------------------------
    // URLs
    // ------------------------------------------------------------------

    /** Streaming gateway URL (the tunnel path — dl=1 switches the
     *  dispatcher into the chunked streamer for any destination). */
    public static function tunnelUrl($url) {
        return add_query_arg(
            ['_glpe' => '1', 'l' => GLPE_Codec::encode($url), 'dl' => '1', 'vs' => '1'],
            home_url('/')
        );
    }

    /** Short-lived opaque serving URL for a stored file. */
    public static function serveUrl($s) {
        $token = GLPE_Codec::encode(wp_json_encode([
            'f'   => $s['hex'],
            'n'   => $s['name'],
            'ct'  => $s['ct'],
            'exp' => time() + 900,
        ]));
        return add_query_arg(['_glpe' => '1', 'dlt' => $token], home_url('/'));
    }

    /**
     * Streams a stored file to the visitor (dispatcher route dlt=...).
     * The token is decoded, its expiry checked, the file streamed in
     * disk-sized pieces — a stored file always fits the window.
     */
    public static function serveStored($rawToken) {
        $json = GLPE_Codec::decodeData(trim((string)$rawToken));
        $doc = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($doc) || empty($doc['f']) || !preg_match('/^[A-Za-z0-9]{8,64}$/', (string)$doc['f'])) {
            status_header(404);
            exit;
        }
        if (!isset($doc['exp']) || (int)$doc['exp'] < time()) {
            status_header(410);
            exit;
        }
        $dir = self::dir();
        $file = ($dir !== '') ? $dir . '/' . (string)$doc['f'] . '.bin' : '';
        if ($file === '' || !is_file($file)) {
            status_header(404);
            exit;
        }
        // The token's name is already a display-safe filename (derived by
        // filenameFrom when the session was opened) — keep it verbatim,
        // only stripping anything dangerous the token may smuggle in.
        $name = isset($doc['n']) && is_string($doc['n']) && trim($doc['n']) !== ''
            ? trim($doc['n']) : 'download.bin';
        $name = preg_replace('#[\x00-\x1f\x7f/\\\\<>:"|?*]+#u', ' ', $name);
        $name = trim((string)$name);
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 96);
        } else {
            $name = substr($name, 0, 96);
        }
        if ($name === '' || $name === '.') {
            $name = 'download.bin';
        }
        $ct = isset($doc['ct']) && is_string($doc['ct']) && $doc['ct'] !== ''
            ? preg_replace('/[^A-Za-z0-9\/\+\-\.\s]/', '', substr($doc['ct'], 0, 64))
            : 'application/octet-stream';
        if ($ct === '') {
            $ct = 'application/octet-stream';
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        @set_time_limit(0);
        @ignore_user_abort(false);
        nocache_headers();
        header('Content-Type: ' . $ct);
        header('Content-Length: ' . (string)@filesize($file));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            status_header(500);
            exit;
        }
        while (!feof($fh)) {
            $chunk = fread($fh, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            if (function_exists('flush')) {
                @flush();
            }
        }
        fclose($fh);
        if (mt_rand(1, 8) === 1) {
            self::cleanup();
        }
        exit;
    }

    /** Admin-post handler: purge stored files (nonce + manage_options). */
    public static function handlePurge() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                wp_die('غیرمجاز', 403);
            }
            exit;
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('glpe_dl_purge');
        }
        self::purge();
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(add_query_arg(
                ['page' => 'glpe-viewer-vb', 'cp_msg' => 'dl-purged'],
                function_exists('admin_url') ? admin_url('admin.php') : ''
            ));
        }
        exit;
    }

    /** Encoded JSON reply (inner payload wrapped into an opaque token). */
    private static function respond($payload) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        if (function_exists('wp_send_json')) {
            // Inside WordPress this exits; inside the selftest harness the
            // shim captures the payload and returns.
            wp_send_json(['ok' => 1, 'd' => GLPE_Codec::encode((string)$json)]);
            return;
        }
        echo '__GLPE_VBDL_REPLY__' . GLPE_Codec::encode((string)$json);
        exit;
    }
}
