<?php
/**
 * Plugin Name: GLPE Viewer — Remote Page Display
 * Plugin URI: https://github.com/Tobeseuss/wordpress-GLPE
 * Description: نمایش سریع و امن صفحات وب دلخواه داخل سایت شما با قابلیت بازنویسی خودکار پیوندها، سبک بارگذاری کم‌مصرف و نوار ناوبری شناور. مناسب هاست‌های اشتراکی و رایگان.
 * Version: 4.10.0
 * Author: Tobeseuss
 * License: MIT
 * Text Domain: glpe-viewer
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('GLPE_VERSION', '4.10.0');
define('GLPE_DIR', plugin_dir_path(__FILE__));
define('GLPE_URL', plugin_dir_url(__FILE__));

/**
 * Per-site secret for the reversible link codec.
 * Generated once and stored in the options table; each installation has its own key.
 */
$glpe_secret = get_option('glpe_secret');
if (!is_string($glpe_secret) || strlen($glpe_secret) < 16) {
    $glpe_secret = wp_generate_password(32, false, false);
    update_option('glpe_secret', $glpe_secret);
}
define('GLPE_SECRET', $glpe_secret);

require_once GLPE_DIR . 'includes/Cookies.php';
require_once GLPE_DIR . 'includes/Codec.php';
require_once GLPE_DIR . 'includes/Engine.php';
require_once GLPE_DIR . 'includes/Access.php';

class GLPE_Plugin {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // PHP session is opened very early, before any output can begin.
        add_action('init', [$this, 'startSession'], 0);

        // Front-end request interception (priority 1 = before theme output).
        add_action('init', [$this, 'dispatchRequest'], 1);

        // Shortcode
        add_shortcode('glpe_viewer', [$this, 'renderShortcode']);

        // Admin
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'maybeUpgrade']);

        // Access management form handlers (admin-post.php)
        add_action('admin_post_glpe_save_roles', [$this, 'handleSaveRoles']);
        add_action('admin_post_glpe_toggle_user', [$this, 'handleToggleUser']);
        add_action('admin_post_glpe_save_jar', [$this, 'handleSaveJar']);

        // Per-account session isolation: a browser's jar belongs to exactly
        // one account — logging out drops it, logging in restores the
        // account's own saved jar (never another user's).
        add_action('wp_login', [$this, 'onLogin'], 10, 1);
        add_action('wp_logout', [$this, 'onLogout']);
    }

    public function startSession() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent() && PHP_SAPI !== 'cli') {
            @session_start();
        }
    }

    private function viewerPageUrl() {
        $slug = trim((string)get_option('glpe_slug', 'view'), '/');
        return home_url('/' . ($slug !== '' ? $slug : 'view') . '/');
    }

    /**
     * Creates a dedicated page on activation if it doesn't already exist.
     */
    public function activate() {
        // Administrators always keep the viewer permission; grant it up front.
        GLPE_Access::grantToAdministrator();
        update_option('glpe_db_version', GLPE_VERSION);

        $pageSlug = trim((string)get_option('glpe_slug', 'view'), '/');
        if ($pageSlug === '') {
            $pageSlug = 'view';
        }
        $existingPage = get_page_by_path($pageSlug);
        if (!$existingPage) {
            $pageId = wp_insert_post([
                'post_title'     => 'نمایشگر صفحات وب',
                'post_name'      => $pageSlug,
                'post_content'   => '[glpe_viewer]',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
            ]);
            if ($pageId && !is_wp_error($pageId)) {
                update_option('glpe_page_id', $pageId);
            }
        }
    }

    public function deactivate() {
        // Safe deactivation
    }

    /**
     * Per-version upgrade routine (runs on admin_init; cheap no-op afterwards).
     * Ensures the viewer permission exists on the administrator role, even on
     * installations that activated an older plugin version.
     */
    public function maybeUpgrade() {
        GLPE_Access::maybeUpgrade(GLPE_VERSION);
    }

    /**
     * Intercepts front-end display requests (?_glpe=1&l=...) before template rendering.
     */
    public function dispatchRequest() {
        $isView = isset($_GET['_glpe']) || isset($_POST['_glpe']);
        if (!$isView) {
            return;
        }

        // 0. Access gate — the viewer is reserved for signed-in users that
        //    hold the "glpe_browser" permission (administrators included).
        $verdict = GLPE_Access::verdict();
        if ($verdict !== 'allow') {
            $this->respondToGate($verdict);
            exit;
        }

        // 0a. Account-jar restore: if this browser session has no records
        //     yet but the signed-in account has a saved snapshot, load it —
        //     logins to remote sites then follow the account, not the browser.
        $this->maybeRestoreAccountJar();

        $viewScript  = add_query_arg(['_glpe' => '1'], home_url('/'));
        $tempCookies = (isset($_GET['tp']) && $_GET['tp'] == '1') || (isset($_POST['tp']) && $_POST['tp'] == '1');
        $engine = new GLPE_Engine($viewScript, $tempCookies);

        // 1. Extract the display link first — control modes below only apply
        //    to gateway requests that carry no display link, so displayed
        //    sites using "mode" as their own field name keep working.
        $rawParam = isset($_GET['l']) ? trim($_GET['l']) : (isset($_POST['l']) ? trim($_POST['l']) : '');

        // 2. Client-side data synchronization beacon
        if ($rawParam === '' && isset($_GET['mode']) && $_GET['mode'] === 'sync') {
            $url    = isset($_POST['url'])    ? sanitize_text_field(wp_unslash($_POST['url']))    : (isset($_GET['url'])    ? sanitize_text_field($_GET['url'])    : '');
            $cookie = isset($_POST['cookie']) ? sanitize_text_field(wp_unslash($_POST['cookie'])) : (isset($_GET['cookie']) ? sanitize_text_field($_GET['cookie']) : '');

            if ($url !== '' && $cookie !== '') {
                $engine->getCookies()->addCookieFromHeader($cookie, $url);
            }
            wp_send_json(['ok' => true]);
            exit;
        }

        // 3. Per-user session & record management page
        if ($rawParam === '' && isset($_GET['mode']) && $_GET['mode'] === 'sessions') {
            $this->runSessionManager($engine);
            exit;
        }

        if ($rawParam === '') {
            wp_redirect($this->viewerPageUrl());
            exit;
        }

        $targetUrl = GLPE_Codec::decode($rawParam);

        if (!preg_match('#^https?://#i', $targetUrl)) {
            $targetUrl = 'https://' . $targetUrl;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        // 4. GET submissions carry their fields in the gateway query string
        //    (the display link itself travels in hidden inputs). Forward every
        //    non-reserved field to the destination as part of its query.
        if ($method === 'GET' && isset($_SERVER['QUERY_STRING'])) {
            $reserved = ['_glpe', 'l', 'ns', 'ni', 'nt', 'nb', 'ec', 'tp'];
            $fields = [];
            foreach (explode('&', (string)$_SERVER['QUERY_STRING']) as $pair) {
                if ($pair === '') continue;
                $fieldName = explode('=', $pair, 2)[0];
                if (in_array($fieldName, $reserved, true)) continue;
                $fields[] = $pair;
            }
            if (!empty($fields)) {
                $targetUrl .= (strpos($targetUrl, '?') !== false ? '&' : '?') . implode('&', $fields);
            }
        }

        // 5. Display flags — an explicit URL flag ("1" or "0") always wins;
        //    otherwise the site-wide defaults from the settings page apply.
        $options = [
            'removeScripts' => $this->viewFlag('ns', 'glpe_no_scripts', '0'),
            'removeImages'  => $this->viewFlag('ni', 'glpe_no_images', '0'),
            'stripTitle'    => $this->viewFlag('nt', 'glpe_blank_title', '0'),
            'showToolbar'   => $this->viewFlag('nb', 'glpe_toolbar', '1'),
            'encodeURL'     => $this->viewFlag('ec', 'glpe_rewrite_links', '1'),
            'mobileView'    => $this->viewFlag('mb', 'glpe_mobile_view', '0'),
            'tempSession'   => $tempCookies,
        ];

        $postData = ($method === 'POST') ? file_get_contents('php://input') : null;

        // Forward the client's own request headers (minus hop-by-hop, identity
        // and location ones — the destination must see its own origin, not
        // this site's, or its cross-site checks reject the exchange).
        // Values are stripped of CR/LF/NUL to prevent header injection.
        $customHeaders = [];
        $blockedHeaders = ['host', 'cookie', 'content-length', 'connection', 'accept-encoding', 'origin', 'referer', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'via', 'forwarded', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'x-cluster-client-ip'];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $val) {
                $nameLower = strtolower($name);
                if (!in_array($nameLower, $blockedHeaders, true)) {
                    $customHeaders[$nameLower] = str_replace(["\r", "\n", "\0"], '', trim((string)$val));
                }
            }
        } else {
            foreach ($_SERVER as $key => $val) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    if (!in_array($name, $blockedHeaders, true)) {
                        $customHeaders[$name] = str_replace(["\r", "\n", "\0"], '', trim((string)$val));
                    }
                }
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $customHeaders['content-type'] = str_replace(["\r", "\n", "\0"], '', trim((string)$_SERVER['CONTENT_TYPE']));
        }

        // Mobile view: fetch destinations with a mobile identity so sites
        // that vary their content by device serve the mobile layout. Safari
        // on iOS sends no client hints, so the desktop hints are dropped
        // instead of contradicting the mobile user agent.
        if (!empty($options['mobileView'])) {
            $customHeaders['user-agent'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
            unset($customHeaders['sec-ch-ua'], $customHeaders['sec-ch-ua-mobile'], $customHeaders['sec-ch-ua-platform']);
            $customHeaders['accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        }

        try {
            // Media targets (video/audio bytes) stream straight through:
            // buffering a full video in PHP memory would exhaust the server
            // and stall playback. Range requests (206) are relayed as-is.
            if ($method === 'GET' && preg_match('#videoplayback|googlevideo\.com|\.m3u8(\?|$)|\.(mp4|webm|m4a|mp3|ts|ogg|flv)(\?|$)#i', $targetUrl)) {
                if ($engine->streamMediaRequest($targetUrl, $customHeaders)) {
                    exit;
                }
                // streaming unavailable → fall through to the buffered path
            }

            $result = $engine->executeRequest($targetUrl, $method, $postData, $customHeaders);

            // Fresh content on every request; allow embedding from any origin.
            header('Cache-Control: no-store, max-age=0');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: *');

            if (function_exists('header_remove')) {
                header_remove('X-Frame-Options');
                header_remove('Content-Security-Policy');
                header_remove('X-Powered-By');
            }

            $contentType = $result['contentType'];
            header('Content-Type: ' . $contentType);
            http_response_code($result['status']);

            $body = $result['body'];

            // Rewrite against the address that actually served the content —
            // a redirect chain can land somewhere else than the request began,
            // and relative references must follow the final landing page.
            $rewriteBase = (isset($result['finalUrl']) && is_string($result['finalUrl']) && $result['finalUrl'] !== '')
                ? $result['finalUrl']
                : $targetUrl;

            if (stripos($contentType, 'text/html') !== false) {
                $body = $engine->rewriteHtml($body, $rewriteBase, $options);

                // Destination-side anti-abuse walls (search captchas, CDN
                // challenge pages) look like a dead viewer to visitors —
                // "nothing happens". Show a clear notice that the block
                // comes from the destination site, not from the viewer.
                if (preg_match('#unusual traffic|/sorry/index|cf-chl|Just a moment|g-recaptcha|Attention Required#i', $body)) {
                    $notice = '<div style="position:fixed;bottom:0;left:0;right:0;z-index:2147483646;'
                        . 'background:#7f1d1d;color:#fecaca;font:12px Tahoma,sans-serif;padding:10px 16px;text-align:center;direction:rtl;">'
                        . '⚠️ این صفحه توسط وب‌سایت مقصد نمایش داده شده است (بررسی ضد سوءاستفاده درباره IP سرور — کپچا یا چالش مرورگر). '
                        . 'چند دقیقه دیگر دوباره تلاش کنید؛ این محدودیت به نمایشگر مربوط نیست.'
                        . '</div>';
                    if (stripos($body, '</body>') !== false) {
                        $body = preg_replace('#</body>#i', $notice . '</body>', $body, 1);
                    } else {
                        $body .= $notice;
                    }
                }
            } elseif (stripos($contentType, 'text/css') !== false) {
                $body = $engine->rewriteCss($body, $rewriteBase, $options);
            } elseif (stripos($contentType, 'javascript') !== false) {
                $body = $engine->rewriteJs($body, $rewriteBase, $options);
            }

            echo $body;
            exit;
        } catch (Exception $e) {
            status_header(502);
            header('Content-Type: text/html; charset=utf-8');
            $viewerPageUrl = $this->viewerPageUrl();
            ?>
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <title>خطا در نمایش صفحه</title>
                <style>
                    body { font-family: Tahoma, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px; margin: 0; }
                    .box { max-width: 580px; margin: 40px auto; background: #1e293b; padding: 28px; border-radius: 16px; border: 1px solid #334155; }
                    h2 { color: #f43f5e; margin-top: 0; font-size: 18px; }
                    p { font-size: 13px; line-height: 1.7; color: #cbd5e1; }
                    a { display: inline-block; margin-top: 15px; color: #38bdf8; text-decoration: none; font-weight: bold; font-size: 13px; }
                </style>
            </head>
            <body>
                <div class="box">
                    <h2>عدم برقراری ارتباط با وبگاه مقصد</h2>
                    <p><?php echo esc_html($e->getMessage()); ?></p>
                    <p style="font-family: monospace; font-size: 12px; color: #94a3b8; background: #0f172a; padding: 8px; border-radius: 8px;">
                        آدرس: <?php echo esc_html($targetUrl); ?>
                    </p>
                    <a href="<?php echo esc_url($viewerPageUrl); ?>">← بازگشت به برگه نمایشگر</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    /** Absolute URL of the current request (used for post-login redirects). */
    private function currentRequestUrl() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        return esc_url_raw(home_url($uri));
    }

    /**
     * Handles visitors that fail the access gate on viewer requests.
     * 'login'     → bounce to the WordPress login form and return here afterwards.
     * 'forbidden' → styled 403 page.
     */
    private function respondToGate($verdict) {
        if ($verdict === 'login') {
            wp_safe_redirect(wp_login_url($this->currentRequestUrl()));
            exit;
        }

        status_header(403);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $loginUrl  = wp_login_url($this->currentRequestUrl());
        $viewerUrl = $this->viewerPageUrl();
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>دسترسی مجاز نیست</title>
            <style>
                body { font-family: Tahoma, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px; margin: 0; }
                .box { max-width: 580px; margin: 40px auto; background: #1e293b; padding: 28px; border-radius: 16px; border: 1px solid #334155; }
                h2 { color: #fbbf24; margin-top: 0; font-size: 18px; }
                p { font-size: 13px; line-height: 1.9; color: #cbd5e1; }
                a { display: inline-block; margin-top: 15px; margin-left: 10px; color: #38bdf8; text-decoration: none; font-weight: bold; font-size: 13px; }
                code { background: #0f172a; padding: 2px 6px; border-radius: 6px; font-size: 12px; color: #fbbf24; }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>🔐 دسترسی مجاز نیست</h2>
                <p>استفاده از نمایشگر صفحات وب فقط برای کاربران دارای مجوز <code>مرورگر GLPE</code> یا مدیران امکان‌پذیر است. حساب کاربری فعلی شما این مجوز را ندارد.</p>
                <p>لطفاً با حسابی دارای مجوز وارد شوید یا از مدیر سایت بخواهید مجوز «مرورگر GLPE» را برای حساب شما فعال کند.</p>
                <a href="<?php echo esc_url($loginUrl); ?>">ورود با حساب دیگر</a>
                <a href="<?php echo esc_url($viewerUrl); ?>">← بازگشت</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    /** Login card shown by the shortcode for logged-out visitors. */
    private function renderLoginCard() {
        $backUrl = get_permalink();
        if (!$backUrl) {
            $backUrl = home_url('/');
        }
        ob_start();
        ?>
        <div class="glpe-widget" style="max-width: 420px; margin: 20px auto; font-family: Tahoma, system-ui, sans-serif; direction: rtl; text-align: right; background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); color: #f8fafc;">
            <div style="text-align: center; margin-bottom: 18px;">
                <span style="font-size: 34px;">🔐</span>
                <h3 style="margin: 10px 0 4px 0; font-size: 16px; font-weight: bold; color: #fff;">ورود لازم است</h3>
                <p style="margin: 0; font-size: 12px; line-height: 1.9; color: #94a3b8;">استفاده از نمایشگر صفحات وب مخصوص کاربران دارای مجوز «مرورگر GLPE» است. ابتدا وارد حساب کاربری خود شوید.</p>
            </div>
            <style>
                #glpe-login-form p { margin-bottom: 12px; }
                #glpe-login-form label { color: #cbd5e1; font-size: 12px; }
                #glpe-login-form input[type="text"],
                #glpe-login-form input[type="password"] {
                    width: 100%; box-sizing: border-box; padding: 11px 14px; background: #1e293b;
                    border: 1px solid #334155; border-radius: 12px; color: #fff; font-size: 13px; outline: none;
                }
                #glpe-login-form .login-remember { color: #94a3b8; font-size: 12px; }
                #glpe-login-form .login-remember input[type="checkbox"] { accent-color: #2563eb; }
                #glpe-login-form .login-submit input[type="submit"] {
                    width: 100%; padding: 12px 24px; background: linear-gradient(to left, #2563eb, #4f46e5);
                    color: #fff; border: none; border-radius: 12px; font-size: 13px; font-weight: bold; cursor: pointer;
                }
            </style>
            <?php
            wp_login_form([
                'echo'           => true,
                'redirect'       => $backUrl,
                'form_id'        => 'glpe-login-form',
                'label_username' => 'نام کاربری یا ایمیل',
                'label_password' => 'گذرواژه',
                'label_remember' => 'مرا به خاطر بسپار',
                'label_log_in'   => 'ورود به نمایشگر',
                'remember'       => true,
            ]);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Denial card shown by the shortcode for signed-in users without the permission. */
    private function renderForbiddenCard() {
        $loginUrl = wp_login_url($this->currentRequestUrl());
        ob_start();
        ?>
        <div class="glpe-widget" style="max-width: 420px; margin: 20px auto; font-family: Tahoma, system-ui, sans-serif; direction: rtl; text-align: right; background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); color: #f8fafc;">
            <div style="text-align: center; margin-bottom: 6px;">
                <span style="font-size: 34px;">🚫</span>
                <h3 style="margin: 10px 0 4px 0; font-size: 16px; font-weight: bold; color: #fff;">دسترسی مجاز نیست</h3>
                <p style="margin: 0; font-size: 12px; line-height: 1.9; color: #94a3b8;">حساب کاربری فعلی شما مجوز «مرورگر GLPE» را ندارد. برای دریافت مجوز با مدیر سایت تماس بگیرید یا با حساب دیگری وارد شوید.</p>
            </div>
            <div style="margin-top: 16px;">
                <a href="<?php echo esc_url($loginUrl); ?>" style="display: block; text-align: center; padding: 11px 18px; background: #1e293b; border: 1px solid #334155; color: #fff; border-radius: 12px; font-size: 13px; font-weight: bold; text-decoration: none;">ورود با حساب دیگر</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the front-end viewer form via the [glpe_viewer] shortcode.
     */
    public function renderShortcode($atts) {
        // Access gate — mirrors the check applied to viewer requests above.
        $verdict = GLPE_Access::verdict();
        if ($verdict === 'login') {
            return $this->renderLoginCard();
        }
        if ($verdict === 'forbidden') {
            return $this->renderForbiddenCard();
        }

        $viewScript = add_query_arg(['_glpe' => '1'], home_url('/'));
        $engine = new GLPE_Engine($viewScript);
        $cookieCount = count($engine->getCookies()->getAllCookies());
        $sessionsUrl = add_query_arg(['_glpe' => '1', 'mode' => 'sessions'], home_url('/'));

        $defaultEc = get_option('glpe_rewrite_links', '1') === '1';
        $defaultNb = get_option('glpe_toolbar', '1') === '1';
        $defaultNt = get_option('glpe_blank_title', '0') === '1';
        $defaultNs = get_option('glpe_no_scripts', '0') === '1';
        $defaultNi = get_option('glpe_no_images', '0') === '1';
        $secretJson = json_encode(GLPE_SECRET);

        ob_start();
        ?>
        <div class="glpe-widget" style="max-width: 800px; margin: 20px auto; font-family: Tahoma, system-ui, sans-serif; direction: rtl; text-align: right; background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); color: #f8fafc;">

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid #1e293b; padding-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px; background: #1e293b; border-radius: 12px; padding: 6px 10px;">🌐</span>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: bold; color: #fff;">نمایشگر صفحات وب</h3>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #94a3b8;">نمایش سریع صفحات اینترنت داخل سایت شما</p>
                    </div>
                </div>
                <span style="background: rgba(37,99,235,0.15); color: #60a5fa; border: 1px solid rgba(37,99,235,0.3); padding: 4px 10px; border-radius: 9999px; font-size: 11px;">
                    نشست‌های فعال: <?php echo (int)$cookieCount; ?>
                </span>
            </div>

            <form action="<?php echo esc_url(home_url('/')); ?>" method="GET" style="margin: 0;" onsubmit="
                var input = this.querySelector('input[name=l]');
                var ecBox = this.querySelector('input[name=ec]');
                var key = <?php echo $secretJson; ?>;
                if (input && input.value) {
                    var v = input.value.trim();
                    if (!v.match(/^https?:/i)) v = 'https://' + v;
                    if (ecBox && ecBox.checked) {
                        try {
                            var bytes = unescape(encodeURIComponent(v));
                            var out = [];
                            for (var i = 0; i < bytes.length; i++) {
                                out.push(String.fromCharCode(bytes.charCodeAt(i) ^ key.charCodeAt(i % key.length)));
                            }
                            var b64 = btoa(out.join('')).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                            input.value = b64;
                        } catch(e) {
                            input.value = v;
                        }
                    } else {
                        input.value = v;
                    }
                }
            ">
                <input type="hidden" name="_glpe" value="1">

                <div style="display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
                    <input
                        type="text"
                        name="l"
                        placeholder="https://example.com یا نشانی وبگاه..."
                        required
                        style="flex: 1; min-width: 250px; padding: 12px 14px; background: #1e293b; border: 1px solid #334155; border-radius: 12px; color: #fff; font-size: 13px; font-family: monospace; outline: none;"
                    >
                    <button
                        type="submit"
                        style="padding: 12px 24px; background: linear-gradient(to left, #2563eb, #4f46e5); color: #fff; border: none; border-radius: 12px; font-size: 13px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,0.3);"
                    >
                        شروع نمایش ↵
                    </button>
                </div>

                <!-- Advanced display options -->
                <div style="background: #141e33; border: 1px solid #1e293b; border-radius: 14px; padding: 12px; margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: bold; color: #94a3b8; margin-bottom: 8px;">گزینه‌های پیشرفته:</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; font-size: 11px; color: #cbd5e1;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="ec" value="1" <?php checked($defaultEc); ?>> بازنویسی پیوندها (Short Links)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="nb" value="1" <?php checked($defaultNb); ?>> نوار ناوبری بالا (Nav Bar)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="nt" value="1" <?php checked($defaultNt); ?>> عنوان عمومی تب (Blank Title)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="ns" value="1" <?php checked($defaultNs); ?>> بدون جاوااسکریپت (No Scripts)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="ni" value="1" <?php checked($defaultNi); ?>> بدون تصاویر (No Images)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="mb" value="1"> 📱 نسخه موبایل (Mobile)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="tp" value="1"> نشست موقت (Temp Session)
                        </label>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #94a3b8;">
                    <span>سایت‌های نمونه:</span>
                    <?php
                    $presets = [
                        'DuckDuckGo' => 'https://html.duckduckgo.com/html/',
                        'Wikipedia'  => 'https://en.m.wikipedia.org/',
                        'Hacker News'=> 'https://news.ycombinator.com/',
                    ];
                    foreach ($presets as $name => $u):
                        $enc = GLPE_Codec::encode($u);
                        $pUrl = add_query_arg(['_glpe' => '1', 'l' => $enc, 'ec' => '1', 'nb' => '1'], home_url('/'));
                    ?>
                        <a href="<?php echo esc_url($pUrl); ?>" style="color: #60a5fa; text-decoration: none; background: #1e293b; padding: 3px 8px; border-radius: 6px;">
                            <?php echo esc_html($name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </form>

            <div style="display: flex; justify-content: center; gap: 8px; margin-top: 14px; flex-wrap: wrap; align-items: center;">
                <a href="<?php echo esc_url($sessionsUrl); ?>" style="font-size: 12px; color: #94a3b8; text-decoration: none; background: #1e293b; border: 1px solid #334155; padding: 8px 16px; border-radius: 10px;">
                    🍪 مدیریت نشست‌ها و کوکی‌های من (<?php echo (int)$cookieCount; ?>)
                </a>
                <?php if (is_user_logged_in()): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline; margin: 0;"
                      onsubmit="return confirm('نشست‌ها و کوکی‌های فعلی مرورگر در حساب شما ذخیره شوند؟');">
                    <input type="hidden" name="action" value="glpe_save_jar">
                    <?php wp_nonce_field('glpe_save_jar'); ?>
                    <button type="submit" style="font-size: 12px; color: #6ee7b7; background: rgba(5,150,105,0.12); border: 1px solid #059669; padding: 8px 16px; border-radius: 10px; cursor: pointer; font-family: inherit;">
                        💾 ذخیره نشست‌ها در حساب من (<?php echo (int)$this->accountJarCount(); ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php if (isset($_GET['cp_msg']) && $_GET['cp_msg'] === 'jar-saved'): ?>
            <div style="text-align: center; margin-top: 10px; font-size: 12px; color: #6ee7b7;">
                ✔ نشست‌ها و کوکی‌های فعلی در حساب شما ذخیره شد؛ از این پس با ورود به حساب‌تان در هر مرورگری بارگذاری می‌شود.
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Admin menu and settings inside the WordPress dashboard.
     */
    public function registerAdminMenu() {
        add_menu_page(
            'نمایشگر صفحات وب',
            'نمایشگر صفحات وب',
            'manage_options',
            'glpe-viewer',
            [$this, 'renderAdminSettingsPage'],
            'dashicons-welcome-view-site',
            85
        );
    }

    public function registerSettings() {
        $flags = ['glpe_rewrite_links', 'glpe_toolbar', 'glpe_blank_title', 'glpe_no_scripts', 'glpe_no_images', 'glpe_ssl_verify'];
        foreach ($flags as $flag) {
            register_setting('glpe_group', $flag, ['sanitize_callback' => [$this, 'sanitizeFlag']]);
        }
    }

    /**
     * Normalizes on/off settings. Unchecked checkboxes are absent from the
     * POST payload, so every flag ships a hidden "0" input and this callback
     * guarantees the saved value is always a clean "0"/"1" string.
     */
    public function sanitizeFlag($value) {
        return ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
    }

    public function renderAdminSettingsPage() {
        $viewerPageUrl = $this->viewerPageUrl();
        ?>
        <div class="wrap" style="direction: rtl; text-align: right; max-width: 900px;">
            <h1 style="font-size: 22px; font-weight: bold; margin-bottom: 20px;">
                تنظیمات نمایشگر صفحات وب (GLPE Viewer)
            </h1>

            <?php
            $cpNotice = isset($_GET['cp_msg']) ? sanitize_key($_GET['cp_msg']) : '';
            $cpNotices = [
                'roles-saved' => 'سطح دسترسی نقش‌ها ذخیره شد.',
                'granted'     => 'مجوز «مرورگر GLPE» به کاربر اعطا شد.',
                'revoked'     => 'مجوز «مرورگر GLPE» از کاربر لغو شد.',
                'notfound'    => 'کاربر موردنظر یافت نشد.',
            ];
            if ($cpNotice !== '' && isset($cpNotices[$cpNotice])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($cpNotices[$cpNotice]) . '</p></div>';
            }
            ?>

            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin-top: 0;">🌐 برگه اختصاصی نمایشگر</h3>
                <p>برگه اختصاصی نمایشگر فعال است و از آدرس زیر در دسترس می‌باشد:</p>
                <p>
                    <a href="<?php echo esc_url($viewerPageUrl); ?>" target="_blank" style="font-family: monospace; font-size: 14px; font-weight: bold; background: #f0f6fc; padding: 6px 12px; border-radius: 6px; border: 1px solid #c8e1ff; color: #0969da; text-decoration: none;">
                        <?php echo esc_url($viewerPageUrl); ?> ↗
                    </a>
                </p>
                <p style="font-size: 12px; color: #64748b;">
                    همچنین می‌توانید با قرار دادن شورت‌کد <code>[glpe_viewer]</code> در هر برگه دلخواه یا در المنتور، فرم نمایشگر را نمایش دهید.
                </p>
            </div>

            <form method="post" action="options.php" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <?php settings_fields('glpe_group'); ?>

                <h3 style="margin-top: 0;">تنظیمات پیش‌فرض نمایشگر</h3>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">بازنویسی خودکار پیوندها (Short Links)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_rewrite_links" value="0">
                                <input type="checkbox" name="glpe_rewrite_links" value="1" <?php checked(get_option('glpe_rewrite_links', '1'), '1'); ?> />
                                فعال‌سازی کدگذاری دوطرفه پیوندهای داخلی صفحات نمایش‌داده‌شده
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">نوار ناوبری بالا (Nav Bar)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_toolbar" value="0">
                                <input type="checkbox" name="glpe_toolbar" value="1" <?php checked(get_option('glpe_toolbar', '1'), '1'); ?> />
                                نمایش نوار ناوبری شناور بالای صفحه با قابلیت جستجوی جدید و دکمه صفحه اصلی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">عنوان عمومی تب (Blank Title)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_blank_title" value="0">
                                <input type="checkbox" name="glpe_blank_title" value="1" <?php checked(get_option('glpe_blank_title', '0'), '1'); ?> />
                                نمایش عنوان عمومی به‌جای عنوان اصلی صفحه در تب مرورگر
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">بدون جاوااسکریپت (No Scripts)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_no_scripts" value="0">
                                <input type="checkbox" name="glpe_no_scripts" value="1" <?php checked(get_option('glpe_no_scripts', '0'), '1'); ?> />
                                غیرفعال‌سازی کدهای جاوااسکریپت صفحات خارجی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">بدون تصاویر (No Images)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_no_images" value="0">
                                <input type="checkbox" name="glpe_no_images" value="1" <?php checked(get_option('glpe_no_images', '0'), '1'); ?> />
                                عدم بارگذاری تصاویر جهت کاهش شدید مصرف پهنای باند
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">اعتبارسنجی SSL مقصد</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_ssl_verify" value="0">
                                <input type="checkbox" name="glpe_ssl_verify" value="1" <?php checked(get_option('glpe_ssl_verify', '1'), '1'); ?> />
                                فقط در صورت خطای گواهی روی هاست‌های قدیمی غیرفعال کنید (کاهش امنیت)
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('ذخیره تغییرات'); ?>
            </form>

            <?php $this->renderAccessCard(); ?>
        </div>
        <?php
    }

    /**
     * Access management card — grants/revokes the "مرورگر GLPE" permission
     * (capability glpe_browser) on roles and individual users.
     */
    private function renderAccessCard() {
        $cap = GLPE_Access::CAP;
        $accessCount = GLPE_Access::countHolders();

        $allRoles = wp_roles()->get_names();
        $formRoles = $allRoles;
        unset($formRoles['administrator']);

        $userSearch = isset($_GET['cp_user_search']) ? sanitize_text_field(wp_unslash($_GET['cp_user_search'])) : '';
        $usersArgs = [
            'number'  => 30,
            'orderby' => 'registered',
            'order'   => 'DESC',
        ];
        if ($userSearch !== '') {
            $usersArgs['search'] = '*' . $userSearch . '*';
            $usersArgs['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
        }
        $usersQuery = new WP_User_Query($usersArgs);

        $userRows = [];
        foreach ($usersQuery->get_results() as $userObj) {
            $effective = $userObj->has_cap($cap);
            $explicit  = array_key_exists($cap, (array)$userObj->caps) ? (bool)$userObj->caps[$cap] : null;

            if ($effective) {
                $status = $explicit === true
                    ? '<span style="color:#059669; font-weight:bold;">✔ مجاز (مجوز اختصاصی)</span>'
                    : '<span style="color:#059669; font-weight:bold;">✔ مجاز (از طریق نقش)</span>';
            } else {
                $status = $explicit === false
                    ? '<span style="color:#b45309; font-weight:bold;">⛔ لغو صریح (نقش مجاز است)</span>'
                    : '<span style="color:#9ca3af;">— بدون مجوز</span>';
            }

            $roleLabels = [];
            foreach ((array)$userObj->roles as $roleName) {
                $roleLabels[] = isset($allRoles[$roleName]) ? translate_user_role($allRoles[$roleName]) : $roleName;
            }

            $userRows[] = [
                'display'   => $userObj->display_name !== '' ? $userObj->display_name : $userObj->user_login,
                'login'     => $userObj->user_login,
                'email'     => $userObj->user_email,
                'roles'     => implode('، ', $roleLabels),
                'status'    => $status,
                'effective' => $effective,
                'action'    => wp_nonce_url(
                    admin_url('admin-post.php?action=glpe_toggle_user&user=' . (int)$userObj->ID . '&dir=' . ($effective ? 'revoke' : 'grant')),
                    'glpe_toggle_user_' . (int)$userObj->ID
                ),
            ];
        }
        ?>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🔐 مدیریت دسترسی — سطح «مرورگر GLPE»</h3>
            <p>
                فقط کاربران دارای سطح دسترسی <strong>«مرورگر GLPE»</strong> یا مدیران می‌توانند از نمایشگر استفاده کنند؛ سایر بازدیدکنندگان ابتدا باید وارد حساب کاربری شوند.
                شناسه فنی این سطح دسترسی: <code><?php echo esc_html($cap); ?></code>
            </p>
            <p style="font-size: 13px; color: #059669; font-weight: bold;">
                ✔ <?php echo (int)$accessCount; ?> کاربر در حال حاضر مجاز است.
            </p>

            <h4 style="margin-bottom: 6px;">مجوز نقش‌ها</h4>
            <p style="font-size: 12px; color: #64748b; margin-top: 0;">
                با فعال‌کردن هر نقش، همه کاربران آن نقش مجاز می‌شوند. مدیرکل همیشه مجاز است و قابل لغو نیست.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="glpe_save_roles">
                <?php wp_nonce_field('glpe_save_roles'); ?>
                <table class="widefat striped" style="max-width: 560px;">
                    <tbody>
                    <tr>
                        <td><label><input type="checkbox" checked disabled> <?php echo esc_html(translate_user_role('Administrator')); ?></label></td>
                        <td><code>administrator</code></td>
                        <td style="color: #64748b; font-size: 11px;">همیشه مجاز (قفل)</td>
                    </tr>
                    <?php foreach ($formRoles as $roleName => $roleLabel):
                        $roleObj = get_role($roleName);
                        $roleHas = $roleObj && $roleObj->has_cap($cap);
                    ?>
                    <tr>
                        <td><label><input type="checkbox" name="glpe_roles[]" value="<?php echo esc_attr($roleName); ?>" <?php checked($roleHas); ?>> <?php echo esc_html(translate_user_role($roleLabel)); ?></label></td>
                        <td><code><?php echo esc_html($roleName); ?></code></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('ذخیره مجوز نقش‌ها', 'secondary', 'submit', false); ?>
            </form>

            <h4 style="margin-top: 24px; margin-bottom: 6px;">مجوز کاربران</h4>
            <p style="font-size: 12px; color: #64748b; margin-top: 0;">
                ۳۰ کاربر اخیر نمایش داده می‌شود؛ برای یافتن کاربر دیگر از جستجو استفاده کنید.
            </p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 10px;">
                <input type="hidden" name="page" value="glpe-viewer">
                <input type="search" name="cp_user_search" value="<?php echo esc_attr($userSearch); ?>" placeholder="جستجوی نام کاربری، ایمیل یا نام..." style="padding: 6px 10px; width: 320px; max-width: 100%;">
                <button type="submit" class="button button-secondary">جستجو</button>
            </form>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>کاربر</th>
                    <th>نقش‌ها</th>
                    <th>وضعیت مجوز</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($userRows)): ?>
                    <tr><td colspan="4">کاربری یافت نشد.</td></tr>
                <?php endif; ?>
                <?php foreach ($userRows as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row['display']); ?></strong><br>
                            <span style="font-size: 11px; color: #64748b;"><?php echo esc_html($row['login']); ?> · <?php echo esc_html($row['email']); ?></span>
                        </td>
                        <td style="font-size: 12px;"><?php echo esc_html($row['roles']); ?></td>
                        <td><?php echo $row['status']; // phpcs:ignore WordPress.Security.EscapeOutput -- static prepared HTML ?></td>
                        <td>
                            <a class="button button-small <?php echo $row['effective'] ? '' : 'button-primary'; ?>" href="<?php echo esc_url($row['action']); ?>">
                                <?php echo $row['effective'] ? 'لغو مجوز' : 'اعطای مجوز'; ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Saves the per-role permission checkboxes. */
    public function handleSaveRoles() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز', '', ['response' => 403]);
        }
        check_admin_referer('glpe_save_roles');

        $requested = isset($_POST['glpe_roles']) && is_array($_POST['glpe_roles'])
            ? array_map('sanitize_key', wp_unslash($_POST['glpe_roles']))
            : [];
        foreach (wp_roles()->get_names() as $roleName => $roleLabel) {
            GLPE_Access::toggleRole($roleName, in_array($roleName, $requested, true));
        }

        wp_safe_redirect(add_query_arg(['page' => 'glpe-viewer', 'cp_msg' => 'roles-saved'], admin_url('admin.php')));
        exit;
    }

    /** Grants or revokes the permission for a single user. */
    public function handleToggleUser() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز', '', ['response' => 403]);
        }
        $userId = isset($_GET['user']) ? absint($_GET['user']) : 0;
        $dir    = (isset($_GET['dir']) && $_GET['dir'] === 'revoke') ? 'revoke' : 'grant';
        check_admin_referer('glpe_toggle_user_' . $userId);

        $msg = 'notfound';
        if ($userId && get_user_by('id', $userId)) {
            if ($dir === 'grant') {
                GLPE_Access::grantToUser($userId);
                $msg = 'granted';
            } else {
                GLPE_Access::revokeFromUser($userId);
                $msg = 'revoked';
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'glpe-viewer', 'cp_msg' => $msg], admin_url('admin.php')));
        exit;
    }

    /** Meta key holding the account's persistent session snapshot. */
    const ACCOUNT_JAR_META = '_glpe_account_jar';

    /**
     * Copies the current browser-session jar into the signed-in user's
     * account (user meta). Invoked by the "save my sessions to my account"
     * buttons in the viewer widget and the session manager. The snapshot is
     * strictly per-account: other accounts never see or restore it.
     */
    public function handleSaveJar() {
        if (!is_user_logged_in()) {
            wp_die('ورود لازم است.');
        }
        if (!current_user_can('administrator') && !current_user_can(GLPE_Access::CAP)) {
            wp_die('دسترسی مجاز نیست.');
        }
        check_admin_referer('glpe_save_jar');

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $jar = (isset($_SESSION['_glpe_cookies']) && is_array($_SESSION['_glpe_cookies']))
            ? $_SESSION['_glpe_cookies']
            : [];
        update_user_meta(get_current_user_id(), self::ACCOUNT_JAR_META, $jar);

        $back = wp_get_referer();
        if (!$back) $back = home_url('/');
        wp_safe_redirect(add_query_arg('cp_msg', 'jar-saved', $back));
        exit;
    }

    /**
     * Login: a browser's jar belongs to exactly one account. Drop whatever
     * the previous session left behind, then restore THIS account's saved
     * snapshot so its remote-site logins follow the account everywhere.
     */
    public function onLogin($user_login) {
        $user = get_user_by('login', $user_login);
        if (!$user) return;
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION['_glpe_cookies'] = [];
        $saved = get_user_meta($user->ID, self::ACCOUNT_JAR_META, true);
        if (is_array($saved) && !empty($saved)) {
            $_SESSION['_glpe_cookies'] = $saved;
        }
    }

    /** Logout: drop the jar so the next account on this browser starts clean. */
    public function onLogout() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION['_glpe_cookies'] = [];
    }

    /**
     * Called on gateway requests after the access gate: if the browser
     * session has no records (fresh PHP session, expired session, another
     * device) but the signed-in account has a saved snapshot, load it.
     */
    private function maybeRestoreAccountJar() {
        if (!is_user_logged_in()) return;
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (!empty($_SESSION['_glpe_cookies']) && is_array($_SESSION['_glpe_cookies'])) {
            return; // live browsing already has records — nothing to restore
        }
        $saved = get_user_meta(get_current_user_id(), self::ACCOUNT_JAR_META, true);
        if (is_array($saved) && !empty($saved)) {
            $_SESSION['_glpe_cookies'] = $saved;
        }
    }

    /** Cookie record count inside the signed-in account's saved snapshot. */
    private function accountJarCount() {
        if (!is_user_logged_in()) return 0;
        $saved = get_user_meta(get_current_user_id(), self::ACCOUNT_JAR_META, true);
        $count = 0;
        if (is_array($saved)) {
            foreach ($saved as $domainCookies) {
                if (is_array($domainCookies)) $count += count($domainCookies);
            }
        }
        return $count;
    }

    /**
     * Display flag resolution: an explicit URL flag ("1" or "0") always wins,
     * otherwise the site-wide default from the settings page is used.
     */
    private function viewFlag($param, $option, $default) {
        $val = null;
        if (isset($_GET[$param])) {
            $val = $_GET[$param];
        } elseif (isset($_POST[$param])) {
            $val = $_POST[$param];
        }
        if ($val !== null) {
            return $val == '1';
        }
        return get_option($option, $default) === '1';
    }

    /** Truncated preview of a stored record value (own data, kept compact). */
    private function previewValue($value) {
        $value = (string)$value;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > 30 ? mb_substr($value, 0, 30) . '…' : $value;
        }
        return strlen($value) > 30 ? substr($value, 0, 30) . '…' : $value;
    }

    /**
     * Per-user session & record manager (mode=sessions).
     * Lets every authorized user inspect and clear the session data stored
     * for their own browsing — per domain, per record, or all at once.
     */
    private function runSessionManager($engine) {
        $jar = $engine->getCookies();
        $baseSessionsUrl = add_query_arg(['_glpe' => '1', 'mode' => 'sessions'], home_url('/'));

        // POST actions: clear everything / one domain / one record.
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $nonce = isset($_POST['_glpe_sess_nonce']) ? wp_unslash($_POST['_glpe_sess_nonce']) : '';
            $do    = isset($_POST['sess_do']) ? sanitize_key(wp_unslash($_POST['sess_do'])) : '';

            if ($nonce === '' || !wp_verify_nonce($nonce, 'glpe_sess_mgmt')) {
                wp_safe_redirect(add_query_arg(['_glpe' => '1', 'mode' => 'sessions', 'err' => '1'], home_url('/')));
                exit;
            }

            if ($do === 'clear_all') {
                $jar->clearAll();
            } elseif ($do === 'clear_domain') {
                $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
                $jar->clearDomain($domain);
            } elseif ($do === 'clear_cookie') {
                $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
                $name   = isset($_POST['name'])   ? sanitize_text_field(wp_unslash($_POST['name']))   : '';
                $path   = isset($_POST['path'])   ? sanitize_text_field(wp_unslash($_POST['path']))   : '';
                $jar->removeCookie($domain, $name, $path !== '' ? $path : null);
            }

            wp_safe_redirect(add_query_arg(['_glpe' => '1', 'mode' => 'sessions', 'done' => '1'], home_url('/')));
            exit;
        }

        $byDomain = $jar->getAllCookiesByDomain();
        ksort($byDomain);
        $total = 0;
        foreach ($byDomain as $domainRecords) {
            $total += count($domainRecords);
        }
        $saved  = isset($_GET['done']);
        $failed = isset($_GET['err']);
        $viewerUrl = $this->viewerPageUrl();

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>مدیریت نشست‌ها و کوکی‌های من</title>
            <style>
                body { font-family: Tahoma, sans-serif; background: #0f172a; color: #f8fafc; padding: 24px; margin: 0; }
                .wrap { max-width: 860px; margin: 0 auto; }
                .card { background: #1e293b; padding: 24px; border-radius: 16px; border: 1px solid #334155; margin-bottom: 16px; }
                h2 { margin: 0 0 6px 0; font-size: 18px; }
                p { font-size: 13px; line-height: 1.9; color: #cbd5e1; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; }
                th { text-align: right; color: #94a3b8; font-weight: bold; padding: 8px; border-bottom: 1px solid #334155; }
                td { padding: 8px; border-bottom: 1px solid #26334d; color: #e2e8f0; word-break: break-all; }
                tr:hover td { background: rgba(148,163,184,0.06); }
                .btn { display: inline-block; background: #334155; color: #f8fafc; border: 1px solid #475569; padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; text-decoration: none; font-family: Tahoma, sans-serif; }
                .btn-danger { background: #7f1d1d; border-color: #991b1b; }
                .btn:hover { opacity: 0.9; }
                .tag { display: inline-block; background: #0f172a; border: 1px solid #334155; color: #94a3b8; border-radius: 6px; padding: 1px 7px; font-size: 10px; margin-right: 4px; }
                .notice { border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
                .notice-ok { background: rgba(5,150,105,0.15); border: 1px solid #059669; color: #6ee7b7; }
                .notice-err { background: rgba(244,63,94,0.15); border: 1px solid #f43f5e; color: #fda4af; }
                .mono { font-family: monospace; font-size: 11px; color: #94a3b8; }
                .domain { font-weight: bold; font-size: 14px; color: #38bdf8; }
            </style>
        </head>
        <body>
        <div class="wrap">
            <?php if ($saved): ?>
                <div class="notice notice-ok">✔ تغییرات اعمال شد.</div>
            <?php elseif ($failed): ?>
                <div class="notice notice-err">خطای اعتبارسنجی؛ لطفاً دوباره تلاش کنید.</div>
            <?php endif; ?>

            <div class="card">
                <h2>🍪 نشست‌ها و کوکی‌های من</h2>
                <p>داده‌های نشست ذخیره‌شده برای مرور شما در این مرورگر. این داده‌ها فقط برای حساب کاربری فعلی شماست و هر کاربر تنها داده‌های خودش را می‌بیند.</p>
                <p style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:0;">
                    <span class="tag"><?php echo (int)$total; ?> کوکی در <?php echo count($byDomain); ?> دامنه</span>
                    <?php $accountCount = $this->accountJarCount(); ?>
                    <span class="tag" title="نسخه ذخیره‌شده در حساب کاربری شما که با ورود در هر مرورگری بارگذاری می‌شود">💾 <?php echo (int)$accountCount; ?> کوکی ذخیره‌شده در حساب</span>
                    <?php if ($total > 0): ?>
                    <form method="post" action="<?php echo esc_url($baseSessionsUrl); ?>" style="display:inline;" onsubmit="return confirm('همه نشست‌ها پاک شوند؟');">
                        <input type="hidden" name="sess_do" value="clear_all">
                        <?php wp_nonce_field('glpe_sess_mgmt', '_glpe_sess_nonce'); ?>
                        <button type="submit" class="btn btn-danger">پاک‌کردن همه</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('نشست‌ها و کوکی‌های فعلی مرورگر در حساب شما ذخیره شوند؟');">
                        <input type="hidden" name="action" value="glpe_save_jar">
                        <?php wp_nonce_field('glpe_save_jar'); ?>
                        <button type="submit" class="btn" style="background: rgba(5,150,105,0.15); border-color: #059669; color: #6ee7b7;">💾 ذخیره در حساب من</button>
                    </form>
                    <?php endif; ?>
                    <a class="btn" href="<?php echo esc_url($viewerUrl); ?>">← بازگشت به نمایشگر</a>
                </p>
            </div>

            <?php if (empty($byDomain)): ?>
                <div class="card">
                    <p style="text-align:center; color:#94a3b8; margin:0;">هیچ نشست ذخیره‌شده‌ای وجود ندارد. با مرور سایت‌های جدید، داده‌های آن‌ها اینجا نمایش داده می‌شود.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($byDomain as $domain => $records): ?>
                <div class="card">
                    <p style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin:0 0 10px 0;">
                        <span class="domain">🌐 <?php echo esc_html($domain); ?> <span class="tag"><?php echo count($records); ?> کوکی</span></span>
                        <form method="post" action="<?php echo esc_url($baseSessionsUrl); ?>" style="display:inline;" onsubmit="return confirm('نشست این دامنه پاک شود؟');">
                            <input type="hidden" name="sess_do" value="clear_domain">
                            <input type="hidden" name="domain" value="<?php echo esc_attr($domain); ?>">
                            <?php wp_nonce_field('glpe_sess_mgmt', '_glpe_sess_nonce'); ?>
                            <button type="submit" class="btn">پاک‌کردن این دامنه</button>
                        </form>
                    </p>
                    <table>
                        <thead>
                        <tr><th>نام</th><th>مقدار</th><th>مسیر</th><th>انقضا</th><th>نشانه‌ها</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($records as $c):
                            $expText = ($c['expires'] === null)
                                ? 'تا پایان نشست'
                                : (function_exists('wp_date') ? wp_date('Y/m/d H:i', (int)$c['expires']) : date('Y/m/d H:i', (int)$c['expires']));
                        ?>
                        <tr>
                            <td class="mono"><?php echo esc_html($c['key']); ?></td>
                            <td class="mono" title="<?php echo esc_attr($c['value']); ?>"><?php echo esc_html($this->previewValue($c['value'])); ?></td>
                            <td class="mono"><?php echo esc_html($c['path']); ?></td>
                            <td class="mono"><?php echo esc_html($expText); ?></td>
                            <td>
                                <?php if (!empty($c['secure'])): ?><span class="tag">Secure</span><?php endif; ?>
                                <?php if (!empty($c['httpOnly'])): ?><span class="tag">HttpOnly</span><?php endif; ?>
                                <?php if (!empty($c['sameSite'])): ?><span class="tag"><?php echo esc_html($c['sameSite']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url($baseSessionsUrl); ?>" style="display:inline;">
                                    <input type="hidden" name="sess_do" value="clear_cookie">
                                    <input type="hidden" name="domain" value="<?php echo esc_attr($domain); ?>">
                                    <input type="hidden" name="name" value="<?php echo esc_attr($c['key']); ?>">
                                    <input type="hidden" name="path" value="<?php echo esc_attr($c['path']); ?>">
                                    <?php wp_nonce_field('glpe_sess_mgmt', '_glpe_sess_nonce'); ?>
                                    <button type="submit" class="btn" title="حذف این کوکی">✕</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
        </body>
        </html>
        <?php
    }
}

// Initialize
GLPE_Plugin::getInstance();
