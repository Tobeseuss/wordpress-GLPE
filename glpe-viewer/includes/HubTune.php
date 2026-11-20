<?php
/**
 * GLPE Relay Core — host evaluation and tuning advisor.
 *
 * Reads the PHP characteristics of the host and then runs PRACTICAL probes
 * against the node itself: round-trip times of the fast endpoint and the
 * CMS-path endpoint, outbound TCP health, stream start latency and output
 * compression. From the measured numbers it derives suggested values for
 * the bundled Python core's config.json (pool size scaled by memory, split
 * timeouts scaled by measured node latency, endpoint preference, ...).
 *
 * Everything runs inside the admin ajax handler (capability + nonce gated
 * by the caller) and the result is stored in the glpe_hub_tuning option so
 * the relay page can display and re-apply the last evaluation.
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Hub_Tune {

    /** Full evaluation. Never throws; failures become probe results. */
    public static function run() {
        $php = self::readPhpProfile();
        $fastProbe = self::probeEndpoint(GLPE_Hub::fastNodeUrl());
        $wpProbe   = self::probeEndpoint(GLPE_Hub::wpNodeUrl());
        $sockProbe = self::probeOutboundSocket();
        $streamProbe = $sockProbe['ok'] ? self::probeStreamFill(6) : null;

        // GLPE viewer fetch/DNS probes: system resolver health + both DoH
        // endpoints (these are the two routes the viewer can resolve names).
        $dnsSys = self::probeDnsSystem();
        $dohCf  = self::probeDohEndpoint(0);
        $dohGg  = self::probeDohEndpoint(1);

        $fastMs = ($fastProbe['ok'] && isset($fastProbe['ms'])) ? $fastProbe['ms'] : null;
        $wpMs   = ($wpProbe['ok'] && isset($wpProbe['ms'])) ? $wpProbe['ms'] : null;
        $streamOk = is_array($streamProbe) ? (bool)$streamProbe['ok'] : false;
        $streamMs = (is_array($streamProbe) && $streamProbe['ok'] && isset($streamProbe['ms'])) ? $streamProbe['ms'] : null;

        $suggestions = self::buildSuggestions($php, $fastMs, $wpMs, $sockProbe['ok'], $streamOk, $streamMs);
        $fetchSuggestions = self::buildFetchSuggestions(
            $dnsSys['ok'], $dnsSys['ms'],
            $dohCf['ok'], $dohCf['ms'],
            $dohGg['ok'], $dohGg['ms']
        );
        $warnings = self::buildWarnings($php, $fastProbe, $wpProbe, $sockProbe, $streamProbe);
        $warnings = array_merge($warnings, self::buildFetchWarnings($dnsSys, $dohCf, $dohGg));

        return [
            'at'          => time(),
            'php'         => $php,
            'probes'      => [
                'fast_endpoint'   => $fastProbe,
                'wp_endpoint'     => $wpProbe,
                'outbound_socket' => $sockProbe,
                'stream_fill'     => $streamProbe,
                'dns_system'      => $dnsSys,
                'doh_cloudflare'  => $dohCf,
                'doh_google'      => $dohGg,
            ],
            'suggestions' => $suggestions,
            'fetch_suggestions' => $fetchSuggestions,
            'warnings'    => $warnings,
        ];
    }

    /* ------------------------------------------------------------------
     * PHP profile (phpinfo-style, read from the runtime itself)
     * ---------------------------------------------------------------- */

    public static function readPhpProfile() {
        $df = (string)ini_get('disable_functions');
        $dfList = array_map('trim', explode(',', $df));
        $sockBlocked = in_array('stream_socket_client', $dfList, true) || !function_exists('stream_socket_client');
        $memRaw = (string)ini_get('memory_limit');
        $memBytes = self::sizeToBytes($memRaw);

        return [
            'sapi'                      => PHP_SAPI,
            'php'                       => PHP_VERSION,
            'memory_limit'              => $memRaw,
            'memory_limit_bytes'        => $memBytes,
            'max_execution_time'        => (int)ini_get('max_execution_time'),
            'output_buffering'          => (int)ini_get('output_buffering'),
            'zlib_output_compression'   => (bool)ini_get('zlib.output_compression'),
            'post_max_size'             => (string)ini_get('post_max_size'),
            'post_max_size_bytes'       => self::sizeToBytes((string)ini_get('post_max_size')),
            'socket_functions_disabled' => $sockBlocked,
            'zip_archive'               => class_exists('ZipArchive'),
            'opcache'                   => function_exists('opcache_get_status') && @opcache_get_status() !== false,
            'server'                    => isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : '-',
        ];
    }

    private static function sizeToBytes($raw) {
        $raw = trim((string)$raw);
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $unit = strtoupper(substr($raw, -1));
        $num = (float)$raw;
        switch ($unit) {
            case 'G': return (int)($num * 1073741824);
            case 'M': return (int)($num * 1048576);
            case 'K': return (int)($num * 1024);
            default:  return (int)$num;
        }
    }

    /* ------------------------------------------------------------------
     * Practical probes
     * ---------------------------------------------------------------- */

    /** Times the host's own system resolver against a stable public name. */
    public static function probeDnsSystem() {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        if (!function_exists('gethostbyname')) {
            $result['note'] = 'تابع gethostbyname در دسترس نیست.';
            return $result;
        }
        $t0 = microtime(true);
        $ip = @gethostbyname('wordpress.org');
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (is_string($ip) && $ip !== 'wordpress.org' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $result['ok'] = true;
            $result['ms'] = $ms;
            $result['ip'] = $ip;
        } else {
            $result['note'] = 'حل نام توسط سیستم هاست ناموفق بود (' . $ms . ' میلی‌ثانیه).';
        }
        return $result;
    }

    /** Pinned DNS-over-HTTPS probe through the shared resolver class. */
    public static function probeDohEndpoint($idx) {
        $res  = GLPE_Dns::dohQuery($idx, 'wordpress.org');
        $note = '';
        if (!$res['ok']) {
            $note = 'پایانهٔ DoH پاسخ نداد' . (isset($res['ms']) && $res['ms'] !== null ? ' (' . $res['ms'] . ' میلی‌ثانیه)' : '') . '.';
        }
        return ['ok' => (bool)$res['ok'], 'ms' => isset($res['ms']) ? $res['ms'] : null, 'ip' => isset($res['ip']) ? $res['ip'] : '', 'note' => $note];
    }

    /** Round-trip a clear-action envelope against one node address. */
    public static function probeEndpoint($baseUrl) {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        if (!is_string($baseUrl) || $baseUrl === '') {
            $result['note'] = 'آدرس گره خالی است.';
            return $result;
        }
        $sess = 'tune' . strtolower(wp_generate_password(6, false, false));
        $env = GLPE_Hub::encodeEnvelope(['a' => 'c', 's' => $sess, 't' => '', 'k' => GLPE_Hub::token()]);
        $url = add_query_arg(['_v' => $env], $baseUrl);
        $t0 = microtime(true);
        $res = wp_remote_get($url, ['timeout' => 20, 'sslverify' => false, 'redirection' => 0]);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (is_wp_error($res)) {
            $result['note'] = $res->get_error_message();
            return $result;
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        $body = (string)wp_remote_retrieve_body($res);
        if ($code === 200 && trim($body) === 'K') {
            $result['ok'] = true;
            $result['ms'] = $ms;
        } else {
            $result['note'] = 'پاسخ ' . $code . ' (بدنه ' . strlen($body) . ' بایت)';
        }
        return $result;
    }

    /** Opens a real outbound TCP connection and exchanges a few bytes. */
    public static function probeOutboundSocket() {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        $t0 = microtime(true);
        $sock = @stream_socket_client('tcp://example.com:80', $errno, $errstr, 6, STREAM_CLIENT_CONNECT);
        if (!$sock) {
            $result['note'] = $errstr !== '' ? (string)$errstr : ('کد ' . $errno);
            return $result;
        }
        stream_set_timeout($sock, 6);
        @fwrite($sock, "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");
        $data = @fread($sock, 256);
        @fclose($sock);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (is_string($data) && strlen($data) > 0) {
            $result['ok'] = true;
            $result['ms'] = $ms;
        } else {
            $result['note'] = 'اتصال برقرار شد اما داده‌ای دریافت نشد.';
        }
        return $result;
    }

    /** Measures the time until the anti-buffering padding starts flowing. */
    public static function probeStreamFill($timeoutSec = 8) {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        $parts = parse_url(home_url('/'));
        if (!$parts || empty($parts['host'])) {
            $result['note'] = 'نشانی سایت قابل تجزیه نیست.';
            return $result;
        }
        $ssl = (($parts['scheme'] ?? 'http') === 'https');
        $port = isset($parts['port']) ? (int)$parts['port'] : ($ssl ? 443 : 80);
        $host = (string)$parts['host'];
        $transport = $ssl ? 'ssl' : 'tcp';
        $sock = @stream_socket_client($transport . '://' . $host . ':' . $port, $errno, $errstr, $timeoutSec);
        if (!$sock) {
            $result['note'] = $errstr !== '' ? (string)$errstr : ('کد ' . $errno);
            return $result;
        }
        stream_set_timeout($sock, $timeoutSec);
        $sess = 'tune' . strtolower(wp_generate_password(6, false, false));
        $chan = strtolower(wp_generate_password(10, false, false));
        $env = GLPE_Hub::encodeEnvelope([
            'a' => 't', 's' => $sess, 't' => $chan, 'h' => 'example.com', 'p' => 80, 'k' => GLPE_Hub::token(), 'net' => 'tcp',
        ]);
        $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
        $req = "GET {$path}/?_v=" . rawurlencode($env) . " HTTP/1.1\r\n"
             . "Host: {$host}\r\n"
             . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
             . "Accept: */*\r\n"
             . "Accept-Encoding: identity\r\n"
             . "Connection: close\r\n\r\n";
        @fwrite($sock, $req);
        $t0 = microtime(true);
        $got = '';
        $fillAt = null;
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($sock)) { break; }
                continue;
            }
            $got .= $chunk;
            if ($fillAt === null && strpos($got, "\r\n\r\n") !== false) {
                $body = substr($got, strpos($got, "\r\n\r\n") + 4);
                // Any whitespace run of a few hundred bytes IS the padding.
                if (strlen($body) >= 256 && preg_match('/^\s+$/', substr($body, 0, 256))) {
                    $fillAt = microtime(true);
                    break;
                }
            }
        }
        @fclose($sock);
        if ($fillAt !== null) {
            $result['ok'] = true;
            $result['ms'] = (int)round(($fillAt - $t0) * 1000);
        } else {
            $result['note'] = 'استریم تا پایان مهلت هیچ داده‌ای نفرستاد (احتمال بافر شدن پاسخ روی وب‌سرور).';
        }
        return $result;
    }

    /* ------------------------------------------------------------------
     * Suggested values (pure function — unit-tested in the selftest)
     * ---------------------------------------------------------------- */

    public static function buildSuggestions($php, $fastMs, $wpMs, $sockOk, $streamOk, $streamMs) {
        $nodeMs = null;
        $fastUsable = ($fastMs !== null);
        if ($fastUsable) { $nodeMs = $fastMs; }
        if ($wpMs !== null && ($nodeMs === null || $wpMs < $nodeMs)) { $nodeMs = $wpMs; }
        $bootSec = $nodeMs !== null ? max(0.2, $nodeMs / 1000.0) : 1.0;

        $mem = isset($php['memory_limit_bytes']) ? (int)$php['memory_limit_bytes'] : 0;
        $pool = 50;
        if ($mem > 0 && $mem <= 96 * 1048576) { $pool = 20; }
        elseif ($mem > 0 && $mem < 256 * 1048576) { $pool = 35; }

        // Split timeouts: generous on slow hosts, still bounded.
        $connect = 15;
        $streamRead = 30;
        $write = (int)max(20, ceil($bootSec * 2) + 10);
        $maxRetries = 2;
        $adaptiveDelay = ($fastUsable && $fastMs !== null && $fastMs < 400 && $streamOk) ? 0.5 : 1.0;
        $idle = 15;
        if ($nodeMs !== null && $nodeMs > 2500) { $idle = 25; }
        $lowTrafficTimeout = 45;
        $lowTrafficMb = 0.25;
        $vipKbps = 10;

        $preferFast = '0';
        if ($fastUsable && ($wpMs === null || $fastMs <= $wpMs)) {
            $preferFast = '1';
        }

        return [
            'prefer_fast_endpoint'           => $preferFast,
            'max_pool_connections'           => $pool,
            'connect_timeout'                => $connect,
            'stream_read_timeout'            => $streamRead,
            'write_timeout'                  => $write,
            'max_retries'                    => $maxRetries,
            'max_adaptive_delay'             => $adaptiveDelay,
            'idle_timeout'                   => $idle,
            'max_tunnel_uptime'              => 3600,
            'low_traffic_timeout'            => $lowTrafficTimeout,
            'low_traffic_threshold_mb'       => $lowTrafficMb,
            'active_transfer_threshold_kbps' => $vipKbps,
        ];
    }

    /**
     * Suggested GLPE-viewer fetch/DNS values for THIS host (pure function —
     * unit-tested in the selftest). Keys are the actual option names so the
     * apply-to-settings action can write them directly.
     */
    public static function buildFetchSuggestions($sysOk, $sysMs, $cfOk, $cfMs, $ggOk, $ggMs) {
        $dohOk = ($cfOk || $ggOk);
        $dohMs = null;
        if ($cfOk && $ggOk) { $dohMs = (int)min($cfMs, $ggMs); }
        elseif ($cfOk) { $dohMs = (int)$cfMs; }
        elseif ($ggOk) { $dohMs = (int)$ggMs; }

        // Mode: dead system resolver → DoH carries everything; a slow one →
        // DoH leads and the cache keeps repeat requests resolver-free.
        $mode = 'auto';
        if (!$sysOk && $dohOk) { $mode = 'doh_first'; }
        elseif ($sysOk && $sysMs > 3000 && $dohOk) { $mode = 'doh_first'; }
        elseif (!$dohOk) { $mode = 'system'; }

        $slow = (!$sysOk || ($sysMs !== null && $sysMs > 3000) || ($dohMs !== null && $dohMs > 1500));
        $verySlow = (!$sysOk || ($sysMs !== null && $sysMs > 6000));

        return [
            'glpe_dns_mode'               => $mode,
            'glpe_dns_ttl'                => ($mode === 'doh_first') ? 1800 : 900,
            'glpe_fetch_connect_timeout'  => $verySlow ? 20 : ($slow ? 15 : 10),
            'glpe_fetch_timeout'          => $slow ? 20 : 15,
            'glpe_fetch_retries'          => ($sysOk && $dohOk) ? 1 : 2,
            'glpe_ipresolve_v4'           => ($sysOk && $sysMs !== null && $sysMs > 4000) ? '1' : '0',
        ];
    }

    private static function buildFetchWarnings($dnsSys, $dohCf, $dohGg) {
        $w = [];
        $sysOk = !empty($dnsSys['ok']);
        $dohOk = !empty($dohCf['ok']) || !empty($dohGg['ok']);
        if (!$sysOk && !$dohOk) {
            $w[] = 'نه سیستم DNS هاست و نه DoH هیچ‌کدام پاسخ نمی‌دهند — باز کردن نشانی‌های تازه با خطای تایم‌اوت مواجه خواهد شد؛ وضعیت شبکهٔ هاست را بررسی کنید.';
        } elseif (!$sysOk && $dohOk) {
            $w[] = 'سیستم DNS هاست پاسخ نمی‌دهد (علت اصلی خطای «Resolving timed out» در مرورگر) — حالت DoH پیشنهاد شده است؛ آن را اعمال کنید تا باز کردن صفحات خودکار ترمیم شود.';
        } elseif ($sysOk && isset($dnsSys['ms']) && $dnsSys['ms'] > 3000 && $dohOk) {
            $w[] = 'حل نام روی هاست کند است (' . (int)$dnsSys['ms'] . ' میلی‌ثانیه) — حالت پیشنهادی DoH مقدار پیش‌خوانی‌ها را به‌طور محسوس کاهش می‌دهد.';
        } elseif ($sysOk && !$dohOk) {
            $w[] = 'DoH روی این هاست در دسترس نیست (خروجی به 1.1.1.1/8.8.4.4 بسته است) — سیستم DNS هاست به‌عنوان تنها مسیر باقی می‌ماند.';
        }
        return $w;
    }

    private static function buildWarnings($php, $fastProbe, $wpProbe, $sockProbe, $streamProbe) {
        $w = [];
        if (!empty($php['socket_functions_disabled'])) {
            $w[] = 'تابع stream_socket_client روی این هاست مسدود شده است — موتور رله اساساً نمی‌تواند اتصال خروجی باز کند. با پشتیبانی هاست تماس بگیرید یا هاست دیگری برای گره انتخاب کنید.';
        }
        if (!$sockProbe['ok'] && empty($php['socket_functions_disabled'])) {
            $w[] = 'اتصال خروجی TCP ناموفق بود — برخی هاست‌های رایگان پورت‌های خروجی را می‌بندند؛ در این حالت گره فقط برای ترافیک ورودی مسیر محلی کار می‌کند.';
        }
        if (!empty($php['zlib_output_compression'])) {
            $w[] = 'zlib.output_compression روشن است — روی استریم جاری ممکن است تأخیر بگذارد؛ در php.ini خاموش کردن آن بهتر است.';
        }
        if (is_array($fastProbe) && !$fastProbe['ok']) {
            $w[] = 'مسیر سریع پاسخ نداد — کلاینت خودکار از مسیر وردپرسی استفاده می‌کند (کندتر اما همان بستهٔ پروتکل). بعد از ذخیرهٔ تنظیمات یک‌بار صفحهٔ مدیریت را باز کنید تا فایل واگذاری مسیر سریع ساخته شود.';
        }
        if (is_array($wpProbe) && !$wpProbe['ok']) {
            $w[] = 'مسیر وردپرسی هم پاسخ نداد — توکن یا وضعیت فعال‌بودن هسته را بررسی کنید.';
        }
        if (is_array($streamProbe) && !$streamProbe['ok']) {
            $w[] = 'استریم شروع به ارسال نشد — احتمالاً وب‌سرور پاسخ را بافر می‌کند؛ پرکنندهٔ ۲۰۴۸ بایتی معمولاً این را می‌شکند، وگرنه با پشتیبانی هاست دربارهٔ «بافر شدن پاسخ در وب‌سرور» تماس بگیرید.';
        }
        if (!empty($php['max_execution_time']) && $php['max_execution_time'] < 30) {
            $w[] = 'max_execution_time کمتر از ۳۰ ثانیه است — استریم‌های طولانی با رسیدن به این سقف قطع می‌شوند (مقدار ایده‌آل ۶۰ یا بیشتر).';
        }
        if (empty($php['opcache']) && PHP_SAPI !== 'cli-server') {
            $w[] = 'OPcache غیرفعال است — فعال‌سازی آن پاسخ‌دهی گره را به‌طور محسوس بهبود می‌دهد.';
        }
        return $w;
    }
}
