<?php
/**
 * Stealth Vault Cookie Jar - RFC 6265 Compliant
 * Manages domain cookies, subdomains, expiration, and JS sync without proxy footprints.
 */

class StealthCookieJar {
    private $sessionKey = '_c_vault_store';
    private $isTempMode = false;

    public function __construct($isTempMode = false) {
        $this->isTempMode = $isTempMode;
        if (session_status() === PHP_SESSION_NONE) {
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
     * Stores a Set-Cookie header string for a given target URL.
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
                    $expires = time() + intval($attrVal);
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

        // If tempCookies is enabled, treat all cookies as session cookies (no persistent expiration)
        if ($this->isTempMode) {
            $expires = null;
        }

        if (!isset($_SESSION[$this->sessionKey][$domain])) {
            $_SESSION[$this->sessionKey][$domain] = [];
        }

        // Handle cookie deletion (value empty or expires in past)
        if ($value === '' || ($expires !== null && $expires < time())) {
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
     * Builds Cookie header string for request matching target URL.
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
                        if ($c['expires'] !== null && $c['expires'] < $now) {
                            unset($_SESSION[$this->sessionKey][$domain][$name]);
                            continue;
                        }
                        if (strpos($path, $c['path']) === 0 || $c['path'] === '/') {
                            $cookies[] = $name . '=' . $c['value'];
                        }
                    }
                }
            }
        }

        return implode('; ', $cookies);
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
