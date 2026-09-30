<?php
/**
 * GLPE Session Data Store — RFC 6265 Compliant
 * Manages per-session key/value records, subdomains, expiration and JS sync.
 */

class GLPE_Cookies {
    private $sessionKey = '_glpe_cookies';
    private $isTempMode = false;

    public function __construct($isTempMode = false) {
        $this->isTempMode = $isTempMode;
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (!isset($_SESSION[$this->sessionKey]) || !is_array($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = [];
        }
    }

    public function setTempMode($temp) {
        $this->isTempMode = (bool)$temp;
    }

    /**
     * Stores a raw Set-Cookie header string for a given source URL.
     */
    public function addCookieFromHeader($setCookieStr, $currentUrl) {
        $parsedUrl = parse_url($currentUrl);
        $defaultHost = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
        $defaultPath = isset($parsedUrl['path']) ? dirname($parsedUrl['path']) : '/';
        if ($defaultPath === '' || $defaultPath === '.') $defaultPath = '/';

        $parts = explode(';', $setCookieStr);
        $firstPart = trim(array_shift($parts));
        if (strpos($firstPart, '=') === false) return;

        list($name, $value) = explode('=', $firstPart, 2);
        $name = trim($name);
        $value = trim($value);

        $domain = $defaultHost;
        $path = $defaultPath;
        $expires = null;
        $secure = false;
        $httpOnly = false;
        $sameSite = 'Lax';

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            if (strpos($part, '=') !== false) {
                list($attrName, $attrVal) = explode('=', $part, 2);
                $attrName = strtolower(trim($attrName));
                $attrVal = trim($attrVal);

                if ($attrName === 'domain') {
                    $domain = ltrim(strtolower($attrVal), '.');
                } elseif ($attrName === 'path') {
                    $path = $attrVal;
                } elseif ($attrName === 'expires') {
                    $expires = strtotime($attrVal);
                } elseif ($attrName === 'max-age') {
                    // RFC 6265 5.2.2: max-age <= 0 discards the record immediately.
                    $maxAge = intval($attrVal);
                    $expires = ($maxAge <= 0) ? (time() - 1) : (time() + $maxAge);
                } elseif ($attrName === 'samesite') {
                    $sameSite = $attrVal;
                }
            } else {
                $attrName = strtolower($part);
                if ($attrName === 'secure') {
                    $secure = true;
                } elseif ($attrName === 'httponly') {
                    $httpOnly = true;
                }
            }
        }

        // Temp mode keeps everything in-session only (no persistent expiry).
        if ($this->isTempMode) {
            $expires = null;
        }

        if (!isset($_SESSION[$this->sessionKey][$domain])) {
            $_SESSION[$this->sessionKey][$domain] = [];
        }

        // Handle record deletion (empty value or expiry in the past)
        if ($value === '' || ($expires !== null && $expires <= time())) {
            unset($_SESSION[$this->sessionKey][$domain][$name]);
            return;
        }

        $_SESSION[$this->sessionKey][$domain][$name] = [
            'key' => $name,
            'value' => $value,
            'domain' => $domain,
            'path' => $path,
            'expires' => $expires,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
            'created' => time(),
        ];
    }

    /**
     * Builds the matching header string for a given source URL.
     */
    public function getCookieHeader($targetUrl) {
        $parsed = parse_url($targetUrl);
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';

        $cookies = [];
        $now = time();

        if (isset($_SESSION[$this->sessionKey]) && is_array($_SESSION[$this->sessionKey])) {
            foreach ($_SESSION[$this->sessionKey] as $domain => $domainCookies) {
                if ($host === $domain || (strlen($host) > strlen($domain) && substr($host, -(strlen($domain) + 1)) === '.' . $domain)) {
                    foreach ($domainCookies as $name => $c) {
                        if ($c['expires'] !== null && $c['expires'] <= $now) {
                            unset($_SESSION[$this->sessionKey][$domain][$name]);
                            continue;
                        }
                        if ($this->isPathMatch($c['path'], $path)) {
                            $cookies[] = $name . '=' . $c['value'];
                        }
                    }
                }
            }
        }

        return implode('; ', $cookies);
    }

    /**
     * RFC 6265 5.1.4 path-match: the request path must start with the record path
     * and the boundary must be a segment separator ('/'), never a mid-word prefix.
     */
    private function isPathMatch($cookiePath, $requestPath) {
        if ($cookiePath === '/') return true;
        if ($cookiePath === $requestPath) return true;
        if (strpos($requestPath, $cookiePath) === 0) {
            $len = strlen($cookiePath);
            if (substr($cookiePath, -1) === '/') return true;
            if (isset($requestPath[$len]) && $requestPath[$len] === '/') return true;
        }
        return false;
    }

    public function getAllCookies() {
        $result = [];
        if (isset($_SESSION[$this->sessionKey]) && is_array($_SESSION[$this->sessionKey])) {
            foreach ($_SESSION[$this->sessionKey] as $domain => $domainCookies) {
                foreach ($domainCookies as $c) {
                    $result[] = $c;
                }
            }
        }
        return $result;
    }

    public function clearAll() {
        $_SESSION[$this->sessionKey] = [];
    }
}
