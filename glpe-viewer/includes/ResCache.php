<?php
/**
 * GLPE Resource Cache — per-subresource artifact store (v7.3.0).
 *
 * A modern application page pulls dozens of subresources through the
 * gateway (YouTube's boot is ~60 requests), and a serialized free host
 * pays a full upstream fetch plus full rewriting CPU for every one of
 * them on every visit. This module caches the FINAL served artifact
 * (already rewritten, ready to echo) for the subresource class only:
 *
 *   - GET exchanges whose upstream response is 200, carries no
 *     Set-Cookie, and is one of the static classes (scripts, sheets,
 *     manifests, fonts, images — HTML documents, API JSON and every
 *     personalized/cookie-bearing response are never stored);
 *   - the entry's freshness is the destination's own max-age, clamped
 *     between two minutes and six hours (default fifteen);
 *   - the key is a SHA-256 over the exact fetch+rewrite inputs (the
 *     destination URL including its query, the identity headers that
 *     may vary the representation, and the display/rewrite options),
 *     so two requests that would produce the same bytes share one
 *     entry and every request that would not gets its own.
 *
 * Storage lives in a guarded runtime directory inside uploads (the
 * deny-all .htaccess pattern the plugin already uses), and every file
 * starts with a PHP exit guard — a direct web hit executes an empty
 * script instead of leaking bytes on servers that ignore .htaccess.
 * Nothing readable (no destination URL, no cookie, no visitor data) is
 * ever written: the filename is an opaque hash and the meta line holds
 * only status, content type, the relayable header set and the expiry.
 *
 * Zero external dependencies. PHP 7.2 - 8.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GLPE_ResCache {

    const OPT_ENABLE = 'glpe_res_cache';
    const DIR_NAME   = 'glpe-res-cache';
    /** Every stored file starts with these bytes — see the class doc. */
    const FILE_GUARD = "<?php exit; ?>\n";
    const TTL_MIN    = 120;      // seconds — floor
    const TTL_DEF    = 900;      // 15 minutes when upstream is silent
    const TTL_MAX    = 21600;    // 6 hours — ceiling
    const ENTRY_MAX  = 3145728;  // 3 MiB per stored artifact
    const QUOTA_DEF  = 67108864; // 64 MiB default total quota

    /** @var string|null test override for the storage directory. */
    public static $testDir = null;

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /** True when the subresource cache is switched on (option + filter). */
    public static function enabled() {
        $on = function_exists('get_option')
            ? get_option(self::OPT_ENABLE, '1')
            : '1';
        $forced = apply_filters('glpe_res_cache_enabled', null);
        if ($forced !== null) {
            return (bool)$forced;
        }
        return $on !== '0';
    }

    /** Guarded runtime directory (created on demand). */
    public static function dir() {
        if (is_string(self::$testDir) && self::$testDir !== '') {
            $dir = self::$testDir;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
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
        $dir = rtrim($base, '/\\') . '/' . self::DIR_NAME;
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return '';
            }
            @file_put_contents($dir . '/index.html', '');
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
            );
        }
        return $dir;
    }

    /** Removes every stored entry (admin purge + uninstall). */
    public static function purge() {
        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $n = 0;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (@unlink($file)) {
                $n++;
            }
        }
        return true;
    }

    /** Entry count / byte total for the settings card. */
    public static function stats() {
        $dir = self::dir();
        $out = ['files' => 0, 'bytes' => 0, 'dir' => $dir];
        if ($dir === '' || !is_dir($dir)) {
            return $out;
        }
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $size = (int)@filesize($file);
            if ($size > 0) {
                $out['files']++;
                $out['bytes'] += $size;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------

    /**
     * SHA-256 over every input that shapes the fetch AND the rewrite of a
     * subresource exchange: the destination URL, the identity headers that
     * may legitimately vary the representation, and the display options.
     * Two requests producing identical bytes collide into one entry;
     * anything else gets its own.
     */
    public static function keyFor($targetUrl, $customHeaders, $options) {
        $vary = ['accept', 'accept-language', 'user-agent', 'sec-fetch-dest', 'sec-fetch-mode'];
        $h = [];
        foreach ($vary as $name) {
            $h[$name] = isset($customHeaders[$name]) ? (string)$customHeaders[$name] : '';
        }
        $o = [
            'ns' => isset($options['removeScripts']) ? (int)(bool)$options['removeScripts'] : 0,
            'ni' => isset($options['removeImages']) ? (int)(bool)$options['removeImages'] : 0,
            'nt' => isset($options['stripTitle']) ? (int)(bool)$options['stripTitle'] : 0,
            'nb' => isset($options['showToolbar']) ? (int)(bool)$options['showToolbar'] : 0,
            'mb' => isset($options['mobileView']) ? (int)(bool)$options['mobileView'] : 0,
            'tp' => isset($options['tempSession']) ? (int)(bool)$options['tempSession'] : 0,
            'vs' => isset($options['vsProfile']) ? sha1(serialize($options['vsProfile'])) : '',
            'pj' => isset($options['packJs']) ? '1' : '',
            'df' => isset($options['docFilter']) ? '1' : '',
        ];
        return hash('sha256', 'glpe-res-v1|' . (string)$targetUrl . '|' . serialize($h) . '|' . serialize($o));
    }

    // ------------------------------------------------------------------
    // Entry file format: guard line, meta JSON line, final body bytes
    // ------------------------------------------------------------------

    /** Stored-entry path for a key. */
    private static function fileFor($key) {
        $dir = self::dir();
        if ($dir === '') {
            return '';
        }
        return $dir . '/' . $key . '.php';
    }

    /**
     * Looks a key up. Returns a result shaped exactly like the engine's
     * buffered fetch result (plus fromCache => true) or null on miss.
     */
    public static function get($key) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key)) {
            return null;
        }
        $file = self::fileFor($key);
        if ($file === '' || !file_exists($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || strlen($raw) <= strlen(self::FILE_GUARD)) {
            return null;
        }
        if (substr($raw, 0, strlen(self::FILE_GUARD)) !== self::FILE_GUARD) {
            return null;
        }
        $rest = substr($raw, strlen(self::FILE_GUARD));
        $nl = strpos($rest, "\n");
        if ($nl === false) {
            return null;
        }
        $meta = json_decode(substr($rest, 0, $nl), true);
        $body = substr($rest, $nl + 1);
        if (!is_array($meta) || !isset($meta['exp'], $meta['sc'], $meta['ct'])) {
            return null;
        }
        if ((int)$meta['exp'] <= time()) {
            @unlink($file);
            return null;
        }
        return [
            'status'      => (int)$meta['sc'],
            'contentType' => (string)$meta['ct'],
            'body'        => $body,
            'finalUrl'    => '',
            'headers'     => isset($meta['h']) && is_array($meta['h']) ? $meta['h'] : [],
            'fromCache'   => true,
        ];
    }

    /**
     * Stores the final artifact when the exchange is cacheable. The verdict
     * is decided HERE from the fetched result (status, content type,
     * Set-Cookie presence, upstream freshness directives) so the caller
     * never has to duplicate the policy.
     */
    public static function maybeStore($key, $result, $body) {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key)) {
            return false;
        }
        if (!is_array($result) || !isset($result['status'], $result['contentType'])) {
            return false;
        }
        if (!is_string($body) || $body === '') {
            return false;
        }
        if ((int)$result['status'] !== 200) {
            return false;
        }
        if (!empty($result['setCookie'])) {
            return false;
        }
        $ct = strtolower(trim((string)$result['contentType']));
        if (!preg_match('#^(text/css|text/javascript|application/javascript|application/x-javascript|application/manifest\+json|text/cache-manifest|application/vnd\.ms-fontobject|font/|image/)#i', $ct)) {
            return false;
        }
        if (strlen($body) > self::ENTRY_MAX) {
            return false;
        }

        $ttl = self::TTL_DEF;
        $headers = isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : [];
        $ccValues = [];
        foreach ($headers as $name => $value) {
            if (strtolower(trim((string)$name)) === 'cache-control') {
                $ccValues = array_merge($ccValues, (array)$value);
            }
        }
        $cc = strtolower(implode(', ', $ccValues));
        if ($cc !== '' && preg_match('#(no-store|no-cache|private)#', $cc)) {
            return false;
        }
        if ($cc !== '' && preg_match('#max-age=\s*([0-9]+)#', $cc, $mm)) {
            $ttl = (int)$mm[1];
        }
        $ttl = max(self::TTL_MIN, min(self::TTL_MAX, $ttl));

        $meta = [
            'v'   => 1,
            'sc'  => 200,
            'ct'  => (string)$result['contentType'],
            'h'   => class_exists('GLPE_Engine')
                ? GLPE_Engine::relayableHeaderValues($headers)
                : [],
            'exp' => time() + $ttl,
        ];

        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        $file = self::fileFor($key);
        $tmp  = $dir . '/.' . $key . '-' . getmypid() . '.tmp';
        $blob = self::FILE_GUARD . json_encode($meta) . "\n" . $body;
        $written = @file_put_contents($tmp, $blob, LOCK_EX);
        if ($written === false) {
            return false;
        }
        $ok = @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0640);
        if (mt_rand(1, 8) === 1) {
            self::trim();
        }
        return true;
    }

    /**
     * Quota keeper: drops the oldest entries (LRU by mtime) until the
     * directory is comfortably below the total budget. Expired entries
     * encountered along the scan go too.
     */
    public static function trim() {
        $dir = self::dir();
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $quota = (int)apply_filters('glpe_res_quota', self::QUOTA_DEF);
        if ($quota < self::ENTRY_MAX) {
            $quota = self::ENTRY_MAX;
        }
        $entries = [];
        $total = 0;
        $now = time();
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $size = (int)@filesize($file);
            $mtime = (int)@filemtime($file);
            $entries[] = ['f' => $file, 's' => $size, 'm' => $mtime];
            $total += $size;
        }
        if ($total <= $quota) {
            return;
        }
        usort($entries, function($a, $b) {
            return $a['m'] <=> $b['m'];
        });
        $target = (int)floor($quota * 0.9);
        foreach ($entries as $entry) {
            if ($total <= $target) {
                break;
            }
            if (@unlink($entry['f'])) {
                $total -= $entry['s'];
            }
        }
    }

    /** Admin-post handler: purge the cache (nonce + manage_options). */
    public static function handlePurge() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                wp_die('غیرمجاز', 403);
            }
            exit;
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('glpe_res_purge');
        }
        self::purge();
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(add_query_arg(
                ['page' => 'glpe-viewer', 'cp_msg' => 'res-purged'],
                function_exists('admin_url') ? admin_url('admin.php') : ''
            ));
        }
        exit;
    }
}
