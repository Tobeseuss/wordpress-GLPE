<?php
/**
 * GLPE Reversible Link Codec
 * Wraps destination references and request payloads into opaque, per-site
 * tokens (XOR over URL-Safe Base64 combined with a per-site secret), so no
 * readable address ever travels between the visitor's browser and this site.
 * Each installation owns its key.
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

    /**
     * Strict decode for opaque request payloads (never legacy-tolerant):
     * returns the original string, or '' when the token is not decodable.
     */
    public static function decodeData($encoded, $key = null) {
        if (!is_string($encoded) || $encoded === '') return '';
        $k = $key ? $key : self::secret();
        $b64 = str_replace(['-', '_'], ['+', '/'], $encoded);
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            return '';
        }

        $len = strlen($raw);
        $klen = strlen($k);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($raw[$i]) ^ ord($k[$i % $klen]));
        }
        return $out;
    }

    /**
     * Encoded request-body envelope. Wrapped submissions carry a single
     * field — d=<token> — whose decoded form is a JSON document holding the
     * original content type and the original body. Returns
     * ['ct' => string, 'body' => string] or null when the body is not an
     * envelope (native multipart uploads and non-wrapped bodies pass as-is).
     */
    public static function unwrapRequestBody($rawBody) {
        if (!is_string($rawBody) || $rawBody === '') {
            return null;
        }
        if (!preg_match('/^d=([A-Za-z0-9_-]+)$/', $rawBody, $m)) {
            return null;
        }
        $json = self::decodeData($m[1]);
        if ($json === '') {
            return null;
        }
        $doc = json_decode($json, true);
        if (!is_array($doc) || !isset($doc['b']) || !is_string($doc['b'])) {
            return null;
        }
        $ct = isset($doc['c']) && is_string($doc['c']) && $doc['c'] !== ''
            ? $doc['c']
            : 'application/x-www-form-urlencoded';
        // Only genuine form/JSON/plaintext media types are accepted here —
        // binary envelopes (multipart) are never wrapped in the first place.
        if (stripos($ct, 'multipart/') === 0) {
            return null;
        }
        return ['ct' => $ct, 'body' => $doc['b']];
    }
}
