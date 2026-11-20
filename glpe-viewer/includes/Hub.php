<?php
/**
 * GLPE Relay Core — multi-node HTTP relay engine (server side).
 *
 * Ports the standalone relay server (index.php) into WordPress while keeping
 * the wire protocol byte-compatible with the bundled Python core:
 *
 *   - Every request carries a single opaque parameter `_v` whose value is a
 *     REVERSED Base64 of a JSON envelope: {"a":action,"s":session,"t":channel,
 *     "h":host,"p":port,"k":secret,"net":tcp|udp}. Nothing readable ever
 *     appears in the URL, the query string or the body.
 *   - Action "c": wipe every stored chunk file of the given session → "K".
 *   - Action "w": POST body (reversed Base64 of raw bytes) is decoded and
 *     appended to the session/channel buffer file → "K".
 *   - Action "t": open a raw TCP/UDP socket to h:p and stream in both
 *     directions. Client→remote bytes are drained from the buffer file;
 *     remote→client bytes are emitted as reversed-Base64 lines inside a
 *     text/event-stream response with X-Accel-Buffering: no. A 2048-byte
 *     leading padding breaks CDN response buffering so bytes start flowing
 *     immediately.
 *
 * Operations hardening applied on top of the original design:
 *   - stored chunk files live inside uploads/glpe-relay-tmp/ which ships a
 *     deny-all .htaccess (Apache 2.4 + 2.2 styles) and an empty index.html;
 *   - the initial-data race is removed: the stream handler no longer deletes
 *     a freshly written buffer (channel ids are per-connection UUIDs and the
 *     client wipes its own session, so truncation was only losing early
 *     ClientHello/first-byte traffic behind slow worker pickup);
 *   - metrics are capped, flock-guarded and never contain readable targets.
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Hub {

    const DATA_EXPIRY      = 3600; // seconds a stored chunk file may live
    const CLEANUP_COOLDOWN = 300;  // vacuum lock cooldown (5 minutes)
    const CF_FILL_BYTES    = 2048; // leading padding defeating CDN buffering
    const METRICS_LIMIT    = 70;   // rolling metrics window
    const UDP_IDLE         = 30;   // idle seconds before an UDP socket closes
    const KEEPALIVE_SECS   = 2;    // silent-tunnel keepalive / abort probe

    /** @var string|null test override for the data directory (selftest only) */
    public static $testDir = null;

    /** @var string|null test override for the request body (selftest only) */
    public static $testRequestBody = null;

    /** @var string|null debug trace file (diagnostics only, empty = off) */
    public static $debugTrace = '';

    /* ------------------------------------------------------------------
     * Request entry point (hooked on init, priority 1)
     * ---------------------------------------------------------------- */

    public static function maybeHandle() {
        if (defined('GLPE_HUB_TRACE') && GLPE_HUB_TRACE !== '') {
            self::$debugTrace = (string)GLPE_HUB_TRACE;
        }
        if (!self::isEnabled()) {
            return;
        }
        if (!isset($_GET['_v'])) {
            return;
        }
        $q = (string)$_GET['_v'];
        if ($q === '') {
            http_response_code(404);
            exit;
        }
        self::handleRequest($q);
        exit;
    }

    /**
     * Full engine flow for one relay request. Echoes the response.
     * Kept free of WP-only calls below dataDir()/token() so the selftest can
     * exercise it standalone.
     */
    public static function handleRequest($q) {
        $meta = self::decodeEnvelope($q);
        if ($meta === null || !isset($meta['k']) || !hash_equals(self::token(), (string)$meta['k'])) {
            self::recordMetric('ERROR', 'UNKNOWN', 'ورود غیرمجاز یا پاکت نامعتبر (اسکن ربات‌ها)');
            self::sendStatus(404);
            return;
        }

        $action  = isset($meta['a']) ? (string)$meta['a'] : '';
        $sessId  = isset($meta['s']) ? self::cleanId($meta['s']) : '';
        $blockId = isset($meta['t']) ? self::cleanId($meta['t']) : '';

        if ($sessId === '') {
            self::sendStatus(404);
            return;
        }

        self::vacuum($sessId);

        if ($action === 'c') {
            self::actClear($sessId);
            return;
        }
        if ($action === 'w') {
            self::actWrite($sessId, $blockId);
            return;
        }
        if ($action === 't') {
            self::actStream($meta, $sessId, $blockId);
            return;
        }

        self::sendStatus(404);
    }

    /* ------------------------------------------------------------------
     * Envelope codec (must stay byte-compatible with the Python core)
     * ---------------------------------------------------------------- */

    /** Reversed-Base64(JSON) — the single obfuscated wire parameter. */
    public static function decodeEnvelope($q) {
        if (!is_string($q) || $q === '') {
            return null;
        }
        // URL-query parsers turn every "+" into a space (and Base64 payloads
        // never contain real spaces), so restoring " " -> "+" is lossless and
        // keeps envelopes intact whether or not the sender URL-encoded them.
        $q = str_replace(' ', '+', $q);
        $json = base64_decode(strrev($q), false);
        if ($json === false) {
            return null;
        }
        $meta = json_decode($json, true);
        return is_array($meta) ? $meta : null;
    }

    public static function encodeEnvelope(array $meta) {
        return strrev(base64_encode((string)json_encode($meta)));
    }

    /** Reversed-Base64(payload) — the chunk framing on the stream body. */
    public static function frameChunk($bytes) {
        return strrev(base64_encode($bytes));
    }

    public static function unframeChunk($line) {
        // Stream lines arrive inside the request body where " "+" is never
        // rewritten, but tolerate whitespace the transport may inject anyway.
        $raw = base64_decode(strrev($line), false);
        return $raw === false ? false : $raw;
    }

    private static function cleanId($value) {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
    }

    private static function sendStatus($code) {
        if (!headers_sent()) {
            http_response_code($code);
            header('Cache-Control: no-store, max-age=0');
        }
    }

    private static function sendNoStore() {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Accel-Buffering: no');
        }
    }

    /* ------------------------------------------------------------------
     * Configuration
     * ---------------------------------------------------------------- */

    public static function isEnabled() {
        return get_option('glpe_hub_enabled', '1') === '1';
    }

    public static function token() {
        $t = get_option('glpe_hub_token');
        if (!is_string($t) || strlen($t) < 24) {
            $t = 'tken_live_' . wp_generate_password(24, false, false);
            update_option('glpe_hub_token', $t);
        }
        return $t;
    }

    public static function dataDir() {
        if (self::$testDir !== null) {
            return self::$testDir;
        }
        $uploads = wp_upload_dir();
        $dir = untrailingslashit((string)$uploads['basedir']) . '/glpe-relay-tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/index.html', '');
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
            );
        }
        return $dir;
    }

    /** The node descriptor handed to the Python core's config.json. */
    public static function clientConfigArray() {
        return [
            'servers' => [
                [
                    'url'   => home_url('/'),
                    'token' => self::token(),
                ],
            ],
            'max_pool_connections'           => 30,
            'request_timeout'                => 10,
            'max_retries'                    => 1,
            'relay_port'                     => 41927,
            'max_adaptive_delay'             => 1.0,
            'idle_timeout'                   => 8.0,
            'max_tunnel_uptime'              => 3600.0,
            'low_traffic_timeout'            => 30.0,
            'low_traffic_threshold_mb'       => 0.1,
            'active_transfer_threshold_kbps' => 10.0,
            'routing' => [
                'direct_iran'           => true,
                'geoip_url'             => 'https://raw.githubusercontent.com/v2fly/geoip/release/text/ir.txt',
                'geosite_base_url'      => 'https://raw.githubusercontent.com/v2fly/domain-list-community/refs/heads/master/data/',
                'geosite_main_category' => 'category-ir',
            ],
        ];
    }

    public static function clientConfigJson() {
        $json = (string)json_encode(self::clientConfigArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json === '' ? '{}' : $json;
    }

    /* ------------------------------------------------------------------
     * Actions
     * ---------------------------------------------------------------- */

    /** Action "c" — the client ordered a wipe of its own session files. */
    private static function actClear($sessId) {
        self::sendNoStore();
        $dir   = self::dataDir();
        $nodes = @scandir($dir);
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if ($node === '.' || $node === '..' || $node === 'sys_metrics.json'
                    || $node === '.vacuum_lock' || $node === 'index.html' || $node === '.htaccess') {
                    continue;
                }
                if (strpos($node, $sessId) !== false) {
                    @unlink($dir . '/' . $node);
                }
            }
        }
        self::recordMetric('CLEAR', $sessId, 'محیط نشست قدیمی به دستور هسته کلاینت پاکسازی شد');
        echo 'K';
    }

    /** Action "w" — append client→remote bytes (reversed-Base64 body). */
    private static function actWrite($sessId, $blockId) {
        self::sendNoStore();
        // php://input is unavailable in CLI SAPI builds, so the selftest
        // injects the body through $testRequestBody; production keeps the
        // standard request stream.
        $raw = self::$testRequestBody !== null
            ? self::$testRequestBody
            : (function_exists('file_get_contents') ? @file_get_contents('php://input') : '');
        if (self::$debugTrace !== '') {
            @file_put_contents(self::$debugTrace, date('H:i:s') . " write sess={$sessId} ch=" . substr($blockId, 0, 8) . " body=" . (is_string($raw) ? strlen($raw) : -1) . "B postkeys=" . count($_POST) . " ctype=" . (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '-') . "\n", FILE_APPEND);
        }
        if (is_string($raw) && $raw !== '') {
            $payload = base64_decode(strrev($raw));
            if (is_string($payload) && $payload !== '') {
                $dir = self::dataDir();
                @file_put_contents($dir . '/' . $sessId . '_' . $blockId . '.buf', $payload, FILE_APPEND | LOCK_EX);
            }
        }
        echo 'K';
    }

    /** Action "t" — bidirectional stream against a raw TCP/UDP socket. */
    private static function actStream($meta, $sessId, $blockId) {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $host     = isset($meta['h']) ? trim((string)$meta['h'], '[]') : '';
        $port     = isset($meta['p']) ? (int)$meta['p'] : 80;
        $protocol = isset($meta['net']) ? strtolower((string)$meta['net']) : 'tcp';
        if ($protocol !== 'tcp' && $protocol !== 'udp') {
            $protocol = 'tcp';
        }

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');
        }

        self::recordMetric('START', $sessId, "Pipeline [{$protocol}] -> " . substr($blockId, 0, 6));

        if ($host === '' || $port <= 0 || $port > 65535) {
            self::recordMetric('ERROR', $sessId, 'Connection Fault: bad target');
            echo self::frameChunk('ERR_ST_CONN: bad target') . "\n";
            @ob_flush(); flush();
            return;
        }

        $dir       = self::dataDir();
        $bufTarget = $dir . '/' . $sessId . '_' . $blockId . '.buf';
        // NOTE: deliberately NOT deleted/truncated here. Channel ids are fresh
        // per connection (client-side UUID) and the client wipes its session on
        // rotation, so any pre-existing bytes are exactly the early client data
        // (e.g. a TLS ClientHello) that landed before the stream worker picked
        // the request up — dropping them used to stall the handshake.
        if (!file_exists($bufTarget)) {
            @file_put_contents($bufTarget, '');
        }

        $isIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $target = $protocol . '://' . ($isIpv6 ? '[' . $host . ']' : $host) . ':' . $port;

        $remote = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!$remote) {
            $ipv6Warn = ($isIpv6 && strpos((string)$errstr, 'unreachable') !== false)
                ? ' (Host Server Lacks IPv6 Routing)' : '';
            self::recordMetric('ERROR', $sessId, 'Connection Fault: ' . $errstr . $ipv6Warn);
            echo self::frameChunk('ERR_ST_CONN: ' . $errstr) . "\n";
            @ob_flush(); flush();
            return;
        }

        stream_set_blocking($remote, false);
        if ($protocol === 'udp') {
            stream_set_timeout($remote, 5);
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        // Leading padding breaks CDN/edge response buffering (TCP only).
        if ($protocol === 'tcp') {
            echo str_repeat(' ', self::CF_FILL_BYTES) . "\n";
            @ob_flush(); flush();
        }
        if (self::$debugTrace) {
            @file_put_contents(self::$debugTrace, date('H:i:s') . " fill-echoed level=" . ob_get_level() . "\n", FILE_APPEND);
        }

        $lastActivity = time();
        $lastKeepAlive = time();

        while (!feof($remote) && !connection_aborted()) {
            if ($protocol === 'udp' && (time() - $lastActivity) > self::UDP_IDLE) {
                break;
            }

            clearstatcache(true, $bufTarget);
            if (file_exists($bufTarget) && filesize($bufTarget) > 0) {
                $fp = @fopen($bufTarget, 'c+');
                if ($fp && flock($fp, LOCK_EX)) {
                    $size  = filesize($bufTarget);
                    $chunk = $size > 0 ? fread($fp, $size) : '';
                    ftruncate($fp, 0);
                    rewind($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    if (is_string($chunk) && $chunk !== '') {
                        $written = @fwrite($remote, $chunk);
                        if (self::$debugTrace !== '') {
                            @file_put_contents(self::$debugTrace, date('H:i:s') . " drain " . strlen($chunk) . "B->" . (int)$written . " \n", FILE_APPEND);
                        }
                        $lastActivity = time();
                    }
                } elseif ($fp) {
                    fclose($fp);
                }
            }

            $r = [$remote];
            $w = null;
            $e = null;
            if (@stream_select($r, $w, $e, 0, 40000) > 0) {
                $data = @fread($remote, 16384);
                if (self::$debugTrace !== '') {
                    @file_put_contents(self::$debugTrace, date('H:i:s') . " remote-read " . (is_string($data) ? strlen($data) : -1) . "B feof=" . var_export(feof($remote), true) . "\n", FILE_APPEND);
                }
                if ($data === false || strlen($data) === 0) {
                    if ($protocol === 'tcp' && feof($remote)) {
                        break;
                    }
                } else {
                    echo self::frameChunk($data) . "\n";
                    @ob_flush(); flush();
                    $lastActivity = time();
                    $lastKeepAlive = time();
                }
            }

            // Silent-tunnel watchdog: a dead client with an idle remote never
            // triggers connection_aborted() (it fires only on output), which
            // leaked a worker per abandoned tunnel on 1-2 worker free hosts —
            // the original design's zombie-connection problem, server side.
            // An empty SSE line is a harmless keepalive: intermediaries never
            // idle-timeout the response, the Python core skips empty lines,
            // and the next flush raises connection_aborted() for dead clients
            // so the worker frees within seconds instead of never.
            if ($protocol === 'tcp' && (time() - $lastKeepAlive) >= self::KEEPALIVE_SECS) {
                echo "\n";
                @ob_flush(); flush();
                $lastKeepAlive = time();
                if (connection_aborted()) {
                    break;
                }
            }
        }

        @fclose($remote);
        @unlink($bufTarget);
        self::recordMetric('CLOSE', $sessId, 'Closed (CH: ' . substr($blockId, 0, 6) . ')');
    }

    /* ------------------------------------------------------------------
     * Housekeeping
     * ---------------------------------------------------------------- */

    /** Lock-file garbage collection with a cooldown (disk-friendly). */
    private static function vacuum($currentSess) {
        $dir  = self::dataDir();
        $lock = $dir . '/.vacuum_lock';
        $now  = time();
        if (file_exists($lock) && ($now - filemtime($lock)) < self::CLEANUP_COOLDOWN) {
            return;
        }
        @touch($lock);
        $nodes = @scandir($dir);
        if (!is_array($nodes)) {
            return;
        }
        foreach ($nodes as $node) {
            if ($node === '.' || $node === '..' || $node === '.vacuum_lock' || $node === 'sys_metrics.json'
                || $node === 'index.html' || $node === '.htaccess') {
                continue;
            }
            $path = $dir . '/' . $node;
            if (is_file($path) && strpos($node, $currentSess) === false && ($now - filemtime($path)) > self::DATA_EXPIRY) {
                @unlink($path);
            }
        }
    }

    /** Rolling, flock-guarded metrics journal (readable from the admin card). */
    public static function recordMetric($event, $sess, $info) {
        $file = self::dataDir() . '/sys_metrics.json';
        $fp   = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $size = filesize($file);
            $raw  = $size > 0 ? fread($fp, $size) : '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            array_unshift($data, [
                'dt'    => date('Y-m-d H:i:s'),
                'event' => (string)$event,
                'ip'    => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0',
                'sess'  => $sess !== '' ? substr((string)$sess, 0, 15) : 'SYSTEM',
                'info'  => (string)$info,
            ]);
            $data = array_slice($data, 0, self::METRICS_LIMIT);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($data));
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public static function readMetrics() {
        $file = self::dataDir() . '/sys_metrics.json';
        if (!file_exists($file)) {
            return [];
        }
        $raw  = (string)file_get_contents($file);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function clearMetrics() {
        $file = self::dataDir() . '/sys_metrics.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function wipeAllData() {
        $dir   = self::dataDir();
        $nodes = @scandir($dir);
        if (!is_array($nodes)) {
            return;
        }
        foreach ($nodes as $node) {
            if ($node === '.' || $node === '..' || $node === '.htaccess' || $node === 'index.html') {
                continue;
            }
            $path = $dir . '/' . $node;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Admin: vitals polling
     * ---------------------------------------------------------------- */
}

/**
 * Admin surface for the relay core: token lifecycle, live vitals table,
 * client bundle downloads and the generated config.json. All handlers are
 * capability-gated (manage_options) and nonce-checked.
 */
class GLPE_Hub_Admin {

    private static function backUrl($msg) {
        return add_query_arg(['page' => 'glpe-viewer', 'cp_msg' => $msg], admin_url('admin.php'));
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی مجاز نیست.');
        }
        check_admin_referer('glpe_hub_admin');
        $action = isset($_GET['action']) ? (string)$_GET['action'] : '';
        if ($action === '') {
            $action = 'glpe_hub_regen';
        }
        return $action;
    }

    public static function handleRegen() {
        self::guard();
        update_option('glpe_hub_token', 'tken_live_' . wp_generate_password(24, false, false));
        wp_safe_redirect(self::backUrl('hub-token-regen'));
        exit;
    }

    public static function handleClearLog() {
        self::guard();
        GLPE_Hub::clearMetrics();
        wp_safe_redirect(self::backUrl('hub-log-clear'));
        exit;
    }

    /** Static bundle files: the Python core and the GeoSite/GeoIP lists. */
    public static function handleServeFile() {
        self::guard();
        $which = isset($_GET['f']) ? (string)$_GET['f'] : '';
        $map = [
            'client'  => ['client.py',   'application/octet-stream'],
            'geoip'   => ['geoip_ir.txt', 'text/plain; charset=utf-8'],
            'geosite' => ['geosite_ir.txt', 'text/plain; charset=utf-8'],
        ];
        if (!isset($map[$which])) {
            wp_die('فایل موردنظر یافت نشد.');
        }
        $path = GLPE_DIR . 'hub-client/' . $map[$which][0];
        if (!file_exists($path)) {
            wp_die('فایل بسته در پوشه افزونه موجود نیست.');
        }
        nocache_headers();
        header('Content-Type: ' . $map[$which][1]);
        header('Content-Disposition: attachment; filename="' . $map[$which][0] . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    /** config.json generated for THIS node (address + secret, ready to run). */
    public static function handleServeConfig() {
        self::guard();
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="config.json"');
        echo GLPE_Hub::clientConfigJson();
        exit;
    }

    /** Full zip bundle (python core + config + geo lists) when ZipArchive exists. */
    public static function handleServeZip() {
        self::guard();
        if (!class_exists('ZipArchive')) {
            wp_safe_redirect(self::backUrl('hub-nozip'));
            exit;
        }
        $zipPath = get_temp_dir() . 'glpe-hub-client-' . wp_generate_password(8, false, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_safe_redirect(self::backUrl('hub-nozip'));
            exit;
        }
        $configFile = get_temp_dir() . 'glpe-config-' . wp_generate_password(8, false, false) . '.json';
        file_put_contents($configFile, GLPE_Hub::clientConfigJson());
        $zip->addFile($configFile, 'config.json');
        foreach (['client.py', 'geoip_ir.txt', 'geosite_ir.txt'] as $name) {
            $p = GLPE_DIR . 'hub-client/' . $name;
            if (file_exists($p)) {
                $zip->addFile($p, $name);
            }
        }
        $zip->close();

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="glpe-hub-client.zip"');
        header('Content-Length: ' . (string)filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        @unlink($configFile);
        exit;
    }

    /** Live vitals feed for the admin card (admin-ajax). */
    public static function ajaxVitals() {
        if (!current_user_can('manage_options')) {
            wp_send_json([], 403);
        }
        check_ajax_referer('glpe_hub_vitals');
        wp_send_json(GLPE_Hub::readMetrics());
    }

    /* ------------------------------------------------------------------
     * Admin card
     * ---------------------------------------------------------------- */

    public static function nodeUrl() {
        return home_url('/');
    }

    public static function renderHubCard() {
        $enabled   = GLPE_Hub::isEnabled();
        $token     = GLPE_Hub::token();
        $nodeUrl   = self::nodeUrl();
        // wp_nonce_url() HTML-escapes the separator (& → &amp;) in recent WP
        // versions, which corrupts any URL consumed outside an HTML attribute
        // (the live vitals fetch). Build the raw links directly instead. The
        // URL action targets the admin-post hook; every hub POST handler
        // verifies the shared 'glpe_hub_admin' nonce.
        $postLink = function ($urlAction, $extra = []) {
            return add_query_arg(array_merge(['action' => $urlAction, '_wpnonce' => wp_create_nonce('glpe_hub_admin')], $extra), admin_url('admin-post.php'));
        };
        $vitalsUrl = add_query_arg(['action' => 'glpe_hub_vitals', '_wpnonce' => wp_create_nonce('glpe_hub_vitals')], admin_url('admin-ajax.php'));
        $regenUrl  = $postLink('glpe_hub_regen');
        $clearUrl  = $postLink('glpe_hub_clear');
        $cfgUrl    = $postLink('glpe_hub_config');
        $zipOk     = class_exists('ZipArchive');
        $zipUrl    = $zipOk ? $postLink('glpe_hub_zip') : '';
        $configJson = GLPE_Hub::clientConfigJson();
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" id="glpe-hub-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0;">📡 هسته ارتباطی چندگرهی (Multi-Node Relay Core)</h3>
                <span id="glpe-hub-state" style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: bold; <?php echo $enabled ? 'background:#dcfce7; color:#166534;' : 'background:#fee2e2; color:#991b1b;'; ?>">
                    <?php echo $enabled ? 'فعال' : 'غیرفعال'; ?>
                </span>
            </div>

            <p style="font-size: 13px; color: #334155; line-height: 1.9;">
                این هسته، ترافیک شبکه را در قالب درخواست‌های معمولی HTTP (GET/POST) بسته‌بندی و بین چند گره (هر سایت وردپرسی که این افزونه را نصب کرده) به‌صورت چرخشی (Round-Robin) تقسیم می‌کند. کل کلاینت Python کنسولیِ همراه افزونه است و کلاینت‌ها به یک پیش‌خوان محلی SOCKS5/HTTP وصل می‌شوند. تمام پاکت‌ها با پاکت مبهم‌سازی‌شدهٔ <code>_v</code> (معکوس + Base64) رد و بدل می‌شوند و هیچ نشانی یا کلیدی به‌صورت خوانا روی خط منتقل نمی‌شود. ویژگی‌ها: مبهم‌سازی ترافیک، عبور از بافر CDN با پرکنندهٔ ۲۰۴۸ بایتی، واچ‌داگ دوگانه با مصونیت VIP برای جریان‌های فعال، مسیریابی مستقیم GeoSite/GeoIP، پاکسازی دیسک با Lock-File، توزیع بار چندگرهی و داشبورد زندهٔ پایش.
            </p>

            <table class="widefat striped" style="margin-top: 8px;">
                <tbody>
                    <tr>
                        <td style="width: 200px;"><strong>نشانی گره این سایت</strong></td>
                        <td style="direction: ltr; text-align: left;"><code style="user-select: all;"><?php echo esc_html($nodeUrl); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>کلید گره (توکن)</strong></td>
                        <td style="direction: ltr; text-align: left;">
                            <code style="user-select: all;" id="glpe-hub-token"><?php echo esc_html($token); ?></code>
                            <a href="<?php echo esc_url($regenUrl); ?>" class="button button-small" style="margin-inline-start: 8px;"
                               onclick="return confirm('کلید جدید ساخته شود؟ کلاینت‌های فعلی باید با config.json جدید به‌روزرسانی شوند.');">ساخت کلید جدید</a>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>وضعیت موتور</strong></td>
                        <td>
                            در بخش تنظیمات پیش‌فرض (فرم بالا) گزینهٔ «هستهٔ ارتباطی چندگرهی» را روشن/خاموش کنید و ذخیره بزنید.
                            <?php if (!$enabled): ?><span style="color:#b45309; font-weight:bold;"> — موتور در حال حاضر غیرفعال است و به هیچ درخواستی پاسخ نمی‌دهد.</span><?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                <a class="button button-primary" href="<?php echo esc_url($cfgUrl); ?>">⬇️ دانلود config.json آماده</a>
                <?php if ($zipOk): ?>
                    <a class="button" href="<?php echo esc_url($zipUrl); ?>">⬇️ دانلود بستهٔ کامل کلاینت (ZIP)</a>
                <?php else: ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_hub_file&f=client'), 'glpe_hub_admin')); ?>">⬇️ client.py</a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_hub_file&f=geoip'), 'glpe_hub_admin')); ?>">⬇️ geoip_ir.txt</a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_hub_file&f=geosite'), 'glpe_hub_admin')); ?>">⬇️ geosite_ir.txt</a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url($clearUrl); ?>" onclick="return confirm('گزارش پایش پاک شود؟');">🧹 پاک‌سازی گزارش پایش</a>
            </div>
            <?php if (!$zipOk): ?>
                <p style="font-size: 12px; color: #64748b; margin-top: 6px;">ساخت ZIP روی این هاست در دسترس نیست (ZipArchive فعال نیست)؛ سه فایل بالا را کنار هم در یک پوشه بگذارید و config.json را هم کنارشان ذخیره کنید.</p>
            <?php endif; ?>

            <h4 style="margin: 18px 0 8px;">🚀 راه‌اندازی سریع کلاینت</h4>
            <ol style="font-size: 13px; color: #334155; line-height: 2; margin: 0 20px 0 0;">
                <li>فایل‌های کلاینت را در یک پوشه قرار دهید (<code>client.py</code>، <code>config.json</code> و دو فایل Geo).</li>
                <li>پیش‌نیاز را نصب کنید: <code>pip install requests</code></li>
                <li>اجرای هسته: <code>python client.py</code> — داشبورد زنده روی کنسول بالا می‌آید.</li>
                <li>مرورگر یا سیستم را به پیش‌خوان محلی وصل کنید: <code>SOCKS5 127.0.0.1:41927</code></li>
            </ol>

            <details style="margin-top: 10px;">
                <summary style="cursor: pointer; font-size: 13px; font-weight: bold; color: #0969da;">مشاهدهٔ محتوای config.json این گره</summary>
                <textarea readonly rows="16" style="width: 100%; margin-top: 8px; direction: ltr; text-align: left; font-family: Consolas, monospace; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 10px;" id="glpe-hub-config-text"><?php echo esc_html($configJson); ?></textarea>
                <p style="font-size: 12px; color: #64748b;">برای افزودن گره‌های دیگر، نشانی و کلید هر سایت را از پنل مدیریت همان سایت بردارید و به آرایهٔ <code>servers</code> اضافه کنید — کلاینت به‌صورت چرخشی بین همهٔ گره‌ها توزیع بار می‌کند و با خطای یک گره به‌طور خودکار به گرهٔ بعدی می‌گذرد.</p>
            </details>

            <h4 style="margin: 18px 0 8px;">🖥️ پایش زندهٔ هسته (به‌روزرسانی خودکار هر ۲ ثانیه)</h4>
            <table class="widefat striped" id="glpe-hub-vitals-table">
                <thead>
                    <tr>
                        <th>زمان ثبت</th>
                        <th>عملیات</th>
                        <th>آی‌پی کلاینت</th>
                        <th>شناسه نشست</th>
                        <th>توضیحات</th>
                    </tr>
                </thead>
                <tbody id="glpe-hub-vitals-body">
                    <tr><td colspan="5" style="text-align:center; color:#71717a;">در حال بارگذاری گزارش زنده...</td></tr>
                </tbody>
            </table>

            <script>
            (function () {
                var url = <?php echo wp_json_encode($vitalsUrl); ?>;
                var badgeClass = { START: 'tag-start', CLEAR: 'tag-clear', CLOSE: 'tag-close', ERROR: 'tag-err' };
                function esc(s) {
                    var d = document.createElement('div');
                    d.textContent = s == null ? '' : String(s);
                    return d.innerHTML;
                }
                function loadVitals() {
                    fetch(url, { cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (rows) {
                            var body = document.getElementById('glpe-hub-vitals-body');
                            if (!body) return;
                            if (!rows || !rows.length) {
                                body.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#71717a;">هنوز هیچ تراکنشی ثبت نشده است — کلاینت را اجرا کنید.</td></tr>';
                                return;
                            }
                            var html = '';
                            for (var i = 0; i < rows.length && i < 40; i++) {
                                var r = rows[i];
                                var cls = badgeClass[r.event] || 'tag-close';
                                html += '<tr>'
                                    + '<td style="font-family:Consolas,monospace; direction:ltr;">' + esc(r.dt) + '</td>'
                                    + '<td><span class="glpe-hub-badge ' + cls + '">' + esc(r.event) + '</span></td>'
                                    + '<td style="font-family:Consolas,monospace; direction:ltr;">' + esc(r.ip) + '</td>'
                                    + '<td style="font-family:Consolas,monospace; direction:ltr;">' + esc(r.sess) + '</td>'
                                    + '<td>' + esc(r.info) + '</td>'
                                    + '</tr>';
                            }
                            body.innerHTML = html;
                        })
                        .catch(function () {});
                }
                var css = document.createElement('style');
                css.textContent = '.glpe-hub-badge{padding:3px 10px;border-radius:6px;font-size:11px;font-weight:bold;display:inline-block;min-width:52px;text-align:center;}'
                    + '.tag-start{background:rgba(16,185,129,.15);color:#10b981;}'
                    + '.tag-close{background:rgba(107,114,128,.2);color:#9ca3af;}'
                    + '.tag-clear{background:rgba(245,158,11,.15);color:#f59e0b;}'
                    + '.tag-err{background:rgba(239,68,68,.15);color:#ef4444;}';
                document.head.appendChild(css);
                loadVitals();
                setInterval(loadVitals, 2000);
            })();
            </script>
        </div>
        <?php
    }
}
