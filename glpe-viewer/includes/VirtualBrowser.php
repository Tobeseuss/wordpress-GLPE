<?php
/**
 * GLPE Virtual Browser — host-side rendering manager (v7.0.0).
 *
 * The virtual browser mode renders every destination entirely inside a
 * private, temporary Chromium environment ON THIS HOST and streams only
 * compressed picture frames to the visitor; mouse and keyboard gestures
 * travel back and are replayed inside that remote renderer. No HTML, CSS
 * or JavaScript from the destination ever reaches the visitor's machine.
 *
 * This class owns the WordPress side of the arrangement:
 *   - the shared session key (options row + a key file in uploads)
 *   - the listen port / client endpoint the browser UI connects to
 *   - the runtime probe (Bun executable + renderer binary)
 *   - the health check and the best-effort auto-start of the companion
 *     service (vb/vb-service.js) as a detached process
 *
 * Zero external dependencies. PHP 7.2 - 8.4.
 */

class GLPE_VirtualBrowser {

    const OPT_KEY = 'glpe_vb_key';
    const OPT_PORT = 'glpe_vb_port';
    const OPT_ENDPOINT = 'glpe_vb_endpoint';
    const DEFAULT_PORT = 3007;
    const CANNOT_TRANSIENT = 'glpe_vb_cannot';

    /** Shared session key (generated once, kept in the options table). */
    public static function key() {
        $key = get_option(self::OPT_KEY);
        if (!is_string($key) || strlen($key) < 16) {
            $key = wp_generate_password(40, false, false);
            update_option(self::OPT_KEY, $key);
        }
        return $key;
    }

    /** Absolute path of the key file inside the uploads directory. */
    public static function keyFile() {
        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
        if ($base === '' || !wp_mkdir_p($base)) {
            return '';
        }
        return rtrim($base, '/\\') . '/glpe-vb-key.txt';
    }

    /**
     * Writes the shared key file the companion service reads on every
     * client connection. Keeping it in uploads lets both sides (PHP under
     * the web server user, the service under the shell user) stay in sync
     * without any database access inside the service process.
     */
    public static function syncKeyFile() {
        $file = self::keyFile();
        if ($file === '') {
            return false;
        }
        $written = @file_put_contents($file, self::key());
        if ($written !== false) {
            @chmod($file, 0640);
        }
        return $written !== false;
    }

    /** Listen port of the companion service (filterable). */
    public static function port() {
        $port = (int)get_option(self::OPT_PORT, self::DEFAULT_PORT);
        if ($port < 1024 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }
        return (int)apply_filters('glpe_vb_port', $port);
    }

    /**
     * Endpoint the visitor's browser connects its WebSocket to.
     * Precedence: saved custom endpoint > query-port gateway path (when the
     * current page itself arrived through such a gateway) > direct
     * scheme://host:port.
     */
    public static function endpoint() {
        $custom = trim((string)get_option(self::OPT_ENDPOINT, ''));
        if ($custom !== '') {
            return $custom;
        }
        $port = self::port();
        // A page served through a query-address gateway reaches the service
        // the same way — same origin, port chosen by the query field.
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $qPort = isset($_GET['XTransformPort']) ? (int)$_GET['XTransformPort'] : 0;
            if ($qPort > 0) {
                return '/?XTransformPort=' . $port;
            }
        }
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST) : '';
        if (!$host) {
            $host = parse_url(home_url('/'), PHP_URL_HOST);
        }
        return $scheme . '://' . $host . ':' . $port;
    }

    /** Locates a Bun executable (the service runtime). */
    public static function findBun() {
        $env = getenv('GLPE_VB_BUN');
        if ($env && @is_executable($env)) {
            return $env;
        }
        $candidates = [
            '/usr/local/bin/bun', '/usr/bin/bun',
            '/opt/bun/bin/bun', '/snap/bin/bun',
        ];
        $pathEnv = getenv('PATH');
        if ($pathEnv) {
            foreach (explode(':', $pathEnv) as $dir) {
                $candidates[] = rtrim($dir, '/') . '/bun';
            }
        }
        $home = getenv('HOME');
        if ($home) {
            $candidates[] = rtrim($home, '/') . '/.bun/bin/bun';
        }
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /** Locates a renderer binary (display only — the service re-probes). */
    public static function chromePath() {
        $env = getenv('GLPE_VB_CHROME');
        if ($env && @file_exists($env)) {
            return $env;
        }
        $patterns = [
            getenv('HOME') . '/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux*/chrome-headless-shell',
            getenv('HOME') . '/.cache/puppeteer/chrome-headless-shell/linux-*/chrome-linux*/chrome-headless-shell',
            getenv('HOME') . '/.cache/ms-playwright/chromium-*/chrome-linux*/chrome',
            getenv('HOME') . '/.cache/puppeteer/chrome/linux-*/chrome-linux64/chrome',
            '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
        ];
        foreach ($patterns as $pattern) {
            if ($pattern === false || $pattern === '') {
                continue;
            }
            $hits = glob($pattern);
            if (is_array($hits)) {
                foreach ($hits as $hit) {
                    if (@is_executable($hit)) {
                        return $hit;
                    }
                }
            }
        }
        return '';
    }

    /** True when the companion service answers its health endpoint. */
    public static function serviceRunning() {
        $url = 'http://127.0.0.1:' . self::port() . '/health';
        $response = wp_remote_get($url, ['timeout' => 1.5, 'redirection' => 0, 'sslverify' => false]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        return $code === 200 && strpos($body, '"glpe-vb"') !== false;
    }

    /**
     * Ensures the companion service is up, starting it when the host has
     * the runtime for it. Negative outcomes are cached briefly so ordinary
     * page renders never pay the probe cost repeatedly.
     */
    public static function ensureService() {
        if (self::serviceRunning()) {
            return true;
        }
        $cannot = get_transient(self::CANNOT_TRANSIENT);
        if ($cannot) {
            return false;
        }
        $bun = self::findBun();
        $chrome = self::chromePath();
        $script = GLPE_DIR . 'vb/vb-service.js';
        if ($bun === '' || $chrome === '' || !file_exists($script)) {
            set_transient(self::CANNOT_TRANSIENT, 1, 6 * HOUR_IN_SECONDS);
            return false;
        }
        $started = self::spawnService();
        if (!$started) {
            set_transient(self::CANNOT_TRANSIENT, 1, HOUR_IN_SECONDS);
            return false;
        }
        // Give the socket a moment, then confirm.
        usleep(500000);
        if (!self::serviceRunning()) {
            usleep(700000);
        }
        $running = self::serviceRunning();
        if (!$running) {
            set_transient(self::CANNOT_TRANSIENT, 1, HOUR_IN_SECONDS);
        }
        return $running;
    }

    /**
     * Spawns the companion service as a detached process (best effort —
     * hosts that forbid process spawning report it in the status card and
     * the admin runs the service manually as a daemon).
     */
    public static function spawnService() {
        if (!function_exists('proc_open')) {
            return false;
        }
        $bun = self::findBun();
        $chrome = self::chromePath();
        $script = GLPE_DIR . 'vb/vb-service.js';
        if ($bun === '' || !file_exists($script)) {
            return false;
        }
        self::syncKeyFile();
        $keyFile = self::keyFile();
        if ($keyFile === '') {
            return false;
        }
        $uploads = wp_upload_dir();
        $log = isset($uploads['basedir']) ? rtrim($uploads['basedir'], '/\\') . '/glpe-vb.log' : '/tmp/glpe-vb.log';

        $cmd = 'GLPE_VB_PORT=' . (int)self::port()
            . ' GLPE_VB_KEYFILE=' . escapeshellarg($keyFile)
            . ' GLPE_VB_CHROME=' . escapeshellarg($chrome)
            . ' setsid ' . escapeshellarg($bun) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open('/bin/sh -c ' . escapeshellarg($cmd), $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        @proc_close($process);
        return true;
    }

    /** Status summary for the settings card / viewer page. */
    public static function status() {
        $bun = self::findBun();
        $chrome = self::chromePath();
        return [
            'running' => self::serviceRunning(),
            'bun' => $bun,
            'chrome' => $chrome,
            'port' => self::port(),
            'endpoint' => self::endpoint(),
        ];
    }

    /** Inline config for the browser UI script. */
    public static function clientConfig() {
        return [
            'ep' => self::endpoint(),
            'key' => self::key(),
            'v' => defined('GLPE_VERSION') ? GLPE_VERSION : '',
        ];
    }

    /** Admin-post handler: start the service / refresh its status. */
    public static function handleStart() {
        if (!current_user_can('manage_options')) {
            wp_die('غیرمجاز', 403);
        }
        check_admin_referer('glpe_vb_start');
        delete_transient(self::CANNOT_TRANSIENT);
        self::syncKeyFile();
        $ok = self::ensureService();
        wp_safe_redirect(add_query_arg(
            ['page' => 'glpe-viewer', 'cp_msg' => $ok ? 'vb-started' : 'vb-failed'],
            admin_url('admin.php')
        ));
        exit;
    }
}
