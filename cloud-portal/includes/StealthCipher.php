<?php
/**
 * Stealth URL Cipher
 * Reversible URL obfuscation using URL-Safe Base64 and XOR salt
 * to prevent destination URLs from appearing in browser history, server logs, or ISP filters.
 */

class StealthCipher {
    private static $defaultKey = 'cp_vault_key';

    public static function encode($url, $key = null) {
        if (empty($url)) return '';
        $k = $key ? $key : self::$defaultKey;
        $len = strlen($url);
        $klen = strlen($k);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($url[$i]) ^ ord($k[$i % $klen]));
        }
        $b64 = base64_encode($out);
        return str_replace(['+', '/', '='], ['-', '_', ''], $b64);
    }

    public static function decode($encoded, $key = null) {
        if (empty($encoded)) return '';
        // If already a plain URL, return directly
        if (preg_match('#^https?://#i', $encoded)) {
            return $encoded;
        }

        $k = $key ? $key : self::$defaultKey;
        $b64 = str_replace(['-', '_'], ['+', '/'], $encoded);
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return $encoded;
        }

        $len = strlen($raw);
        $klen = strlen($k);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($raw[$i]) ^ ord($k[$i % $klen]));
        }

        if (preg_match('#^https?://#i', $out)) {
            return $out;
        }

        // Check if raw base64 without XOR was passed
        $plain = base64_decode($b64, true);
        if ($plain && preg_match('#^https?://#i', $plain)) {
            return $plain;
        }

        return $out;
    }
}
