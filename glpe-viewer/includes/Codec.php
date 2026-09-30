<?php
/**
 * GLPE Reversible Link Codec
 * Short-link generation using URL-Safe Base64 combined with a per-site secret.
 * Keeps target addresses compact and site-specific; each installation owns its key.
 */

class GLPE_Codec {
    /**
     * Per-site secret (set by the plugin bootstrap from the options table).
     */
    public static function secret() {
        return (defined('GLPE_SECRET') && GLPE_SECRET !== '') ? GLPE_SECRET : 'glpe-local-key';
    }

    public static function encode($url, $key = null) {
        if (empty($url)) return '';
        $k = $key ? $key : self::secret();
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
        // Already a plain link — pass through
        if (preg_match('#^https?://#i', $encoded)) {
            return $encoded;
        }

        $k = $key ? $key : self::secret();
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

        // Plain base64 (no codec) fallback
        $plain = base64_decode($b64, true);
        if ($plain && preg_match('#^https?://#i', $plain)) {
            return $plain;
        }

        return $out;
    }
}
