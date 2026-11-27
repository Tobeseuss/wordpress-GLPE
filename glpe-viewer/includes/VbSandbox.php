<?php
/**
 * GLPE Virtual Browser — sandbox engine (v7.1.0).
 *
 * A SECOND engine for the virtual browser, designed for hosts that offer
 * NOTHING beyond plain PHP — free hosting panels with no shell, no process
 * spawning, no runtime and no renderer binary. The companion service of the
 * v7.0.0 service engine cannot exist on such hosts; this engine can.
 *
 * Arrangement (all pieces run through THIS HOST, on any PHP-only host):
 *   - every byte of the destination is still fetched, decoded and re-served
 *     by the host through the encrypted gateway — the destination only ever
 *     sees the hosting server's address;
 *   - the destination renders inside a locked, temporary sandboxed frame
 *     with its own throwaway cookie jar (wiped when the session closes);
 *   - the visitor steers a "virtual monitor" like a remote desktop: mouse,
 *     wheel and keyboard gestures are captured, wrapped into opaque tokens,
 *     sent to THIS HOST first, normalized/journaled there, and only the
 *     host's echo moves the virtual cursor and replays the gesture inside
 *     the sandbox;
 *   - sessions live in transients with a TTL and a concurrency cap, so the
 *     temporary environments reap themselves.
 *
 * Zero external dependencies. PHP 7.2 - 8.4.
 */

class GLPE_Vb_Sandbox {

    const OPT_ENGINE = 'glpe_vb_engine';          // auto | service | sandbox
    const OPT_INDEX = 'glpe_vb_vbs_index';        // [token => expiry]
    const PREFIX = 'glpe_vbs_';                   // transient keys
    const JAR_SUFFIX = '_vbs';                     // cookie-jar session key
    const TTL = 1800;                              // 30 minutes per session
    const MAX_SESSIONS = 6;
    const EV_CAP = 40;                             // events per round trip
    const TEXT_CAP = 2000;

    /** @var array|null test store: [token => ['v' => value, 'exp' => ts]] */
    public static $storeShim = null;

    // ------------------------------------------------------------------
    // Engine selection
    // ------------------------------------------------------------------

    /**
     * Which virtual-browser engine serves this site right now.
     * 'service'  — the v7.0.0 host-side renderer (Bun + Chromium, full
     *              server-side painting, picture-only channel);
     * 'sandbox'  — this engine (pure PHP, runs on free hosting panels).
     * The admin may force either; 'auto' picks the service engine when its
     * companion answers, otherwise falls back to the sandbox engine.
     *
     * @param bool|null $running test injection for the service health flag
     */
    public static function engineChoice($running = null) {
        $opt = strtolower(trim((string)get_option(self::OPT_ENGINE, 'auto')));
        if ($opt === 'service' || $opt === 'sandbox') {
            return $opt;
        }
        if ($running === null) {
            $running = class_exists('GLPE_VirtualBrowser') && GLPE_VirtualBrowser::ensureService();
        }
        return $running ? 'service' : 'sandbox';
    }

    // ------------------------------------------------------------------
    // Identity profile (host-consistent environment)
    // ------------------------------------------------------------------

    /**
     * The single fingerprint every sandbox exchange carries, on the wire
     * AND inside the sandboxed page's JavaScript: upstream requests are
     * fetched with this user agent and locale, and the companion spoofs
     * navigator/Intl/screen to the very same values, so the destination
     * sees one consistent identity — never the visitor's own signals.
     */
    public static function profile() {
        $defaults = [
            'locale' => 'en-US',
            'lang'   => 'en',
            'tz'     => 'UTC',
            'ua'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                     . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'plat'   => 'Win32',
            'hc'     => 8,
            'dm'     => 8,
            'scrw'   => 1280,
            'scrh'   => 800,
        ];
        $profile = apply_filters('glpe_vb_profile', $defaults);
        if (!is_array($profile)) {
            $profile = $defaults;
        }
        foreach ($defaults as $k => $v) {
            if (!isset($profile[$k]) || !is_scalar($profile[$k])) {
                $profile[$k] = $v;
            }
        }
        return $profile;
    }

    /**
     * Applies the profile to the outgoing request headers: a canonical
     * user agent, the profile locale, and none of the visitor's own
     * client-hint/location signals.
     */
    public static function applyRequestProfile(array &$headers) {
        $p = self::profile();
        foreach ($headers as $name => $value) {
            $low = strtolower((string)$name);
            if (strpos($low, 'sec-ch-') === 0 || $low === 'accept-language') {
                unset($headers[$name]);
            }
        }
        $headers['user-agent'] = $p['ua'];
        $headers['accept-language'] = $p['locale'] . ',' . $p['lang'] . ';q=0.9';
    }

    // ------------------------------------------------------------------
    // Gateway URLs for sandbox destinations
    // ------------------------------------------------------------------

    /** Gateway URL that loads a destination inside the sandboxed frame. */
    public static function gatewayUrl($url) {
        return add_query_arg(
            ['_glpe' => '1', 'l' => GLPE_Codec::encode($url), 'vs' => '1'],
            home_url('/')
        );
    }

    // ------------------------------------------------------------------
    // Session store (transients + a small index for the cap)
    // ------------------------------------------------------------------

    private static function storeGet($token) {
        $token = (string)$token;
        if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) {
            return false;
        }
        if (is_array(self::$storeShim)) {
            return isset(self::$storeShim[$token]) ? self::$storeShim[$token]['v'] : false;
        }
        return get_transient(self::PREFIX . $token);
    }

    private static function storeSet($token, $value, $ttl) {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }
        if (is_array(self::$storeShim)) {
            self::$storeShim[$token] = ['v' => $value, 'exp' => time() + $ttl];
            return true;
        }
        return set_transient(self::PREFIX . $token, $value, $ttl);
    }

    private static function storeDelete($token) {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }
        if (is_array(self::$storeShim)) {
            unset(self::$storeShim[$token]);
            return true;
        }
        return delete_transient(self::PREFIX . $token);
    }

    private static function index() {
        $idx = get_option(self::OPT_INDEX);
        return is_array($idx) ? $idx : [];
    }

    private static function indexPrune() {
        $idx = self::index();
        $now = time();
        $clean = [];
        foreach ($idx as $t => $exp) {
            if (is_numeric($exp) && (int)$exp > $now) {
                $clean[(string)$t] = (int)$exp;
            } else {
                self::storeDelete($t);
            }
        }
        update_option(self::OPT_INDEX, $clean);
        return $clean;
    }

    private static function indexRemove($token) {
        $idx = self::index();
        unset($idx[(string)$token]);
        update_option(self::OPT_INDEX, $idx);
    }

    // ------------------------------------------------------------------
    // Session lifecycle
    // ------------------------------------------------------------------

    /**
     * Creates a temporary sandbox session for a destination.
     * Returns ['t' => token, 'g' => gateway URL, 'ttl' => seconds] or
     * ['e' => 'busy'] when the concurrency cap is reached.
     */
    public static function create($url, $w = 1024, $h = 768) {
        $url = trim((string)$url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return ['e' => 'badurl'];
        }
        $w = max(320, min(1920, (int)$w));
        $h = max(240, min(1200, (int)$h));

        $cap = (int)apply_filters('glpe_vb_vbs_cap', self::MAX_SESSIONS);
        $live = self::indexPrune();
        if (count($live) >= max(1, $cap)) {
            return ['e' => 'busy'];
        }

        $token = wp_generate_password(32, false, false);
        $exp = time() + self::TTL;
        $ok = self::storeSet($token, [
            'u'      => $url,
            'w'      => $w,
            'h'      => $h,
            'created' => time(),
            'exp'    => $exp,
            'ev'     => 0,
        ], self::TTL);
        if (!$ok) {
            return ['e' => 'store'];
        }

        $idx = self::index();
        $idx[$token] = $exp;
        update_option(self::OPT_INDEX, $idx);

        // A fresh environment starts with a clean jar — anything a previous
        // sandbox session stored in this PHP session is thrown away now.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_glpe_cookies' . self::JAR_SUFFIX] = [];
        }

        return ['t' => $token, 'g' => self::gatewayUrl($url), 'ttl' => self::TTL];
    }

    /** Reads a live session record (expired/unknown → false). */
    public static function session($token) {
        $s = self::storeGet($token);
        if (!is_array($s) || !isset($s['exp']) || (int)$s['exp'] <= time()) {
            return false;
        }
        return $s;
    }

    // ------------------------------------------------------------------
    // Event normalization — the host genuinely processes every gesture
    // ------------------------------------------------------------------

    /**
     * Whitelists, clamps and caps the visitor's gesture batch. Unknown
     * event shapes and unknown fields never survive the round trip; only
     * the canonical form below is echoed back to drive the sandbox.
     */
    public static function normalizeEvents($events, $w = 1920, $h = 1200) {
        if (!is_array($events)) {
            return [];
        }
        $out = [];
        foreach ($events as $ev) {
            if (!is_array($ev) || count($out) >= self::EV_CAP) {
                continue;
            }
            $type = isset($ev['t']) ? (string)$ev['t'] : '';
            switch ($type) {
                case 'mm':
                    $out[] = ['t' => 'mm',
                        'x' => self::clamp((int)round((float)$ev['x']), 0, max(0, (int)$w - 1)),
                        'y' => self::clamp((int)round((float)$ev['y']), 0, max(0, (int)$h - 1))];
                    break;
                case 'dn':
                case 'up':
                    $button = (isset($ev['b']) && in_array($ev['b'], ['left', 'right', 'middle'], true)) ? $ev['b'] : 'left';
                    $out[] = ['t' => $type,
                        'x' => self::clamp((int)round((float)$ev['x']), 0, max(0, (int)$w - 1)),
                        'y' => self::clamp((int)round((float)$ev['y']), 0, max(0, (int)$h - 1)),
                        'b' => $button];
                    break;
                case 'wh':
                    $out[] = ['t' => 'wh',
                        'x' => self::clamp((int)round((float)$ev['x']), 0, max(0, (int)$w - 1)),
                        'y' => self::clamp((int)round((float)$ev['y']), 0, max(0, (int)$h - 1)),
                        'dx' => self::clamp((int)round((float)$ev['dx']), -5000, 5000),
                        'dy' => self::clamp((int)round((float)$ev['dy']), -5000, 5000)];
                    break;
                case 'kd':
                case 'ku':
                    $out[] = ['t' => $type,
                        'key'  => substr((string)(isset($ev['key']) ? $ev['key'] : ''), 0, 32),
                        'code' => substr((string)(isset($ev['code']) ? $ev['code'] : ''), 0, 32),
                        'vk'   => self::clamp((int)$ev['vk'], 0, 255),
                        'mods' => self::clamp((int)$ev['mods'], 0, 15)];
                    break;
                case 'tx':
                    $out[] = ['t' => 'tx', 'v' => substr((string)(isset($ev['v']) ? $ev['v'] : ''), 0, self::TEXT_CAP)];
                    break;
            }
        }
        return $out;
    }

    private static function clamp($v, $min, $max) {
        $v = (int)$v;
        return max($min, min($max, $v));
    }

    // ------------------------------------------------------------------
    // Dispatcher endpoints (?_glpe=1&mode=vb_*)
    // ------------------------------------------------------------------

    /**
     * Handles a sandbox endpoint request. The whole body rides ONE opaque
     * token ('p') decoded with the per-site codec; the reply itself wraps
     * its payload into an opaque token again — no readable gesture, typed
     * text or destination ever travels in the clear.
     */
    public static function handleMode($mode) {
        $raw = isset($_POST['p']) ? trim((string)wp_unslash($_POST['p'])) : '';
        $doc = [];
        if ($raw !== '') {
            $json = GLPE_Codec::decodeData($raw);
            $decoded = $json !== '' ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $doc = $decoded;
            }
        }

        switch ($mode) {
            case 'vb_open':
                $u = isset($doc['u']) ? (string)$doc['u'] : '';
                $w = isset($doc['w']) ? (int)$doc['w'] : 1024;
                $h = isset($doc['h']) ? (int)$doc['h'] : 768;
                self::respond(self::create($u, $w, $h));
                break;

            case 'vb_in':
                $token = isset($doc['t']) ? (string)$doc['t'] : '';
                $s = self::session($token);
                if (!$s) {
                    self::respond(['e' => 'expired']);
                    break;
                }
                $events = self::normalizeEvents(
                    isset($doc['ev']) ? $doc['ev'] : [],
                    $s['w'],
                    $s['h']
                );
                $s['ev'] = (int)$s['ev'] + count($events);
                // The store write is throttled: a lively session renews its
                // TTL the moment the remaining life drops below half, so a
                // mouse stream never pays a database write per round trip.
                if ((int)$s['exp'] - time() < (int)(self::TTL / 2)) {
                    $s['exp'] = time() + self::TTL;
                    self::storeSet($token, $s, self::TTL);
                    $idx = self::index();
                    $idx[$token] = $s['exp'];
                    update_option(self::OPT_INDEX, $idx);
                }
                self::respond(['ev' => $events, 'now' => time(), 'ct' => (int)$s['ev']]);
                break;

            case 'vb_nav':
                $token = isset($doc['t']) ? (string)$doc['t'] : '';
                $u = isset($doc['u']) ? trim((string)$doc['u']) : '';
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    $u = 'https://' . $u;
                }
                $s = self::session($token);
                if (!$s) {
                    self::respond(['e' => 'expired']);
                    break;
                }
                if (!preg_match('#^https?://#i', $u)) {
                    self::respond(['e' => 'badurl']);
                    break;
                }
                $s['u'] = $u;
                $s['exp'] = time() + self::TTL;
                self::storeSet($token, $s, self::TTL);
                $idx = self::index();
                $idx[$token] = $s['exp'];
                update_option(self::OPT_INDEX, $idx);
                self::respond(['g' => self::gatewayUrl($u)]);
                break;

            case 'vb_beat':
                $token = isset($doc['t']) ? (string)$doc['t'] : '';
                $s = self::session($token);
                if (!$s) {
                    self::respond(['e' => 'expired']);
                    break;
                }
                $s['exp'] = time() + self::TTL;
                self::storeSet($token, $s, self::TTL);
                $idx = self::index();
                $idx[$token] = $s['exp'];
                update_option(self::OPT_INDEX, $idx);
                self::respond(['ttl' => self::TTL]);
                break;

            case 'vb_close':
                $token = isset($doc['t']) ? (string)$doc['t'] : '';
                if ($token !== '') {
                    self::storeDelete($token);
                    self::indexRemove($token);
                    // The temporary environment's records go with it.
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION['_glpe_cookies' . self::JAR_SUFFIX] = [];
                    }
                }
                self::respond(['ok' => 1]);
                break;
        }
    }

    /** Encoded JSON reply (inner payload wrapped into an opaque token). */
    private static function respond($payload) {
        wp_send_json(['ok' => 1, 'd' => GLPE_Codec::encode(wp_json_encode($payload))]);
    }

    /** Config the visitor-side sandbox surface script boots from. */
    public static function clientConfig() {
        return [
            'ep'  => add_query_arg(['_glpe' => '1'], home_url('/')),
            'k'   => GLPE_Codec::secret(),
            'ttl' => self::TTL,
            'cap' => (int)apply_filters('glpe_vb_vbs_cap', self::MAX_SESSIONS),
            'v'   => defined('GLPE_VERSION') ? GLPE_VERSION : '',
        ];
    }
}
