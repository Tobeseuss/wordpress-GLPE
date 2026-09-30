<?php
/**
 * Plugin Name: GLPE Viewer — Remote Page Display
 * Plugin URI: https://github.com/Tobeseuss/wordpress-GLPE
 * Description: نمایش سریع و امن صفحات وب دلخواه داخل سایت شما با قابلیت بازنویسی خودکار پیوندها، سبک بارگذاری کم‌مصرف و نوار ناوبری شناور. مناسب هاست‌های اشتراکی و رایگان.
 * Version: 4.1.0
 * Author: Tobeseuss
 * License: MIT
 * Text Domain: glpe-viewer
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('GLPE_VERSION', '4.1.0');
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

        $viewScript  = add_query_arg(['_glpe' => '1'], home_url('/'));
        $tempCookies = (isset($_GET['tp']) && $_GET['tp'] == '1') || (isset($_POST['tp']) && $_POST['tp'] == '1');
        $engine = new GLPE_Engine($viewScript, $tempCookies);

        // 1. Client-side data synchronization beacon
        if (isset($_GET['mode']) && $_GET['mode'] === 'sync') {
            $url    = isset($_POST['url'])    ? sanitize_text_field(wp_unslash($_POST['url']))    : (isset($_GET['url'])    ? sanitize_text_field($_GET['url'])    : '');
            $cookie = isset($_POST['cookie']) ? sanitize_text_field(wp_unslash($_POST['cookie'])) : (isset($_GET['cookie']) ? sanitize_text_field($_GET['cookie']) : '');

            if ($url !== '' && $cookie !== '') {
                $engine->getCookies()->addCookieFromHeader($cookie, $url);
            }
            wp_send_json(['ok' => true]);
            exit;
        }

        // 2. Extract and decode the target link
        $rawParam = isset($_GET['l']) ? trim($_GET['l']) : (isset($_POST['l']) ? trim($_POST['l']) : '');
        if ($rawParam === '') {
            wp_redirect($this->viewerPageUrl());
            exit;
        }

        $targetUrl = GLPE_Codec::decode($rawParam);

        if (!preg_match('#^https?://#i', $targetUrl)) {
            $targetUrl = 'https://' . $targetUrl;
        }

        // 3. Display flags
        $options = [
            'removeScripts' => (isset($_GET['ns']) && $_GET['ns'] == '1') || (get_option('glpe_no_scripts', '0') === '1'),
            'removeImages'  => (isset($_GET['ni']) && $_GET['ni'] == '1') || (get_option('glpe_no_images', '0') === '1'),
            'stripTitle'    => (isset($_GET['nt']) && $_GET['nt'] == '1') || (get_option('glpe_blank_title', '0') === '1'),
            'showToolbar'   => (isset($_GET['nb']) && $_GET['nb'] == '1') || (get_option('glpe_toolbar', '1') === '1'),
            'encodeURL'     => (isset($_GET['ec']) && $_GET['ec'] == '1') || (get_option('glpe_rewrite_links', '1') === '1'),
        ];

        $method   = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $postData = ($method === 'POST') ? file_get_contents('php://input') : null;

        // Forward the client's own request headers (minus hop-by-hop / identity ones).
        // Values are stripped of CR/LF/NUL to prevent header injection.
        $customHeaders = [];
        $blockedHeaders = ['host', 'cookie', 'content-length', 'connection', 'accept-encoding', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'via', 'forwarded', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'x-cluster-client-ip'];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $val) {
                $nameLower = strtolower($name);
                if (!in_array($nameLower, $blockedHeaders, true)) {
                    $customHeaders[$nameLower] = str_replace(["\r", "\n", "\0"], '', sanitize_text_field($val));
                }
            }
        } else {
            foreach ($_SERVER as $key => $val) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    if (!in_array($name, $blockedHeaders, true)) {
                        $customHeaders[$name] = str_replace(["\r", "\n", "\0"], '', sanitize_text_field($val));
                    }
                }
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $customHeaders['content-type'] = str_replace(["\r", "\n", "\0"], '', sanitize_text_field($_SERVER['CONTENT_TYPE']));
        }

        try {
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

            if (stripos($contentType, 'text/html') !== false) {
                $body = $engine->rewriteHtml($body, $targetUrl, $options);
            } elseif (stripos($contentType, 'text/css') !== false) {
                $body = $engine->rewriteCss($body, $targetUrl, $options);
            } elseif (stripos($contentType, 'javascript') !== false) {
                $body = $engine->rewriteJs($body, $targetUrl, $options);
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
        register_setting('glpe_group', 'glpe_rewrite_links');
        register_setting('glpe_group', 'glpe_toolbar');
        register_setting('glpe_group', 'glpe_blank_title');
        register_setting('glpe_group', 'glpe_no_scripts');
        register_setting('glpe_group', 'glpe_no_images');
        register_setting('glpe_group', 'glpe_ssl_verify');
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
                                <input type="checkbox" name="glpe_rewrite_links" value="1" <?php checked(get_option('glpe_rewrite_links', '1'), '1'); ?> />
                                فعال‌سازی کدگذاری دوطرفه پیوندهای داخلی صفحات نمایش‌داده‌شده
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">نوار ناوبری بالا (Nav Bar)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="glpe_toolbar" value="1" <?php checked(get_option('glpe_toolbar', '1'), '1'); ?> />
                                نمایش نوار ناوبری شناور بالای صفحه با قابلیت جستجوی جدید و دکمه صفحه اصلی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">عنوان عمومی تب (Blank Title)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="glpe_blank_title" value="1" <?php checked(get_option('glpe_blank_title', '0'), '1'); ?> />
                                نمایش عنوان عمومی به‌جای عنوان اصلی صفحه در تب مرورگر
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">بدون جاوااسکریپت (No Scripts)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="glpe_no_scripts" value="1" <?php checked(get_option('glpe_no_scripts', '0'), '1'); ?> />
                                غیرفعال‌سازی کدهای جاوااسکریپت صفحات خارجی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">بدون تصاویر (No Images)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="glpe_no_images" value="1" <?php checked(get_option('glpe_no_images', '0'), '1'); ?> />
                                عدم بارگذاری تصاویر جهت کاهش شدید مصرف پهنای باند
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">اعتبارسنجی SSL مقصد</th>
                        <td>
                            <label>
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
}

// Initialize
GLPE_Plugin::getInstance();
