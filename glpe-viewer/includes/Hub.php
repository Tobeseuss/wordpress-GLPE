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
    const NODE_FILE        = 'node_info.php'; // credential handoff for the fast endpoint
    /** Every runtime file starts with these bytes: a direct web hit (even on
     * servers that ignore deny-all .htaccess, like some nginx builds) executes
     * an empty script instead of leaking the file contents. */
    const FILE_GUARD       = "<?php exit; ?>\n";
    const STREAM_MARK      = 'strm_'; // live-channel marker prefix (flock-held)
    const STREAM_CAP_MIN   = 2;
    const STREAM_CAP_MAX   = 32;
    const STREAM_CAP_DEF   = 6;  // free hosts have very few PHP workers — each
                                 // live channel pins one for its whole lifetime
    const CALIB_MARK       = 'calib_'; // calibration-hold marker prefix
    const CALIB_ABS_MAX    = 12; // absolute admission ceiling for concurrent
                                 // holds (streams + calibration together)
    const CALIB_HOLD_MIN   = 100;  // ms — one-way clamp bounds for a hold
    const CALIB_HOLD_MAX   = 3000;

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
        if ($action === 'z') {
            self::actCalibrationHold($meta, $sessId, $blockId);
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

    /* ------------------------------------------------------------------
     * Guarded runtime files + credential handoff for the fast endpoint
     * ---------------------------------------------------------------- */

    public static function guardLen() {
        return strlen(self::FILE_GUARD);
    }

    /** Keeps the guard prefix present on a runtime file (no-op if fine). */
    private static function ensureGuard($path) {
        clearstatcache(true, $path);
        if (!file_exists($path) || (int)filesize($path) < self::guardLen()) {
            @file_put_contents($path, self::FILE_GUARD, LOCK_EX);
        }
    }

    /** Payload after the guard prefix of a runtime file. */
    public static function readGuarded($path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strlen($raw) <= self::guardLen()) {
            return '';
        }
        return (string)substr($raw, self::guardLen());
    }

    /** Writes/refreshes the node handoff file consumed by hub-endpoint.php:
     * the node secret, the enabled flag and the uploads root. Cheap: content
     * is compared first so repeated admin hits do not touch the disk. */
    public static function syncNodeStore() {
        $file  = self::dataDir() . '/' . self::NODE_FILE;
        $basedir = self::$testDir !== null ? '' : untrailingslashit((string)wp_upload_dir()['basedir']);
        $payload = (string)json_encode([
            'token'   => self::token(),
            'enabled' => self::isEnabled() ? '1' : '0',
            'dir'     => $basedir,
            // The channel cap must travel with the handoff: the standalone
            // fast endpoint has no CMS option store, so without this entry it
            // would fall back to the default cap and admit over-cap channels.
            'max_streams' => self::streamCap(),
        ]);
        $existing = @file_get_contents($file);
        if ($existing !== false && substr((string)$existing, self::guardLen()) === $payload) {
            return true;
        }
        return @file_put_contents($file, self::FILE_GUARD . $payload, LOCK_EX) !== false;
    }

    /** Re-syncs the handoff file on every admin hit. syncNodeStore() compares
     * the payload first and writes only on a real change, so this costs one
     * stat + one small read — and still repairs a stale enabled flag or token
     * even when the file happens to exist. */
    public static function maybeSyncNodeStore() {
        return self::syncNodeStore();
    }

    /** Node addresses: the standalone endpoint (no CMS boot, tens of
     * milliseconds per exchange) is preferred; the CMS-path handler stays
     * as the automatic fallback the Python core switches to on failure. */
    public static function fastNodeUrl() {
        return untrailingslashit(GLPE_URL) . '/hub-endpoint.php';
    }

    public static function wpNodeUrl() {
        // Installs whose TLS is terminated at an edge layer often record an
        // http:// site address while the edge serves https only: a fallback
        // URL on the stored scheme dies in a redirect (POST bodies do not
        // survive a 301). Always hand out the scheme that actually served
        // the current admin request.
        return set_url_scheme(home_url('/'), is_ssl() ? 'https' : 'http');
    }

    /** Best-practice baseline for the bundled Python core (v6.2 tuning):
     * generous split timeouts so slow hosts still complete TLS handshakes,
     * identity transport (no compression buffering) — but a SMALL pool:
     * every live channel pins a PHP worker on the node, and free hosts have
     * very few. The node's own channel cap clamps this value at generation. */
    public static function defaultClientConfig() {
        return [
            'max_pool_connections'           => 10,
            'connect_timeout'                => 15,
            'stream_read_timeout'            => 30,
            'write_timeout'                  => 20,
            'max_retries'                    => 2,
            'relay_port'                     => 41927,
            'max_adaptive_delay'             => 1.0,
            'idle_timeout'                   => 12.0,
            'max_tunnel_uptime'              => 3600.0,
            'low_traffic_timeout'            => 35.0,
            'low_traffic_threshold_mb'       => 0.15,
            'active_transfer_threshold_kbps' => 10.0,
            'routing' => [
                'direct_iran'           => true,
                'geoip_url'             => 'https://raw.githubusercontent.com/v2fly/geoip/release/text/ir.txt',
                'geosite_base_url'      => 'https://raw.githubusercontent.com/v2fly/domain-list-community/refs/heads/master/data/',
                'geosite_main_category' => 'category-ir',
            ],
        ];
    }

    /** The node descriptor handed to the Python core's config.json:
     * defaults <- saved admin overrides <- this node + optional extra nodes. */
    public static function clientConfigArray() {
        $cfg = self::defaultClientConfig();
        $ov  = get_option('glpe_hub_cfg', []);
        $preferFast = true;
        if (is_array($ov)) {
            $intKeys  = ['relay_port', 'max_pool_connections', 'max_retries'];
            $numKeys  = ['connect_timeout', 'stream_read_timeout', 'write_timeout', 'max_adaptive_delay',
                         'idle_timeout', 'max_tunnel_uptime', 'low_traffic_timeout',
                         'low_traffic_threshold_mb', 'active_transfer_threshold_kbps'];
            // Stored overrides pass the same clamps the editor enforces —
            // a legacy stray value can not leak into the served config.
            foreach ($intKeys as $k) {
                $v = isset($ov[$k]) && is_numeric($ov[$k]) ? (int)$ov[$k] : 0;
                if ($v > 0) {
                    $c = GLPE_Hub_Admin::clampCfg($k, $v);
                    $cfg[$k] = ($c === null) ? $v : (int)$c;
                }
            }
            foreach ($numKeys as $k) {
                $c = isset($ov[$k]) ? GLPE_Hub_Admin::clampCfg($k, $ov[$k]) : null;
                if ($c !== null && $c > 0) {
                    $cfg[$k] = $c;
                }
            }
            if (isset($ov['routing']) && is_array($ov['routing'])) {
                foreach (['geoip_url', 'geosite_base_url'] as $k) {
                    $v = isset($ov['routing'][$k]) ? trim((string)$ov['routing'][$k]) : '';
                    if ($v !== '') { $cfg['routing'][$k] = esc_url_raw($v); }
                }
                $v = isset($ov['routing']['geosite_main_category']) ? trim((string)$ov['routing']['geosite_main_category']) : '';
                if ($v !== '') { $cfg['routing']['geosite_main_category'] = sanitize_key($v); }
                if (isset($ov['routing']['direct_iran'])) {
                    $cfg['routing']['direct_iran'] = ($ov['routing']['direct_iran'] === '1');
                }
            }
            $preferFast = !isset($ov['prefer_fast_endpoint']) || $ov['prefer_fast_endpoint'] === '1';
        }

        // The node's hard channel cap wins over any client preference — a
        // pool larger than the cap just produces guaranteed 503s.
        $cfg['max_pool_connections'] = min((int)$cfg['max_pool_connections'], self::streamCap());

        $primary  = $preferFast ? self::fastNodeUrl() : self::wpNodeUrl();
        $fallback = $preferFast ? self::wpNodeUrl() : self::fastNodeUrl();
        $servers = [[
            'url'          => $primary,
            'fallback_url' => $fallback,
            'token'        => self::token(),
        ]];
        $extra = get_option('glpe_hub_nodes', []);
        if (is_array($extra)) {
            foreach ($extra as $n) {
                if (is_array($n) && !empty($n['url']) && !empty($n['token'])
                    && strlen((string)$n['token']) >= 16) {
                    $servers[] = [
                        'url'   => esc_url_raw((string)$n['url']),
                        'token' => (string)$n['token'],
                    ];
                }
            }
        }
        $cfg['servers'] = $servers;
        return $cfg;
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
                if ($node === '.' || $node === '..' || $node === self::NODE_FILE
                    || $node === 'sys_metrics.php' || $node === '.vacuum_lock'
                    || $node === 'index.html' || $node === '.htaccess') {
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

    /** Action "w" — append client→remote bytes (reversed-Base64 body).
     * Buffer files are guarded PHP documents: fresh files get the self-exit
     * prefix before any payload byte lands, so a direct web hit always runs
     * an empty script regardless of server-side deny rules. */
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
                $fp = @fopen(self::dataDir() . '/' . $sessId . '_' . $blockId . '.php', 'c+');
                if ($fp) {
                    @flock($fp, LOCK_EX);
                    $meta = @stream_get_meta_data($fp);
                    clearstatcache(true, isset($meta['uri']) ? $meta['uri'] : '');
                    $size = (int)@filesize(isset($meta['uri']) ? $meta['uri'] : '');
                    if ($size < self::guardLen()) {
                        @rewind($fp);
                        @fwrite($fp, self::FILE_GUARD);
                    }
                    @fseek($fp, 0, SEEK_END);
                    @fwrite($fp, $payload);
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                }
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

        // Host guard: every live channel pins a PHP worker for its whole
        // lifetime. Without a hard server-side cap one busy client exhausts
        // every worker on a small shared host and the whole site (including
        // the admin) starts answering 503. Over-cap requests are rejected
        // cheaply — before any egress — with 503 + Retry-After, which the
        // Python core treats as "node busy, back off", never as a node fault.
        $marker  = $dir . '/' . self::STREAM_MARK . $sessId . '_' . $blockId . '.php';
        $slotFp  = self::acquireStreamSlot($marker);
        if ($slotFp === null) {
            self::recordMetric('CAP', $sessId, 'Channel cap reached (' . self::streamCap() . ')');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            http_response_code(503);
            header('Retry-After: 3');
            header('Content-Type: text/plain; charset=utf-8');
            header('Connection: close');
            echo 'F';
            return;
        }

        $bufTarget = $dir . '/' . $sessId . '_' . $blockId . '.php';
        // NOTE: deliberately NOT deleted/truncated here. Channel ids are fresh
        // per connection (client-side UUID) and the client wipes its session on
        // rotation, so any pre-existing bytes are exactly the early client data
        // (e.g. a TLS ClientHello) that landed before the stream worker picked
        // the request up — dropping them used to stall the handshake.
        self::ensureGuard($bufTarget);

        $isIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        $target = $protocol . '://' . ($isIpv6 ? '[' . $host . ']' : $host) . ':' . $port;

        $remote = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!$remote) {
            self::releaseStreamSlot($slotFp, $marker);
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
            if (file_exists($bufTarget) && (int)filesize($bufTarget) > self::guardLen()) {
                $fp = @fopen($bufTarget, 'c+');
                if ($fp && flock($fp, LOCK_EX)) {
                    clearstatcache(true, $bufTarget);
                    $size  = (int)filesize($bufTarget);
                    $chunk = '';
                    if ($size > self::guardLen()) {
                        @fseek($fp, self::guardLen());
                        $chunk = (string)fread($fp, $size - self::guardLen());
                    }
                    @ftruncate($fp, self::guardLen());
                    @fseek($fp, self::guardLen());
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
        self::releaseStreamSlot($slotFp, $marker);
        self::recordMetric('CLOSE', $sessId, 'Closed (CH: ' . substr($blockId, 0, 6) . ')');
    }

    /* ------------------------------------------------------------------
     * Calibration hold ('z') — the tuner's load probe
     * ---------------------------------------------------------------- */

    /** One-way clamp for the requested hold duration (ms). */
    public static function calibHoldClamp($ms) {
        $ms = (int)$ms;
        if ($ms < self::CALIB_HOLD_MIN) { return self::CALIB_HOLD_MIN; }
        if ($ms > self::CALIB_HOLD_MAX) { return self::CALIB_HOLD_MAX; }
        return $ms;
    }

    /**
     * Holds exactly one PHP worker for a bounded, token-authenticated
     * duration and then answers "K" — the tuner opens these in batches to
     * measure how many concurrent requests the host truly serves before it
     * starts answering 503. Admission uses the SAME marker mechanism as live
     * channels but with the fixed discovery ceiling (CALIB_ABS_MAX) instead
     * of the configured cap, so a calibration can discover safe values both
     * below and above whatever cap is currently set; the combined count
     * (streams + holds) never exceeds that absolute ceiling. Each hold is
     * short by design (<= CALIB_HOLD_MAX), so this is strictly gentler on
     * the host than one ordinary live channel, which pins its worker for the
     * whole connection lifetime.
     */
    public static function actCalibrationHold($meta, $sessId, $blockId) {
        if ($blockId === '') {
            self::sendStatus(404);
            return;
        }
        $holdMs = self::calibHoldClamp(isset($meta['d']) ? $meta['d'] : 0);

        $marker = self::dataDir() . '/' . self::CALIB_MARK . $sessId . '_' . $blockId . '.php';
        $slot   = self::acquireStreamSlot($marker, self::CALIB_ABS_MAX);
        if ($slot === null) {
            self::recordMetric('CALIB', $sessId, 'Hold rejected — ceiling reached');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            http_response_code(503);
            header('Retry-After: 2');
            header('Content-Type: text/plain; charset=utf-8');
            header('Connection: close');
            echo 'F';
            return;
        }

        self::recordMetric('CALIB', $sessId, 'Hold ' . $holdMs . 'ms (CH: ' . substr($blockId, 0, 6) . ')');
        usleep($holdMs * 1000);

        // No buffer clearing here: the answer rides whatever output buffer
        // the runtime has open (it flushes normally at script end), so the
        // caller — including the shared-engine selftest — sees the K body.
        self::sendNoStore();
        echo 'K';
        self::releaseStreamSlot($slot, $marker);
    }

    /* ------------------------------------------------------------------
     * Live-channel cap (host overload guard)
     * ---------------------------------------------------------------- */

    /** Configured maximum of simultaneously live channels on this node. */
    public static function streamCap() {
        $cap = (int)get_option('glpe_hub_max_streams', self::STREAM_CAP_DEF);
        if ($cap < self::STREAM_CAP_MIN) { $cap = self::STREAM_CAP_MIN; }
        if ($cap > self::STREAM_CAP_MAX) { $cap = self::STREAM_CAP_MAX; }
        return $cap;
    }

    /**
     * Counts worker-pinning requests by probing the flock markers (live
     * channels AND calibration holds — both pin one PHP worker each): a
     * marker whose lock can be taken shared is NOT held by a running
     * request — stale, cleaned. Returns the live count and opportunistically
     * unlinks stale markers.
     */
    public static function countLiveStreams() {
        $dir = self::dataDir();
        $nodes = @scandir($dir);
        if (!is_array($nodes)) {
            return 0;
        }
        $live = 0;
        foreach ($nodes as $node) {
            if (substr($node, -4) !== '.php'
                || (strpos($node, self::STREAM_MARK) !== 0 && strpos($node, self::CALIB_MARK) !== 0)) {
                continue;
            }
            $path = $dir . '/' . $node;
            $fp = @fopen($path, 'r');
            if (!$fp) {
                continue;
            }
            if (@flock($fp, LOCK_SH | LOCK_NB)) {
                // No exclusive holder → the stream is gone; remove the husk.
                @flock($fp, LOCK_UN);
                @fclose($fp);
                @unlink($path);
                continue;
            }
            @fclose($fp);
            $live++;
        }
        return $live;
    }

    /**
     * Registers a worker-pinning slot. Returns the locked marker handle
     * (resource) on success, true when the filesystem refuses markers
     * (soft-fail: the request runs uncounted rather than blocked), or null
     * when the node is already at the given ceiling (default: the configured
     * channel cap; calibration passes its own higher discovery ceiling).
     */
    public static function acquireStreamSlot($marker, $ceiling = null) {
        $cap = ($ceiling !== null) ? (int)$ceiling : self::streamCap();
        if (self::countLiveStreams() >= $cap) {
            return null;
        }
        $fp = @fopen($marker, 'c');
        if (!$fp) {
            return true; // cannot count → cannot cap → do not punish the user
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return true; // same marker already held — treat as counted elsewhere
        }
        return $fp;
    }

    /** Frees a channel slot acquired by acquireStreamSlot(). */
    public static function releaseStreamSlot($slot, $marker) {
        if (is_resource($slot)) {
            @flock($slot, LOCK_UN);
            @fclose($slot);
        }
        if (is_string($marker)) {
            @unlink($marker);
        }
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
            if ($node === '.' || $node === '..' || $node === '.vacuum_lock' || $node === self::NODE_FILE
                || $node === 'sys_metrics.php' || $node === 'index.html' || $node === '.htaccess') {
                continue;
            }
            $path = $dir . '/' . $node;
            // Worker-pinning markers (live channels + calibration holds): held
            // ones are active (never delete); unlocked ones are stale husks.
            if (strpos($node, self::STREAM_MARK) === 0 || strpos($node, self::CALIB_MARK) === 0) {
                $fp = @fopen($path, 'r');
                if ($fp) {
                    $held = !@flock($fp, LOCK_SH | LOCK_NB);
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                    if ($held) {
                        continue;
                    }
                    @unlink($path);
                    continue;
                }
            }
            if (is_file($path) && strpos($node, $currentSess) === false && ($now - filemtime($path)) > self::DATA_EXPIRY) {
                @unlink($path);
            }
        }
    }

    /** Rolling, flock-guarded metrics journal (readable from the admin page).
     * Stored as a guarded PHP document (see FILE_GUARD). */
    public static function recordMetric($event, $sess, $info) {
        $file = self::dataDir() . '/sys_metrics.php';
        $fp   = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $size = (int)filesize($file);
            $raw  = $size > 0 ? (string)fread($fp, $size) : '';
            $data = json_decode(substr($raw, self::guardLen()), true);
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
            fwrite($fp, self::FILE_GUARD . (string)json_encode($data));
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public static function readMetrics() {
        $file = self::dataDir() . '/sys_metrics.php';
        if (!file_exists($file)) {
            return [];
        }
        $raw  = (string)file_get_contents($file);
        $data = json_decode(substr($raw, self::guardLen()), true);
        return is_array($data) ? $data : [];
    }

    public static function clearMetrics() {
        $dir = self::dataDir();
        foreach (['sys_metrics.php', 'sys_metrics.json'] as $legacy) {
            if (file_exists($dir . '/' . $legacy)) {
                @unlink($dir . '/' . $legacy);
            }
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
 * Admin surface for the relay core — lives on its own dedicated dashboard
 * page (menu: نمایشگر صفحات وب → هستهٔ ارتباطی چندگرهی). Contains the node
 * status, the host evaluation system (practical probes + suggested values),
 * the fully editable client-config form and the live vitals table. All
 * handlers are capability-gated (manage_options) and nonce-checked.
 */
class GLPE_Hub_Admin {

    private static function backUrl($msg) {
        return add_query_arg(['page' => 'glpe-viewer-relay', 'cp_msg' => $msg], admin_url('admin.php'));
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
        GLPE_Hub::syncNodeStore();
        wp_safe_redirect(self::backUrl('hub-token-regen'));
        exit;
    }

    public static function handleClearLog() {
        self::guard();
        GLPE_Hub::clearMetrics();
        wp_safe_redirect(self::backUrl('hub-log-clear'));
        exit;
    }

    /** Numeric bounds for the editable client config — the same envelope
     * the remote set action enforces. One stray "36000" in a timeout field
     * once made every tunnel hang for hours before failing. */
    public static function cfgRanges() {
        return [
            'max_pool_connections'           => [1, 64],
            'max_retries'                    => [1, 5],
            'relay_port'                     => [1024, 65535],
            'connect_timeout'                => [1, 60],
            'stream_read_timeout'            => [1, 120],
            'write_timeout'                  => [1, 90],
            'max_adaptive_delay'             => [0.05, 5],
            'idle_timeout'                   => [3, 120],
            'max_tunnel_uptime'              => [30, 86400],
            'low_traffic_timeout'            => [5, 300],
            'low_traffic_threshold_mb'       => [0.01, 100],
            'active_transfer_threshold_kbps' => [0.5, 1000],
        ];
    }

    /** Clamps one editable-config value into its allowed range
     * (null when the key has no range rule). */
    public static function clampCfg($key, $value) {
        $ranges = self::cfgRanges();
        if (!isset($ranges[$key]) || !is_numeric($value)) {
            return null;
        }
        return (float)max($ranges[$key][0], min($ranges[$key][1], (float)$value));
    }

    /** Saves the editable client-config values + extra nodes from the form. */
    public static function handleSaveCfg() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی مجاز نیست.');
        }
        check_admin_referer('glpe_hub_admin', '_wpnonce');
        $in = isset($_POST['cfg']) && is_array($_POST['cfg']) ? wp_unslash($_POST['cfg']) : [];

        $ranges = self::cfgRanges();
        $numKeys = ['connect_timeout', 'stream_read_timeout', 'write_timeout', 'max_adaptive_delay',
                    'idle_timeout', 'max_tunnel_uptime', 'low_traffic_timeout',
                    'low_traffic_threshold_mb', 'active_transfer_threshold_kbps'];
        $intKeys = ['relay_port', 'max_pool_connections', 'max_retries'];

        $out = [];
        foreach ($intKeys as $k) {
            $v = isset($in[$k]) ? (int)$in[$k] : 0;
            if ($v > 0) {
                if (isset($ranges[$k])) {
                    $v = (int)max($ranges[$k][0], min($ranges[$k][1], $v));
                }
                $out[$k] = $v;
            }
        }
        foreach ($numKeys as $k) {
            $clamped = isset($in[$k]) ? self::clampCfg($k, $in[$k]) : null;
            if ($clamped !== null && $clamped > 0) {
                $out[$k] = $clamped;
            }
        }
        if (isset($in['prefer_fast_endpoint'])) {
            $out['prefer_fast_endpoint'] = ($in['prefer_fast_endpoint'] === '1') ? '1' : '0';
        }

        $routing = [];
        $routing['direct_iran'] = (isset($in['routing']['direct_iran']) && $in['routing']['direct_iran'] === '1') ? '1' : '0';
        foreach (['geoip_url', 'geosite_base_url'] as $k) {
            $v = isset($in['routing'][$k]) ? trim((string)$in['routing'][$k]) : '';
            if ($v !== '') {
                $v = esc_url_raw($v);
                if ($v !== '') { $routing[$k] = $v; }
            }
        }
        $cat = isset($in['routing']['geosite_main_category']) ? trim((string)$in['routing']['geosite_main_category']) : '';
        if ($cat !== '') { $routing['geosite_main_category'] = sanitize_key($cat); }
        if (count($routing) > 0) { $out['routing'] = $routing; }

        // Extra nodes: one "address key" pair per line, both whitespace-split.
        $extras = [];
        $rawLines = isset($_POST['extra_nodes']) ? (string)wp_unslash($_POST['extra_nodes']) : '';
        foreach (preg_split('/\r\n|\r|\n/', $rawLines) as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) { continue; }
            $url   = esc_url_raw(array_shift($parts));
            $token = array_shift($parts);
            if ($url !== '' && is_string($token) && strlen($token) >= 16 && strlen($token) <= 200) {
                $extras[] = ['url' => $url, 'token' => $token];
            }
        }

        update_option('glpe_hub_cfg', $out);
        update_option('glpe_hub_nodes', $extras);

        // Node channel cap (host guard) travels with the same form.
        $cap = isset($_POST['hub_max_streams']) ? (int)$_POST['hub_max_streams'] : 0;
        if ($cap >= GLPE_Hub::STREAM_CAP_MIN && $cap <= GLPE_Hub::STREAM_CAP_MAX) {
            update_option('glpe_hub_max_streams', (string)$cap);
        }

        GLPE_Hub::syncNodeStore();
        wp_safe_redirect(self::backUrl('hub-cfg-saved'));
        exit;
    }

    /** Clears the recorded fatal-error journal (diagnostics panel). */
    public static function handleClearFatal() {
        self::guard();
        if (class_exists('GLPE_Diag')) {
            GLPE_Diag::clearFatals();
        }
        wp_safe_redirect(self::backUrl('hub-fatals-cleared'));
        exit;
    }

    /** Applies the tuner's suggested viewer-fetch/DNS values straight into
     * the plugin settings (the same options the settings page edits). */
    public static function handleFetchApply() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی مجاز نیست.');
        }
        check_admin_referer('glpe_hub_admin', '_wpnonce');

        $mode = isset($_POST['glpe_dns_mode']) ? (string)$_POST['glpe_dns_mode'] : 'auto';
        update_option('glpe_dns_mode', in_array($mode, ['auto', 'doh_first', 'system'], true) ? $mode : 'auto');

        $ranges = [
            'glpe_dns_ttl'               => [60, 86400],
            'glpe_fetch_connect_timeout' => [3, 60],
            'glpe_fetch_timeout'         => [8, 120],
        ];
        foreach ($ranges as $key => $range) {
            $v = isset($_POST[$key]) ? (int)$_POST[$key] : 0;
            if ($v >= $range[0] && $v <= $range[1]) {
                update_option($key, (string)$v);
            }
        }
        $retries = isset($_POST['glpe_fetch_retries']) ? (int)$_POST['glpe_fetch_retries'] : 0;
        update_option('glpe_fetch_retries', ($retries >= 1 && $retries <= 3) ? (string)$retries : '1');
        update_option('glpe_ipresolve_v4', (isset($_POST['glpe_ipresolve_v4']) && $_POST['glpe_ipresolve_v4'] === '1') ? '1' : '0');

        wp_safe_redirect(self::backUrl('fetch-applied'));
        exit;
    }

    /** Host evaluation + suggested values (admin-ajax). The evaluation now
     * includes the concurrency calibration and AUTO-APPLIES everything it
     * finds (viewer fetch/DNS options, node channel cap, client config
     * values) — the admin never has to click a save button for the tuned
     * values to take effect. */
    public static function ajaxTune() {
        if (!current_user_can('manage_options')) {
            wp_send_json(['error' => 'forbidden'], 403);
        }
        check_ajax_referer('glpe_hub_tune');
        // The calibration can run for tens of seconds: release the admin's
        // PHP session lock immediately so other same-session requests (live
        // vitals, editor autosave, another admin tab) never queue up behind
        // this one, and stretch the execution budget where the host allows.
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        @set_time_limit(300);
        $result = GLPE_Hub_Tune::run();
        $applied = GLPE_Hub_Tune::autoApply($result);
        if (!empty($applied)) {
            $result['applied'] = $applied;
        }
        update_option('glpe_hub_tuning', $result);
        wp_send_json($result);
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

    /** Small pointer rendered on the main settings page (the full surface
     * lives on its own dedicated menu page). */
    public static function renderRelayPointer() {
        $relayUrl = add_query_arg(['page' => 'glpe-viewer-relay'], admin_url('admin.php'));
        ?>
        <div style="background: linear-gradient(135deg, #eef2ff, #f0fdf4); border: 1px solid #c7d2fe; border-radius: 12px; padding: 16px 20px; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h3 style="margin: 0 0 4px;">📡 هستهٔ ارتباطی چندگرهی (Multi-Node Relay Core)</h3>
                    <p style="margin: 0; font-size: 13px; color: #334155;">مدیریت گرهٔ این سایت، ارزیابی عملی هاست، ویرایش مقادیر کانفیگ کلاینت و پایش زندهٔ هسته — همه در صفحهٔ اختصاصی خودش.</p>
                </div>
                <a class="button button-primary" href="<?php echo esc_url($relayUrl); ?>">ورود به صفحهٔ هستهٔ ارتباطی ↗</a>
            </div>
        </div>
        <?php
    }

    /** The dedicated relay-core dashboard page. */
    public static function renderRelayPage() {
        $enabled   = GLPE_Hub::isEnabled();
        $token     = GLPE_Hub::token();
        $fastUrl   = GLPE_Hub::fastNodeUrl();
        $wpUrl     = GLPE_Hub::wpNodeUrl();
        $fastOk    = self::fastEndpointReachable();
        // wp_nonce_url() HTML-escapes the separator (& → &amp;) in recent WP
        // versions, which corrupts any URL consumed outside an HTML attribute
        // (the live vitals fetch). Build the raw links directly instead. The
        // URL action targets the admin-post hook; every hub POST handler
        // verifies the shared 'glpe_hub_admin' nonce.
        $postLink = function ($urlAction, $extra = []) {
            return add_query_arg(array_merge(['action' => $urlAction, '_wpnonce' => wp_create_nonce('glpe_hub_admin')], $extra), admin_url('admin-post.php'));
        };
        $vitalsUrl = add_query_arg(['action' => 'glpe_hub_vitals', '_wpnonce' => wp_create_nonce('glpe_hub_vitals')], admin_url('admin-ajax.php'));
        $tuneUrl   = add_query_arg(['action' => 'glpe_hub_tune', '_wpnonce' => wp_create_nonce('glpe_hub_tune')], admin_url('admin-ajax.php'));
        $saveUrl   = $postLink('glpe_hub_savecfg');
        $fetchApplyUrl = $postLink('glpe_hub_fetch_apply');
        $regenUrl  = $postLink('glpe_hub_regen');
        $clearUrl  = $postLink('glpe_hub_clear');
        $cfgUrl    = $postLink('glpe_hub_config');
        $zipOk     = class_exists('ZipArchive');
        $zipUrl    = $zipOk ? $postLink('glpe_hub_zip') : '';
        $configJson = GLPE_Hub::clientConfigJson();

        $cap = GLPE_Hub::streamCap();
        $savedCap = (int)get_option('glpe_hub_max_streams', GLPE_Hub::STREAM_CAP_DEF);
        $fatals = class_exists('GLPE_Diag') ? GLPE_Diag::lastFatals() : [];
        $optionalMissing = class_exists('GLPE_Diag') ? GLPE_Diag::missingOptional() : [];
        $cfg    = GLPE_Hub::defaultClientConfig();
        $ov     = get_option('glpe_hub_cfg', []);
        if (is_array($ov)) {
            foreach (['max_pool_connections', 'connect_timeout', 'stream_read_timeout', 'write_timeout', 'max_retries',
                      'max_adaptive_delay', 'idle_timeout', 'max_tunnel_uptime', 'low_traffic_timeout',
                      'low_traffic_threshold_mb', 'active_transfer_threshold_kbps'] as $k) {
                if (isset($ov[$k]) && is_numeric($ov[$k]) && (float)$ov[$k] > 0) { $cfg[$k] = $ov[$k]; }
            }
            if (isset($ov['relay_port']) && is_numeric($ov['relay_port']) && (int)$ov['relay_port'] > 0) { $cfg['relay_port'] = (int)$ov['relay_port']; }
        }
        $preferFast = !is_array($ov) || !isset($ov['prefer_fast_endpoint']) || $ov['prefer_fast_endpoint'] === '1';
        $routing    = isset($cfg['routing']) && is_array($cfg['routing']) ? $cfg['routing'] : [];
        if (is_array($ov) && isset($ov['routing']) && is_array($ov['routing'])) {
            foreach (['geoip_url', 'geosite_base_url', 'geosite_main_category'] as $k) {
                if (!empty($ov['routing'][$k])) { $routing[$k] = $ov['routing'][$k]; }
            }
            if (isset($ov['routing']['direct_iran'])) { $routing['direct_iran'] = ($ov['routing']['direct_iran'] === '1'); }
        }
        $extraNodes = get_option('glpe_hub_nodes', []);
        $extraText  = '';
        if (is_array($extraNodes)) {
            foreach ($extraNodes as $n) {
                if (is_array($n) && !empty($n['url']) && !empty($n['token'])) {
                    $extraText .= $n['url'] . ' ' . $n['token'] . "\n";
                }
            }
        }
        $tuning = get_option('glpe_hub_tuning', []);
        $field = function ($key, $label, $unit = '') use ($cfg) {
            $v = isset($cfg[$key]) ? (string)$cfg[$key] : '';
            echo '<tr>';
            echo '<th scope="row" style="width: 42%;"><code style="direction:ltr; display:inline-block;">' . esc_html($key) . '</code></th>';
            echo '<td><input type="text" name="cfg[' . esc_attr($key) . ']" value="' . esc_attr($v) . '" class="regular-text glpe-cfg-field" data-key="' . esc_attr($key) . '" style="direction:ltr; text-align:left;" /> ' . esc_html($unit) . '</td>';
            echo '</tr>';
        };
        ?>
        <div class="wrap" style="direction: rtl; text-align: right; max-width: 1000px;">
            <h1 style="font-size: 22px; font-weight: bold; margin-bottom: 16px;">📡 هستهٔ ارتباطی چندگرهی (Multi-Node Relay Core)</h1>

            <?php
            $cpNotice = isset($_GET['cp_msg']) ? sanitize_key($_GET['cp_msg']) : '';
            $cpNotices = [
                'hub-token-regen' => 'کلید گرهٔ جدید ساخته شد — باید config.json کلاینت‌ها را به‌روزرسانی کنید.',
                'hub-log-clear'   => 'گزارش پایش هستهٔ ارتباطی پاک شد.',
                'hub-nozip'       => 'ساخت ZIP روی این هاست در دسترس نیست؛ فایل‌های کلاینت را جداگانه دانلود کنید.',
                'hub-cfg-saved'   => 'مقادیر کانفیگ و گره‌های اضافی ذخیره شد — config.json تولیدشده از این پس از مقادیر جدید استفاده می‌کند.',
                'hub-fatals-cleared' => 'تاریخچهٔ خطاهای ثبت‌شده پاک شد.',
                'fetch-applied'   => 'مقادیر پیشنهادی DNS/اتصال در تنظیمات افزونه ذخیره شد — همین حالا روی همهٔ بارگذاری‌های مرورگر اثر می‌گذارد.',
            ];
            if ($cpNotice !== '' && isset($cpNotices[$cpNotice])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($cpNotices[$cpNotice]) . '</p></div>';
            }
            ?>

            <!-- وضعیت گره -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" id="glpe-hub-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <h3 style="margin: 0;">وضعیت گرهٔ این سایت</h3>
                    <span id="glpe-hub-state" style="padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: bold; <?php echo $enabled ? 'background:#dcfce7; color:#166534;' : 'background:#fee2e2; color:#991b1b;'; ?>">
                        <?php echo $enabled ? 'فعال' : 'غیرفعال'; ?>
                    </span>
                </div>

                <p style="font-size: 13px; color: #334155; line-height: 1.9;">
                    این هسته ترافیک را در قالب درخواست‌های معمولی HTTP بسته‌بندی می‌کند؛ هر سایت وردپرسی که افزونه را نصب کرده یک گره است و کلاینت پایتون به‌صورت چرخشی بین گره‌ها توزیع بار می‌کند. تمام پاکت‌ها با پاکت ماتِ <code>_v</code> رد و بدل می‌شوند و هیچ نشانی یا کلیدی به‌صورت خوانا روی خط منتقل نمی‌شود. گره دو آدرس دارد: «مسیر سریع» مستقیم و بدون بوت سنگین سیستم پاسخ می‌دهد (چند ده میلی‌ثانیه) و «مسیر پشتیبان» از طریق وردپرس؛ کلاینت با خطا یا کندی مسیر اول به‌طور خودکار به دومی می‌گذرد.
                </p>

                <table class="widefat striped" style="margin-top: 8px;">
                    <tbody>
                        <tr>
                            <td style="width: 220px;"><strong>مسیر سریع گره</strong></td>
                            <td style="direction: ltr; text-align: left;">
                                <code style="user-select: all;"><?php echo esc_html($fastUrl); ?></code>
                                <?php if ($fastOk === true): ?><span style="color:#16a34a; font-weight:bold;">✔ در دسترس</span>
                                <?php elseif ($fastOk === false): ?><span style="color:#dc2626; font-weight:bold;">✖ پاسخ نداد</span><span style="color:#64748b;"> — پایش زنده روی مسیر پشتیبان انجام می‌شود</span>
                                <?php else: ?><span style="color:#64748b;">— برای سنجش، دکمهٔ ارزیابی هاست را بزنید</span><?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>مسیر پشتیبان (وردپرس)</strong></td>
                            <td style="direction: ltr; text-align: left;"><code style="user-select: all;"><?php echo esc_html($wpUrl); ?></code></td>
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
                            <td>کلید «هستهٔ ارتباطی چندگرهی» در بخش تنظیمات پیش‌فرض (منوی اصلی افزونه) روشن/خاموش می‌شود.
                            <?php if (!$enabled): ?><span style="color:#b45309; font-weight:bold;"> — موتور در حال حاضر غیرفعال است و به هیچ درخواستی پاسخ نمی‌دهد.</span><?php endif; ?></td>
                        </tr>
                        <tr>
                            <td><strong>سقف کانال‌های همزمان</strong></td>
                            <td><span style="direction:ltr; display:inline-block;"><?php echo (int)$cap; ?></span> کانال (محافظ هاست — هر کانال زنده یک کارگر PHP را اشغال می‌کند؛ در فرم ویرایش کانفیگ زیر قابل تغییر است)</td>
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
                <div style="margin-top: 12px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; padding: 12px 16px; font-size: 12.5px; color: #78350f; line-height: 2;">
                    <strong>نکتهٔ مهم برای سایت‌های فیلترشده (مثل BBC فارسی و ایران‌اینترنشنال):</strong>
                    مرورگر را با <strong>نوع اتصال HTTP</strong> (به‌جای SOCKS5) به <code style="direction:ltr;">127.0.0.1:41927</code> وصل کنید — در این حالت نام دامنه‌ها مستقیم و بدون تغییر به گره می‌رسد و حل نشانی سمت هاست انجام می‌شود.
                    اگر SOCKS5 لازم دارید، در فایرفاکس گزینهٔ فرستادن درخواست‌های نام دامنه از طریق خود SOCKS v5 را حتماً فعال کنید.
                    دلیل: در حالت پیش‌فرض برخی مرورگرها DNS را روی سیستم خودتان حل می‌کنند و برای دامنه‌های مسدودشده نشانی جعلی ISP را می‌گیرند — تونل هم به همان نشانی جعلی وصل می‌شود و صفحه باز نمی‌شود.
                </div>
            </div>

            <!-- ارزیابی هاست -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" id="glpe-tune-card">
                <h3 style="margin: 0 0 8px;">🧪 ارزیابی عملی هاست، کالیبراسیون همزمانی و ذخیرهٔ خودکار</h3>
                <p style="font-size: 13px; color: #334155; line-height: 1.9;">
                    این سیستم مشخصات PHP هاست را می‌خواند، چند آزمایش واقعی روی خود هاست اجرا می‌کند (زمان پاسخ مسیر سریع و وردپرسی، سلامت اتصال خروجی، استریم، DNS و DoH، خروجی پورت 443 و دسترسی به دو سایت نمونه) و در پایان <strong>کالیبراسیون همزمانی</strong> را اجرا می‌کند: چندین اتصال به‌صورت دسته‌ای و هم‌زمان با هاست برقرار می‌شود و به‌محض دیدن خطای 503 اندازهٔ دسته‌ها خودکار کم می‌شود تا بزرگ‌ترین دستهٔ بدون خطا پیدا شود (زمان پاسخ‌دهی هر دسته هم تعداد کارگر مؤثر هاست را لو می‌دهد).
                    همهٔ مقادیر پیدا‌شده — تنظیمات DNS و اتصال مرورگر GLPE، سقف کانال‌های گره و کانفیگ کلاینت — <strong>بلافاصله و بدون نیاز به هیچ دکمهٔ ذخیره‌ای، خودکار ثبت می‌شوند</strong>. در چند ثانیهٔ کالیبراسیون سایت ممکن است لحظه‌ای کند شود؛ طبیعی است.
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px;">
                    <button type="button" class="button button-primary" id="glpe-tune-run">▶ اجرای ارزیابی و کالیبراسیون خودکار روی هاست</button>
                    <button type="button" class="button" id="glpe-tune-apply" style="display:none;">✅ اعمال پیشنهادها در فرم کانفیگ</button>
                    <span id="glpe-tune-status" style="font-size: 13px; color: #64748b;"></span>
                </div>
                <div id="glpe-tune-result" style="margin-top: 12px;"></div>

                <!-- پیشنهادهای مرورگر GLPE: فرم مستقل که مقدارها از ارزیابی پر می‌شوند -->
                <form id="glpe-fetch-form" method="post" action="<?php echo esc_url($fetchApplyUrl); ?>" style="display: none; margin-top: 14px; background: #f8fafc; border: 1px dashed #94a3b8; border-radius: 10px; padding: 14px 16px;">
                    <input type="hidden" name="action" value="glpe_hub_fetch_apply" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('glpe_hub_admin')); ?>" />
                    <h4 style="margin: 0 0 4px;">🖥 مقادیر پیشنهادی مرورگر GLPE — اعمال در تنظیمات افزونه</h4>
                    <p style="font-size: 12.5px; color: #475569; margin: 0 0 8px; line-height: 1.9;">این جدول روی مبنای آزمایش‌های واقعی همین هاست (سیستم DNS و دو پایانهٔ DoH) ساخته می‌شود؛ می‌توانید مقدارها را قبل از ذخیره دستی اصلاح کنید. مقادیر نهایی در <a href="<?php echo esc_url(add_query_arg(['page' => 'glpe-viewer'], admin_url('admin.php'))); ?>">تنظیمات پیش‌فرض افزونه</a> ذخیره می‌شوند.</p>
                    <table class="form-table" role="presentation" style="margin-bottom: 0;">
                        <tr>
                            <th scope="row">حالت حل نام (glpe_dns_mode)</th>
                            <td>
                                <select id="glpe-fetch-mode" name="glpe_dns_mode" style="direction:ltr;">
                                    <option value="auto">auto — سیستم + ترمیم خودکار DoH</option>
                                    <option value="doh_first">doh_first — همیشه DoH با نشانی ثابت</option>
                                    <option value="system">system — فقط DNS هاست</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">TTL کش نشانی‌ها (glpe_dns_ttl)</th>
                            <td><input type="number" min="60" max="86400" id="glpe-fetch-ttl" name="glpe_dns_ttl" style="direction:ltr; width:120px;" /> ثانیه</td>
                        </tr>
                        <tr>
                            <th scope="row">سقف اتصال (glpe_fetch_connect_timeout)</th>
                            <td><input type="number" min="3" max="60" id="glpe-fetch-connect" name="glpe_fetch_connect_timeout" style="direction:ltr; width:120px;" /> ثانیه</td>
                        </tr>
                        <tr>
                            <th scope="row">سقف کل درخواست (glpe_fetch_timeout)</th>
                            <td><input type="number" min="8" max="120" id="glpe-fetch-timeout" name="glpe_fetch_timeout" style="direction:ltr; width:120px;" /> ثانیه</td>
                        </tr>
                        <tr>
                            <th scope="row">دفعات ترمیم (glpe_fetch_retries)</th>
                            <td><input type="number" min="1" max="3" id="glpe-fetch-retries" name="glpe_fetch_retries" style="direction:ltr; width:120px;" /> بار</td>
                        </tr>
                        <tr>
                            <th scope="row">فقط IPv4 (glpe_ipresolve_v4)</th>
                            <td>
                                <label>
                                    <input type="hidden" name="glpe_ipresolve_v4" value="0" />
                                    <input type="checkbox" id="glpe-fetch-ipv4" name="glpe_ipresolve_v4" value="1" />
                                    فعال
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p style="font-size:12px; color:#64748b; margin-top:6px;">این مقادیر پس از هر «ارزیابی و کالیبراسیون» خودکار ذخیره می‌شوند؛ فرم بالا فقط برای اعمال یا ویرایش دستی مقادیر است.</p>
                    <?php submit_button('💾 ذخیرهٔ این مقادیر در تنظیمات افزونه', 'primary', 'glpe-fetch-submit'); ?>
                </form>

                <script>
                (function () {
                    var url = <?php echo wp_json_encode($tuneUrl); ?>;
                    var saved = <?php echo wp_json_encode(is_array($tuning) ? $tuning : []); ?>;
                    function esc(s) {
                        var d = document.createElement('div');
                        d.textContent = s == null ? '' : String(s);
                        return d.innerHTML;
                    }
                    function fmtMs(v) { return (v === null || v === undefined) ? '—' : (Math.round(v) + ' ms'); }
                    function render(res) {
                        var box = document.getElementById('glpe-tune-result');
                        if (!res || !res.php) { box.innerHTML = ''; return; }
                        var html = '';
                        if (res.applied) {
                            html += '<div style="background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:12px 16px; margin:10px 0; font-size:13px; color:#166534; line-height:2;">'
                                + '<strong>✔ مقادیر پیدا‌شده خودکار ذخیره شدند — بدون نیاز به ذخیرهٔ دستی:</strong>'
                                + '<ul style="margin:6px 0 0 18px; padding:0;">';
                            if (res.applied.max_streams) {
                                html += '<li>سقف کانال‌های همزمان گره: <strong style="direction:ltr; display:inline-block;">' + esc(res.applied.max_streams) + '</strong></li>';
                            }
                            if (res.applied.client_cfg) {
                                html += '<li>اندازهٔ استخر کلاینت در config.json: <strong style="direction:ltr; display:inline-block;">' + esc(res.applied.client_cfg.max_pool_connections) + '</strong> + تایم‌اوت‌های پیشنهادی</li>';
                            }
                            if (res.applied.fetch) {
                                html += '<li>تنظیمات DNS و اتصال مرورگر GLPE: حالت <code style="direction:ltr; display:inline-block;">' + esc(res.applied.fetch.glpe_dns_mode) + '</code>، تایم‌اوت‌ها و دفعات ترمیم</li>';
                            }
                            html += '</ul>پیش‌نمایش config.json و فیلدهای فرم‌ها بعد از نوسازی صفحه، مقادیر جدید را نشان می‌دهند.</div>';
                        }
                        html += '<h4 style="margin: 14px 0 6px;">مشخصات PHP هاست</h4>';
                        html += '<table class="widefat striped"><tbody>';
                        var rows = [
                            ['نوع اجرا (SAPI)', res.php.sapi],
                            ['نسخهٔ PHP', res.php.php],
                            ['محدودیت حافظه', res.php.memory_limit],
                            ['حداکثر زمان اجرا', res.php.max_execution_time + ' ثانیه'],
                            ['فشرده‌سازی خروجی zlib', res.php.zlib_output_compression ? 'روشن (بهتر است خاموش باشد)' : 'خاموش'],
                            ['بافر خروجی', res.php.output_buffering],
                            ['توابع سوکت مسدود شده', res.php.socket_functions_disabled ? 'بله — هستهٔ رله روی این هاست کار نمی‌کند!' : 'خیر'],
                            ['پشتیبانی ZIP', res.php.zip_archive ? 'موجود' : 'ناموجود'],
                            ['OPcache', res.php.opcache ? 'فعال' : 'غیرفعال (کندی پاسخ گره)'],
                            ['نرم‌افزار وب‌سرور', res.php.server]
                        ];
                        for (var i = 0; i < rows.length; i++) {
                            html += '<tr><td style="width:40%;">' + esc(rows[i][0]) + '</td><td style="direction:ltr; text-align:left;">' + esc(rows[i][1]) + '</td></tr>';
                        }
                        html += '</tbody></table>';

                        if (res.probes) {
                            html += '<h4 style="margin: 14px 0 6px;">نتایج آزمایش‌های عملی</h4>';
                            html += '<table class="widefat striped"><tbody>';
                            var probes = [
                                ['مسیر سریع (بدون بوت سیستم)', res.probes.fast_endpoint],
                                ['مسیر وردپرسی', res.probes.wp_endpoint],
                                ['اتصال خروجی (سوکت TCP)', res.probes.outbound_socket],
                                ['شروع استریم (پرکنندهٔ ضدبافر CDN)', res.probes.stream_fill],
                                ['DNS سیستم هاست', res.probes.dns_system],
                                ['DoH — Cloudflare (1.1.1.1)', res.probes.doh_cloudflare],
                                ['DoH — Google (8.8.4.4)', res.probes.doh_google],
                                ['خروجی پورت 443 (مسیر HTTPS)', res.probes.egress_443],
                                ['دست‌دادن TLS از هاست', res.probes.tls_handshake],
                                ['نمونه: www.bbc.com:443', res.probes.sample_bbc],
                                ['نمونه: www.iranintl.com:443', res.probes.sample_intl]
                            ];
                            for (var j = 0; j < probes.length; j++) {
                                var p = probes[j][1];
                                var mark = (!p) ? '—' : (p.ok ? '<span style="color:#16a34a;font-weight:bold;">✔ موفق</span>' : '<span style="color:#dc2626;font-weight:bold;">✖ ناموفق</span>');
                                var detail = (!p) ? '' : (p.note ? esc(p.note) : '');
                                html += '<tr><td style="width:40%;">' + esc(probes[j][0]) + '</td><td>' + mark + '</td><td style="direction:ltr; text-align:left;">' + (p && p.ok ? fmtMs(p.ms) : '') + '</td><td>' + detail + '</td></tr>';
                            }
                            html += '</tbody></table>';
                        }

                        if (res.calibration) {
                            var c = res.calibration;
                            html += '<h4 style="margin: 14px 0 6px;">کالیبراسیون همزمانی — دسته‌های اتصال هم‌زمان</h4>';
                            if (c.batches && c.batches.length) {
                                html += '<table class="widefat striped"><thead><tr>'
                                    + '<th>دسته</th><th>اتصال هم‌زمان</th><th>پاسخ موفق</th><th>خطای اشغال (5xx)</th><th>ناموفق</th><th>زمان کل</th>'
                                    + '</tr></thead><tbody>';
                                for (var ci = 0; ci < c.batches.length; ci++) {
                                    var b = c.batches[ci];
                                    html += '<tr>'
                                        + '<td>' + (ci + 1) + '</td>'
                                        + '<td style="direction:ltr; text-align:center; font-weight:bold;">' + esc(b.n) + '</td>'
                                        + '<td style="direction:ltr; text-align:center; color:#16a34a; font-weight:bold;">' + esc(b.ok) + '</td>'
                                        + '<td style="direction:ltr; text-align:center; color:' + (b.busy > 0 ? '#dc2626' : '#9ca3af') + '; font-weight:' + (b.busy > 0 ? 'bold' : 'normal') + ';">' + esc(b.busy) + '</td>'
                                        + '<td style="direction:ltr; text-align:center; color:' + (b.other > 0 ? '#dc2626' : '#9ca3af') + ';">' + esc(b.other) + '</td>'
                                        + '<td style="direction:ltr; text-align:left;">' + fmtMs(b.wall_ms) + '</td>'
                                        + '</tr>';
                                }
                                html += '</tbody></table>';
                            }
                            if (c.ok && c.safe_cap) {
                                html += '<p style="font-size:13px; margin:8px 0 0;">✔ سقف امن همزمانی روی این هاست: <strong style="direction:ltr; display:inline-block;">' + esc(c.safe_cap) + '</strong> کانال';
                                if (c.effective !== null && c.effective !== undefined) {
                                    html += ' <span style="color:#64748b;">(کارگر مؤثر مشاهده‌شده: ~' + esc(c.effective) + ')</span>';
                                }
                                html += ' — این مقدار خودکار در سقف گره و کانفیگ کلاینت ثبت شد.</p>';
                            } else if (c.note) {
                                html += '<p style="font-size:12.5px; color:#b45309; margin:8px 0 0;">⚠ ' + esc(c.note) + '</p>';
                            }
                        }

                        if (res.suggestions) {
                            html += '<h4 style="margin: 14px 0 6px;">مقادیر پیشنهادی هستهٔ ارتباطی برای این هاست</h4>';
                            html += '<table class="widefat striped"><tbody>';
                            var s = res.suggestions;
                            var sug = [
                                ['prefer_fast_endpoint', s.prefer_fast_endpoint === '1' ? '1 (مسیر سریع)' : '0 (مسیر وردپرسی)'],
                                ['max_pool_connections', s.max_pool_connections],
                                ['connect_timeout', s.connect_timeout],
                                ['stream_read_timeout', s.stream_read_timeout],
                                ['write_timeout', s.write_timeout],
                                ['max_retries', s.max_retries],
                                ['max_adaptive_delay', s.max_adaptive_delay],
                                ['idle_timeout', s.idle_timeout],
                                ['low_traffic_timeout', s.low_traffic_timeout],
                                ['low_traffic_threshold_mb', s.low_traffic_threshold_mb]
                            ];
                            for (var k = 0; k < sug.length; k++) {
                                html += '<tr><td style="width:40%;"><code style="direction:ltr;display:inline-block;">' + esc(sug[k][0]) + '</code></td><td style="direction:ltr; text-align:left;">' + esc(sug[k][1]) + '</td></tr>';
                            }
                            html += '</tbody></table>';
                            var btn = document.getElementById('glpe-tune-apply');
                            if (btn) { btn.style.display = 'inline-block'; }
                        }

                        if (res.fetch_suggestions) {
                            var f = res.fetch_suggestions;
                            html += '<h4 style="margin: 14px 0 6px;">مقادیر پیشنهادی مرورگر GLPE (DNS و اتصال)</h4>';
                            html += '<table class="widefat striped"><tbody>';
                            var fs = [
                                ['glpe_dns_mode', f.glpe_dns_mode === 'doh_first' ? 'doh_first (همیشه DoH)' : (f.glpe_dns_mode === 'system' ? 'system (فقط DNS هاست)' : 'auto (سیستم + ترمیم DoH)')],
                                ['glpe_dns_ttl', f.glpe_dns_ttl],
                                ['glpe_fetch_connect_timeout', f.glpe_fetch_connect_timeout],
                                ['glpe_fetch_timeout', f.glpe_fetch_timeout],
                                ['glpe_fetch_retries', f.glpe_fetch_retries],
                                ['glpe_ipresolve_v4', f.glpe_ipresolve_v4 === '1' ? '1 (فقط IPv4)' : '0']
                            ];
                            for (var k2 = 0; k2 < fs.length; k2++) {
                                html += '<tr><td style="width:40%;"><code style="direction:ltr;display:inline-block;">' + esc(fs[k2][0]) + '</code></td><td style="direction:ltr; text-align:left;">' + esc(fs[k2][1]) + '</td></tr>';
                            }
                            html += '</tbody></table>';
                            var form = document.getElementById('glpe-fetch-form');
                            if (form) {
                                form.style.display = 'block';
                                setFetch('glpe-fetch-mode', f.glpe_dns_mode);
                                setFetch('glpe-fetch-ttl', f.glpe_dns_ttl);
                                setFetch('glpe-fetch-connect', f.glpe_fetch_connect_timeout);
                                setFetch('glpe-fetch-timeout', f.glpe_fetch_timeout);
                                setFetch('glpe-fetch-retries', f.glpe_fetch_retries);
                                var v4 = document.getElementById('glpe-fetch-ipv4');
                                if (v4) { v4.checked = (String(f.glpe_ipresolve_v4) === '1'); }
                            }
                        }

                        if (res.warnings && res.warnings.length) {
                            html += '<div style="margin-top: 10px;">';
                            for (var w = 0; w < res.warnings.length; w++) {
                                html += '<p style="font-size:12.5px; color:#b45309; margin:4px 0;">⚠ ' + esc(res.warnings[w]) + '</p>';
                            }
                            html += '</div>';
                        }
                        box.innerHTML = html;
                    }
                    function setFetch(id, val) {
                        var el = document.getElementById(id);
                        if (el && val !== undefined && val !== null && String(val) !== '') { el.value = String(val); }
                    }
                    function applySuggestions(res) {
                        if (!res || !res.suggestions) return;
                        var s = res.suggestions;
                        var fields = document.querySelectorAll('.glpe-cfg-field');
                        for (var i = 0; i < fields.length; i++) {
                            var key = fields[i].getAttribute('data-key');
                            if (s[key] !== undefined && s[key] !== null) { fields[i].value = String(s[key]); }
                        }
                        var fast = document.querySelector('input[name="cfg[prefer_fast_endpoint]"]');
                        if (fast) { fast.checked = String(s.prefer_fast_endpoint) === '1'; }
                        document.getElementById('glpe-tune-status').textContent = 'پیشنهادها در فرم اعمال شد — این مقادیر پس از هر ارزیابی خودکار هم ذخیره می‌شوند؛ دکمه‌های ذخیره فقط برای ویرایش دستی مجدد لازم‌اند.';
                    }
                    var btn = document.getElementById('glpe-tune-run');
                    if (btn) {
                        btn.addEventListener('click', function () {
                            var st = document.getElementById('glpe-tune-status');
                            window.glpeTuneRunning = true;
                            btn.disabled = true;
                            st.textContent = 'در حال اجرای آزمایش‌ها و کالیبراسیون دسته‌ای... (چند تا چند‌ده ثانیه طول می‌کشد — صفحه را نبندید؛ لحظه‌ای کندی سایت طبیعی است)';
                            fetch(url, { cache: 'no-store' })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    if (res.error) { st.textContent = 'خطا: ' + res.error; return; }
                                    st.textContent = 'ارزیابی و کالیبراسیون انجام شد.';
                                    window.glpeTuning = res;
                                    render(res);
                                    if (res.applied && res.applied.max_streams) {
                                        var capInput = document.querySelector('input[name="hub_max_streams"]');
                                        if (capInput) { capInput.value = String(res.applied.max_streams); }
                                    }
                                    if (res.at) {
                                        var d = new Date(res.at * 1000);
                                        st.textContent = 'ارزیابی و کالیبراسیون انجام شد (' + d.toLocaleString('fa-IR') + ') — مقادیر خودکار ذخیره شدند.';
                                    }
                                })
                                .catch(function () { st.textContent = 'ارزیابی ناموفق بود — دوباره تلاش کنید.'; })
                                .finally(function () {
                                    window.glpeTuneRunning = false;
                                    btn.disabled = false;
                                });
                        });
                    }
                    var apply = document.getElementById('glpe-tune-apply');
                    if (apply) {
                        apply.addEventListener('click', function () {
                            applySuggestions(window.glpeTuning || saved);
                        });
                    }
                    if (saved && saved.php && saved.suggestions) { render(saved); }
                })();
                </script>
            </div>

            <!-- فرم ویرایش کانفیگ -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" id="glpe-cfg-card">
                <h3 style="margin: 0 0 8px;">⚙️ ویرایش مقادیر کانفیگ کلاینت</h3>
                <p style="font-size: 13px; color: #334155;">مقادیر زیر دقیقاً همان کلیدهای <code>config.json</code> تولیدشده برای کلاینت پایتون هستند؛ بعد از ذخیره، فایل دانلودشده از این مقادیر ساخته می‌شود. مقادیر خالی یعنی «بهترین مقدار پیش‌فرض افزونه».</p>
                <form method="post" action="<?php echo esc_url($saveUrl); ?>">
                    <input type="hidden" name="action" value="glpe_hub_savecfg" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('glpe_hub_admin')); ?>" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">ترجیح مسیر سریع <code style="direction:ltr;">prefer_fast_endpoint</code></th>
                            <td>
                                <label>
                                    <input type="hidden" name="cfg[prefer_fast_endpoint]" value="0" />
                                    <input type="checkbox" name="cfg[prefer_fast_endpoint]" value="1" <?php checked($preferFast, true); ?> class="glpe-cfg-field" data-key="prefer_fast_endpoint" />
                                    کلاینت ابتدا مسیر سریع را امتحان می‌کند و با خطا خودکار به مسیر وردپرسی می‌گذرد
                                </label>
                            </td>
                        </tr>
                        <?php
                        $field('relay_port', '', '');
                        $field('max_retries', '', '');
                        $field('connect_timeout', '', 'ثانیه');
                        $field('stream_read_timeout', '', 'ثانیه');
                        $field('write_timeout', '', 'ثانیه');
                        $field('max_adaptive_delay', '', 'ثانیه');
                        $field('idle_timeout', '', 'ثانیه');
                        $field('max_tunnel_uptime', '', 'ثانیه');
                        $field('low_traffic_timeout', '', 'ثانیه');
                        $field('low_traffic_threshold_mb', '', 'مگابایت');
                        $field('active_transfer_threshold_kbps', '', 'کیلوبایت بر ثانیه');
                        ?>
                        <tr>
                            <th scope="row">سقف کانال‌های همزمان گره <code style="direction:ltr;">hub_max_streams</code></th>
                            <td>
                                <input type="number" min="<?php echo (int)GLPE_Hub::STREAM_CAP_MIN; ?>" max="<?php echo (int)GLPE_Hub::STREAM_CAP_MAX; ?>" name="hub_max_streams" value="<?php echo esc_attr((string)$savedCap); ?>" style="direction:ltr; width:90px;" class="glpe-cfg-field" data-key="max_pool_connections" /> کانال
                                <p style="font-size:12px; color:#64748b; margin-top:4px;">محافظ هاست: هر کانال زنده یک کارگر PHP را تا پایان اتصال نگه می‌دارد؛ روی هاست‌های رایگان این مقدار را کم نگه دارید (پیش‌فرض ۶). درخواست‌های بیش از سقف با کد 503 و تلاش مجدد خودکار کلاینت پاسخ داده می‌شوند. مقدار <code>max_pool_connections</code> تولیدشده در کانفیگ هرگز از این سقف بیشتر نمی‌شود.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">مسیریابی مستقیم GeoSite/GeoIP <code style="direction:ltr;">routing.direct_iran</code></th>
                            <td>
                                <label>
                                    <input type="hidden" name="cfg[routing][direct_iran]" value="0" />
                                    <input type="checkbox" name="cfg[routing][direct_iran]" value="1" <?php checked(!empty($routing['direct_iran'])); ?> />
                                    دامنه‌ها و شبکه‌های داخلی از گره‌ها عبور نکنند و مستقیم وصل شوند
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><code style="direction:ltr;">routing.geoip_url</code></th>
                            <td><input type="text" name="cfg[routing][geoip_url]" value="<?php echo esc_attr(isset($routing['geoip_url']) ? (string)$routing['geoip_url'] : ''); ?>" class="large-text" style="direction:ltr; text-align:left;" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><code style="direction:ltr;">routing.geosite_base_url</code></th>
                            <td><input type="text" name="cfg[routing][geosite_base_url]" value="<?php echo esc_attr(isset($routing['geosite_base_url']) ? (string)$routing['geosite_base_url'] : ''); ?>" class="large-text" style="direction:ltr; text-align:left;" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><code style="direction:ltr;">routing.geosite_main_category</code></th>
                            <td><input type="text" name="cfg[routing][geosite_main_category]" value="<?php echo esc_attr(isset($routing['geosite_main_category']) ? (string)$routing['geosite_main_category'] : ''); ?>" class="regular-text" style="direction:ltr; text-align:left;" /></td>
                        </tr>
                        <tr>
                            <th scope="row">گره‌های اضافی<br /><span style="font-weight:normal; font-size:11px; color:#64748b;">هر خط: نشانی و کلید</span></th>
                            <td>
                                <textarea name="extra_nodes" rows="4" class="large-text code" style="direction:ltr; text-align:left; font-size:12px;" placeholder="https://node2.example.com/ tken_live_xxxxxxxxxxxxxxxx"><?php echo esc_textarea($extraText); ?></textarea>
                                <p style="font-size:12px; color:#64748b;">نشانی و کلید هر سایت دیگر را از صفحهٔ هستهٔ ارتباطی همان سایت بردارید. کلاینت به‌صورت چرخشی بین همهٔ گره‌ها توزیع بار می‌کند.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('💾 ذخیرهٔ مقادیر کانفیگ', 'primary', 'glpe-cfg-submit'); ?>
                </form>

                <details style="margin-top: 6px;">
                    <summary style="cursor: pointer; font-size: 13px; font-weight: bold; color: #0969da;">پیش‌نمایش زندهٔ config.json با مقادیر فعلی</summary>
                    <textarea readonly rows="18" style="width: 100%; margin-top: 8px; direction: ltr; text-align: left; font-family: Consolas, monospace; font-size: 12px; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 10px;" id="glpe-hub-config-text"><?php echo esc_html($configJson); ?></textarea>
                    <p style="font-size: 12px; color: #64748b;">برای افزودن گره از طریق همین فرم (کادر «گره‌های اضافی») اقدام کنید — مقادیر به‌صورت ایمن پالایش می‌شوند.</p>
                </details>
            </div>

            <!-- پایش زنده -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 8px;">🖥️ پایش زندهٔ هسته (به‌روزرسانی خودکار هر ۲ ثانیه)</h3>
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
            </div>

            <!-- تشخیص خطا -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 8px;">🩺 آخرین خطاهای ثبت‌شدهٔ افزونه</h3>
                <p style="font-size: 12.5px; color: #64748b; line-height: 1.9;">اگر نمایشگر یا گره با خطای داخلی متوقف شود، متن کامل خطا این‌جا ذخیره می‌شود تا بررسی و ارسال آن آسان باشد — به‌جای پیام ترسناک «خطای مهم در این وب سایت».</p>
                <?php if (!empty($optionalMissing)): ?>
                    <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#78350f; margin-bottom:10px;">
                        فایل‌های اختیاری غایب: <code style="direction:ltr;"><?php echo esc_html(implode(', ', $optionalMissing)); ?></code> — دانلود بستهٔ کلاینت کامل نخواهد بود؛ برای رفع، افزونه را دوباره نصب کنید.
                    </div>
                <?php endif; ?>
                <?php if (empty($fatals)): ?>
                    <p style="font-size:13px; color:#166534;">✔ هیچ خطایی ثبت نشده است.</p>
                <?php else: ?>
                    <table class="widefat striped"><tbody>
                    <?php foreach (array_slice(array_reverse($fatals), 0, 5) as $f): ?>
                        <tr>
                            <td style="width:150px; white-space:nowrap;"><?php echo esc_html(date('Y-m-d H:i:s', (int)$f['at'])); ?></td>
                            <td style="direction:ltr; text-align:left; font-size:11.5px; word-break:break-all;"><?php echo esc_html(GLPE_Diag::fatalTypeName($f['type']) . ': ' . $f['message'] . ' — ' . basename((string)$f['file']) . ':' . (int)$f['line']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <form method="post" action="<?php echo esc_url($postLink('glpe_hub_clearfatal')); ?>" style="margin-top:10px;">
                        <input type="hidden" name="action" value="glpe_hub_clearfatal" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('glpe_hub_admin')); ?>" />
                        <?php submit_button('پاک‌سازی تاریخچهٔ خطاها', 'secondary small', 'glpe-clearfatals-submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <script>
            (function () {
                var url = <?php echo wp_json_encode($vitalsUrl); ?>;
                var badgeClass = { START: 'tag-start', CLEAR: 'tag-clear', CLOSE: 'tag-close', ERROR: 'tag-err', CAP: 'tag-calib', CALIB: 'tag-calib' };
                function esc(s) {
                    var d = document.createElement('div');
                    d.textContent = s == null ? '' : String(s);
                    return d.innerHTML;
                }
                function loadVitals() {
                    // During the batch calibration every worker is busy by
                    // design — polling would queue up on the same host (and
                    // poison the measurement), so the poller yields.
                    if (window.glpeTuneRunning) { return; }
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
                    + '.tag-err{background:rgba(239,68,68,.15);color:#ef4444;}'
                    + '.tag-calib{background:rgba(13,148,136,.15);color:#0d9488;}';
                document.head.appendChild(css);
                loadVitals();
                setInterval(loadVitals, 2000);
            })();
            </script>
        </div>
        <?php
    }

    /** Practical liveness check of the fast endpoint (null = unknown/never run). */
    public static function fastEndpointReachable() {
        $tuning = get_option('glpe_hub_tuning', []);
        if (is_array($tuning) && isset($tuning['probes']['fast_endpoint']['ok'], $tuning['at'])) {
            return (bool)$tuning['probes']['fast_endpoint']['ok'];
        }
        return null;
    }
}
