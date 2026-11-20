<?php
/**
 * GLPE Relay Core — host evaluation, tuning advisor and concurrency
 * calibrator.
 *
 * Reads the PHP characteristics of the host and then runs PRACTICAL probes
 * against the node itself: round-trip times of the fast endpoint and the
 * CMS-path endpoint, outbound TCP health, stream start latency and output
 * compression. It then CALIBRATES the host's real concurrency limit: batches
 * of concurrent token-authenticated holds are opened against the node and the
 * batch size descends the moment the host answers 503 — the largest clean
 * batch (together with wave-timing analysis) is the safe concurrency. From
 * the measured numbers it derives suggested values for the bundled Python
 * core's config.json (pool size scaled by memory, split timeouts scaled by
 * measured node latency, endpoint preference, ...) which the caller
 * auto-applies without any save button.
 *
 * Everything runs inside the admin ajax handler (capability + nonce gated
 * by the caller) and the result is stored in the glpe_hub_tuning option so
 * the relay page can display and re-apply the last evaluation.
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Hub_Tune {

    /** One canonical browser identity for every request the node makes to
     * itself (and hands to the bundled core). Live-caught on shared free
     * hosting: the openresty front answers a hard 403 for the CMS's default
     * request identity BEFORE any application code runs, which silently
     * killed both node probes and every calibration batch. */
    const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** wp_remote_* argument set for the self-node requests. Kept as one
     * pure method so the exact wire identity is unit-testable. */
    public static function nodeProbeArgs() {
        return [
            'timeout' => 20,
            'sslverify' => false,
            'redirection' => 0,
            'user-agent' => self::BROWSER_UA,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];
    }

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

        // Tunnel-egress probes: the relay core carries browser TLS as raw
        // TCP on port 443 — if the host blocks egress 443, every HTTPS site
        // fails through the tunnel while plain HTTP works (the classic
        // "BBC opens in the viewer but not through the Python core" report).
        $egress443 = self::probeTcpTarget('www.cloudflare.com', 443);
        $tlsProbe  = $egress443['ok'] ? self::probeTlsTarget('www.cloudflare.com') : ['ok' => false, 'ms' => null, 'note' => ''];
        $newsBbc   = self::probeTcpTarget('www.bbc.com', 443);
        $newsIntl  = self::probeTcpTarget('www.iranintl.com', 443);

        $fastMs = ($fastProbe['ok'] && isset($fastProbe['ms'])) ? $fastProbe['ms'] : null;
        $wpMs   = ($wpProbe['ok'] && isset($wpProbe['ms'])) ? $wpProbe['ms'] : null;
        $streamOk = is_array($streamProbe) ? (bool)$streamProbe['ok'] : false;
        $streamMs = (is_array($streamProbe) && $streamProbe['ok'] && isset($streamProbe['ms'])) ? $streamProbe['ms'] : null;

        // Adaptive concurrency calibration: batches of concurrent holds
        // against the live node; the batch size descends the moment the host
        // answers 503, and wave timing reveals the effective worker count on
        // queuing hosts. Result bounds the suggested pool and is auto-applied
        // by the caller (channel cap + config values + viewer options).
        $calibUrl = null;
        $calibMs = null;
        if (isset($fastProbe['ok']) && $fastProbe['ok']) {
            $calibUrl = GLPE_Hub::fastNodeUrl();
            $calibMs = $fastMs;
        } elseif (isset($wpProbe['ok']) && $wpProbe['ok']) {
            $calibUrl = GLPE_Hub::wpNodeUrl();
            $calibMs = $wpMs;
        }
        if ($calibUrl !== null) {
            $calibration = self::runCalibration($calibUrl, $calibMs);
        } else {
            $calibration = [
                'ok' => false, 'note' => 'هیچ پایانهٔ گره‌ای پاسخ نداد — کالیبراسیون همزمانی اجرا نشد.',
                'hold_ms' => 0, 'batches' => [], 'safe_cap' => null, 'effective' => null, 'verdict' => 'skipped',
            ];
        }

        $suggestions = self::buildSuggestions($php, $fastMs, $wpMs, $sockProbe['ok'], $streamOk, $streamMs);
        $suggestions = self::clampPoolByCalibration($suggestions, $calibration);
        $fetchSuggestions = self::buildFetchSuggestions(
            $dnsSys['ok'], $dnsSys['ms'],
            $dohCf['ok'], $dohCf['ms'],
            $dohGg['ok'], $dohGg['ms']
        );
        $warnings = self::buildWarnings($php, $fastProbe, $wpProbe, $sockProbe, $streamProbe);
        $warnings = array_merge($warnings, self::buildFetchWarnings($dnsSys, $dohCf, $dohGg));
        $warnings = array_merge($warnings, self::buildEgressWarnings($egress443, $tlsProbe, $newsBbc, $newsIntl));

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
                'egress_443'      => $egress443,
                'tls_handshake'   => $tlsProbe,
                'sample_bbc'      => $newsBbc,
                'sample_intl'     => $newsIntl,
            ],
            'suggestions' => $suggestions,
            'fetch_suggestions' => $fetchSuggestions,
            'calibration' => $calibration,
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
        $res = wp_remote_get($url, self::nodeProbeArgs());
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
        } elseif ($code === 200 && strpos($body, 'aes.js') !== false && strpos($body, '__test') !== false) {
            // Some free-hosting edges gate requests behind a JavaScript
            // cookie challenge. The bundled core solves it automatically;
            // the probe itself cannot, so the note says exactly that.
            $result['note'] = 'لبهٔ هاست پاسخ را پشت چالش کوکی جاوااسکریپت گذاشته — هستهٔ کلاینت جدید آن را خودکار حل می‌کند.';
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

    /** Raw outbound TCP connect to host:port — the path browser-TLS rides on. */
    public static function probeTcpTarget($host, $port) {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        $t0 = microtime(true);
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 6, STREAM_CLIENT_CONNECT);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($sock) {
            @fclose($sock);
            $result['ok'] = true;
            $result['ms'] = $ms;
        } else {
            $result['note'] = $errstr !== '' ? (string)$errstr : ('کد ' . $errno);
        }
        return $result;
    }

    /** Full TLS handshake from the host to a large CDN (SNI + CA health). */
    public static function probeTlsTarget($host) {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        $t0 = microtime(true);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($sock) {
            @fclose($sock);
            $result['ok'] = true;
            $result['ms'] = $ms;
        } else {
            $result['note'] = $errstr !== '' ? (string)$errstr : ('کد ' . $errno);
        }
        return $result;
    }

    /** Persian warning set for the tunnel-egress probes. */
    public static function buildEgressWarnings($egress443, $tlsProbe, $newsBbc, $newsIntl) {
        $w = [];
        if (empty($egress443['ok'])) {
            $w[] = 'خروجی پورت 443 از این هاست باز نیست — سایت‌های HTTPS از طریق کلاینت (هستهٔ ارتباطی) باز نمی‌شوند؛ مرورگر GLPE مستقل از این محدودیت است. با پشتیبانی هاست دربارهٔ باز کردن خروجی 443 تماس بگیرید.';
        } elseif (empty($tlsProbe['ok'])) {
            $w[] = 'اتصال TCP پورت 443 برقرار می‌شود اما دست‌دادن TLS شکست می‌خورد (' . (string)$tlsProbe['note'] . ') — احتمالاً OpenSSL هاست قدیمی است؛ ترافیک HTTPS از طریق گره ممکن است ناموفق باشد.';
        } else {
            if (empty($newsBbc['ok'])) {
                $w[] = 'www.bbc.com از این هاست در دسترس نیست (اتصال TCP 443 رد شد) — مقصد یا هاست را عوض کنید یا این سایت را فقط با مرورگر GLPE باز کنید.';
            }
            if (empty($newsIntl['ok'])) {
                $w[] = 'www.iranintl.com از این هاست در دسترس نیست (اتصال TCP 443 رد شد) — مقصد یا هاست را عوض کنید یا این سایت را فقط با مرورگر GLPE باز کنید.';
            }
            if (!empty($newsBbc['ok']) && !empty($newsIntl['ok'])) {
                $w[] = 'خروجی 443 و دسترسی به هر دو سایت نمونه سالم است — اگر با کلاینت باز نمی‌شوند، مشکل از حل نشانی محلی مرورگر است: مرورگر را با نوع اتصال HTTP (به‌جای SOCKS5) به 127.0.0.1:41927 وصل کنید (یا در فایرفاکس گزینهٔ فرستادن درخواست‌های نام دامنه از طریق خود SOCKS v5 را فعال کنید) تا نام دامنه‌ها مستقیم به گره برسد.';
            }
        }
        return $w;
    }

    /** Measures the time until the anti-buffering padding starts flowing. */
    public static function probeStreamFill($timeoutSec = 8) {
        $result = ['ok' => false, 'ms' => null, 'note' => ''];
        // Target the CMS-path node address (live scheme), NOT the stored
        // home_url: installs whose TLS is terminated at an edge layer often
        // record an http:// site address while the edge only serves https —
        // probing port 80 then dies in a redirect and the stream never starts.
        $parts = parse_url(GLPE_Hub::wpNodeUrl());
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
             . "User-Agent: " . self::BROWSER_UA . "\r\n"
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
     * Adaptive concurrency calibration
     *
     * The tuner opens batches of concurrent 'z' holds against the node and
     * descends the batch size the moment the host answers 503 (or the batch
     * times out), until it finds the largest size the host serves cleanly.
     * The wall-clock time of each clean batch additionally reveals the
     * EFFECTIVE worker count (waves: ceil(n / workers) x hold), so queuing
     * hosts that never emit a 503 are measured too. The discovered value is
     * then auto-applied: node channel cap, client pool, and all related
     * config values — no save button involved.
     * ---------------------------------------------------------------- */

    /** Descending candidate batch sizes (never above the recommended max). */
    public static function calibLadder() {
        return [10, 7, 5, 4, 3, 2];
    }

    /** Next ladder size strictly below $n (null when $n is already at the floor). */
    public static function calibNextBelow($n) {
        foreach (self::calibLadder() as $size) {
            if ($size < (int)$n) {
                return $size;
            }
        }
        return null;
    }

    /**
     * Classifies one batch outcome (pure — unit-tested).
     *   clean_kept_up  — every hold answered, and fast enough that the host
     *                    served them all at once (no queuing waves).
     *   clean_saturated — every hold answered, but in waves: the effective
     *                    worker count is below the batch size.
     *   busy_reject    — the host answered at least one 429/5xx: hard limit.
     *   slow_fail      — holds went missing (timeout/unexpected answers).
     */
    public static function interpretBatch($n, $ok, $busy, $other, $wallMs, $holdMs) {
        $n = max(1, (int)$n);
        $wallMs = max(1, (int)$wallMs);
        $holdMs = max(1, (int)$holdMs);
        if ((int)$busy > 0) {
            return ['verdict' => 'busy_reject', 'eff' => null];
        }
        if ((int)$ok < $n) {
            return ['verdict' => 'slow_fail', 'eff' => null];
        }
        if ($wallMs <= $holdMs * 1.4) {
            return ['verdict' => 'clean_kept_up', 'eff' => $n];
        }
        $eff = (int)round($n * $holdMs / $wallMs);
        if ($eff >= $n) { $eff = $n - 1; }
        if ($eff < 1) { $eff = 1; }
        return ['verdict' => 'clean_saturated', 'eff' => $eff];
    }

    /**
     * Batch driver (injectable runner for tests). Returns the calibrated
     * safe concurrency plus every batch's raw numbers for the report.
     */
    public static function runCalibration($url, $endpointMs = null, $batchRunner = null) {
        $result = [
            'ok' => false, 'note' => '', 'hold_ms' => 0, 'batches' => [],
            'safe_cap' => null, 'effective' => null, 'verdict' => '',
        ];
        if (!is_string($url) || $url === '') {
            $result['note'] = 'نشانی گره برای کالیبراسیون در دسترس نیست.';
            return $result;
        }
        $runner = $batchRunner !== null ? $batchRunner : [get_called_class(), 'runCalibBatch'];

        $holdMs = 1200;
        if (is_numeric($endpointMs) && (int)$endpointMs > 0) {
            $holdMs = max(1200, min(3000, 2 * (int)$endpointMs));
        }
        $deadlineMs = $holdMs * 3 + 4000;
        $result['hold_ms'] = $holdMs;

        $liveBefore = GLPE_Hub::countLiveStreams();
        $result['live_before'] = $liveBefore;

        $batchIdx = 0;
        $runBatch = function ($n) use (&$batchIdx, $runner, $url, $holdMs, $deadlineMs, &$result) {
            $b = call_user_func($runner, $url, (int)$n, $holdMs, $deadlineMs);
            if (!is_array($b)) { $b = ['ok' => 0, 'busy' => 0, 'other' => (int)$n, 'wall_ms' => $deadlineMs]; }
            $b['n'] = (int)$n;
            $b['idx'] = ++$batchIdx;
            $result['batches'][] = $b;
            return $b;
        };

        // Smoke: the two-hold batch must pass before anything bigger runs —
        // on a broken/busy node this exits the whole ladder in one step.
        $smoke = $runBatch(2);
        $sm = self::interpretBatch(2, $smoke['ok'], $smoke['busy'], $smoke['other'], $smoke['wall_ms'], $holdMs);
        if ($sm['verdict'] !== 'clean_kept_up') {
            $result['ok'] = true;
            $result['safe_cap'] = 2;
            $result['effective'] = 2;
            $result['verdict'] = 'floor';
            $result['note'] = 'حتی دستهٔ دوتایی هم پاسخ تمیز نگرفت — سقف روی کمینهٔ ۲ ثابت شد. هاست در همین لحظه به‌شدت اشغال یا محدود است؛ بعداً دوباره کالیبره کنید.';
            return $result;
        }

        $n = 10;
        $slowFails = 0;
        $saturatedOnce = false;
        $safe = null;
        $eff = null;
        for ($i = 0; $i < 5; $i++) {
            $b = $runBatch($n);
            $in = self::interpretBatch($n, $b['ok'], $b['busy'], $b['other'], $b['wall_ms'], $holdMs);
            if ($in['verdict'] === 'clean_kept_up') {
                $safe = $n;
                $eff = $n;
                $result['verdict'] = 'kept_up';
                break;
            }
            if ($in['verdict'] === 'clean_saturated') {
                $target = max(2, min($n - 1, (int)$in['eff']));
                if ($saturatedOnce) {
                    // Second wave-quantized estimate in a row — take it; a
                    // third batch would only refine by ±1 worker.
                    $safe = $target;
                    $eff = (int)$in['eff'];
                    $result['verdict'] = 'saturated';
                    break;
                }
                $saturatedOnce = true;
                if ($target < 2) {
                    $safe = 2;
                    $eff = (int)$in['eff'];
                    $result['verdict'] = 'saturated';
                    break;
                }
                $n = $target;
                continue;
            }
            if ($in['verdict'] === 'slow_fail') {
                $slowFails++;
                if ($slowFails >= 2) {
                    // Two batches in a row lost holds to timeouts — the host
                    // is far too slow under load; stop punishing it.
                    $safe = 2;
                    $eff = 1;
                    $result['verdict'] = 'timeout_floor';
                    break;
                }
            }
            // busy_reject (or a single slow_fail): descend one ladder step.
            $next = self::calibNextBelow($n);
            if ($next === null) {
                $safe = 2;
                $eff = 1;
                $result['verdict'] = 'ladder_floor';
                break;
            }
            $n = $next;
        }
        if ($safe === null) {
            $safe = 2;
            $eff = 1;
            $result['verdict'] = 'ladder_floor';
        }

        $result['ok'] = true;
        $result['safe_cap'] = max(GLPE_Hub::STREAM_CAP_MIN, min(GLPE_Hub::STREAM_CAP_MAX, (int)$safe));
        $result['effective'] = $eff;
        if ($liveBefore > 0) {
            $result['note'] = 'هنگام کالیبراسیون ' . (int)$liveBefore . ' کانال زنده فعال بود — نتیجه محافظه‌کارانه است.';
        }
        return $result;
    }

    /**
     * Opens $n concurrent 'z' holds against the node. Each socket is
     * connected, (TLS-)handshaken and CARRIES ITS REQUEST before the next
     * one opens — a server worker that accepted an earlier connection is
     * already busy with it by then, so connections spread across workers
     * exactly like real client traffic (verified live: connections opened
     * data-less all clump into one poll-loop worker on some servers). The
     * wave clock starts when the LAST request was written, so serialized
     * connection setup never pollutes the worker-count measurement.
     */
    public static function runCalibBatch($url, $n, $holdMs, $deadlineMs) {
        $n = max(1, min(GLPE_Hub::CALIB_ABS_MAX, (int)$n));
        $out = ['ok' => 0, 'busy' => 0, 'other' => 0, 'wall_ms' => 0, 'deadline_hit' => false];
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            $out['other'] = $n;
            $out['note'] = 'نشانی گره قابل تجزیه نیست.';
            return $out;
        }
        $ssl  = ((isset($parts['scheme']) ? $parts['scheme'] : 'http') === 'https');
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? (int)$parts['port'] : ($ssl ? 443 : 80);
        $path = isset($parts['path']) && $parts['path'] !== '' ? (string)$parts['path'] : '/';

        // Resolve once for every socket in the batch.
        $ip = function_exists('gethostbyname') ? @gethostbyname($host) : $host;
        $connectHost = (is_string($ip) && $ip !== '' && $ip !== $host) ? $ip : $host;
        if (strpos($connectHost, ':') !== false && $connectHost[0] !== '[') {
            $connectHost = '[' . $connectHost . ']';
        }

        $sess = 'cal' . strtolower(wp_generate_password(6, false, false));
        $chan = strtolower(wp_generate_password(10, false, false));
        $env = GLPE_Hub::encodeEnvelope([
            'a' => 'z', 's' => $sess, 't' => $chan, 'd' => (int)$holdMs, 'k' => GLPE_Hub::token(),
        ]);
        $target = $path . '?_v=' . rawurlencode($env);
        $req = "GET {$target} HTTP/1.1\r\n"
             . "Host: {$host}\r\n"
             . "User-Agent: " . self::BROWSER_UA . "\r\n"
             . "Accept: */*\r\n"
             . "Accept-Encoding: identity\r\n"
             . "Connection: close\r\n\r\n";

        $ctxOpts = $ssl ? ['ssl' => [
            'peer_name' => $host, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true,
        ]] : [];
        $ctx = stream_context_create($ctxOpts);

        $t0 = microtime(true);
        $deadline = $t0 + max(3.0, $deadlineMs / 1000.0);
        $socks = [];
        $lastWriteAt = null;
        for ($i = 0; $i < $n; $i++) {
            $entry = ['fp' => null, 'resp' => '', 'done' => false, 'fail' => false];
            if (microtime(true) >= $deadline) {
                $entry['fail'] = true; // host too slow to even set the batch up
                $socks[] = $entry;
                continue;
            }
            $errno = 0; $errstr = '';
            $fp = @stream_socket_client('tcp://' . $connectHost . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);
            if ($fp !== false) {
                stream_set_blocking($fp, false);
                if (self::calibSendRequest($fp, $req, $ssl, $deadline)) {
                    $entry['fp'] = $fp;
                    $lastWriteAt = microtime(true);
                } else {
                    @fclose($fp);
                    $entry['fail'] = true;
                }
            } else {
                $entry['fail'] = true;
            }
            $socks[] = $entry;
            // Small spacing between connections: the worker that accepted
            // connection i is already busy with its request when connection
            // i+1 arrives, so the server's real worker spread takes effect.
            if ($i < $n - 1) {
                usleep(15000);
            }
        }
        if ($lastWriteAt === null) {
            $out['other'] = $n;
            $out['wall_ms'] = (int)round((microtime(true) - $t0) * 1000);
            return $out;
        }

        // Read phase: wait for every answer; the wave clock covers the span
        // from the last request written to the last answer received.
        $leftover = true;
        while (microtime(true) < $deadline) {
            $r = [];
            $activeCount = 0;
            foreach ($socks as $idx => $s) {
                if ($s['done'] || $s['fail'] || $s['fp'] === null) { continue; }
                $activeCount++;
                $r[] = $s['fp'];
            }
            if ($activeCount === 0) { $leftover = false; break; }
            $left = $deadline - microtime(true);
            if ($left <= 0) { break; }
            $sel = @stream_select($r, $w, $e, min(0.5, $left));
            if ($sel === false) { break; }
            foreach ($r as $fp) {
                $idx = false;
                foreach ($socks as $j => $s) {
                    if ($s['fp'] === $fp) { $idx = $j; break; }
                }
                if ($idx === false) { continue; }
                $s = &$socks[$idx];
                $chunk = @fread($fp, 8192);
                if ($chunk === false) { $s['fail'] = true; unset($s); continue; }
                if ($chunk === '') {
                    if (@feof($fp)) { $s['done'] = true; }
                    unset($s); continue;
                }
                $s['resp'] .= $chunk;
                $hEnd = strpos($s['resp'], "\r\n\r\n");
                if (@feof($fp) || ($hEnd !== false && strlen($s['resp']) > $hEnd + 4)) {
                    $s['done'] = true;
                }
                unset($s);
            }
        }

        foreach ($socks as $s) {
            if (is_resource($s['fp'])) { @fclose($s['fp']); }
            $resp = (string)$s['resp'];
            $code = 0;
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $resp, $m)) { $code = (int)$m[1]; }
            $body = strpos($resp, "\r\n\r\n") !== false ? substr($resp, strpos($resp, "\r\n\r\n") + 4) : '';
            if ($s['done'] && $code === 200 && strpos($body, 'K') !== false) { $out['ok']++; }
            elseif ($s['done'] && in_array($code, [429, 500, 502, 503, 504], true)) { $out['busy']++; }
            else { $out['other']++; }
        }
        $out['wall_ms'] = (int)round((microtime(true) - $lastWriteAt) * 1000);
        $out['deadline_hit'] = $leftover;
        return $out;
    }

    /**
     * Inline setup for one calibration socket: wait for the async connect,
     * drive the TLS handshake when needed, and push the whole request — all
     * before the caller opens the next socket. Bounded by $deadline; never
     * blocks longer than the remaining batch budget.
     */
    private static function calibSendRequest($fp, $req, $ssl, $deadline) {
        // 1. wait for the TCP connect (writable, not refused).
        $connDl = min(microtime(true) + 2.0, $deadline);
        $connected = false;
        while (microtime(true) < $connDl) {
            $r = [$fp]; $w = [$fp]; $e = null;
            $left = $connDl - microtime(true);
            if ($left <= 0) { break; }
            $sel = @stream_select($r, $w, $e, min(0.25, $left));
            if ($sel === false) { return false; }
            if (empty($w) && empty($r)) { continue; }
            if (@feof($fp)) { return false; }
            $connected = true;
            break;
        }
        if (!$connected) { return false; }

        // 2. TLS handshake (driven manually on the non-blocking socket).
        if ($ssl) {
            $tlsDl = min(microtime(true) + 3.0, $deadline);
            $handshake = 0;
            while (microtime(true) < $tlsDl) {
                $handshake = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($handshake === true) { break; }
                if ($handshake === false) { return false; }
                $left = $tlsDl - microtime(true);
                if ($left <= 0) { break; }
                $r = [$fp]; $w = [$fp]; $e = null;
                @stream_select($r, $w, $e, min(0.25, $left));
            }
            if ($handshake !== true) { return false; }
        }

        // 3. write the full request.
        $written = 0;
        $len = strlen($req);
        $writeDl = min(microtime(true) + 2.0, $deadline);
        while ($written < $len && microtime(true) < $writeDl) {
            $bw = @fwrite($fp, substr($req, $written));
            if ($bw === false) { return false; }
            if ($bw === 0) {
                if (@feof($fp)) { return false; }
                usleep(2000);
                continue;
            }
            $written += $bw;
        }
        return $written >= $len;
    }

    /** Pure clamp: the calibration result bounds the suggested pool. */
    public static function clampPoolByCalibration($suggestions, $calib) {
        if (!is_array($suggestions) || !is_array($calib) || empty($calib['ok']) || empty($calib['safe_cap'])) {
            return $suggestions;
        }
        $cap = max(GLPE_Hub::STREAM_CAP_MIN, min(GLPE_Hub::STREAM_CAP_MAX, (int)$calib['safe_cap']));
        if (isset($suggestions['max_pool_connections'])) {
            $suggestions['max_pool_connections'] = min((int)$suggestions['max_pool_connections'], $cap);
        }
        return $suggestions;
    }

    /**
     * AUTO-APPLY — persists everything the evaluation found, no save button:
     *   1. viewer fetch/DNS options (the settings page's own options),
     *   2. the node channel cap from the calibration,
     *   3. the client-config overrides behind config.json (pool clamped by
     *      the calibrated cap + the tuned timeouts).
     * Unrelated admin values (routing, extra nodes) are preserved.
     * Returns the applied summary for the UI.
     */
    public static function autoApply($result) {
        if (!is_array($result)) {
            return [];
        }
        $applied = [];

        $fetch = isset($result['fetch_suggestions']) && is_array($result['fetch_suggestions'])
            ? $result['fetch_suggestions'] : [];
        if (!empty($fetch)) {
            $mode = isset($fetch['glpe_dns_mode']) && in_array($fetch['glpe_dns_mode'], ['auto', 'doh_first', 'system'], true)
                ? $fetch['glpe_dns_mode'] : 'auto';
            update_option('glpe_dns_mode', $mode);
            $ranges = [
                'glpe_dns_ttl'               => [60, 86400],
                'glpe_fetch_connect_timeout' => [3, 60],
                'glpe_fetch_timeout'         => [8, 120],
            ];
            foreach ($ranges as $key => $range) {
                $v = isset($fetch[$key]) ? (int)$fetch[$key] : 0;
                if ($v >= $range[0] && $v <= $range[1]) {
                    update_option($key, (string)$v);
                }
            }
            $retries = isset($fetch['glpe_fetch_retries']) ? (int)$fetch['glpe_fetch_retries'] : 0;
            update_option('glpe_fetch_retries', ($retries >= 1 && $retries <= 3) ? (string)$retries : '1');
            update_option('glpe_ipresolve_v4', (isset($fetch['glpe_ipresolve_v4']) && $fetch['glpe_ipresolve_v4'] === '1') ? '1' : '0');
            $applied['fetch'] = $fetch;
        }

        $calib = isset($result['calibration']) && is_array($result['calibration']) ? $result['calibration'] : [];
        $sug = isset($result['suggestions']) && is_array($result['suggestions']) ? $result['suggestions'] : [];
        if (!empty($calib['ok']) && !empty($calib['safe_cap'])) {
            $cap = max(GLPE_Hub::STREAM_CAP_MIN, min(GLPE_Hub::STREAM_CAP_MAX, (int)$calib['safe_cap']));
            update_option('glpe_hub_max_streams', (string)$cap);
            $applied['max_streams'] = $cap;

            $ov = get_option('glpe_hub_cfg', []);
            if (!is_array($ov)) { $ov = []; }
            $pool = isset($sug['max_pool_connections']) ? (int)$sug['max_pool_connections'] : 10;
            $pool = max(1, min($pool, $cap));
            $ov['max_pool_connections'] = $pool;
            foreach (['connect_timeout', 'stream_read_timeout', 'write_timeout', 'max_adaptive_delay',
                      'idle_timeout', 'low_traffic_timeout', 'low_traffic_threshold_mb',
                      'active_transfer_threshold_kbps'] as $k) {
                if (isset($sug[$k]) && is_numeric($sug[$k]) && (float)$sug[$k] > 0) {
                    $ov[$k] = (float)$sug[$k];
                }
            }
            if (isset($sug['max_retries']) && (int)$sug['max_retries'] > 0) {
                $ov['max_retries'] = (int)$sug['max_retries'];
            }
            if (isset($sug['prefer_fast_endpoint'])) {
                $ov['prefer_fast_endpoint'] = ($sug['prefer_fast_endpoint'] === '1') ? '1' : '0';
            }
            update_option('glpe_hub_cfg', $ov);
            $applied['client_cfg'] = ['max_pool_connections' => $pool];
            // The standalone fast endpoint reads its channel cap from the
            // guarded handoff file — refresh it so the new cap takes effect
            // there immediately too (no admin page visit needed).
            GLPE_Hub::syncNodeStore();
        }
        return $applied;
    }

    /* ------------------------------------------------------------------
     * Suggested values (pure function — unit-tested in the selftest)
     * ---------------------------------------------------------------- */

    public static function buildSuggestions($php, $fastMs, $wpMs, $sockOk, $streamOk, $streamMs) {
        $nodeMs = null;
        $fastUsable = ($fastMs !== null);
        if ($fastUsable) { $nodeMs = $fastMs; }
        if ($wpMs !== null && ($nodeMs === null || $wpMs < $nodeMs)) { $nodeMs = $wpMs; }

        $mem = isset($php['memory_limit_bytes']) ? (int)$php['memory_limit_bytes'] : 0;

        // Pool = concurrently-live channels on the node = pinned PHP workers.
        // Free hosts have very few workers, so the baseline is deliberately
        // small; only a demonstrably fast, streaming-healthy node earns more.
        $pool = 8;
        if (!$sockOk || !$streamOk) { $pool = 6; }
        if ($mem > 0 && $mem <= 96 * 1048576) { $pool = min($pool, 6); }
        if ($fastUsable && $streamOk && $fastMs !== null && $fastMs < 400) { $pool = 10; }
        if ($nodeMs !== null && $nodeMs > 2500) { $pool = min($pool, 6); }

        // Split timeouts: generous on slow hosts, still bounded.
        $connect = 15;
        $streamRead = 30;
        $write = (int)max(20, ceil(($nodeMs !== null ? max(0.2, $nodeMs / 1000.0) : 1.0) * 2) + 10);
        $maxRetries = 2;
        $adaptiveDelay = ($fastUsable && $fastMs !== null && $fastMs < 400 && $streamOk) ? 0.5 : 1.0;
        $idle = 12;
        if ($nodeMs !== null && $nodeMs > 2500) { $idle = 20; }
        $lowTrafficTimeout = 35;
        $lowTrafficMb = 0.15;
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
        if (is_array($fastProbe) && !$fastProbe['ok'] && is_array($wpProbe) && !$wpProbe['ok']) {
            $w[] = 'هر دو پایانهٔ گره پاسخ ندادند — اگر پاسخ خطا (مانند 403) است، مسدودسازی در لایهٔ وب‌سرور/دیوار آتش هاست رخ داده و فقط از درون هاست قابل شناسایی است. برای یافتن و رفع علت، از تنظیمات اصلی افزونه یک «کلید تشخیص از راه دور» بسازید و آن را به دستیار مورد اعتماد خود بدهید.';
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
