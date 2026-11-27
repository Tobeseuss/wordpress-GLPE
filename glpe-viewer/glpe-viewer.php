<?php
/**
 * Plugin Name: GLPE Viewer — Remote Page Display
 * Plugin URI: https://github.com/Tobeseuss/wordpress-GLPE
 * Description: نمایش سریع و امن صفحات وب دلخواه داخل سایت شما با کدگذاری کامل تبادلات، بازنویسی خودکار پیوندها، سبک بارگذاری کم‌مصرف و نوار ناوبری شناور. مناسب هاست‌های اشتراکی و رایگان.
 * Version: 7.4.0
 * Author: Tobeseuss
 * License: MIT
 * Text Domain: glpe-viewer
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('GLPE_VERSION', '7.4.0');
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

require_once GLPE_DIR . 'includes/Diag.php';

// Armed BEFORE the engine includes: a truncated include from a broken update
// (free host, overloaded extraction) fatals during the require lines below —
// this catcher must already be armed to journal it and answer with the
// readable page instead of the CMS white screen. A plain function, not a
// class method, so it resolves even when the class below never loads.
if (!function_exists('glpe_catch_fatal')) {
    function glpe_catch_fatal() {
        $err = error_get_last();
        if (!is_array($err) || !isset($err['type'], $err['message'], $err['file'])) {
            return;
        }
        if (!GLPE_Diag::isFatalType($err['type'])) {
            return; // warnings/notices: not ours to handle
        }
        // Only fatals that happened inside this plugin's own files.
        if (strpos((string)$err['file'], (string)GLPE_DIR) === false) {
            return;
        }
        GLPE_Diag::appendFatal($err['type'], $err['message'], $err['file'], isset($err['line']) ? (int)$err['line'] : 0);

        $summary = GLPE_Diag::fatalTypeName($err['type']) . ': ' . (string)$err['message']
            . ' — ' . basename((string)$err['file']) . ':' . (isset($err['line']) ? (int)$err['line'] : 0);
        if (!headers_sent()) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
        }
        echo '<!doctype html><html lang="fa"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>خطای داخلی نمایشگر</title></head>'
            . '<body style="font-family:Tahoma,Vazir,sans-serif;direction:rtl;text-align:right;background:#f8fafc;margin:0;padding:40px 16px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #fecaca;border-radius:12px;padding:24px;">'
            . '<h1 style="font-size:17px;color:#991b1b;margin:0 0 10px;">⚠ نمایشگر صفحات وب دچار خطای داخلی شد</h1>'
            . '<p style="font-size:13.5px;line-height:2;color:#334155;margin:0 0 8px;">سایت شما سالم است؛ فقط این نمایشگر متوقف شد. جزئیات خطا در پیشخوان (صفحهٔ «هستهٔ ارتباطی چندگرهی» ← پنل «آخرین خطاهای ثبت‌شده») ذخیره شده تا بررسی و ارسال آن آسان باشد.</p>'
            . '<p style="font-size:12.5px;line-height:1.9;color:#64748b;margin:0 0 12px;">اگر همین خطا تکرار می‌شود، افزونه را با فایل ZIP نسخهٔ جدید دوباره نصب کنید (به‌روزرسانی ناقص روی هاست شلوغ رایج است).</p>'
            . '<p style="font-size:11.5px;direction:ltr;text-align:left;background:#fef2f2;border:1px dashed #fca5a5;border-radius:8px;padding:8px 10px;color:#7f1d1d;margin:0;word-break:break-all;">'
            . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div></body></html>';
        exit;
    }
}
register_shutdown_function('glpe_catch_fatal');

require_once GLPE_DIR . 'includes/Cookies.php';
require_once GLPE_DIR . 'includes/Codec.php';
require_once GLPE_DIR . 'includes/Engine.php';
require_once GLPE_DIR . 'includes/Access.php';
require_once GLPE_DIR . 'includes/Hub.php';
// Per-site special solutions (v6.5.0) — one adapter file per destination
// that needs tuning beyond the general engine; a missing directory or a
// malformed pack only removes the tuning, never the display path.
if (file_exists(GLPE_DIR . 'includes/SitePacks.php')) {
    require_once GLPE_DIR . 'includes/SitePacks.php';
}
// Virtual browser (v7.0.0): whole-destination rendering inside a temporary
// host-side environment, streamed to the visitor as picture frames only.
// A missing file just removes the virtual mode, never the display path.
if (file_exists(GLPE_DIR . 'includes/VirtualBrowser.php')) {
    require_once GLPE_DIR . 'includes/VirtualBrowser.php';
}

// Virtual browser — sandbox engine (pure PHP, made for free hosting panels
// where no server software can exist). Guarded include: a missing file
// merely disables the sandbox engine.
if (file_exists(GLPE_DIR . 'includes/VbSandbox.php')) {
    require_once GLPE_DIR . 'includes/VbSandbox.php';
}
// Virtual-browser file downloads (v7.4.0): guarded include like its
// siblings — a missing file only disables the download module.
if (file_exists(GLPE_DIR . 'includes/VbDl.php')) {
    require_once GLPE_DIR . 'includes/VbDl.php';
}
// Remote-diagnostics API (v6.3.0): optional by design — a missing file only
// removes the diag card + endpoint, never the site.
if (file_exists(GLPE_DIR . 'includes/DiagApi.php')) {
    require_once GLPE_DIR . 'includes/DiagApi.php';
}
// Subresource artifact cache (v7.3.0): optional — a missing file only means
// every subresource exchange is fetched live (v7.2.0 behavior).
if (file_exists(GLPE_DIR . 'includes/ResCache.php')) {
    require_once GLPE_DIR . 'includes/ResCache.php';
}
// Host evaluation is an admin-only surface — never loaded on front-end
// requests (one less file that can break the visitor path).
if (is_admin()) {
    require_once GLPE_DIR . 'includes/HubTune.php';
}

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

        // Install-integrity gate: an update extracted partially on an
        // overloaded free host must degrade to a notice — never to a fatal.
        // With required files missing the plugin registers NOTHING else and
        // the site keeps running normally.
        $missing = GLPE_Diag::missingRequired();
        if (!empty($missing)) {
            add_action('admin_notices', function () use ($missing) {
                echo '<div class="notice notice-error"><p style="direction:rtl;text-align:right;">'
                    . '<strong>نصب افزونهٔ نمایشگر صفحات وب ناقص است.</strong> فایل‌های زیر یافت نشد: '
                    . '<code style="direction:ltr;">' . esc_html(implode(', ', $missing)) . '</code>. '
                    . 'احتمالاً به‌روزرسانی قبلی روی هاست شلوغ ناقص مانده است — پوشهٔ افزونه را از مدیریت فایل هاست حذف و فایل ZIP نسخهٔ جدید را از «افزونه‌ها ← بارگذاری» دوباره نصب کنید. تا آن زمان افزونه غیرفعال است تا سایت شما سالم بماند.'
                    . '</p></div>';
            });
            return;
        }

        // PHP session is opened very early, before any output can begin.
        add_action('init', [$this, 'startSession'], 0);

        // Multi-node relay core (v6.0.0): public endpoint driven by the
        // bundled Python core — token lives inside the obfuscated envelope,
        // so no WordPress session is required. Runs before the viewer
        // dispatcher; both exit for their own requests only.
        add_action('init', ['GLPE_Hub', 'maybeHandle'], 1);

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

        // Relay core management (v6.0.0) + host tuning + config editor (v6.1.0)
        add_action('admin_post_glpe_hub_regen', ['GLPE_Hub_Admin', 'handleRegen']);
        add_action('admin_post_glpe_hub_clear', ['GLPE_Hub_Admin', 'handleClearLog']);
        add_action('admin_post_glpe_hub_file', ['GLPE_Hub_Admin', 'handleServeFile']);
        add_action('admin_post_glpe_hub_config', ['GLPE_Hub_Admin', 'handleServeConfig']);
        add_action('admin_post_glpe_hub_zip', ['GLPE_Hub_Admin', 'handleServeZip']);
        add_action('admin_post_glpe_hub_savecfg', ['GLPE_Hub_Admin', 'handleSaveCfg']);
        add_action('admin_post_glpe_hub_clearfatal', ['GLPE_Hub_Admin', 'handleClearFatal']);
        add_action('admin_post_glpe_hub_fetch_apply', ['GLPE_Hub_Admin', 'handleFetchApply']);
        add_action('wp_ajax_glpe_hub_vitals', ['GLPE_Hub_Admin', 'ajaxVitals']);
        add_action('wp_ajax_glpe_hub_tune', ['GLPE_Hub_Admin', 'ajaxTune']);

        // Virtual browser service control (v7.0.0).
        if (class_exists('GLPE_VirtualBrowser')) {
            add_action('admin_post_glpe_vb_start', ['GLPE_VirtualBrowser', 'handleStart']);
        }
        if (class_exists('GLPE_ResCache')) {
            add_action('admin_post_glpe_res_purge', ['GLPE_ResCache', 'handlePurge']);
        }
        // Virtual-browser file downloads (v7.4.0).
        if (class_exists('GLPE_VbDl')) {
            add_action('admin_post_glpe_dl_purge', ['GLPE_VbDl', 'handlePurge']);
        }
        // Host test for the virtual-browser settings page (v7.4.0):
        // measures the real hosting limits and auto-fills the recommended
        // values into the form.
        add_action('admin_post_glpe_vb_hosttest', [$this, 'handleVbHostTest']);
        // Path-preserving gateway entry self-test (v7.2.0).
        add_action('admin_post_glpe_path_probe', [$this, 'handlePathProbe']);

        // DNS hardening: every WP-HTTP request through the cURL transport gets
        // resolve pins staged by GLPE_Dns::planHop plus tuned connect budgets.
        add_filter('http_api_curl', ['GLPE_Dns', 'onHttpApiCurl'], 10, 3);

        // Keep the fast-endpoint handoff file present (cheap stat on admin hit).
        add_action('admin_init', ['GLPE_Hub', 'maybeSyncNodeStore']);
        // Enable/disable must reach the fast endpoint instantly — the endpoint
        // reads its enabled flag from the handoff file, not from the DB.
        add_action('update_option_glpe_hub_enabled', ['GLPE_Hub', 'syncNodeStore'], 10, 0);

        // Remote-diagnostics API (v6.3.0): key management, card and handoff
        // sync. The endpoint itself (diag-endpoint.php) is fully standalone.
        if (class_exists('GLPE_Diag_Api')) {
            add_action('admin_post_glpe_diag_gen', ['GLPE_Diag_Api', 'handleGen']);
            add_action('admin_post_glpe_diag_off', ['GLPE_Diag_Api', 'handleOff']);
            add_action('admin_post_glpe_diag_self', ['GLPE_Diag_Api', 'handleSelfTest']);
            add_action('admin_init', ['GLPE_Diag_Api', 'maybeSyncStore']);
            // Key rotations and disable/enable must reach the standalone
            // endpoint instantly (it never boots the CMS for authentication).
            add_action('update_option_glpe_diag_enabled', ['GLPE_Diag_Api', 'syncStore'], 10, 0);
            add_action('update_option_glpe_diag_key', ['GLPE_Diag_Api', 'syncStore'], 10, 0);
        }

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

        // Publish the fast-endpoint handoff file (token + uploads root).
        GLPE_Hub::syncNodeStore();

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
        // Deactivating the plugin withdraws the remote-diagnostics handoff
        // immediately — the standalone endpoint must stop answering with the
        // old key even though its file still exists until deletion.
        if (class_exists('GLPE_Diag_Api')) {
            GLPE_Diag_Api::disable();
        }
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

        // 0z. Path-entry self-check marker: a signed-out zero-data reply
        //     that answers BEFORE the access gate so the admin self-test can
        //     verify the front controller routes the reserved /_glpe/...
        //     namespace into WordPress. It reveals only that the plugin is
        //     present (the viewer page already does that publicly).
        if (!isset($_GET['l']) && !isset($_POST['l']) && isset($_GET['mode']) && $_GET['mode'] === 'gwprobe') {
            status_header(200);
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo 'glpe-path-ok';
            exit;
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
        // Virtual-browser sandbox requests carry vs=1: their destination
        // records live in a jar of their own (GLPE_Vb_Sandbox::JAR_SUFFIX)
        // — separate from the visitor's normal records, wiped when the
        // sandbox session closes.
        $vsRequest = (isset($_GET['vs']) && $_GET['vs'] === '1');

        // 0b. Path-entry evidence: a request that reached the dispatcher
        //     through a /_glpe/... path proves the front controller routes
        //     the reserved namespace — remember it once so subresource
        //     references can adopt the path-preserving entry.
        $reqPath = isset($_SERVER['REQUEST_URI']) ? (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        if ($reqPath !== '' && strpos($reqPath, '/_glpe') === 0 && get_option('glpe_path_entry', '') !== 'yes') {
            update_option('glpe_path_entry', 'yes', false);
        }
        $pathEntry = $this->pathEntryAvailable();

        $engine = new GLPE_Engine(
            $viewScript,
            $tempCookies,
            $vsRequest && class_exists('GLPE_Vb_Sandbox') ? GLPE_Vb_Sandbox::JAR_SUFFIX : '',
            $pathEntry
        );

        // 1. Extract the display link first — control modes below only apply
        //    to gateway requests that carry no display link, so displayed
        //    sites using "mode" as their own field name keep working.
        $rawParam = isset($_GET['l']) ? trim($_GET['l']) : (isset($_POST['l']) ? trim($_POST['l']) : '');

        // 2. Client-side data synchronization beacon. The destination
        //    reference and the record line arrive as wrapped tokens — they
        //    are decoded here; legacy readable values still accepted.
        if ($rawParam === '' && isset($_GET['mode']) && $_GET['mode'] === 'sync') {
            $rawUrl    = isset($_POST['u'])    ? trim((string)wp_unslash($_POST['u']))    : (isset($_GET['u'])    ? trim((string)$_GET['u'])    : '');
            $rawRecord = isset($_POST['c'])    ? trim((string)wp_unslash($_POST['c']))    : (isset($_GET['c'])    ? trim((string)$_GET['c'])    : '');
            $url    = GLPE_Codec::decodeData($rawUrl);
            $cookie = GLPE_Codec::decodeData($rawRecord);
            if ($url === '' && preg_match('#^https?://#i', $rawUrl))       $url = $rawUrl;       // legacy beacon
            if ($cookie === '' && $rawRecord !== '' && strpos($rawRecord, '=') !== false) $cookie = $rawRecord; // legacy beacon

            if ($url !== '' && $cookie !== '') {
                $engine->getCookies()->addCookieFromHeader($cookie, $url);
            }
            wp_send_json(['ok' => true]);
            exit;
        }

        // 2a. Virtual-browser sandbox endpoints (v7.1.0). Session lifecycle
        //     plus the host-first input round trip: the visitor's gestures
        //     arrive as one opaque token, are normalized and journaled by
        //     THIS host, and only the host's echo drives the sandbox. The
        //     reply payload is an opaque token again. v7.4.0: the download
        //     endpoints (dlopen/dlchunk) joined the family and receive the
        //     gateway engine of this very request so their upstream fetches
        //     ride the same jar, identity and relay pipeline.
        if ($rawParam === '' && isset($_GET['mode']) && preg_match('/^vb_(open|in|nav|beat|close|dlopen|dlchunk)$/', (string)$_GET['mode'])) {
            if (class_exists('GLPE_Vb_Sandbox')) {
                GLPE_Vb_Sandbox::handleMode((string)$_GET['mode'], $engine);
            }
            exit;
        }

        // 2b. Stored-file serving (v7.4.0): a short-lived opaque token
        //     (issued by the download module when a host-side stored file
        //     completed) streams the stored bytes from disk with the
        //     destination's own filename. Nothing about the destination
        //     travels in the clear — the token is opaque like every other
        //     gateway reference.
        if ($rawParam === '' && isset($_GET['dlt']) && class_exists('GLPE_VbDl')) {
            GLPE_VbDl::serveStored($_GET['dlt']);
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

        // 4. GET submissions carry their fields in the gateway query string.
        //    With scripting enabled the client wraps the entire submission
        //    (endpoint + fields) into the token, so this branch mostly serves
        //    the scripting-disabled fallback. Forward every non-reserved
        //    field to the destination as part of its query.
        if ($method === 'GET' && isset($_SERVER['QUERY_STRING'])) {
            $reserved = ['_glpe', 'l', 'd', 'ns', 'ni', 'nt', 'nb', 'ec', 'mb', 'tp', 'vs', 'dl', 'dlt', 'mode'];
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
        //    Wrapping of destination references is unconditional — it is not
        //    a flag and never was allowed to be switched off.
        $options = [
            'removeScripts' => $this->viewFlag('ns', 'glpe_no_scripts', '0'),
            'removeImages'  => $this->viewFlag('ni', 'glpe_no_images', '0'),
            'stripTitle'    => $this->viewFlag('nt', 'glpe_blank_title', '0'),
            'showToolbar'   => $this->viewFlag('nb', 'glpe_toolbar', '1'),
            'mobileView'    => $this->viewFlag('mb', 'glpe_mobile_view', '0'),
            'tempSession'   => $tempCookies,
        ];

        $postData = ($method === 'POST') ? file_get_contents('php://input') : null;
        $postContentType = isset($_SERVER['CONTENT_TYPE']) ? str_replace(["\r", "\n", "\0"], '', trim((string)$_SERVER['CONTENT_TYPE'])) : '';

        // 5a. Wrapped request bodies: script-enabled POST submissions arrive
        //     as a single d=<token> field whose decoded form is a JSON
        //     document with the original content type and body. Unwrap it so
        //     the destination receives exactly what the page would have sent.
        if ($method === 'POST' && is_string($postData) && $postData !== '') {
            $unwrapped = GLPE_Codec::unwrapRequestBody($postData);
            if (is_array($unwrapped)) {
                $postData = $unwrapped['body'];
                $postContentType = $unwrapped['ct'];
            }
        }

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
        // The effective content type: the original request's value, or the
        // decoded envelope's value when the body was wrapped (5a above).
        if ($postContentType !== '') {
            $customHeaders['content-type'] = $postContentType;
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

        // Virtual-browser sandbox display: the destination renders inside a
        // locked temporary environment with a host-consistent identity —
        // the profile below rides every request (canonical agent/locale,
        // visitor's client hints and location signals removed) and the
        // in-page companion mirrors the same values inside the sandbox, so
        // the destination never measures the visitor's own signals. No
        // floating toolbar: the virtual surface has its own controls.
        if ($vsRequest) {
            $options['showToolbar'] = false;
            if (class_exists('GLPE_Vb_Sandbox')) {
                $options['vsProfile'] = GLPE_Vb_Sandbox::profile();
                GLPE_Vb_Sandbox::applyRequestProfile($customHeaders);
            }
        }

        // Site pack for this destination — a special solution ships with the
        // plugin as a per-site adapter (request-header fills, client-side
        // helper, document post-processing).
        $sitePack = class_exists('GLPE_SitePacks') ? GLPE_SitePacks::forUrl($targetUrl) : null;
        if ($sitePack) {
            GLPE_SitePacks::applyRequestHeaders($customHeaders, $sitePack);
            if (!empty($sitePack['pack_js']) && is_string($sitePack['pack_js'])) {
                $options['packJs'] = $sitePack['pack_js'];
            }
            if (!empty($sitePack['doc_filter']) && is_callable($sitePack['doc_filter'])) {
                $options['docFilter'] = $sitePack['doc_filter'];
            }
        }

        try {
            // 6. Download tunnel (v7.4.0): a request the client marked as a
            //    file download (dl=1 — download-shaped links, the virtual
            //    browser's download flow) skips the buffered pipeline
            //    entirely and streams the destination's own response to
            //    the visitor in chunks — a big file never lands in PHP
            //    memory. Range requests (206) are relayed as-is, and the
            //    destination's own Content-Disposition/Content-Length
            //    reach the visitor's save dialog.
            if ($method === 'GET' && isset($_GET['dl']) && $_GET['dl'] === '1') {
                if ($engine->streamMediaRequest($targetUrl, $customHeaders)) {
                    exit;
                }
                // streaming unavailable on this host → fall through to the
                // buffered path (small files still download fine that way).
            }

            // Media targets (video/audio bytes) stream straight through:
            // buffering a full video in PHP memory would exhaust the server
            // and stall playback. Range requests (206) are relayed as-is.
            if ($method === 'GET' && preg_match('#videoplayback|googlevideo\.com|\.m3u8(\?|$)|\.(mp4|webm|m4a|mp3|ts|ogg|flv)(\?|$)#i', $targetUrl)) {
                if ($engine->streamMediaRequest($targetUrl, $customHeaders)) {
                    exit;
                }
                // streaming unavailable → fall through to the buffered path
            }

            // Application exchanges (fetch/XHR — every request whose
            // sec-fetch-dest is not a document/frame, plus explicit
            // event-stream accepts) stream their response body straight
            // through: long-running chat-style endpoints outlast any sane
            // buffered timeout, and event streams only make sense when the
            // chunks arrive live. Bodies that need server-side rewriting
            // (HTML fragments, sheets, script files) abort the stream and
            // land in the buffered path below.
            $fetchDest = isset($customHeaders['sec-fetch-dest']) ? strtolower(trim((string)$customHeaders['sec-fetch-dest'])) : '';
            $acceptHdr = isset($customHeaders['accept']) ? strtolower((string)$customHeaders['accept']) : '';
            $isAppExchange = ($fetchDest !== '' && !in_array($fetchDest, ['document', 'iframe', 'frame'], true))
                || strpos($acceptHdr, 'text/event-stream') !== false;
            if ($isAppExchange && $engine->streamApiRequest($targetUrl, $method, $postData, $customHeaders)) {
                exit;
            }

            // Subresource artifact cache (v7.3.0): the buffered path is where
            // every subresource that needs rewriting (or is simply buffered)
            // lands after the streamers above declined it. Serve a cached
            // final artifact when one exists — no upstream fetch, no
            // rewriting pass, just the stored bytes. The key folds in every
            // input that shapes both the fetch and the rewrite.
            $resKey = null;
            $resHit = null;
            if ($method === 'GET' && class_exists('GLPE_ResCache') && GLPE_ResCache::enabled()) {
                $resKey = GLPE_ResCache::keyFor($targetUrl, $customHeaders, $options);
                $resHit = GLPE_ResCache::get($resKey);
            }

            if ($resHit !== null) {
                $result = $resHit;
            } else {
                $result = $engine->executeRequest($targetUrl, $method, $postData, $customHeaders);
            }

            // Fresh content on every request; allow embedding from any origin.
            header('Cache-Control: no-store, max-age=0');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: *');
            header('Access-Control-Expose-Headers: *');

            if (function_exists('header_remove')) {
                header_remove('X-Frame-Options');
                header_remove('Content-Security-Policy');
                header_remove('X-Powered-By');
                header_remove('Cross-Origin-Opener-Policy');
                header_remove('Cross-Origin-Embedder-Policy');
                header_remove('Cross-Origin-Resource-Policy');
                header_remove('Permissions-Policy');
                header_remove('Clear-Site-Data');
                header_remove('Report-To');
                header_remove('NEL');
            }

            // Relay the destination's own response headers (API tokens,
            // challenge headers, pagination links, Retry-After — filtered
            // through GLPE_Engine::relayableHeaderValues). Applications read
            // these through response.headers.get(); a silently missing header
            // breaks header-driven flows without any console error.
            $relayHeaders = (isset($result['headers']) && is_array($result['headers']))
                ? GLPE_Engine::relayableHeaderValues($result['headers'])
                : [];
            foreach ($relayHeaders as $hName => $hValue) {
                header($hName . ': ' . $hValue, false);
            }

            $contentType = $result['contentType'];
            header('Content-Type: ' . $contentType);
            http_response_code($result['status']);

            $body = $result['body'];

            // A cached artifact is the FINAL serialized body — the rewriting
            // passes already ran before it was stored, so replaying them
            // would double-wrap every reference. Fresh fetches keep the
            // full pipeline below.
            $fromCache = !empty($result['fromCache']);

            // Rewrite against the address that actually served the content —
            // a redirect chain can land somewhere else than the request began,
            // and relative references must follow the final landing page.
            $rewriteBase = (isset($result['finalUrl']) && is_string($result['finalUrl']) && $result['finalUrl'] !== '')
                ? $result['finalUrl']
                : $targetUrl;

            if (!$fromCache && stripos($contentType, 'text/html') !== false) {
                // Megabyte-class documents (modern search results and app
                // shells) can exceed the default PCRE backtrack budget inside
                // the rewriting passes — raise it for this request (silently:
                // hosts may lock it) before the pipeline starts.
                @ini_set('pcre.backtrack_limit', '100000000');
                @ini_set('pcre.recursion_limit', '1000000');
                $body = $engine->rewriteHtml($body, $rewriteBase, $options);

                // Region-restricted service: some destinations offer their
                // service only to a set of countries and decide by the
                // country of the relay exit — the hosting server behind the
                // viewer (the visitor's own address never reaches the
                // destination, every exchange rides the encrypted gateway).
                // Surface that distinction clearly instead of letting a bare
                // policy page look like a viewer defect. Packs that carry
                // their own region layers ('region_handling') are skipped
                // here so one page never stacks two notices.
                $packHandlesRegion = is_array($sitePack) && !empty($sitePack['region_handling']);
                if (!$packHandlesRegion && preg_match('#not supported in your country|unsupported country|not available in your (country|region)#i', $body)) {
                    $notice = '<div id="glpe-region-note" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483646;'
                        . 'background:#78350f;color:#fde68a;font:12px/1.9 Tahoma,sans-serif;padding:10px 16px;text-align:center;'
                        . 'direction:rtl;border-top:1px solid #f59e0b;">'
                        . '⛔ این پیام از سوی خودِ وب‌سایت مقصد است، نه نقص نمایشگر: '
                        . 'سرویس فقط به برخی کشورها ارائه می‌شود و تصمیم بر اساس کشورِ آی‌پیِ «خروجی رله» (میزبان نمایشگر) است — '
                        . 'نه مکان شما؛ نشانی شما هرگز به مقصد نمی‌رسد. '
                        . 'راه حل: میزبانی نمایشگر روی سروری در یکی از کشورهای پشتیبانی‌شدهٔ آن سایت.'
                        . '</div>';
                    if (stripos($body, '</body>') !== false) {
                        $body = preg_replace('#</body>#i', $notice . '</body>', $body, 1);
                    } else {
                        $body .= $notice;
                    }
                }
                // Destination-side anti-abuse walls (search captchas, CDN
                // challenge pages) look like a dead viewer to visitors —
                // "nothing happens". Show a clear notice that the block
                // comes from the destination site, not from the viewer.
                elseif (preg_match('#unusual traffic|/sorry/index|cf-chl|Just a moment|g-recaptcha|Attention Required#i', $body)) {
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
            } elseif (stripos($contentType, 'manifest') !== false) {
                // PWA manifests are consumed by the browser itself (never
                // through a script hook), so icon/start_url references must
                // be wrapped server-side or they load around the gateway.
                $body = $engine->rewriteJs($body, $rewriteBase, $options);
            }

            // Store the final artifact for the subresource class (v7.3.0).
            // The full verdict — status 200, static content classes only,
            // no Set-Cookie, upstream freshness respected — lives inside the
            // cache module, so a refusal here is silent and cost-free.
            if ($resKey !== null && $resHit === null) {
                GLPE_ResCache::maybeStore($resKey, $result, $body);
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
                    <p style="font-size: 12px; color: #94a3b8;">به دلیل اصل مخفی‌سازی کامل، نشانی مقصد در این صفحه نمایش داده نمی‌شود.</p>
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
     * Whether the path-preserving gateway entry (/_glpe/<destination path>)
     * is servable on this host: the web server's front controller must
     * route non-file paths into WordPress. Resolution order: an explicit
     * filter, the stored probe verdict, then the permalink structure as a
     * passive heuristic (pretty permalinks imply a working front
     * controller; PATHINFO-style structures do not).
     */
    private function pathEntryAvailable() {
        $forced = apply_filters('glpe_path_entry', null);
        if ($forced !== null) {
            return (bool)$forced;
        }
        $opt = get_option('glpe_path_entry', '');
        if ($opt === 'yes') {
            return true;
        }
        if ($opt === 'no') {
            return false;
        }
        $struct = (string)get_option('permalink_structure', '');
        if ($struct !== '' && strpos($struct, '/index.php/') !== 0) {
            return true;
        }
        return false;
    }

    /**
     * Admin self-test for the path-preserving entry: the host fetches its
     * own /_glpe/ probe URL once and stores the verdict, so subresource
     * references know whether the front controller routes the namespace.
     * The browser identity rides the probe (edge layers reject the CMS's
     * default identity before PHP runs).
     */
    public function handlePathProbe() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted.', 'glpe-viewer'));
        }
        check_admin_referer('glpe_path_probe');

        $probeUrl = add_query_arg(
            ['_glpe' => '1', 'mode' => 'gwprobe'],
            home_url('/_glpe-probe/route-check')
        );
        $ua = defined('HubTune::BROWSER_UA') ? constant('HubTune::BROWSER_UA') : '';
        if ($ua === '') {
            $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        }
        $args = [
            'timeout'    => 8,
            'redirection' => 0,
            'user-agent' => $ua,
            'headers'    => ['Accept' => 'text/plain, */*;q=0.8'],
        ];
        $response = wp_remote_get($probeUrl, $args);
        $ok = !is_wp_error($response)
            && (int)wp_remote_retrieve_response_code($response) === 200
            && trim((string)wp_remote_retrieve_body($response)) === 'glpe-path-ok';
        update_option('glpe_path_entry', $ok ? 'yes' : 'no', false);

        wp_safe_redirect(add_query_arg(
            ['page' => 'glpe-viewer', 'cp_msg' => $ok ? 'path-ok' : 'path-no'],
            admin_url('admin.php')
        ));
        exit;
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

        $defaultNb = get_option('glpe_toolbar', '1') === '1';
        $defaultNt = get_option('glpe_blank_title', '0') === '1';
        $defaultNs = get_option('glpe_no_scripts', '0') === '1';
        $defaultNi = get_option('glpe_no_images', '0') === '1';
        // esc_js() is mandatory here: the handler lives inside a double-quoted
        // HTML attribute, and json_encode()'s surrounding quotes would truncate
        // the attribute mid-handler (silently disabling the wrapping).
        $secretJs = esc_js(GLPE_SECRET);

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
                var key = '<?php echo $secretJs; ?>';
                if (input && input.value) {
                    var v = input.value.trim();
                    if (!v.match(/^https?:/i)) v = 'https://' + v;
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

                <div style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #94a3b8; flex-wrap: wrap;">
                    <span>سایت‌های نمونه:</span>
                    <?php
                    $presets = [
                        'DuckDuckGo' => 'https://html.duckduckgo.com/html/',
                        'Wikipedia'  => 'https://en.m.wikipedia.org/',
                        'Hacker News'=> 'https://news.ycombinator.com/',
                    ];
                    foreach ($presets as $name => $u):
                        $enc = GLPE_Codec::encode($u);
                        $pUrl = add_query_arg(['_glpe' => '1', 'l' => $enc, 'nb' => '1'], home_url('/'));
                    ?>
                        <a href="<?php echo esc_url($pUrl); ?>" style="color: #60a5fa; text-decoration: none; background: #1e293b; padding: 3px 8px; border-radius: 6px;">
                            <?php echo esc_html($name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php
                // Destinations with a site pack — the plugin ships a special
                // solution for each of these (پلاگین داخلی سایت).
                $siteSamples = class_exists('GLPE_SitePacks') ? GLPE_SitePacks::samples() : [];
                if (!empty($siteSamples)): ?>
                <div style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #94a3b8; flex-wrap: wrap; margin-top: 8px;">
                    <span>سایت‌های نمونه با راه‌حل ویژه:</span>
                    <?php foreach ($siteSamples as $sp):
                        $encS = GLPE_Codec::encode($sp['sample']);
                        $spUrl = add_query_arg(['_glpe' => '1', 'l' => $encS, 'nb' => '1'], home_url('/'));
                    ?>
                        <a href="<?php echo esc_url($spUrl); ?>" title="<?php echo esc_attr($sp['note']); ?>"
                           style="color: #6ee7b7; text-decoration: none; background: rgba(5,150,105,0.10); border: 1px solid #059669; padding: 3px 8px; border-radius: 6px;">
                            ⚡ <?php echo esc_html($sp['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </form>

            <?php
            // Virtual browser (v7.0.0 / v7.1.0): the destination is handled
            // inside a temporary isolated environment on this host. Two
            // engines serve it — the host-side renderer (full server-side
            // painting, picture-only channel; needs runtime + renderer binary
            // on the server) and the sandbox engine (pure PHP, runs on free
            // hosting panels where nothing can be installed on the server:
            // every exchange and every input gesture still rides this host).
            $vbEngine = class_exists('GLPE_Vb_Sandbox')
                ? GLPE_Vb_Sandbox::engineChoice()
                : (class_exists('GLPE_VirtualBrowser') ? 'service' : 'sandbox');
            if ($vbEngine === 'service' && class_exists('GLPE_VirtualBrowser')):
                GLPE_VirtualBrowser::syncKeyFile();
                $vbReady = GLPE_VirtualBrowser::ensureService();
            ?>
            <div id="glpe-vb-launch" style="background: #141e33; border: 1px solid #1e293b; border-radius: 14px; padding: 14px; margin-top: 16px;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px;">
                    <span style="font-size: 20px; background: #1e293b; border-radius: 12px; padding: 4px 8px;">🖥️</span>
                    <b style="color: #fff; font-size: 13px;">مرورگر مجازی</b>
                    <span style="background: rgba(110,231,183,0.12); color: #6ee7b7; border: 1px solid #059669; padding: 3px 10px; border-radius: 9999px; font-size: 11px;">رندر کامل روی میزبان — مانند ریموت دسکتاپ</span>
                    <span style="font-size: 11px; color: <?php echo $vbReady ? '#6ee7b7' : '#fbbf24'; ?>;">
                        وضعیت: <?php echo $vbReady ? 'فعال' : 'در دسترس نیست'; ?>
                    </span>
                </div>
                <p style="font-size: 11px; line-height: 1.9; color: #94a3b8; margin: 0 0 10px;">
                    تمام وب‌سایت و محتوای مقصد فقط روی همین میزبان بارگذاری، اجرا و رندر می‌شود؛ به دستگاه شما تنها تصویر فریم‌ها می‌رسد و حرکت موس و کیبورد شما ابتدا به همین میزبان ارسال و در همان محیط مجازی بازپخش می‌شود. هیچ کد، محتوا یا کوکی‌ای از سایت مقصد وارد مرورگرِ شما نمی‌شود.
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <input id="glpe-vb-url" type="text" dir="ltr" value="https://example.com" spellcheck="false"
                           style="flex: 1; min-width: 220px; padding: 11px 14px; background: #1e293b; border: 1px solid #334155; border-radius: 12px; color: #fff; font-size: 12px; font-family: monospace; outline: none; text-align: left;">
                    <button type="button" class="glpe-vb-open"
                            style="padding: 11px 22px; background: linear-gradient(to left, #059669, #10b981); color: #fff; border: none; border-radius: 12px; font-size: 12px; font-weight: bold; cursor: pointer; font-family: inherit;">
                        🖥️ شروع مرورگر مجازی
                    </button>
                </div>
                <?php if (!$vbReady): ?>
                <p style="font-size: 11px; color: #fbbf24; margin: 8px 0 0; line-height: 1.8;">
                    سرویس مرورگر مجازی روی این میزبان در دسترس نیست (نیاز به زمان اجرای Bun و یک هستهٔ رندر نصب‌شده روی سرور دارد). از مدیر هاست بخواهید از بخش «مرورگر مجازی» در تنظیمات افزونه راه‌اندازی‌اش کند.
                </p>
                <?php endif; ?>
            </div>
            <script>window.GLPE_VB = <?php echo wp_json_encode(GLPE_VirtualBrowser::clientConfig()); ?>;</script>
            <script src="<?php echo esc_url(GLPE_URL . 'includes/vb-client.js'); ?>?v=<?php echo esc_attr(GLPE_VERSION); ?>" defer></script>
            <?php
            else:
                // Sandbox engine — the free-hosting virtual browser. All
                // exchanges and all input gestures ride this host; the
                // destination renders inside a locked temporary environment.
                $vbsCfg = class_exists('GLPE_Vb_Sandbox') ? GLPE_Vb_Sandbox::clientConfig() : [];
            ?>
            <div id="glpe-vbs-launch" style="background: #141e33; border: 1px solid #1e293b; border-radius: 14px; padding: 14px; margin-top: 16px;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px;">
                    <span style="font-size: 20px; background: #1e293b; border-radius: 12px; padding: 4px 8px;">🖥️</span>
                    <b style="color: #fff; font-size: 13px;">مرورگر مجازی</b>
                    <span style="background: rgba(56,189,248,0.12); color: #38bdf8; border: 1px solid rgba(56,189,248,0.35); padding: 3px 10px; border-radius: 9999px; font-size: 11px;">موتور جعبه‌ای — بدون نیاز به هیچ نرم‌افزاری روی سرور (مناسب هاست رایگان)</span>
                    <span style="font-size: 11px; color: #6ee7b7;">وضعیت: آماده</span>
                </div>
                <p style="font-size: 11px; line-height: 2; color: #94a3b8; margin: 0 0 10px;">
                    در این موتور، تمام درخواست‌ها و پاسخ‌های وب‌سایت مقصد فقط از همین میزبان شما عبور و پردازش می‌شوند (مقصد تنها IP همین هاست را می‌بیند و هرگز IP شما را)؛
                    حرکت موس، اسکرول و کیبورد شما ابتدا رمزگذاری‌شده به همین هاست ارسال و پس از پردازش در همان‌جا، در «نمایشگر مجازی» بازپخش می‌شود؛
                    صفحهٔ مقصد هم داخل یک جعبهٔ ایزولهٔ موقت نمایش می‌یابد — بدون دسترسی به صفحه یا کوکی‌های مرورگر شما — و با بستن، آن محیط و کوکی‌هایش به‌کلی دور ریخته می‌شود.
                    با دکمهٔ «پیکسل» تنها تصویر صفحه را می‌بینید. برای رندرِ کامل و صددرصد سمت سرور، موتور «سرویس رندر» در تنظیمات افزونه موجود است (نیازمند سروری با دسترسی کامل).
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <input id="glpe-vb-url" type="text" dir="ltr" value="https://example.com" spellcheck="false"
                           style="flex: 1; min-width: 220px; padding: 11px 14px; background: #1e293b; border: 1px solid #334155; border-radius: 12px; color: #fff; font-size: 12px; font-family: monospace; outline: none; text-align: left;">
                    <button type="button" class="glpe-vbs-open"
                            style="padding: 11px 22px; background: linear-gradient(to left, #0284c7, #38bdf8); color: #fff; border: none; border-radius: 12px; font-size: 12px; font-weight: bold; cursor: pointer; font-family: inherit;">
                        🖥️ شروع مرورگر مجازی
                    </button>
                </div>
            </div>
            <script>window.GLPE_VBS = <?php echo wp_json_encode($vbsCfg); ?>;</script>
            <script src="<?php echo esc_url(GLPE_URL . 'includes/vb-sandbox.js'); ?>?v=<?php echo esc_attr(GLPE_VERSION); ?>" defer></script>
            <?php endif; ?>

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
        // Dedicated page for the Multi-Node Relay Core (v6.1.0 request).
        add_submenu_page(
            'glpe-viewer',
            'هستهٔ ارتباطی چندگرهی',
            'هستهٔ ارتباطی چندگرهی',
            'manage_options',
            'glpe-viewer-relay',
            ['GLPE_Hub_Admin', 'renderRelayPage']
        );
        // Dedicated page for the Virtual Browser (v7.4.0 request): every
        // virtual-browser setting, status card and self-test moved here so
        // the main settings page stays about the viewer itself.
        add_submenu_page(
            'glpe-viewer',
            'مرورگر مجازی',
            'مرورگر مجازی',
            'manage_options',
            'glpe-viewer-vb',
            [$this, 'renderAdminVbPage']
        );
    }

    public function registerSettings() {
        $flags = ['glpe_toolbar', 'glpe_blank_title', 'glpe_no_scripts', 'glpe_no_images', 'glpe_ssl_verify', 'glpe_hub_enabled', 'glpe_ipresolve_v4', 'glpe_res_cache'];
        foreach ($flags as $flag) {
            register_setting('glpe_group', $flag, ['sanitize_callback' => [$this, 'sanitizeFlag']]);
        }
        register_setting('glpe_group', 'glpe_dns_mode', ['sanitize_callback' => [$this, 'sanitizeDnsMode']]);
        register_setting('glpe_group', 'glpe_dns_ttl', ['sanitize_callback' => [$this, 'sanitizePositiveInt']]);
        register_setting('glpe_group', 'glpe_fetch_connect_timeout', ['sanitize_callback' => [$this, 'sanitizePositiveInt']]);
        register_setting('glpe_group', 'glpe_fetch_timeout', ['sanitize_callback' => [$this, 'sanitizePositiveInt']]);
        register_setting('glpe_group', 'glpe_fetch_retries', ['sanitize_callback' => [$this, 'sanitizeRetry']]);
        register_setting('glpe_group', 'glpe_vb_port', ['sanitize_callback' => [$this, 'sanitizeVbPort']]);
        register_setting('glpe_group', 'glpe_vb_endpoint', ['sanitize_callback' => [$this, 'sanitizeVbEndpoint']]);
        register_setting('glpe_group', 'glpe_vb_engine', ['sanitize_callback' => [$this, 'sanitizeVbEngine']]);
        // Virtual-browser session tuning (v7.4.0).
        register_setting('glpe_group', 'glpe_vb_session_ttl', ['sanitize_callback' => [$this, 'sanitizeVbSessionTtl']]);
        register_setting('glpe_group', 'glpe_vb_max_sessions', ['sanitize_callback' => [$this, 'sanitizeVbMaxSessions']]);
        // Subresource-cache tuning (v7.4.0 — was fixed constants before).
        register_setting('glpe_group', 'glpe_res_ttl_def', ['sanitize_callback' => [$this, 'sanitizeResTtlDef']]);
        register_setting('glpe_group', 'glpe_res_ttl_max', ['sanitize_callback' => [$this, 'sanitizeResTtlMax']]);
        register_setting('glpe_group', 'glpe_res_entry_cap', ['sanitize_callback' => [$this, 'sanitizeResEntryCap']]);
        register_setting('glpe_group', 'glpe_res_quota', ['sanitize_callback' => [$this, 'sanitizeResQuota']]);
        // Download delivery tuning (v7.4.0).
        register_setting('glpe_group', 'glpe_dl_threshold', ['sanitize_callback' => [$this, 'sanitizeDlMiB']]);
        register_setting('glpe_group', 'glpe_dl_folder', ['sanitize_callback' => [$this, 'sanitizeDlFolder']]);
        register_setting('glpe_group', 'glpe_dl_retention', ['sanitize_callback' => [$this, 'sanitizeDlRetention']]);
        register_setting('glpe_group', 'glpe_dl_maxsize', ['sanitize_callback' => [$this, 'sanitizeDlMaxSize']]);
        register_setting('glpe_group', 'glpe_dl_quota', ['sanitize_callback' => [$this, 'sanitizeDlQuota']]);
    }

    public function sanitizeVbPort($value) {
        $v = (int)$value;
        return ($v >= 1024 && $v <= 65535) ? (string)$v : '';
    }

    public function sanitizeVbEndpoint($value) {
        $v = str_replace(["\r", "\n", "\0"], '', trim((string)$value));
        return $v;
    }

    /**
     * Virtual-browser engine choice: 'auto' picks the host-side service when
     * its companion answers and the sandbox engine otherwise; the two forced
     * values pin one engine regardless of the host's software.
     */
    public function sanitizeVbEngine($value) {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['auto', 'service', 'sandbox'], true) ? $value : 'auto';
    }

    /** Virtual-browser session life: 5..240 minutes (blank = 30 default). */
    public function sanitizeVbSessionTtl($value) {
        $v = (int)$value;
        return ($v >= 5 && $v <= 240) ? (string)$v : '';
    }

    /** Concurrent sandbox sessions: 1..32 (blank = 6 default). */
    public function sanitizeVbMaxSessions($value) {
        $v = (int)$value;
        return ($v >= 1 && $v <= 32) ? (string)$v : '';
    }

    /** Subresource-cache default entry TTL: 2..360 minutes. */
    public function sanitizeResTtlDef($value) {
        $v = (int)$value;
        return ($v >= 2 && $v <= 360) ? (string)$v : '';
    }

    /** Subresource-cache entry TTL ceiling: 5..720 minutes. */
    public function sanitizeResTtlMax($value) {
        $v = (int)$value;
        return ($v >= 5 && $v <= 720) ? (string)$v : '';
    }

    /** Per-entry size cap for the subresource cache: 1..16 MiB. */
    public function sanitizeResEntryCap($value) {
        $v = (int)$value;
        return ($v >= 1 && $v <= 16) ? (string)$v : '';
    }

    /** Total subresource-cache quota: 16..1024 MiB. */
    public function sanitizeResQuota($value) {
        $v = (int)$value;
        return ($v >= 16 && $v <= 1024) ? (string)$v : '';
    }

    /** Direct-tunnel ceiling for downloads: 1..512 MiB. */
    public function sanitizeDlMiB($value) {
        $v = (int)$value;
        return ($v >= 1 && $v <= 512) ? (string)$v : '';
    }

    /** Guarded folder name under uploads: one slug token, no traversal. */
    public function sanitizeDlFolder($value) {
        $v = trim((string)$value);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $v) ? $v : '';
    }

    /** Stored-file retention: 5..1440 minutes. */
    public function sanitizeDlRetention($value) {
        $v = (int)$value;
        return ($v >= 5 && $v <= 1440) ? (string)$v : '';
    }

    /** Per-file size ceiling for host-stored downloads: 1..2048 MiB. */
    public function sanitizeDlMaxSize($value) {
        $v = (int)$value;
        return ($v >= 1 && $v <= 2048) ? (string)$v : '';
    }

    /** Total host-side download quota: 16..4096 MiB. */
    public function sanitizeDlQuota($value) {
        $v = (int)$value;
        return ($v >= 16 && $v <= 4096) ? (string)$v : '';
    }

    public function sanitizeDnsMode($value) {
        $value = (string)$value;
        return in_array($value, ['auto', 'doh_first', 'system'], true) ? $value : 'auto';
    }

    public function sanitizePositiveInt($value) {
        $v = (int)$value;
        return $v > 0 ? (string)$v : '';
    }

    public function sanitizeRetry($value) {
        $v = (int)$value;
        return ($v >= 1 && $v <= 3) ? (string)$v : '';
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
                'roles-saved'    => 'سطح دسترسی نقش‌ها ذخیره شد.',
                'granted'        => 'مجوز «مرورگر GLPE» به کاربر اعطا شد.',
                'revoked'        => 'مجوز «مرورگر GLPE» از کاربر لغو شد.',
                'notfound'       => 'کاربر موردنظر یافت نشد.',
                'hub-token-regen' => 'کلید گرهٔ ارتباطی جدید ساخته شد — باید config.json کلاینت‌ها را به‌روزرسانی کنید.',
                'hub-log-clear'  => 'گزارش پایش هستهٔ ارتباطی پاک شد.',
                'hub-nozip'      => 'ساخت ZIP روی این هاست در دسترس نیست؛ فایل‌های کلاینت را جداگانه دانلود کنید.',
                'diag-gen'       => 'کلید تشخیص از راه دور ساخته شد — کلید کامل اتصال را کپی و به دستیار خود بدهید.',
                'diag-off'       => 'رابط تشخیص از راه دور غیرفعال شد و فایل واگذاری آن حذف شد.',
                'vb-started'     => 'سرویس مرورگر مجازی روی میزبان اجرا شد و به سلامت پاسخ می‌دهد.',
                'vb-failed'      => 'اجرای سرویس مرورگر مجازی ممکن نشد — وضعیت دقیق در کارت «مرورگر مجازی» آمده است.',
                'path-ok'        => 'تست مسیر موفق بود — پیشوند مسیر مقصد روی نشانی زیرمنبع‌ها فعال شد.',
                'res-purged'    => 'کش زیرمنبع‌ها پاک شد.',
            ];
            if ($cpNotice !== '' && isset($cpNotices[$cpNotice])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($cpNotices[$cpNotice]) . '</p></div>';
            }
            if ($cpNotice === 'path-no') {
                echo '<div class="notice notice-error is-dismissible"><p>تست مسیر ناموفق بود — سرور این هاست نشانی‌های /_glpe/... را به وردپرس نمی‌رساند؛ زیرمنبع‌ها روی نشانی ریشه می‌مانند (کارکرد عادی سالم است، فقط قابلیت سازگاری با سایت‌هایی مثل یوتیوب فعال نمی‌شود).</p></div>';
            }
            if ($cpNotice === 'diag-self-code') {
                $dcode = isset($_GET['dcode']) ? (int)$_GET['dcode'] : 0;
                $dms = isset($_GET['dms']) ? (int)$_GET['dms'] : 0;
                if ($dcode === 200) {
                    echo '<div class="notice notice-success is-dismissible"><p>تست پایانهٔ تشخیص موفق بود — پاسخ 200 در ' . (int)$dms . ' میلی‌ثانیه.</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>تست پایانهٔ تشخیص پاسخ ' . (int)$dcode . ' گرفت — اگر 403 یا خطای دیگری بود، همان لایه مشکل‌ساز برای درخواست‌های بیرونی هم هست؛ خروجی تشخیص را به دستیار بدهید.</p></div>';
                }
            }
            if ($cpNotice === 'diag-self-fail') {
                $derr = isset($_GET['derr']) ? sanitize_text_field(wp_unslash($_GET['derr'])) : '';
                echo '<div class="notice notice-error is-dismissible"><p>تست پایانهٔ تشخیص ناموفق بود' . ($derr !== '' ? ' — ' . esc_html($derr) : '') . '</p></div>';
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

                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 12.5px; color: #14532d; line-height: 1.9;">
                    🔒 <strong>اصل پایه — مخفی‌سازی کامل تبادل‌ها:</strong>
                    تمام نشانی‌های مقصد و تمام محتوای تبادل‌شده میان مرورگر شما و سرور همیشه به‌صورت کدگذاری‌شده (توکن مات مخصوص هر نصب) منتقل می‌شوند؛ هیچ نشانی یا محتوایی به‌صورت خوانا روی خط رد و بدل نمی‌شود و هیچ منبعی از سایت مقصد به‌طور مستقیم بارگذاری نمی‌شود. نشانی صفحهٔ فعلی فقط «در مرورگر خودِ کاربر» نمایش داده می‌شود — جاوااسکریپت افزونه آن را محلی از روی توکن نوار آدرس بازسازی می‌کند و همین مقدار بازسازی‌شده هرگز در هیچ درخواست یا پاسخی منتقل نمی‌گردد. حالت اختیاری قبلی (Short Links) حذف شده است چون این رفتار دیگر اختیاری نیست — همیشه فعال است.
                </div>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">نوار ناوبری بالا (Nav Bar)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_toolbar" value="0">
                                <input type="checkbox" name="glpe_toolbar" value="1" <?php checked(get_option('glpe_toolbar', '1'), '1'); ?> />
                                نمایش نوار ناوبری شناور بالای صفحه با قابلیت جستجوی جدید، دکمه صفحه اصلی و نمایش محلی نشانی صفحهٔ فعلی (فقط در مرورگر کاربر)
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
                    <tr>
                        <th scope="row">هستهٔ ارتباطی چندگرهی (Relay Core)</th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_hub_enabled" value="0">
                                <input type="checkbox" name="glpe_hub_enabled" value="1" <?php checked(get_option('glpe_hub_enabled', '1'), '1'); ?> />
                                فعال‌سازی هستهٔ رله چندگرهی روی این سایت — مدیریت گره، ارزیابی عملی هاست، ویرایش مقادیر کانفیگ کلاینت و پایش زنده در <a href="<?php echo esc_url(add_query_arg(['page' => 'glpe-viewer-relay'], admin_url('admin.php'))); ?>">صفحهٔ اختصاصی هستهٔ ارتباطی</a>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">کش زیرمنبع‌ها <code style="direction:ltr;">res_cache</code></th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_res_cache" value="0">
                                <input type="checkbox" name="glpe_res_cache" value="1" <?php checked(get_option('glpe_res_cache', '1'), '1'); ?> />
                                نگه‌داشتن نسخهٔ نهایی اسکریپت‌ها، شیوه‌نامه‌ها، فونت‌ها و تصاویر روی هاست (تازه‌سازی خودکار بر اساس مدت اعتبار خود سایت مقصد) — برای سایت‌های سنگین مثل یوتیوب بارِ ثانویه را از هاست برمی‌دارد؛ فقط محتوای ثابت و بی‌کوکی ذخیره می‌شود
                            </label>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 10px;">⚡ سرعت اتصال و DNS مرورگر</h3>
                <p style="font-size: 12.5px; color: #334155; line-height: 1.9; margin-top: 6px;">
                    اگر باز کردن نشانی‌ها با خطای «cURL error 28: Resolving timed out» مواجه می‌شود، مشکل از سیستم DNS هاست است، نه از نشانی. افزونه برای مقابلهٔ ریشه‌ای یک کش نشانی با پایداری بالا و مسیر یدکی DoH (با پایانهٔ ثابت و بدون نیاز به DNS هاست) دارد. مقادیر زیر را می‌توانید به‌صورت دستی تنظیم کنید، یا از <a href="<?php echo esc_url(add_query_arg(['page' => 'glpe-viewer-relay'], admin_url('admin.php'))); ?>">ارزیابی عملی هاست</a> مقادیر پیشنهادی را با یک کلیک همین‌جا اعمال کنید. مقدار خالی یعنی «بهترین پیش‌فرض افزونه».
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">حالت حل نام <code style="direction:ltr;">dns_mode</code></th>
                        <td>
                            <select name="glpe_dns_mode" style="direction:ltr;">
                                <option value="auto" <?php selected(get_option('glpe_dns_mode', 'auto'), 'auto'); ?>>auto — ابتدا سیستم هاست، با خطا ترمیم خودکار با DoH (پیشنهادی)</option>
                                <option value="doh_first" <?php selected(get_option('glpe_dns_mode', 'auto'), 'doh_first'); ?>>doh_first — همیشه DoH با نشانی ثابت (برای هاست‌هایی با DNS معیوب)</option>
                                <option value="system" <?php selected(get_option('glpe_dns_mode', 'auto'), 'system'); ?>>system — فقط DNS هاست (وقتی DoH مسدود است)</option>
                            </select>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">نشانی‌های حل‌شده در حافظهٔ ماندگار با TTL نگه داشته می‌شوند تا درخواست‌های تکراری هرگز منتظر DNS نمانند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">TTL کش نشانی‌ها <code style="direction:ltr;">dns_ttl</code></th>
                        <td><input type="number" min="60" max="86400" name="glpe_dns_ttl" value="<?php echo esc_attr(get_option('glpe_dns_ttl', '')); ?>" style="direction:ltr; width: 110px;" /> ثانیه (پیش‌فرض ۹۰۰)</td>
                    </tr>
                    <tr>
                        <th scope="row">سقف زمان اتصال <code style="direction:ltr;">fetch_connect_timeout</code></th>
                        <td><input type="number" min="3" max="60" name="glpe_fetch_connect_timeout" value="<?php echo esc_attr(get_option('glpe_fetch_connect_timeout', '')); ?>" style="direction:ltr; width: 110px;" /> ثانیه (پیش‌فرض ۱۰)</td>
                    </tr>
                    <tr>
                        <th scope="row">سقف کل هر درخواست <code style="direction:ltr;">fetch_timeout</code></th>
                        <td><input type="number" min="8" max="120" name="glpe_fetch_timeout" value="<?php echo esc_attr(get_option('glpe_fetch_timeout', '')); ?>" style="direction:ltr; width: 110px;" /> ثانیه (پیش‌فرض ۱۵)</td>
                    </tr>
                    <tr>
                        <th scope="row">دفعات ترمیم تایم‌اوت <code style="direction:ltr;">fetch_retries</code></th>
                        <td><input type="number" min="1" max="3" name="glpe_fetch_retries" value="<?php echo esc_attr(get_option('glpe_fetch_retries', '')); ?>" style="direction:ltr; width: 110px;" /> بار (پیش‌فرض ۱) — هر بار تلاش مجدد با نشانی تازهٔ DoH انجام می‌شود</td>
                    </tr>
                    <tr>
                        <th scope="row">فقط IPv4 <code style="direction:ltr;">ipresolve_v4</code></th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_ipresolve_v4" value="0">
                                <input type="checkbox" name="glpe_ipresolve_v4" value="1" <?php checked(get_option('glpe_ipresolve_v4', '0'), '1'); ?> />
                                اگر هاست مسیر IPv6 معیوب دارد و نشانی‌ها گاهی قفل می‌شوند فعال کنید
                            </label>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 18px;">🖥️ مرورگر مجازی</h3>
                <p style="font-size: 13px; line-height: 2; color: #334155; margin: 0;">
                    تمام تنظیمات، کارت‌های وضعیت و ابزارهای خودآزمایی مرورگر مجازی (موتور و سرویس، نشست‌ها، کش زیرمنبع‌ها، دانلود فایل‌ها و دکمهٔ تست هاست)
                    به صفحهٔ مجزای خودش منتقل شده‌اند:
                    <a href="<?php echo esc_url(admin_url('admin.php?page=glpe-viewer-vb')); ?>" style="font-weight: bold;">مرورگر مجازی ← صفحهٔ تنظیمات مجزا</a>
                </p>

                <?php submit_button('ذخیره تغییرات'); ?>
            </form>

            <?php $this->renderVbPointerCard(); ?>

            <?php $this->renderAccessCard(); ?>

            <?php if (class_exists('GLPE_Diag_Api')) { GLPE_Diag_Api::renderCard(); } ?>

            <?php GLPE_Hub_Admin::renderRelayPointer(); ?>
        </div>
        <?php
    }

    /**
     * Compact pointer card on the main settings page toward the virtual
     * browser's own settings page (v7.4.0 move).
     */
    private function renderVbPointerCard() {
        $vbUrl = admin_url('admin.php?page=glpe-viewer-vb');
        $engine = class_exists('GLPE_Vb_Sandbox') ? GLPE_Vb_Sandbox::engineChoice() : 'sandbox';
        $label = $engine === 'service' ? 'سرویس رندر میزبان' : 'موتور جعبه‌ای (بدون نیاز به نرم‌افزار روی سرور)';
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🖥️ مرورگر مجازی</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155; margin: 0 0 12px;">
                موتور فعال: <b><?php echo esc_html($label); ?></b> — موتور و سرویس، عمر و سقف نشست‌ها، تنظیمات کش زیرمنبع‌ها، تنظیمات دانلود فایل‌ها (تونل مستقیم/ذخیره روی هاست) و دکمهٔ تست هاست که مقادیر پیشنهادی را خودکار پر می‌کند، همگی در صفحهٔ مجزای مرورگر مجازی قرار دارند.
            </p>
            <a href="<?php echo esc_url($vbUrl); ?>" class="button button-primary">رفتن به صفحهٔ مرورگر مجازی</a>
        </div>
        <?php
    }

    /**
     * The virtual browser's own settings page (v7.4.0): engine + service,
     * session lifetime/concurrency, subresource-cache tuning, file-download
     * delivery tuning, the host test that auto-fills recommended values,
     * and the status cards that used to live on the main settings page.
     */
    public function renderAdminVbPage() {
        $hostTest = isset($_GET['hosttest']) && $_GET['hosttest'] === '1';
        $report = $hostTest ? get_transient('glpe_vb_hostrec') : false;
        ?>
        <div class="wrap" style="direction: rtl; text-align: right; max-width: 900px;">
            <h1 style="font-size: 22px; font-weight: bold; margin-bottom: 20px;">
                🖥️ مرورگر مجازی — تنظیمات و وضعیت
            </h1>

            <?php
            $vbNotice = isset($_GET['cp_msg']) ? sanitize_key($_GET['cp_msg']) : '';
            $vbNotices = [
                'res-purged' => 'کش زیرمنبع‌ها پاک شد.',
                'dl-purged'  => 'فایل‌های دریافت‌شدهٔ روی هاست پاک شدند.',
            ];
            if ($vbNotice !== '' && isset($vbNotices[$vbNotice])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($vbNotices[$vbNotice]) . '</p></div>';
            }
            if ($hostTest && is_array($report)) {
                $m = isset($report['measured']) ? $report['measured'] : [];
                echo '<div class="notice notice-success is-dismissible"><p>تست هاست انجام شد — مقادیر پیشنهادی به‌صورت خودکار در فرم زیر پر شدند؛ برای اعمال، «ذخیره تغییرات» را بزنید.</p></div>';
            }
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                <?php settings_fields('glpe_group'); ?>

                <h3 style="margin-top: 0;">موتور و سرویس رندر</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">موتور مرورگر مجازی <code style="direction:ltr;">vb_engine</code></th>
                        <td>
                            <select name="glpe_vb_engine" style="max-width: 100%;">
                                <option value="auto" <?php selected(get_option('glpe_vb_engine', 'auto'), 'auto'); ?>>auto — خودکار: سرویس رندر میزبان اگر در دسترس بود، وگرنه موتور جعبه‌ای (پیشنهادی)</option>
                                <option value="service" <?php selected(get_option('glpe_vb_engine', 'auto'), 'service'); ?>>service — سرویس رندر میزبان: رندرِ کامل سمت سرور، فقط تصویر می‌رسد (نیازمند Bun و Chromium روی سرور)</option>
                                <option value="sandbox" <?php selected(get_option('glpe_vb_engine', 'auto'), 'sandbox'); ?>>sandbox — موتور جعبه‌ای: تمام تبادلات و ورودی‌ها از همین هاست می‌گذرد؛ بدون نیاز به هیچ نرم‌افزاری روی سرور (مناسب هاست رایگان)</option>
                            </select>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">هر دو موتور تجربهٔ «مثل ریموت دسکتاپ» می‌دهند؛ تفاوت: موتور سرویس تصویر را صددرصد روی سرور می‌کشد، موتور جعبه‌ای صفحه را داخل جعبهٔ ایزولهٔ مرورگر کاربر نمایش می‌دهد و فقط تبادلات و ورودی‌ها را روی هاست پردازش می‌کند — موتوری که روی هاست رایگان بدون دسترسی سرور کار می‌کند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">درگاه سرویس <code style="direction:ltr;">vb_port</code></th>
                        <td>
                            <input type="number" min="1024" max="65535" name="glpe_vb_port" value="<?php echo esc_attr(get_option('glpe_vb_port', '')); ?>" style="direction:ltr; width: 110px;" placeholder="3007" />
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">درگاهی که فرایند رندر میزبان روی آن گوش می‌دهد (خالی = ۳۰۰۷). این درگاه باید از بیرون قابل دسترس باشد.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">نشانی سرویس <code style="direction:ltr;">vb_endpoint</code></th>
                        <td>
                            <input type="text" name="glpe_vb_endpoint" value="<?php echo esc_attr(get_option('glpe_vb_endpoint', '')); ?>" style="direction:ltr; width: 320px; max-width: 100%;" placeholder="خالی = خودکار" />
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">نشانی اتصال مرورگر کاربر به سرویس مجازی — خالی یعنی محاسبهٔ خودکار. فقط وقتی پر کنید که سرویس را پشت یک نشانی دیگری در دسترس گذاشته‌اید.</p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 18px;">نشست‌های محیط مجازی</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">اعتبار هر نشست <code style="direction:ltr;">vb_session_ttl</code></th>
                        <td>
                            <input type="number" min="5" max="240" name="glpe_vb_session_ttl" value="<?php echo esc_attr(get_option('glpe_vb_session_ttl', '')); ?>" style="direction:ltr; width: 110px;" placeholder="30" />
                            <span style="font-size: 12px; color: #64748b;">دقیقه (۵ تا ۲۴۰)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">عمر هر محیط موازی موقت؛ نشستِ فعال با فعالیت خودش تمدید می‌شود و در نبود فعالیت، در همین زمان از پایگاه داده دور ریخته می‌شود (خالی = ۳۰).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">حداکثر نشست‌های همزمان <code style="direction:ltr;">vb_max_sessions</code></th>
                        <td>
                            <input type="number" min="1" max="32" name="glpe_vb_max_sessions" value="<?php echo esc_attr(get_option('glpe_vb_max_sessions', '')); ?>" style="direction:ltr; width: 110px;" placeholder="6" />
                            <span style="font-size: 12px; color: #64748b;">عدد (۱ تا ۳۲)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">سقف محیط‌های موازیِ همزمان روی این هاست — از این عدد به بعد درخواستِ «محیط جدید» با پیام «پُر» پاسخ می‌گیرد (خالی = ۶).</p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 18px;">کش زیرمنبع‌ها</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">وضعیت کش <code style="direction:ltr;">res_cache</code></th>
                        <td>
                            <label>
                                <input type="hidden" name="glpe_res_cache" value="0">
                                <input type="checkbox" name="glpe_res_cache" value="1" <?php checked(get_option('glpe_res_cache', '1'), '1'); ?> />
                                فعال (پیشنهادی)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">اعتبار پیش‌فرض هر مدخل <code style="direction:ltr;">res_ttl_def</code></th>
                        <td>
                            <input type="number" min="2" max="360" name="glpe_res_ttl_def" value="<?php echo esc_attr(get_option('glpe_res_ttl_def', '')); ?>" style="direction:ltr; width: 110px;" placeholder="15" />
                            <span style="font-size: 12px; color: #64748b;">دقیقه (۲ تا ۳۶۰)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">اگر سایت مقصد مدت اعتبار خودش را اعلام نکرده باشد، مدخل با این عمر نگه داشته می‌شود؛ اعلام خود سایت مقصد همیشه ملاک است.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">سقف اعتبار مدخل‌ها <code style="direction:ltr;">res_ttl_max</code></th>
                        <td>
                            <input type="number" min="5" max="720" name="glpe_res_ttl_max" value="<?php echo esc_attr(get_option('glpe_res_ttl_max', '')); ?>" style="direction:ltr; width: 110px;" placeholder="360" />
                            <span style="font-size: 12px; color: #64748b;">دقیقه (۵ تا ۷۲۰)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">حتی اگر سایت مقصد اعتبار بلندمدتی اعلام کند، عمر هر مدخل از این سقف فراتر نمی‌رود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">سقف حجم هر مدخل <code style="direction:ltr;">res_entry_cap</code></th>
                        <td>
                            <input type="number" min="1" max="16" name="glpe_res_entry_cap" value="<?php echo esc_attr(get_option('glpe_res_entry_cap', '')); ?>" style="direction:ltr; width: 110px;" placeholder="3" />
                            <span style="font-size: 12px; color: #64748b;">مگابایت (۱ تا ۱۶)</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">سهمیهٔ کل کش <code style="direction:ltr;">res_quota</code></th>
                        <td>
                            <input type="number" min="16" max="1024" name="glpe_res_quota" value="<?php echo esc_attr(get_option('glpe_res_quota', '')); ?>" style="direction:ltr; width: 110px;" placeholder="64" />
                            <span style="font-size: 12px; color: #64748b;">مگابایت (۱۶ تا ۱۰۲۴)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">با پُر شدن سهمیه، قدیمی‌ترین مدخل‌ها خودکار حذف می‌شوند.</p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top: 18px;">دانلود فایل‌ها</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">سقف تونل مستقیم <code style="direction:ltr;">dl_threshold</code></th>
                        <td>
                            <input type="number" min="1" max="512" name="glpe_dl_threshold" value="<?php echo esc_attr(get_option('glpe_dl_threshold', '')); ?>" style="direction:ltr; width: 110px;" placeholder="8" />
                            <span style="font-size: 12px; color: #64748b;">مگابایت (۱ تا ۵۱۲)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">فایل‌های تا این حجم مستقیم و تونل‌شده به مرورگر خود کاربر می‌رسند (بدون ذخیره روی هاست)؛ فایل‌های بزرگ‌تر تکه‌تکه روی همین هاست دریافت و سپس به کاربر سرو می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">پوشهٔ ذخیره <code style="direction:ltr;">dl_folder</code></th>
                        <td>
                            <input type="text" name="glpe_dl_folder" value="<?php echo esc_attr(get_option('glpe_dl_folder', '')); ?>" style="direction:ltr; width: 220px; max-width: 100%;" placeholder="glpe-downloads" />
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">نام پوشهٔ نگهداری فایل‌های دریافت‌شده داخل uploads (محافظت‌شده با htaccess و سرو فقط از طریق توکن مبهم دروازه؛ خالی = نام پیش‌فرض).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدت نگهداری <code style="direction:ltr;">dl_retention</code></th>
                        <td>
                            <input type="number" min="5" max="1440" name="glpe_dl_retention" value="<?php echo esc_attr(get_option('glpe_dl_retention', '')); ?>" style="direction:ltr; width: 110px;" placeholder="120" />
                            <span style="font-size: 12px; color: #64748b;">دقیقه (۵ تا ۱۴۴۰)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">فایل‌های ذخیره‌شده بعد از این مدت، خودکار و تنبل (بدون کرون) از هاست حذف می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">حداکثر حجم هر فایل <code style="direction:ltr;">dl_maxsize</code></th>
                        <td>
                            <input type="number" min="1" max="2048" name="glpe_dl_maxsize" value="<?php echo esc_attr(get_option('glpe_dl_maxsize', '')); ?>" style="direction:ltr; width: 110px;" placeholder="256" />
                            <span style="font-size: 12px; color: #64748b;">مگابایت (۱ تا ۲۰۴۸)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">فایل‌هایی که از این حجم بزرگ‌تر باشند، دریافتشان رد می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">سهمیهٔ کل <code style="direction:ltr;">dl_quota</code></th>
                        <td>
                            <input type="number" min="16" max="4096" name="glpe_dl_quota" value="<?php echo esc_attr(get_option('glpe_dl_quota', '')); ?>" style="direction:ltr; width: 110px;" placeholder="512" />
                            <span style="font-size: 12px; color: #64748b;">مگابایت (۱۶ تا ۴۰۹۶)</span>
                            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0;">مجموع حجم فایل‌های ذخیره‌شده؛ با پُر شدن، قدیمی‌ترین‌ها حذف می‌شوند.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('ذخیره تغییرات'); ?>
            </form>

            <?php $this->renderHostTestCard($report); ?>

            <?php if (class_exists('GLPE_VirtualBrowser')) { $this->renderVirtualBrowserCard(); } ?>

            <?php $this->renderPathEntryCard(); ?>

            <?php if (class_exists('GLPE_ResCache')) { $this->renderResCacheCard(); } ?>

            <?php if (class_exists('GLPE_VbDl')) { $this->renderDownloadsCard(); } ?>
        </div>
        <?php
        if (is_array($report) && !empty($report['rec'])) {
            // Auto-fill the form's fields with the host test's recommended
            // values (the admin reviews and saves them).
            $fill = wp_json_encode($report['rec']);
            echo '<script>(function(){try{var rec=' . $fill . ';var map={'
                . "'glpe_dl_threshold':'dl_threshold','glpe_dl_retention':'dl_retention',"
                . "'glpe_dl_maxsize':'dl_maxsize','glpe_dl_quota':'dl_quota',"
                . "'glpe_res_ttl_def':'res_ttl_def','glpe_res_ttl_max':'res_ttl_max',"
                . "'glpe_res_entry_cap':'res_entry_cap','glpe_res_quota':'res_quota',"
                . "'glpe_vb_session_ttl':'vb_session_ttl','glpe_vb_max_sessions':'vb_max_sessions'"
                . '};for(var k in map){if(rec[map[k]]!==undefined){var inp=document.querySelector(\'[name="\'+k+\'"]\');'
                . 'if(inp){inp.value=rec[map[k]];inp.style.background="#fefce8";}}}}catch(e){}})();</script>';
        }
    }

    /**
     * Host test card (v7.4.0): the one-click probe that measures this
     * host's real limits (execution window, memory, disk in uploads) and
     * computes recommended values for every tunable above. The results
     * land in a transient, the redirect carries hosttest=1, and the page
     * auto-fills the form fields with the recommended numbers.
     */
    private function renderHostTestCard($report) {
        $testUrl = wp_nonce_url(
            admin_url('admin-post.php?action=glpe_vb_hosttest'),
            'glpe_vb_hosttest'
        );
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🧪 تست هاست</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155;">
                تست، محدودیت‌های واقعی همین هاست (پنجرهٔ اجرا، حافظه، فضای خالی uploads و امکان نوشتن) را می‌سنجد و مقادیر پیشنهادی امن برای همهٔ تنظیمات همین صفحه را محاسبه و به‌صورت خودکار در فرم پر می‌کند — کافی است بعدش «ذخیره تغییرات» را بزنید.
            </p>
            <form method="post" action="<?php echo esc_url($testUrl); ?>" style="margin-top: 14px; display: inline;">
                <?php wp_nonce_field('glpe_vb_hosttest'); ?>
                <button type="submit" class="button button-primary">اجرای تست هاست و پر کردن مقادیر پیشنهادی</button>
            </form>
            <?php if (is_array($report) && !empty($report['measured'])): ?>
            <table class="widefat striped" style="max-width: 760px; direction: ltr; text-align: left; margin-top: 14px;">
                <tbody>
                <tr><th style="width:220px;">PHP</th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                <tr><th>max execution window</th><td><?php echo esc_html(isset($report['measured']['exec']) ? $report['measured']['exec'] . ' s' : '—'); ?></td></tr>
                <tr><th>memory limit</th><td><?php echo esc_html(isset($report['measured']['mem']) ? $report['measured']['mem'] . ' MiB' : '—'); ?></td></tr>
                <tr><th>uploads free disk</th><td><?php echo esc_html(isset($report['measured']['disk']) ? $report['measured']['disk'] . ' MiB' : '—'); ?></td></tr>
                <tr><th>uploads writable</th><td><?php echo !empty($report['measured']['writable']) ? 'yes' : 'no'; ?></td></tr>
                </tbody>
            </table>
            <p style="font-size: 12px; color: #64748b; line-height: 1.9; margin-bottom: 0;">
                مقادیر پیشنهادی محاسبه‌شده (پرشده در فرم بالا با پس‌زمینهٔ روشن): سقف تونل دانلود بر اساس پنجرهٔ اجرا، سقف‌ها و سهمیه‌ها بر اساس حافظه و فضای دیسک، و اعتبار مدخل‌ها بر اساس سازگاری هاست رایگان. اگر میزان مصرف هاست متفاوت است، مقادیر را دستی اصلاح کنید.
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Downloads status card (v7.4.0): storage location, kept volume,
     * retention and the one-click purge.
     */
    private function renderDownloadsCard() {
        $stats = GLPE_VbDl::stats();
        $purgeUrl = wp_nonce_url(
            admin_url('admin-post.php?action=glpe_dl_purge'),
            'glpe_dl_purge'
        );
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">📥 دانلود فایل‌ها</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155;">
                فایل‌های کوچک (تا سقف تونل) مستقیم و تونل‌شده به مرورگر خود کاربر سرو می‌شوند؛ فایل‌های بزرگ تکه‌تکه در پوشهٔ محافظت‌شدهٔ زیر دریافت و پس از تکمیل، با نشانی مبهم و کوتاه‌عمر به کاربر سرو می‌شوند. هیچ فایلی بیرون از پنجرهٔ نگهداری روی هاست نمی‌ماند.
            </p>
            <table class="widefat striped" style="max-width: 760px; direction: ltr; text-align: left;">
                <tbody>
                <tr><th style="width:170px;">storage</th><td><code style="direction:ltr;"><?php echo esc_html($stats['dir']); ?></code> — guarded runtime folder</td></tr>
                <tr><th>files</th><td><?php echo (int)$stats['files']; ?> (<?php echo number_format((float)$stats['bytes'] / 1048576, 1); ?> MB)</td></tr>
                <tr><th>retention</th><td><?php echo (int)GLPE_VbDl::retention(); ?> min (lazy reaping, no cron)</td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url($purgeUrl); ?>" style="margin-top: 14px; display: inline;">
                <?php wp_nonce_field('glpe_dl_purge'); ?>
                <button type="submit" class="button" onclick="return confirm('همه فایل‌های دریافت‌شده روی هاست پاک شوند؟');">پاک‌سازی فایل‌های ذخیره‌شده</button>
            </form>
            <p style="font-size: 12px; color: #64748b; line-height: 1.9; margin-bottom: 0;">
                نام فایل‌های ذخیره‌شده هش تصادفی است؛ پوشه با htaccess deny-all و index خالی محافظت می‌شود و سروِ فایل فقط از طریق توکن رمزگشایی‌شدهٔ دروازه انجام می‌گیرد.
            </p>
        </div>
        <?php
    }

    /**
     * Host test runner (admin_post_glpe_vb_hosttest): measures the real
     * limits of THIS hosting and derives every recommended value for the
     * virtual-browser settings page. Results land in a short-lived
     * transient; the redirect carries hosttest=1 and the settings page
     * auto-fills the form with the recommendations.
     */
    public function handleVbHostTest() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted.', 'glpe-viewer'));
        }
        check_admin_referer('glpe_vb_hosttest');

        // --- measured facts -------------------------------------------------
        $execRaw = (string)@ini_get('max_execution_time');
        $exec = (int)$execRaw;
        if ($exec <= 0) {
            $exec = 60; // 0/unlimited is never honest on shared hosting —
                        // budget like a generous window instead.
        }
        $memRaw = (string)@ini_get('memory_limit');
        $memMiB = 128;
        if (preg_match('/^(-?\d+)([KMG]?)$/i', trim($memRaw), $mm)) {
            $n = (int)$mm[1];
            $mult = strtoupper($mm[2]) === 'M' ? 1 : (strtoupper($mm[2]) === 'G' ? 1024 : (strtoupper($mm[2]) === 'K' ? 0.0009765625 : 1));
            $memMiB = $n < 0 ? 512 : max(16, (int)round($n * $mult));
        }
        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';
        $diskMiB = 64;
        if ($base !== '' && function_exists('disk_free_space')) {
            $free = @disk_free_space($base);
            if ($free !== false && $free > 0) {
                $diskMiB = (int)floor($free / 1048576);
            }
        }
        $writable = false;
        if ($base !== '') {
            $probe = $base . '/.glpe-hosttest-' . wp_generate_password(8, false, false);
            $writable = @file_put_contents($probe, 'ok') !== false;
            if ($writable) {
                @unlink($probe);
            }
        }

        // --- recommendations (clamped by the same limits the sanitizers
        //     enforce — a recommendation can never be unsavable) ----------
        $rec = [
            // A direct tunnel must finish inside the execution window;
            // assume a conservative transfer rate for free hosting.
            'dl_threshold'  => max(2, min(64, (int)floor($exec * 0.5))),
            // Host-side storage scales with the disk actually free (a
            // quarter per file at most, a fifth for the whole quota).
            'dl_maxsize'   => max(16, min(1024, (int)floor($diskMiB / 4))),
            'dl_quota'     => max(32, min(2048, (int)floor($diskMiB / 5))),
            'dl_retention' => 120,
            // The subresource cache reacts to memory and disk.
            'res_entry_cap' => $memMiB >= 128 ? 3 : ($memMiB >= 64 ? 2 : 1),
            'res_quota'    => max(16, min(512, (int)floor($diskMiB / 10))),
            'res_ttl_def'  => 15,
            'res_ttl_max'  => 360,
            'vb_session_ttl' => 30,
            'vb_max_sessions' => 6,
        ];

        set_transient('glpe_vb_hostrec', [
            'measured' => [
                'exec'     => $exec,
                'mem'      => $memMiB,
                'disk'     => $diskMiB,
                'writable' => $writable ? 1 : 0,
            ],
            'rec' => $rec,
        ], 600);

        wp_safe_redirect(add_query_arg(
            ['page' => 'glpe-viewer-vb', 'hosttest' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Virtual browser card — both engines' status: the host-side rendering
     * service (runtime/renderer discovery, one-click start) and the sandbox
     * engine (always available — the free-hosting path, v7.1.0).
     */
    private function renderVirtualBrowserCard() {
        $st = GLPE_VirtualBrowser::status();
        $engine = class_exists('GLPE_Vb_Sandbox') ? GLPE_Vb_Sandbox::engineChoice() : 'sandbox';
        $startUrl = wp_nonce_url(
            admin_url('admin-post.php?action=glpe_vb_start'),
            'glpe_vb_start'
        );
        $uploads = wp_upload_dir();
        $logPath = isset($uploads['basedir']) && $uploads['basedir'] !== ''
            ? rtrim($uploads['basedir'], '/\\') . '/glpe-vb.log'
            : '';
        $row = function ($label, $value, $ok) {
            $color = $ok ? '#059669' : '#b45309';
            echo '<tr><th style="width:170px;">' . esc_html($label) . '</th><td><span style="color:' . $color . '; font-weight:bold; direction:ltr;">' . esc_html($value !== '' ? $value : '—') . '</span></td></tr>';
        };
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🖥️ مرورگر مجازی — دو موتور: رندر کامل روی میزبان و موتور جعبه‌ای</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155;">
                مرورگر مجازی دو موتور دارد و هر دو تجربه‌ای شبیه ریموت دسکتاپ می‌دهند:
                <b>سرویس رندر میزبان</b> کل وب‌سایت مقصد را روی همین سرور بارگذاری و اجرا می‌کند و به بازدیدکننده فقط تصویر فریم‌ها می‌رسد — هیچ کد یا محتوایی از مقصد به دستگاه کاربر نمی‌رسد؛ نیازمند زمان اجرای <code>Bun</code> و یک باینری رندرکنندهٔ بدون رابط (Chromium) روی سرور است.
                <b>موتور جعبه‌ای</b> (جدید — برای هاست رایگان بدون دسترسی سرور) بدون هیچ نرم‌افزاری روی سرور کار می‌کند: تمام درخواست‌ها و پاسخ‌های مقصد از همین هاست عبور و پردازش می‌شوند، ورودی‌های موس/کیبورد ابتدا به هاست ارسال و پس از پردازش همان‌جا در نمایشگر مجازی بازپخش می‌شوند، و صفحهٔ مقصد داخل یک جعبهٔ ایزولهٔ موقت (با کوکی‌های دورریز و هویتِ هماهنگ با هاست) نمایش می‌یابد.
            </p>
            <table class="widefat striped" style="max-width: 760px; direction: ltr; text-align: left;">
                <tbody>
                <?php
                $row('active engine', $engine, true);
                $row('sandbox engine', 'available — no server software required', true);
                $row('service', $st['running'] ? 'running : ' . (int)$st['port'] : 'stopped', $st['running']);
                $row('bun runtime', $st['bun'], $st['bun'] !== '');
                $row('renderer binary', $st['chrome'], $st['chrome'] !== '');
                $row('client endpoint', $st['endpoint'], true);
                $row('log file', $logPath, true);
                ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url($startUrl); ?>" style="margin-top: 14px; display: inline;">
                <?php wp_nonce_field('glpe_vb_start'); ?>
                <button type="submit" class="button button-primary">اجرای سرویس رندر / تازه‌سازی وضعیت</button>
            </form>
            <p style="font-size: 12px; color: #64748b; line-height: 1.9; margin-bottom: 0;">
                موتور فعال در فهرست «موتور مرورگر مجازی» فرم بالا قابل تغییر است (auto / service / sandbox).
                اگر هاست اجازهٔ اجرای فرایند پس‌زمینه نمی‌دهد، سرویس را به‌صورت دستی (داِیمن) اجرا کنید:
                <code style="direction:ltr; display:inline-block;">GLPE_VB_PORT=3007 GLPE_VB_KEYFILE=&lt;uploads&gt;/glpe-vb-key.txt GLPE_VB_CHROME=&lt;chromium&gt; bun &lt;plugin&gt;/vb/vb-service.js</code>
            </p>
        </div>
        <?php
    }

    /**
     * Path-entry card (v7.2.0): shows whether subresource references may
     * carry the destination's URL path under the reserved /_glpe/ namespace
     * — the compatibility layer that lets path-validating destinations
     * (module loaders such as YouTube's) boot inside the gateway — and
     * offers the admin self-test that proves the front controller routes
     * that namespace on this host.
     */
    private function renderPathEntryCard() {
        $opt = get_option('glpe_path_entry', '');
        $struct = (string)get_option('permalink_structure', '');
        if ($opt === 'yes') {
            $state = 'active — verified';
            $ok = true;
        } elseif ($opt === 'no') {
            $state = 'inactive — probe says this host does not route /_glpe/ paths';
            $ok = false;
        } elseif ($struct !== '' && strpos($struct, '/index.php/') !== 0) {
            $state = 'active — inferred from pretty permalinks';
            $ok = true;
        } else {
            $state = 'inactive — run the self-test to check this host';
            $ok = false;
        }
        $probeUrl = wp_nonce_url(
            admin_url('admin-post.php?action=glpe_path_probe'),
            'glpe_path_probe'
        );
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🧭 سازگاری نشانی زیرمنبع‌ها (پیشوند مسیر مقصد)</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155;">
                زیرمنبع‌های صفحه (اسکریپت‌ها، شییت‌های استایل، تصاویر) می‌توانند مسیر خودشان از سایت مقصد را زیر فضای نام رزرو‌شدهٔ <code style="direction:ltr;">/_glpe/…</code> حمل کنند — میزبان و کوئری همچنان داخل توکن مبهم می‌مانند. برخی سایت‌ها (مثل یوتیوب) آدرس اسکریپت‌های خود را از نظر شکل مسیر اعتبارسنجی می‌کنند و بدون این لایه، بارگذاریشان داخل دروازه شکست می‌خورد.
            </p>
            <table class="widefat striped" style="max-width: 760px; direction: ltr; text-align: left;">
                <tbody>
                <tr><th style="width:170px;">path entry</th><td><span style="color:<?php echo $ok ? '#059669' : '#b45309'; ?>; font-weight:bold;"><?php echo esc_html($state); ?></span></td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url($probeUrl); ?>" style="margin-top: 14px; display: inline;">
                <?php wp_nonce_field('glpe_path_probe'); ?>
                <button type="submit" class="button button-primary">اجرای تست مسیر</button>
            </form>
            <p style="font-size: 12px; color: #64748b; line-height: 1.9; margin-bottom: 0;">
                تست، یک درخواست از خود هاست به نشانی <code style="direction:ltr;">/_glpe-probe/route-check</code> می‌فرستد؛ اگر پاسخ رسید یعنی سرور مسیرهای دلخواه را به وردپرس می‌رساند و لایه فعال می‌شود — وگرنه همه‌چیز مثل قبل روی نشانی ریشه کار می‌کند.
            </p>
        </div>
        <?php
    }

    /**
     * Subresource cache card (v7.3.0) — state, volume, and the one-click
     * purge. Entries expire by their own upstream-freshness clocks and the
     * quota keeper drops the oldest ones automatically.
     */
    private function renderResCacheCard() {
        $enabled = class_exists('GLPE_ResCache') ? GLPE_ResCache::enabled() : false;
        $stats = class_exists('GLPE_ResCache') ? GLPE_ResCache::stats() : ['files' => 0, 'bytes' => 0, 'dir' => ''];
        $purgeUrl = wp_nonce_url(
            admin_url('admin-post.php?action=glpe_res_purge'),
            'glpe_res_purge'
        );
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">📦 کش زیرمنبع‌ها</h3>
            <p style="font-size: 13px; line-height: 2; color: #334155;">
                نسخهٔ نهایی زیرمنبع‌های ثابت (اسکریپت، شیوه‌نامه، فونت، تصویر) روی هاست نگه داشته می‌شود تا مراجعه‌های بعدی همان منبع بدون رفت‌وبرگشت به سایت مقصد و بدون پردازش مجدد بازنویسی سرو شوند — تازه‌سازی هر مدخل بر اساس مدت اعتبار اعلام‌شدهٔ خود سایت مقصد است و سقف کل فضای مصرفی با حذف قدیمی‌ترین مدخل‌ها خودکار رعایت می‌شود.
            </p>
            <table class="widefat striped" style="max-width: 760px; direction: ltr; text-align: left;">
                <tbody>
                <tr><th style="width:170px;">state</th><td><?php echo $enabled ? '<span style="color:#059669;font-weight:bold;">enabled</span>' : '<span style="color:#b45309;font-weight:bold;">disabled</span>'; ?></td></tr>
                <tr><th>entries</th><td><?php echo (int)$stats['files']; ?> (<?php echo number_format((float)$stats['bytes'] / 1048576, 1); ?> MB)</td></tr>
                <tr><th>storage</th><td><code style="direction:ltr;"><?php echo esc_html($stats['dir']); ?></code> — guarded runtime directory</td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url($purgeUrl); ?>" style="margin-top: 14px; display: inline;">
                <?php wp_nonce_field('glpe_res_purge'); ?>
                <button type="submit" class="button" onclick="return confirm('همه مدخل‌های کش زیرمنبع‌ها پاک شوند؟');">پاک‌سازی کش</button>
            </form>
            <p style="font-size: 12px; color: #64748b; line-height: 1.9; margin-bottom: 0;">
                محتوای دارای کوکی یا پویا (سندهای HTML و پاسخ‌های API) هرگز ذخیره نمی‌شود؛ نام فایل‌ها هش مبهم است و فایل‌ها با گارد PHP و htaccess deny-all محافظت‌اند.
            </p>
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
