<?php
/**
 * GLPE Relay Core — standalone fast node endpoint.
 *
 * This file is the express entry of the relay node: it answers the same
 * obfuscated `_v` wire protocol (actions "c", "w", "t" and the tuner's
 * calibration hold "z") WITHOUT booting the CMS. On a typical host the whole
 * exchange completes in tens of milliseconds instead of the 1-7 seconds a
 * full CMS request costs — the difference that makes interactive TLS
 * tunneling possible on slow hosts.
 *
 * Request flow:
 *   1. Locate the node handoff file (uploads/glpe-relay-tmp/node_info.php)
 *      written and kept in sync by the plugin. It carries the node secret,
 *      the enabled flag and the uploads root (self-exit guarded).
 *   2. Define the minimal runtime shims the shared engine reads through
 *      CMS functions (token / enabled flag / uploads dir).
 *   3. Hand the request to the shared engine (includes/Hub.php) — the exact
 *      same code the CMS-path handler runs, so the wire protocol can never
 *      drift between the two paths.
 *
 * If anything is missing (handoff file absent, node disabled, malformed
 * envelope) the endpoint answers a bare 404 and reveals nothing. The bundled
 * Python core keeps the CMS path as fallback_url, so hosts where the
 * uploads folder was relocated keep working through the slow path.
 */

// Locate the uploads root from this file's position:
// wp-content/plugins/glpe-viewer/hub-endpoint.php → wp-content/uploads
$glpeNodeDir = dirname(__DIR__, 2) . '/uploads/glpe-relay-tmp';

// Read the guarded handoff file (first line is a self-exit tag, payload is JSON).
$glpeNodeStore = null;
foreach ([$glpeNodeDir . '/node_info.php', $glpeNodeDir . '/node_info.json'] as $glpeNodeFile) {
    if (is_readable($glpeNodeFile)) {
        $glpeRaw = (string)file_get_contents($glpeNodeFile);
        $glpeRaw = preg_replace('/^<\?php[^;]*;[^\n]*\n?/', '', $glpeRaw, 1);
        $glpeNodeStore = json_decode((string)$glpeRaw, true);
        if (is_array($glpeNodeStore)) {
            break;
        }
        $glpeNodeStore = null;
    }
}
if (!is_array($glpeNodeStore) || empty($glpeNodeStore['enabled'])) {
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    exit;
}

// Minimal runtime shims — only defined when the CMS is NOT already running
// (a direct hit), so including this file from WP context stays harmless.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        global $glpeNodeStore;
        if ($name === 'glpe_hub_token') {
            return isset($glpeNodeStore['token']) && is_string($glpeNodeStore['token']) && $glpeNodeStore['token'] !== ''
                ? $glpeNodeStore['token'] : $default;
        }
        if ($name === 'glpe_hub_enabled') {
            return !empty($glpeNodeStore['enabled']) ? '1' : '0';
        }
        if ($name === 'glpe_hub_max_streams') {
            // Channel cap (host guard) — carried by the handoff so the
            // standalone endpoint enforces exactly what the admin set.
            $cap = isset($glpeNodeStore['max_streams']) ? (int)$glpeNodeStore['max_streams'] : 0;
            if ($cap < GLPE_Hub::STREAM_CAP_MIN) { $cap = GLPE_Hub::STREAM_CAP_MIN; }
            if ($cap > GLPE_Hub::STREAM_CAP_MAX) { $cap = GLPE_Hub::STREAM_CAP_MAX; }
            return (string)$cap;
        }
        return $default;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        global $glpeNodeStore;
        $basedir = isset($glpeNodeStore['dir']) && is_string($glpeNodeStore['dir']) && $glpeNodeStore['dir'] !== ''
            ? $glpeNodeStore['dir']
            : dirname(__DIR__, 2) . '/uploads';
        return ['basedir' => $basedir, 'error' => false];
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) {
        return rtrim((string)$value, '/\\');
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value) {
        return false; // the fast endpoint never writes CMS options
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length, $special = false, $extra = false) {
        return ''; // token generation belongs to the CMS context only
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key));
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) {
        $json = json_encode($data, $flags);
        return $json === false ? '' : (string)$json;
    }
}

// The shared engine refuses to run without the CMS context marker; this
// endpoint IS the context, so it declares it itself.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// A partially extracted update must never fatal here — fail closed instead.
if (!file_exists(__DIR__ . '/includes/Hub.php')) {
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    exit;
}
require __DIR__ . '/includes/Hub.php';

if (!isset($_GET['_v']) || (string)$_GET['_v'] === '') {
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    exit;
}

GLPE_Hub::handleRequest((string)$_GET['_v']);
exit;
