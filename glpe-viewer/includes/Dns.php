<?php
/**
 * GLPE DNS hardening — persistent resolve cache with a DoH fallback.
 *
 * Free/shared hosts often ship a broken or overloaded system resolver; every
 * destination fetch then dies with the classic stall:
 *   "cURL error 28: Resolving timed out after 10001 milliseconds"
 *
 * This class removes that single point of failure:
 *   1. RESOLVE CACHE — every successfully resolved target (system or DoH)
 *      is stored host→IP in a guarded store file with a TTL. Fresh pins are
 *      handed to cURL via CURLOPT_RESOLVE, so repeat requests skip the
 *      resolver entirely.
 *   2. DoH FALLBACK — resolution over DNS-over-HTTPS (Cloudflare / Google)
 *      with the endpoint PINNED by IP (CURLOPT_RESOLVE on the DoH endpoint
 *      itself), so it works even when the system resolver is completely
 *      dead — no chicken-and-egg problem.
 *   3. RETRY — the display engine calls planHop() before each hop and
 *      forceResolve() + one retry after a resolve-type failure, transparent
 *      to the visitor.
 *
 * Modes (option glpe_dns_mode):
 *   auto      system DNS first; DoH pin only after a resolve failure or
 *             from the cache (best default — zero overhead on healthy hosts)
 *   doh_first always resolve through DoH and pin the IP (hosts with a dead
 *             system resolver)
 *   system    never use DoH (hosts blocking outbound DNS-over-HTTPS)
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Dns {

    const GUARD = "<?php exit; ?>\n"; // every runtime store file starts with a self-exit tag

    /** IP-pinned DNS-over-HTTPS endpoints (query host + bootstrap IP). */
    private static $dohEndpoints = [
        ['host' => 'cloudflare-dns.com', 'ip' => '1.1.1.1',  'path' => '/dns-query'],
        ['host' => 'dns.google',         'ip' => '8.8.4.4',  'path' => '/resolve'],
    ];

    /** @var string|null test override for the store directory */
    public static $testDir = null;

    /** @var callable|null test override for DoH queries: fn($idx,$host) => ['ok'=>bool,'ip'=>string,'ms'=>int] */
    public static $dohHook = null;

    /** @var int test hook counter — how many real DoH calls were attempted */
    public static $dohCalls = 0;

    /** @var array process-lifetime pin cache: host => ['ips'=>[...], 'ts'=>int] */
    private static $memory = [];

    /** @var array pins staged for the very next transport call: host => ["host:port:ip", ...] */
    private static $pending = [];

    /* ------------------------------------------------------------------
     * Settings
     * ---------------------------------------------------------------- */

    /** Effective fetch/DNS settings with safe defaults. */
    public static function settings() {
        $mode = (string)get_option('glpe_dns_mode', 'auto');
        if ($mode !== 'auto' && $mode !== 'doh_first' && $mode !== 'system') {
            $mode = 'auto';
        }
        return [
            'mode'    => $mode,
            'ttl'     => self::numOpt('glpe_dns_ttl', 900, 60, 86400),
            'connect' => self::numOpt('glpe_fetch_connect_timeout', 10, 3, 60),
            'timeout' => self::numOpt('glpe_fetch_timeout', 15, 8, 120),
            'retries' => self::numOpt('glpe_fetch_retries', 1, 1, 3),
            'ipv4'    => get_option('glpe_ipresolve_v4', '0') === '1',
        ];
    }

    /** Numeric option with a fallback to the plugin default when empty. */
    private static function numOpt($key, $default, $min, $max) {
        $raw = get_option($key, '');
        if ($raw === '' || $raw === false || $raw === null || !is_numeric($raw)) {
            return $default;
        }
        $v = (int)$raw;
        if ($v <= 0) {
            return $default; // stored zero = "use plugin default"
        }
        return max($min, min($max, $v));
    }

    /* ------------------------------------------------------------------
     * Engine-facing API
     * ---------------------------------------------------------------- */

    /**
     * Stage resolve pins for the URL about to be fetched. Call immediately
     * before every transport call. Second call of the same hop ($force=true)
     * performs a fresh DoH lookup — the retry path.
     * @return array pins applied (empty when system DNS is being trusted)
     */
    public static function planHop($url, $force = false) {
        $parsed = parse_url((string)$url);
        if (empty($parsed['host']) || !function_exists('curl_init')) {
            return [];
        }
        $host = strtolower((string)$parsed['host']);
        $port = isset($parsed['port']) ? (int)$parsed['port'] : ((($parsed['scheme'] ?? 'https') === 'https') ? 443 : 80);
        $cfg  = self::settings();

        if ($cfg['mode'] === 'system' && !$force) {
            return [];
        }
        $ips = self::pinIps($host, $cfg, $force);
        if ($ips === []) {
            self::$pending[$host] = [];
            return [];
        }
        $pins = [];
        foreach ($ips as $ip) {
            $pins[] = self::pinEntry($host, $port, $ip);
        }
        self::$pending[$host] = $pins;
        return $pins;
    }

    /**
     * http_api_curl filter — applies staged pins + tuned timeouts to every
     * WP-HTTP request traveling through the cURL transport. Registered once
     * in the main plugin file.
     */
    public static function onHttpApiCurl($ch, $parsedArgs = null, $url = null) {
        if (!is_resource($ch) && !$ch instanceof CurlHandle) {
            return $ch;
        }
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host !== '' && isset(self::$pending[$host]) && self::$pending[$host] !== [] && defined('CURLOPT_RESOLVE')) {
            curl_setopt($ch, CURLOPT_RESOLVE, self::$pending[$host]);
        }
        unset(self::$pending[$host]);
        self::applyTuning($ch);
        return $ch;
    }

    /**
     * Raw-cURL path helper — staged pins + tuned timeouts for a handle.
     * $force=true re-resolves through DoH first (the retry path).
     */
    public static function applyCurl($ch, $url, $force = false) {
        if (!function_exists('curl_setopt')) {
            return $ch;
        }
        self::planHop($url, $force);
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host !== '' && isset(self::$pending[$host]) && self::$pending[$host] !== [] && defined('CURLOPT_RESOLVE')) {
            curl_setopt($ch, CURLOPT_RESOLVE, self::$pending[$host]);
        }
        unset(self::$pending[$host]);
        self::applyTuning($ch);
        return $ch;
    }

    /** Connect budget + IPv4 preference for any cURL handle. */
    private static function applyTuning($ch) {
        if (!function_exists('curl_setopt')) {
            return;
        }
        $cfg = self::settings();
        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $cfg['connect'] * 1000);
        } elseif (defined('CURLOPT_CONNECTTIMEOUT')) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $cfg['connect']);
        }
        if ($cfg['ipv4'] && defined('CURL_IPRESOLVE_V4') && defined('CURLOPT_IPRESOLVE')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

    /**
     * Fresh DoH resolution for a URL (used by the retry path after a
     * resolve-type failure). Returns true when a usable pin was staged.
     */
    public static function forceResolve($url) {
        $parsed = parse_url((string)$url);
        if (empty($parsed['host'])) {
            return false;
        }
        $host = strtolower((string)$parsed['host']);
        unset(self::$memory[$host]); // ignore positive AND negative cache
        self::storeForget($host);
        $pins = self::planHop($url, true);
        return $pins !== [];
    }

    /** Detects resolve/connect-failure messages worth a pinned retry. */
    public static function isResolveError($message) {
        $m = strtolower((string)$message);
        if ($m === '') {
            return false;
        }
        // cURL 6 (resolve failure) and 28 (timeout) — digit-boundary aware,
        // so "curl error 60" (certificate) never matches the "6" probe.
        if (preg_match('/curl error (6|28)\D/', $m)) {
            return true;
        }
        foreach ([
            'resolving timed out',
            'could not resolve',
            'name or service not known',
            'temporary failure in name resolution',
            'no address associated',
            'name does not resolve',
            'timed out' /* bare connect-timeout also benefits from a pinned retry */,
        ] as $needle) {
            if (strpos($m, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * Resolution core
     * ---------------------------------------------------------------- */

    /** IP candidates for a host (cache → DoH), honoring mode + TTL. */
    private static function pinIps($host, $cfg, $force) {
        if ($force) {
            $ips = self::dohResolve($host, $cfg);
            if ($ips !== []) {
                self::storePut($host, $ips);
                self::$memory[$host] = ['ips' => $ips, 'ts' => time()];
            }
            return $ips;
        }
        if (isset(self::$memory[$host]) && (time() - self::$memory[$host]['ts']) <= $cfg['ttl']) {
            return self::$memory[$host]['ips'];
        }
        $stored = self::storeGet($host, $cfg['ttl']);
        if ($stored !== null) {
            self::$memory[$host] = ['ips' => $stored, 'ts' => time()];
            return $stored;
        }
        if ($cfg['mode'] === 'doh_first') {
            $ips = self::dohResolve($host, $cfg);
            if ($ips !== []) {
                self::storePut($host, $ips);
                self::$memory[$host] = ['ips' => $ips, 'ts' => time()];
            }
            return $ips;
        }
        return [];
    }

    /**
     * DoH resolution for A (and AAAA when no A exists) records.
     * Endpoints are IP-pinned so this path never needs the system resolver.
     * @return array of IP strings (best-first)
     */
    private static function dohResolve($host, $cfg) {
        $order = self::endpointOrder();
        $chosen = null;
        $ips = [];
        foreach ($order as $idx) {
            $res = self::dohQuery($idx, $host);
            if ($res['ok'] && $res['ip'] !== '') {
                $chosen = $idx;
                $ips = $res['ips'];
                break;
            }
        }
        if ($chosen !== null) {
            update_option('glpe_doh_endpoint', (string)$chosen, false); // remember the healthy endpoint
        }
        if ($cfg['ipv4']) {
            $v4 = array_values(array_filter($ips, function ($ip) { return strpos($ip, ':') === false; }));
            if ($v4 !== []) {
                return $v4;
            }
        }
        return $ips;
    }

    /** Endpoint preference: the last known-healthy one probes first. */
    private static function endpointOrder() {
        $last = (string)get_option('glpe_doh_endpoint', '');
        $all = [0, 1];
        if ($last === '1') {
            return [1, 0];
        }
        return $all;
    }

    /**
     * One pinned DoH query. Returns ['ok'=>bool,'ip'=>string,'ips'=>array,'ms'=>int].
     * Public so the host evaluator can measure DNS-over-HTTPS health directly.
     */
    public static function dohQuery($idx, $queryHost) {
        self::$dohCalls++;
        if (self::$dohHook !== null) {
            $res = call_user_func(self::$dohHook, $idx, $queryHost);
            $res['ips'] = isset($res['ips']) ? (array)$res['ips'] : [$res['ip']];
            return $res;
        }
        if (!isset(self::$dohEndpoints[$idx]) || !function_exists('curl_init')) {
            return ['ok' => false, 'ip' => '', 'ips' => [], 'ms' => null];
        }
        $ep    = self::$dohEndpoints[$idx];
        $t0    = microtime(true);
        $query = $ep['path'] . '?name=' . rawurlencode($queryHost) . '&type=A';
        $url   = 'https://' . $ep['host'] . $query;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 4000);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/dns-json']);
        if (defined('CURLOPT_RESOLVE')) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$ep['host'] . ':443:' . $ep['ip']]);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        if ($body === false && $errNo === 60) {
            // Missing/outdated CA bundle on the host — the answer still rides
            // TLS (1.1.1.1/8.8.4.4), unverified, and is only used to pick a
            // route; the target's own certificate remains fully verified.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $body = curl_exec($ch);
            $errNo = curl_errno($ch);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'ip' => '', 'ips' => [], 'ms' => $ms];
        }
        $ips = self::parseDohAnswer((string)$body);
        $pick = '';
        foreach ($ips as $ip) {
            if (strpos($ip, ':') === false) {
                $pick = $ip; // prefer an A record
                break;
            }
        }
        if ($pick === '' && $ips !== []) {
            $pick = $ips[0];
        }
        return ['ok' => ($pick !== ''), 'ip' => $pick, 'ips' => $ips, 'ms' => $ms];
    }

    /** Extracts A/AAAA records from a DoH JSON answer. */
    public static function parseDohAnswer($json) {
        $data = json_decode((string)$json, true);
        if (!is_array($data) || !isset($data['Answer']) || !is_array($data['Answer'])) {
            return [];
        }
        $ips = [];
        foreach ($data['Answer'] as $rec) {
            if (!is_array($rec) || !isset($rec['type'], $rec['data'])) {
                continue;
            }
            $type = (int)$rec['type'];
            $val  = trim((string)$rec['data']);
            if ($type === 1 && filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $val;
            } elseif ($type === 28 && filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ips[] = $val;
            }
        }
        return array_values(array_unique($ips));
    }

    private static function pinEntry($host, $port, $ip) {
        if (strpos($ip, ':') !== false) {
            return $host . ':' . $port . ':[' . $ip . ']';
        }
        return $host . ':' . $port . ':' . $ip;
    }

    /* ------------------------------------------------------------------
     * Guarded store file (uploads/glpe-viewer-tmp/dns_store.php)
     * ---------------------------------------------------------------- */

    public static function storeDir() {
        if (self::$testDir !== null) {
            return self::$testDir;
        }
        $uploads = wp_upload_dir();
        $dir = untrailingslashit((string)$uploads['basedir']) . '/glpe-viewer-tmp';
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

    private static function storeFile() {
        return self::storeDir() . '/dns_store.php';
    }

    /** @return array|null IPs for host (null = unknown/expired), [] = known-bad */
    private static function storeGet($host, $ttl) {
        $file = self::storeFile();
        if (!file_exists($file)) {
            return null;
        }
        $fp = @fopen($file, 'r');
        if (!$fp) {
            return null;
        }
        @flock($fp, LOCK_SH);
        $raw = (string)stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode((string)substr($raw, strlen(self::GUARD)), true);
        if (!is_array($data) || !isset($data[$host]) || !is_array($data[$host])) {
            return null;
        }
        $ts  = (int)$data[$host]['ts'];
        $ips = (array)$data[$host]['ips'];
        $age = time() - $ts;
        if ($ips === []) {
            return ($age <= 30) ? [] : null; // short negative cache
        }
        return ($age <= $ttl) ? $ips : null;
    }

    private static function storePut($host, $ips) {
        $file = self::storeFile();
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return false;
        }
        @flock($fp, LOCK_EX);
        $size = (int)filesize($file);
        $raw  = $size > 0 ? (string)fread($fp, $size) : '';
        $data = json_decode((string)substr($raw, strlen(self::GUARD)), true);
        if (!is_array($data)) {
            $data = [];
        }
        $data[$host] = ['ips' => array_values(array_slice((array)$ips, 0, 3)), 'ts' => time()];
        if (count($data) > 256) {
            uasort($data, function ($a, $b) { return ((int)$a['ts']) - ((int)$b['ts']); });
            $data = array_slice($data, -256, null, true);
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, self::GUARD . (string)json_encode($data));
        @flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    private static function storeForget($host) {
        $file = self::storeFile();
        if (!file_exists($file)) {
            return;
        }
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        @flock($fp, LOCK_EX);
        $size = (int)filesize($file);
        $raw  = $size > 0 ? (string)fread($fp, $size) : '';
        $data = json_decode((string)substr($raw, strlen(self::GUARD)), true);
        if (is_array($data)) {
            unset($data[$host]);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, self::GUARD . (string)json_encode($data));
        }
        @flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** Full store wipe (uninstall). */
    public static function wipeStore() {
        $dir = self::storeDir();
        foreach (['dns_store.php', 'dns_store.json', 'index.html', '.htaccess'] as $f) {
            if (file_exists($dir . '/' . $f)) {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }
}
