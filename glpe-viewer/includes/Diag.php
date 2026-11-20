<?php
/**
 * GLPE diagnostics — install integrity + guarded fatal-error journal.
 *
 * Free hosts under load can corrupt a plugin update (truncated extraction)
 * or exhaust the PHP memory limit on heavy pages; the visitor then sees the
 * scary CMS fatal page with no hint about the cause. This class gives the
 * plugin two safety nets that depend on NOTHING else:
 *
 *   1. INTEGRITY MANIFEST — the exact set of files the bootstrap needs. When
 *      one is missing the plugin registers no hooks at all (the site keeps
 *      running) and the admin gets a precise reinstall notice instead of a
 *      white screen.
 *   2. FATAL JOURNAL — the shutdown catcher records every fatal that happens
 *      inside this plugin's files (type, message, file, line) into a guarded
 *      store file, so the admin can read — and paste — the real error text
 *      instead of "a critical error occurred".
 *
 * Self-contained by design: no CMS functions beyond wp_upload_dir(), so the
 * catcher still works when the rest of the plugin is the thing that broke.
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Diag {

    const GUARD     = "<?php exit; ?>\n";
    const LOG_FILE  = 'fatal_log.php';
    const LOG_LIMIT = 12; // rolling window

    /** @var string|null test override for the store directory */
    public static $testDir = null;

    /** Files the bootstrap and runtime strictly require to function. */
    public static function requiredManifest() {
        return [
            'glpe-viewer.php',
            'hub-endpoint.php',
            'uninstall.php',
            'includes/Codec.php',
            'includes/Cookies.php',
            'includes/Access.php',
            'includes/Engine.php',
            'includes/Hub.php',
            'includes/HubTune.php',
            'includes/Dns.php',
            'includes/client.js',
        ];
    }

    /** Optional payload files — missing ones degrade features, never the site. */
    public static function optionalManifest() {
        return [
            'hub-client/client.py',
            'hub-client/geoip_ir.txt',
            'hub-client/geosite_ir.txt',
            'diag-endpoint.php',
            'includes/DiagApi.php',
        ];
    }

    /**
     * Missing required files (relative paths). Empty = intact install.
     * @param string|null $baseDir override for tests
     */
    public static function missingRequired($baseDir = null) {
        $base = $baseDir !== null ? (string)$baseDir : GLPE_DIR;
        $missing = [];
        foreach (self::requiredManifest() as $rel) {
            if (!file_exists(rtrim($base, '/') . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        return $missing;
    }

    /** Missing optional payload files (relative paths). */
    public static function missingOptional($baseDir = null) {
        $base = $baseDir !== null ? (string)$baseDir : GLPE_DIR;
        $missing = [];
        foreach (self::optionalManifest() as $rel) {
            if (!file_exists(rtrim($base, '/') . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        return $missing;
    }

    /* ------------------------------------------------------------------
     * Guarded fatal journal
     * ---------------------------------------------------------------- */

    public static function storeDir() {
        if (self::$testDir !== null) {
            return self::$testDir;
        }
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            $base = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
            if ($base !== '') {
                $dir = rtrim($base, '/\\') . '/glpe-viewer-tmp';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                    @file_put_contents($dir . '/index.html', '');
                }
                return $dir;
            }
        }
        return sys_get_temp_dir() . '/glpe-viewer-tmp';
    }

    private static function logFile() {
        return self::storeDir() . '/' . self::LOG_FILE;
    }

    /** Appends one fatal record; never throws, never depends on the plugin. */
    public static function appendFatal($type, $message, $file, $line) {
        $entry = [
            'at'  => time(),
            'type'   => (int)$type,
            'message' => (string)$message,
            'file'    => (string)$file,
            'line'    => (int)$line,
            'uri'     => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
        ];
        $list = self::lastFatals();
        $list[] = $entry;
        $list = array_slice($list, -self::LOG_LIMIT);
        @file_put_contents(self::logFile(), self::GUARD . (string)json_encode($list), LOCK_EX);
        return true;
    }

    /** @return array last recorded fatals (oldest first, capped) */
    public static function lastFatals() {
        $file = self::logFile();
        if (!file_exists($file)) {
            return [];
        }
        $raw  = (string)@file_get_contents($file);
        $data = json_decode((string)substr($raw, strlen(self::GUARD)), true);
        return is_array($data) ? $data : [];
    }

    public static function clearFatals() {
        $file = self::logFile();
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /** Human label for a PHP fatal type constant. */
    public static function fatalTypeName($type) {
        $map = [
            E_ERROR             => 'E_ERROR',
            E_PARSE             => 'E_PARSE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_USER_ERROR        => 'E_USER_ERROR',
        ];
        return isset($map[(int)$type]) ? $map[(int)$type] : ('E_' . (int)$type);
    }

    /** True when the error type terminates the script (the catcher's filter). */
    public static function isFatalType($type) {
        return in_array((int)$type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    }

    /** Wipe store (uninstall). */
    public static function wipeStore() {
        $file = self::logFile();
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
