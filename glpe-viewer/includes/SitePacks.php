<?php
/**
 * GLPE Site Packs — per-site adapters inside the plugin.
 *
 * Some destinations need a special solution on top of the general display
 * engine (a different player, a session nudge, a tuned handshake). Those
 * solutions live here as self-contained packs: one file per site, dropped
 * into the site-packs directory. A pack can adjust outgoing request
 * headers, inject a small client-side helper after the display companion,
 * and post-process the document markup before it reaches the visitor.
 *
 * Pack file shape (the file simply returns an array):
 *   'id'         => unique short identifier (required)
 *   'match'      => host list, exact ('example.com') or wildcard
 *                   ('*.example.com' also matches the bare host) (required)
 *   'label'      => Persian display name for the special-sites list
 *   'note'       => one-line Persian description
 *   'sample'     => absolute URL shown in the viewer's special-sites list
 *   'req_headers'=> extra outgoing request headers (client's own win)
 *   'pack_js'    => raw JavaScript injected into displayed documents
 *   'doc_filter' => callable($html, $targetUrl, $options) => html
 *
 * Zero external dependencies. Compatible with PHP 7.2 - 8.4.
 */

class GLPE_SitePacks {
    /** @var array|null scanned pack list (per-request cache) */
    private static $packs = null;

    /**
     * Loads every pack file from the site-packs directory. Malformed files
     * (missing id/match, wrong shape) are skipped silently — a broken pack
     * must never take the display engine down.
     */
    public static function all() {
        if (self::$packs !== null) {
            return self::$packs;
        }
        self::$packs = [];
        $dir = __DIR__ . '/../site-packs';
        if (!is_dir($dir)) {
            return self::$packs;
        }
        $files = glob($dir . '/*.php');
        if (!is_array($files)) {
            return self::$packs;
        }
        sort($files);
        foreach ($files as $file) {
            $pack = include $file;
            if (!is_array($pack)) {
                continue;
            }
            if (empty($pack['id']) || !is_string($pack['id'])) {
                continue;
            }
            if (empty($pack['match']) || !is_array($pack['match'])) {
                continue;
            }
            $pack['id'] = strtolower(trim((string)$pack['id']));
            self::$packs[] = $pack;
        }
        return self::$packs;
    }

    /**
     * Returns the pack whose host list covers the destination, or null.
     * The first matching pack wins (files are applied in name order).
     */
    public static function forUrl($url) {
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (self::all() as $pack) {
            foreach ($pack['match'] as $pattern) {
                if (self::hostMatches($host, $pattern)) {
                    return $pack;
                }
            }
        }
        return null;
    }

    /**
     * Wildcard host comparison: '*.example.com' covers example.com itself
     * and every subdomain; a plain pattern is an exact match.
     */
    public static function hostMatches($host, $pattern) {
        $pattern = strtolower(trim((string)$pattern));
        if ($pattern === '' || $host === '') {
            return false;
        }
        if (strpos($pattern, '*.') === 0) {
            $base = substr($pattern, 2);
            if ($base === '') {
                return false;
            }
            return ($host === $base) || (substr($host, -(strlen($base) + 1)) === '.' . $base);
        }
        return $host === $pattern;
    }

    /**
     * Packs that advertise a sample destination — shown in the viewer's
     * special-sites list so visitors can reach them in one click.
     */
    public static function samples() {
        $out = [];
        foreach (self::all() as $pack) {
            $sample = isset($pack['sample']) ? trim((string)$pack['sample']) : '';
            if ($sample === '' || !preg_match('#^https?://#i', $sample)) {
                continue;
            }
            $out[] = [
                'id'     => $pack['id'],
                'label'  => isset($pack['label']) && $pack['label'] !== '' ? $pack['label'] : $pack['id'],
                'note'   => isset($pack['note']) ? (string)$pack['note'] : '',
                'sample' => $sample,
            ];
        }
        return $out;
    }

    /**
     * Applies a pack's request-header adjustments onto the visitor's own
     * header set. Pack values only FILL GAPS — an explicit header the
     * client's application sent always wins.
     */
    public static function applyRequestHeaders(&$customHeaders, $pack) {
        if (!is_array($pack) || empty($pack['req_headers']) || !is_array($pack['req_headers'])) {
            return;
        }
        foreach ($pack['req_headers'] as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (!isset($customHeaders[$key]) && !isset($customHeaders[$name])) {
                $customHeaders[$key] = str_replace(["\r", "\n", "\0"], '', trim((string)$value));
            }
        }
    }
}
