<?php
/**
 * Plugin Name: Cloud Portal & Web Viewer
 * Plugin URI: https://github.com/sitsaz/cloud-portal
 * Description: سامانه پیشرفته پرتال مرورگر و نمایشگر وب برای وردپرس با معماری کاملاً نامحسوس (Stealth)، کوکی‌جار RFC 6265، رمزگذاری آدرس‌ها و نوار ابزار Glype.
 * Version: 3.3.3
 * Author: sitsaz
 * License: MIT
 * Text Domain: cloud-portal
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('CLOUD_PORTAL_VERSION', '3.3.3');
define('CLOUD_PORTAL_DIR', plugin_dir_path(__FILE__));
define('CLOUD_PORTAL_URL', plugin_dir_url(__FILE__));

require_once CLOUD_PORTAL_DIR . 'includes/CookieJar.php';
require_once CLOUD_PORTAL_DIR . 'includes/StealthCipher.php';
require_once CLOUD_PORTAL_DIR . 'includes/ProxyEngine.php';

class CloudPortalWordPressPlugin {
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

        // Gateway Request Interception (Priority 1 to capture before theme outputs anything)
        add_action('init', [$this, 'handleGatewayRequest'], 1);

        // Shortcodes
        add_shortcode('cloud_portal', [$this, 'renderShortcode']);
        add_shortcode('web_viewer', [$this, 'renderShortcode']);
        add_shortcode('newglype_proxy', [$this, 'renderShortcode']); // Legacy alias

        // Admin Menu
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Creates a dedicated page on activation if it doesn't already exist.
     */
    public function activate() {
        $pageSlug = get_option('cloud_portal_slug', 'portal');
        $existingPage = get_page_by_path($pageSlug);
        if (!$existingPage) {
            $pageId = wp_insert_post([
                'post_title'     => 'پرتال مرورگر وب (Cloud Portal)',
                'post_name'      => $pageSlug,
                'post_content'   => '[cloud_portal]',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
            ]);
            if ($pageId && !is_wp_error($pageId)) {
                update_option('cloud_portal_page_id', $pageId);
            }
        }
    }

    public function deactivate() {
        // Safe deactivation
    }

    /**
     * Intercepts gateway requests (?_portal=1&b=...) before WordPress template rendering.
     */
    public function handleGatewayRequest() {
        $isPortal = isset($_GET['_portal']) || isset($_POST['_portal']) ||
                    isset($_GET['_view']) || isset($_POST['_view']) ||
                    isset($_GET['newglype_gateway']) || isset($_POST['newglype_gateway']);

        // Neutralize WordPress core trackback conflict: WordPress reserves 'tb' for trackbacks!
        // If 'tb' exists in query string, WordPress runs wp-trackback.php and returns XML error.
        if (isset($_GET['tb'])) {
            if (!isset($_GET['cp_tb'])) {
                $_GET['cp_tb'] = $_GET['tb'];
            }
            unset($_GET['tb']);
            unset($_REQUEST['tb']);
        }
        if (isset($_GET['st'])) {
            if (!isset($_GET['cp_st'])) {
                $_GET['cp_st'] = $_GET['st'];
            }
            unset($_GET['st']);
            unset($_REQUEST['st']);
        }

        if (!$isPortal) {
            return;
        }

        $gatewayUrl = add_query_arg(['_portal' => '1'], home_url('/'));
        $tempCookies = (isset($_GET['cp_temp']) && $_GET['cp_temp'] == '1') || (isset($_GET['temp']) && $_GET['temp'] == '1') || (isset($_POST['temp']) && $_POST['temp'] == '1');
        $engine = new StealthPortalEngine($gatewayUrl, $tempCookies);

        // 1. Handle Cookie Synchronization Beacon
        if (isset($_GET['action']) && $_GET['action'] === 'sync_cookie') {
            $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : (isset($_GET['url']) ? sanitize_text_field($_GET['url']) : '');
            $cookie = isset($_POST['cookie']) ? sanitize_text_field($_POST['cookie']) : (isset($_GET['cookie']) ? sanitize_text_field($_GET['cookie']) : '');

            if (!empty($url) && !empty($cookie)) {
                $engine->getCookieJar()->addCookieFromHeader($cookie, $url);
            }
            wp_send_json(['success' => true]);
            exit;
        }

        // 2. Extract and decode target URL
        $rawParam = isset($_GET['b']) ? trim($_GET['b']) : (isset($_POST['b']) ? trim($_POST['b']) : '');
        if (empty($rawParam)) {
            $rawParam = isset($_GET['url']) ? trim($_GET['url']) : (isset($_POST['url']) ? trim($_POST['url']) : '');
        }

        if (empty($rawParam)) {
            $pageUrl = home_url('/portal/');
            wp_redirect($pageUrl);
            exit;
        }

        $targetUrl = StealthCipher::decode($rawParam);

        if (!preg_match('#^https?://#i', $targetUrl)) {
            $targetUrl = 'https://' . $targetUrl;
        }

        // 3. Extract browsing flags (supports both cp_ prefixed and legacy flags)
        $options = [
            'removeScripts' => (isset($_GET['cp_rs']) && $_GET['cp_rs'] == '1') || (isset($_GET['rs']) && $_GET['rs'] == '1') || (get_option('cloud_portal_remove_scripts', '0') === '1'),
            'removeImages'  => (isset($_GET['cp_ri']) && $_GET['cp_ri'] == '1') || (isset($_GET['ri']) && $_GET['ri'] == '1') || (get_option('cloud_portal_remove_images', '0') === '1'),
            'stripTitle'    => (isset($_GET['cp_st']) && $_GET['cp_st'] == '1') || (isset($_GET['st']) && $_GET['st'] == '1') || (get_option('cloud_portal_strip_title', '0') === '1'),
            'showToolbar'   => (isset($_GET['cp_tb']) && $_GET['cp_tb'] == '1') || (isset($_GET['tb']) && $_GET['tb'] == '1') || (get_option('cloud_portal_show_toolbar', '1') === '1'),
            'encodeURL'     => (isset($_GET['cp_enc']) && $_GET['cp_enc'] == '1') || (isset($_GET['enc']) && $_GET['enc'] == '1') || (get_option('cloud_portal_encode_url', '1') === '1'),
        ];

        $method = $_SERVER['REQUEST_METHOD'];
        $postData = ($method === 'POST') ? file_get_contents('php://input') : null;

        $customHeaders = [];
        if (function_exists('getallheaders')) {
            foreach(getallheaders() as $name => $val) {
                $nameLower = strtolower($name);
                if (!in_array($nameLower, ['host', 'cookie', 'content-length', 'connection', 'accept-encoding', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'via', 'forwarded', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'x-cluster-client-ip'])) {
                    $customHeaders[$nameLower] = sanitize_text_field($val);
                }
            }
        } else {
            foreach($_SERVER as $key => $val) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    if (!in_array($name, ['host', 'cookie', 'content-length', 'connection', 'accept-encoding', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'via', 'forwarded', 'client-ip', 'true-client-ip', 'cf-connecting-ip', 'x-cluster-client-ip'])) {
                        $customHeaders[$name] = sanitize_text_field($val);
                    }
                }
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $customHeaders['content-type'] = sanitize_text_field($_SERVER['CONTENT_TYPE']);
        }

        try {
            $result = $engine->executeRequest($targetUrl, $method, $postData, $customHeaders);

            // Sanitize headers to allow framing & CORS
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
            } elseif (stripos($contentType, 'javascript') !== false || stripos($contentType, 'application/x-javascript') !== false) {
                $body = $engine->rewriteJs($body, $targetUrl, $options);
            }

            echo $body;
            exit;
        } catch (Exception $e) {
            status_header(502);
            header('Content-Type: text/html; charset=utf-8');
            $portalPageUrl = home_url('/portal/');
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
                    <a href="<?php echo esc_url($portalPageUrl); ?>">← بازگشت به برگه پرتال</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    /**
     * Renders the frontend portal interface via shortcode [cloud_portal].
     */
    public function renderShortcode($atts) {
        $gatewayUrl = add_query_arg(['_portal' => '1'], home_url('/'));
        $engine = new StealthPortalEngine($gatewayUrl);
        $cookieCount = count($engine->getCookieJar()->getAllCookies());

        $defaultEnc = get_option('cloud_portal_encode_url', '1') === '1';
        $defaultTb  = get_option('cloud_portal_show_toolbar', '1') === '1';
        $defaultSt  = get_option('cloud_portal_strip_title', '0') === '1';
        $defaultRs  = get_option('cloud_portal_remove_scripts', '0') === '1';
        $defaultRi  = get_option('cloud_portal_remove_images', '0') === '1';

        ob_start();
        ?>
        <div class="cloud-portal-widget" style="max-width: 800px; margin: 20px auto; font-family: Tahoma, system-ui, sans-serif; direction: rtl; text-align: right; background: #0f172a; border: 1px solid #1e293b; border-radius: 24px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); color: #f8fafc;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid #1e293b; padding-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px; background: #1e293b; border-radius: 12px; padding: 6px 10px;">🌐</span>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: bold; color: #fff;">پرتال مرورگر وب (Cloud Portal)</h3>
                        <p style="margin: 3px 0 0 0; font-size: 12px; color: #94a3b8;">سامانه نمایش نامحسوس و سریع صفحات وب</p>
                    </div>
                </div>
                <span style="background: rgba(37,99,235,0.15); color: #60a5fa; border: 1px solid rgba(37,99,235,0.3); padding: 4px 10px; border-radius: 9999px; font-size: 11px;">
                    سشن‌های فعال: <?php echo $cookieCount; ?>
                </span>
            </div>

            <form action="<?php echo esc_url(home_url('/')); ?>" method="GET" style="margin: 0;" onsubmit="
                var input = this.querySelector('input[name=b]');
                var encBox = this.querySelector('input[name=cp_enc]');
                if (input && input.value) {
                    var v = input.value.trim();
                    if (!v.match(/^https?:/i)) v = 'https://' + v;
                    if (encBox && encBox.checked) {
                        try {
                            var key = 'cp_vault_key';
                            var out = [];
                            for (var i = 0; i < v.length; i++) {
                                out.push(String.fromCharCode(v.charCodeAt(i) ^ key.charCodeAt(i % key.length)));
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
                <input type="hidden" name="_portal" value="1">
                
                <div style="display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
                    <input 
                        type="text" 
                        name="b" 
                        placeholder="https://example.com یا نشانی وبگاه..." 
                        required 
                        style="flex: 1; min-width: 250px; padding: 12px 14px; background: #1e293b; border: 1px solid #334155; border-radius: 12px; color: #fff; font-size: 13px; font-family: monospace; outline: none;"
                    >
                    <button 
                        type="submit" 
                        style="padding: 12px 24px; background: linear-gradient(to left, #2563eb, #4f46e5); color: #fff; border: none; border-radius: 12px; font-size: 13px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,0.3);"
                    >
                        شروع مرور وب ↵
                    </button>
                </div>

                <!-- Glype Feature Options -->
                <div style="background: #141e33; border: 1px solid #1e293b; border-radius: 14px; padding: 12px; margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: bold; color: #94a3b8; margin-bottom: 8px;">گزینه‌های پیشرفته (Glype Options):</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; font-size: 11px; color: #cbd5e1;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_enc" value="1" <?php checked($defaultEnc); ?>> کدگذاری آدرس (Encode URL)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_tb" value="1" <?php checked($defaultTb); ?>> نوار ابزار بالا (Mini Toolbar)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_st" value="1" <?php checked($defaultSt); ?>> پنهان‌سازی عنوان (Strip Title)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_rs" value="1" <?php checked($defaultRs); ?>> حذف اسکریپت‌ها (No Scripts)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_ri" value="1" <?php checked($defaultRi); ?>> عدم لود تصاویر (No Images)
                        </label>
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="cp_temp" value="1"> کوکی‌های موقت (Temp Cookies)
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
                        $enc = StealthCipher::encode($u);
                        $pUrl = add_query_arg(['_portal' => '1', 'b' => $enc, 'cp_enc' => '1', 'cp_tb' => '1'], home_url('/'));
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
     * Admin Menu and Settings in WordPress Dashboard
     */
    public function registerAdminMenu() {
        add_menu_page(
            'پرتال مرورگر ابری',
            'پرتال مرورگر ابری',
            'manage_options',
            'cloud-portal',
            [$this, 'renderAdminSettingsPage'],
            'dashicons-cloud',
            85
        );
    }

    public function registerSettings() {
        register_setting('cloud_portal_group', 'cloud_portal_encode_url');
        register_setting('cloud_portal_group', 'cloud_portal_show_toolbar');
        register_setting('cloud_portal_group', 'cloud_portal_strip_title');
        register_setting('cloud_portal_group', 'cloud_portal_remove_scripts');
        register_setting('cloud_portal_group', 'cloud_portal_remove_images');
    }

    public function renderAdminSettingsPage() {
        $portalPageUrl = home_url('/portal/');
        ?>
        <div class="wrap" style="direction: rtl; text-align: right; max-width: 900px;">
            <h1 style="font-size: 22px; font-weight: bold; margin-bottom: 20px;">
                تنظیمات پرتال مرورگر ابری (Cloud Portal & Web Viewer)
            </h1>

            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin-top: 0;">🌐 برگه اختصاصی پرتال در سایت شما</h3>
                <p>برگه اختصاصی پرتال شما فعال است و بدون هیچ ردی از پروکسی از آدرس زیر در دسترس می‌باشد:</p>
                <p>
                    <a href="<?php echo esc_url($portalPageUrl); ?>" target="_blank" style="font-family: monospace; font-size: 14px; font-weight: bold; background: #f0f6fc; padding: 6px 12px; border-radius: 6px; border: 1px solid #c8e1ff; color: #0969da; text-decoration: none;">
                        <?php echo esc_url($portalPageUrl); ?> ↗
                    </a>
                </p>
                <p style="font-size: 12px; color: #64748b;">
                    همچنین می‌توانید با قرار دادن شورت‌کد <code>[cloud_portal]</code> در هر برگه دلخواه یا در المنتور، فرم پرتال را نمایش دهید.
                </p>
            </div>

            <form method="post" action="options.php" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <?php settings_fields('cloud_portal_group'); ?>
                
                <h3 style="margin-top: 0;">تنظیمات پیش‌فرض مرورگر (Glype Engine Settings)</h3>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">کدگذاری خودکار آدرس‌ها (Encode URL)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloud_portal_encode_url" value="1" <?php checked(get_option('cloud_portal_encode_url', '1'), '1'); ?> />
                                فعال‌سازی رمزنگاری دوطرفه آدرس‌های URL (مخفی ماندن از تاریخچه و فیلترها)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">نوار ابزار بالا (Mini Toolbar)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloud_portal_show_toolbar" value="1" <?php checked(get_option('cloud_portal_show_toolbar', '1'), '1'); ?> />
                                نمایش نوار ناوبری شناور بالای صفحه با قابلیت جستجوی جدید و دکمه صفحه اصلی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">پنهان‌سازی عنوان تب (Strip Title)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloud_portal_strip_title" value="1" <?php checked(get_option('cloud_portal_strip_title', '0'), '1'); ?> />
                                حذف یا تغییر عنوان صفحات در تب مرورگر (جلوگیری از لو رفتن سایت در تاریخچه)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">حذف اسکریپت‌ها (Remove Scripts)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloud_portal_remove_scripts" value="1" <?php checked(get_option('cloud_portal_remove_scripts', '0'), '1'); ?> />
                                غیرفعال‌سازی کدهای جاوااسکریپت صفحات خارجی
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">حذف تصاویر (Remove Images)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloud_portal_remove_images" value="1" <?php checked(get_option('cloud_portal_remove_images', '0'), '1'); ?> />
                                عدم بارگذاری تصاویر جهت کاهش شدید مصرف پهنای باند سرور
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
CloudPortalWordPressPlugin::getInstance();
