<?php
/**
 * Plugin Name: GLPE Viewer — Remote Page Display
 * Plugin URI: https://github.com/Tobeseuss/wordpress-GLPE
 * Description: نمایش سریع و امن صفحات وب دلخواه داخل سایت شما با قابلیت بازنویسی خودکار پیوندها، سبک بارگذاری کم‌مصرف و نوار ناوبری شناور. مناسب هاست‌های اشتراکی و رایگان.
 * Version: 4.0.0
 * Author: Tobeseuss
 * License: MIT
 * Text Domain: glpe-viewer
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('GLPE_VERSION', '4.0.0');
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
     * Intercepts front-end display requests (?_glpe=1&l=...) before template rendering.
     */
    public function dispatchRequest() {
        $isView = isset($_GET['_glpe']) || isset($_POST['_glpe']);
        if (!$isView) {
            return;
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

    /**
     * Renders the front-end viewer form via the [glpe_viewer] shortcode.
     */
    public function renderShortcode($atts) {
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
        </div>
        <?php
    }
}

// Initialize
GLPE_Plugin::getInstance();
