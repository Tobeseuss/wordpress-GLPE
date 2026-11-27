<?php
/**
 * GLPE remote-diagnostics API — admin side.
 *
 * One access key ("site address + access key") that the site owner can
 * hand to a trusted assistant, who can then run read-only diagnostics
 * and a small whitelist of repairs against the live host through
 * diag-endpoint.php. Built for the "both node endpoints answer 403"
 * class of problems, where the blocker sits at the web-server / WAF
 * layer and can only be identified from the inside.
 *
 * Security model:
 *   - the key is generated ONLY by an explicit admin action; the feature
 *     ships disabled and stays disabled until that moment;
 *   - the key lives in the options table AND (guarded, inside the
 *     deny-all runtime directory) in a handoff file the standalone
 *     endpoint reads — no CMS boot is needed to authenticate;
 *   - the endpoint answers a bare 404 until the key matches
 *     (constant-time compare) and locks for 30 minutes after repeated
 *     failures;
 *   - every remote mutation (set/clear/resync) is journaled to a guarded
 *     audit file and shown on the settings card, so the admin always
 *     sees exactly what the assistant changed;
 *   - deactivating the plugin immediately withdraws the handoff file.
 */
if (!defined('ABSPATH')) {
    exit;
}

class GLPE_Diag_Api {

    const KEY_FILE   = 'diag_key.php';   // guarded handoff for the standalone endpoint
    const KEY_FILE_J = 'diag_key.json';  // plain-name twin (hosts that mangle .php)
    const AUDIT_FILE = 'diag_audit.php'; // guarded journal of remote mutations
    const AUDIT_LIMIT = 30;

    /** @var string|null test override for the store directory (selftest only) */
    public static $testDir = null;

    /* ------------------------------------------------------------------
     * Key management
     * ---------------------------------------------------------------- */

    /** Generates a fresh key, enables the endpoint and syncs the handoff. */
    public static function generateKey() {
        $key = 'glpe_' . wp_generate_password(48, false, false);
        update_option('glpe_diag_key', $key);
        update_option('glpe_diag_enabled', '1');
        update_option('glpe_diag_enabled_at', (string)time());
        self::syncStore();
        return $key;
    }

    /** The stored access key ('' when absent or malformed). */
    public static function key() {
        $k = get_option('glpe_diag_key');
        // wp_generate_password() without specials yields a-zA-Z0-9.
        return (is_string($k) && preg_match('/^glpe_[a-zA-Z0-9]{40,64}$/', $k)) ? $k : '';
    }

    /** True only when the admin deliberately switched the endpoint on. */
    public static function enabled() {
        return get_option('glpe_diag_enabled', '0') === '1' && self::key() !== '';
    }

    /** Disables the endpoint and withdraws the handoff file. */
    public static function disable() {
        update_option('glpe_diag_enabled', '0');
        self::syncStore();
    }

    /** The standalone endpoint URL inside the plugin folder. */
    public static function endpointUrl() {
        return GLPE_URL . 'diag-endpoint.php';
    }

    /** Combined connection string the owner pastes to the assistant:
     *  «site address # access key» — one opaque line, nothing else needed. */
    public static function connectionString() {
        return untrailingslashit(home_url()) . '#' . self::key();
    }

    /* ------------------------------------------------------------------
     * Guarded handoff for the standalone endpoint
     * ---------------------------------------------------------------- */

    /** Runtime directory shared with the relay core (deny-all .htaccess). */
    public static function storeDir() {
        if (self::$testDir !== null) {
            return self::$testDir;
        }
        return GLPE_Hub::dataDir();
    }

    /** Everything the standalone endpoint needs to authenticate and act. */
    public static function storePayload() {
        return [
            'key'        => self::key(),
            'enabled'    => self::enabled() ? '1' : '0',
            'home'       => untrailingslashit(home_url()),
            'plugin_url' => untrailingslashit(GLPE_URL),
            'plugin_dir' => untrailingslashit(GLPE_DIR),
            'abspath'    => defined('ABSPATH') ? rtrim((string)ABSPATH, '/') : '',
            'uploads_dir'=> self::$testDir !== null ? '' : untrailingslashit((string)wp_upload_dir()['basedir']),
            'ver'        => defined('GLPE_VERSION') ? GLPE_VERSION : '',
            'gen'        => (int)get_option('glpe_diag_enabled_at', 0),
        ];
    }

    /**
     * Writes (or removes) the handoff file. Content is compared first so
     * repeated admin hits never touch the disk.
     */
    public static function syncStore() {
        $file = self::storeDir() . '/' . self::KEY_FILE;
        if (!self::enabled()) {
            $removed = true;
            foreach ([$file, self::storeDir() . '/' . self::KEY_FILE_J] as $f) {
                if (file_exists($f)) {
                    $removed = @unlink($f) && $removed;
                }
            }
            return $removed;
        }
        $payload = (string)json_encode(self::storePayload());
        $existing = @file_get_contents($file);
        if ($existing !== false && substr((string)$existing, GLPE_Hub::guardLen()) === $payload) {
            return true;
        }
        return @file_put_contents($file, GLPE_Hub::FILE_GUARD . $payload, LOCK_EX) !== false;
    }

    /** Cheap admin-hit resync (payload compare first, then write on drift). */
    public static function maybeSyncStore() {
        return self::syncStore();
    }

    /* ------------------------------------------------------------------
     * Audit journal (remote mutations)
     * ---------------------------------------------------------------- */

    public static function appendAudit($action, $detail) {
        $entry = [
            'at'     => time(),
            'ip'     => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
            'action' => (string)$action,
            'detail' => (string)$detail,
        ];
        $list = self::lastAudits();
        $list[] = $entry;
        $list = array_slice($list, -self::AUDIT_LIMIT);
        @file_put_contents(self::storeDir() . '/' . self::AUDIT_FILE, GLPE_Hub::FILE_GUARD . (string)json_encode($list), LOCK_EX);
        return true;
    }

    /** @return array last journaled remote mutations (oldest first, capped) */
    public static function lastAudits() {
        $file = self::storeDir() . '/' . self::AUDIT_FILE;
        if (!file_exists($file)) {
            return [];
        }
        $raw = (string)@file_get_contents($file);
        $data = json_decode((string)substr($raw, GLPE_Hub::guardLen()), true);
        return is_array($data) ? $data : [];
    }

    /* ------------------------------------------------------------------
     * Remote "set" — closed whitelist with per-key validation
     * ---------------------------------------------------------------- */

    /**
     * Validates one (key, value) pair from the remote API.
     * Pure function — unit-tested in the selftest, shared with the endpoint.
     * @return array [ok(bool), normalized(mixed), error(string)]
     */
    public static function validateSet($key, $value) {
        $key = (string)$key;
        $value = ($value === true || $value === '1') ? '1' : ($value === false || $value === '0' ? '0' : (string)$value);

        $boolOptions = ['glpe_ipresolve_v4', 'glpe_ssl_verify', 'glpe_no_scripts', 'glpe_no_images',
                        'glpe_toolbar', 'glpe_blank_title', 'glpe_hub_enabled'];
        if (in_array($key, $boolOptions, true)) {
            return ($value === '1' || $value === '0')
                ? [true, $value, '']
                : [false, null, 'مقدار باید 0 یا 1 باشد'];
        }
        if ($key === 'glpe_dns_mode') {
            return in_array($value, ['auto', 'doh_first', 'system'], true)
                ? [true, $value, '']
                : [false, null, 'حل نام نامعتبر است'];
        }
        $intRanges = [
            'glpe_dns_ttl'                => [60, 86400],
            'glpe_fetch_connect_timeout'  => [3, 60],
            'glpe_fetch_timeout'          => [8, 120],
            'glpe_fetch_retries'          => [1, 3],
            'glpe_hub_max_streams'        => [GLPE_Hub::STREAM_CAP_MIN, GLPE_Hub::STREAM_CAP_MAX],
        ];
        if (isset($intRanges[$key])) {
            $v = (int)$value;
            return ($v >= $intRanges[$key][0] && $v <= $intRanges[$key][1] && preg_match('/^\d+$/', (string)$value))
                ? [true, (string)$v, '']
                : [false, null, 'خارج از بازهٔ مجاز (' . $intRanges[$key][0] . ' تا ' . $intRanges[$key][1] . ')'];
        }
        // Client-config sub-keys (stored inside the glpe_hub_cfg array).
        if (strpos($key, 'cfg.') === 0) {
            $sub = substr($key, 4);
            $intCfg = [
                'max_pool_connections' => [1, 64],
                'max_retries'          => [1, 5],
                'relay_port'           => [1024, 65535],
            ];
            if (isset($intCfg[$sub])) {
                $v = (int)$value;
                return ($v >= $intCfg[$sub][0] && $v <= $intCfg[$sub][1] && preg_match('/^\d+$/', (string)$value))
                    ? [true, $v, '']
                    : [false, null, 'خارج از بازهٔ مجاز (' . $intCfg[$sub][0] . ' تا ' . $intCfg[$sub][1] . ')'];
            }
            if ($sub === 'prefer_fast_endpoint') {
                return ($value === '1' || $value === '0')
                    ? [true, $value, '']
                    : [false, null, 'مقدار باید 0 یا 1 باشد'];
            }
            $numCfg = [
                'connect_timeout'          => [1, 60],
                'stream_read_timeout'      => [1, 120],
                'write_timeout'            => [1, 90],
                'idle_timeout'             => [3, 120],
                'max_adaptive_delay'       => [0.05, 5],
                'max_tunnel_uptime'        => [30, 86400],
                'low_traffic_timeout'      => [5, 300],
                'low_traffic_threshold_mb' => [0.01, 100],
                'active_transfer_threshold_kbps' => [0.5, 1000],
            ];
            if (isset($numCfg[$sub])) {
                $v = (float)$value;
                return ($v >= $numCfg[$sub][0] && $v <= $numCfg[$sub][1] && is_numeric($value))
                    ? [true, $v, '']
                    : [false, null, 'خارج از بازهٔ مجاز'];
            }
            return [false, null, 'کلید کانفیگ ناشناخته'];
        }
        return [false, null, 'کلید تنظیم ناشناخته'];
    }

    /**
     * Applies one whitelisted option write and resyncs the handoff files.
     * Called from the remote endpoint (after the CMS is booted) and safe
     * to call from WP context directly.
     * @return array [ok(bool), old, new, error]
     */
    public static function applySet($key, $value) {
        list($ok, $norm, $err) = self::validateSet($key, $value);
        if (!$ok) {
            return [false, null, null, $err];
        }
        if (strpos((string)$key, 'cfg.') === 0) {
            $cfg = get_option('glpe_hub_cfg', []);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $old = isset($cfg[substr($key, 4)]) ? $cfg[substr($key, 4)] : null;
            $cfg[substr($key, 4)] = $norm;
            update_option('glpe_hub_cfg', $cfg);
            self::afterMutation();
            self::appendAudit('set', $key . ': ' . json_encode($old) . ' ← ' . json_encode($norm));
            return [true, $old, $norm, ''];
        }
        $old = get_option($key, null);
        update_option($key, $norm);
        self::afterMutation();
        self::appendAudit('set', $key . ': ' . json_encode($old) . ' ← ' . json_encode($norm));
        return [true, $old, $norm, ''];
    }

    /** Keeps every handoff consistent after any remote mutation. */
    private static function afterMutation() {
        if (class_exists('GLPE_Hub')) {
            GLPE_Hub::syncNodeStore();
        }
        self::syncStore();
    }

    /* ------------------------------------------------------------------
     * Admin form handlers (admin-post.php)
     * ---------------------------------------------------------------- */

    private static function backUrl($msg) {
        return add_query_arg(['page' => 'glpe-viewer', 'cp_msg' => $msg], admin_url('admin.php'));
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی مجاز نیست.');
        }
        check_admin_referer('glpe_diag_admin');
    }

    /** Generate / rotate the access key (also switches the endpoint on). */
    public static function handleGen() {
        self::guard();
        self::generateKey();
        wp_safe_redirect(self::backUrl('diag-gen'));
        exit;
    }

    /** Switch the remote endpoint off and withdraw the handoff. */
    public static function handleOff() {
        self::guard();
        self::disable();
        wp_safe_redirect(self::backUrl('diag-off'));
        exit;
    }

    /**
     * Self-test: the SERVER fetches its own endpoint exactly the way an
     * outside caller would (public address, key in the header). Useful
     * first signal — but note that some firewalls treat a server calling
     * its own public address differently from a real outside visitor.
     */
    public static function handleSelfTest() {
        self::guard();
        $key = self::key();
        $url = add_query_arg(['action' => 'info'], self::endpointUrl());
        $t0 = microtime(true);
        $res = wp_remote_get($url, [
            'timeout'    => 12,
            'sslverify'  => false,
            'redirection'=> 0,
            'headers'    => ['X-GLPE-Key' => $key],
        ]);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (is_wp_error($res)) {
            wp_safe_redirect(add_query_arg(
                ['cp_msg' => 'diag-self-fail', 'derr' => rawurlencode(mb_substr($res->get_error_message(), 0, 180))],
                admin_url('admin.php?page=glpe-viewer')
            ));
            exit;
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        wp_safe_redirect(add_query_arg(
            ['cp_msg' => 'diag-self-code', 'dcode' => $code, 'dms' => $ms],
            admin_url('admin.php?page=glpe-viewer')
        ));
        exit;
    }

    /* ------------------------------------------------------------------
     * Settings card
     * ---------------------------------------------------------------- */

    public static function renderCard() {
        $enabled = self::enabled();
        $key = self::key();
        $audits = array_slice(self::lastAudits(), -5);
        $genAt = (int)get_option('glpe_diag_enabled_at', 0);
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0;">🛠 رابط تشخیص از راه دور</h3>
            <p style="font-size: 12.5px; color: #334155; line-height: 2;">
                وقتی هاستی مشکل جدی ایجاد می‌کند (مثل پاسخ 403 به هر دو پایانهٔ گره در ارزیابی هاست)، علت معمولاً در لایهٔ وب‌سرور یا دیوار آتش هاست است و فقط از درون خود هاست قابل شناسایی است. با ساختن «کلید دسترسی»، یک دستیار مورد اعتماد می‌تواند از راه دور — فقط با همین کلید — اطلاعات فنی هاست را بخواند و تنظیمات افزونه را اصلاح کند تا علت خطا پیدا و رفع شود.
            </p>

            <?php if ($enabled && $key !== ''): ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">آدرس وب‌سایت</th>
                        <td><code style="direction:ltr;"><?php echo esc_html(untrailingslashit(home_url())); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">کلید دسترسی</th>
                        <td><code style="direction:ltr; word-break:break-all;"><?php echo esc_html($key); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">نشانی پایانه</th>
                        <td><code style="direction:ltr; word-break:break-all;"><?php echo esc_html(self::endpointUrl()); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row">کلید کامل اتصال<br><span style="font-weight:400; font-size:11px; color:#64748b;">(آدرس + کلید دسترسی)</span></th>
                        <td>
                            <input id="glpe-diag-conn" type="text" readonly value="<?php echo esc_attr(self::connectionString()); ?>" style="direction:ltr; width:100%; max-width:560px; font-family:monospace; font-size:12px; background:#f8fafc;" onclick="this.select();" />
                            <button type="button" class="button" id="glpe-diag-copy" onclick="glpeDiagCopy();">کپی کلید کامل</button>
                            <p style="font-size:11.5px; color:#64748b; margin:6px 0 0;">این یک خط را کامل کپی کرده و به دستیار خود بدهید — همان برای اتصال کافی است.</p>
                        </td>
                    </tr>
                </table>

                <p style="font-size:12px; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:8px 12px; line-height:1.9;">
                    ⚠ هر کسی که این کلید را داشته باشد می‌تواند اطلاعات فنی هاست را بخواند و تنظیمات این افزونه را تغییر دهد. آن را فقط به دستیار مورد اعتماد خود بدهید و بعد از پایان کار، با دکمهٔ زیر غیرفعالش کنید.
                </p>

                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_diag_self'), 'glpe_diag_admin')); ?>">تست پایانه (از خود هاست)</a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_diag_gen'), 'glpe_diag_admin')); ?>" onclick="return confirm('کلید فعلی بی‌اعتبار و کلید جدید ساخته می‌شود؛ مطمئنید؟');">نوسازی کلید</a>
                    <a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_diag_off'), 'glpe_diag_admin')); ?>">غیرفعال‌سازی رابط تشخیص</a>
                    <?php if ($genAt > 0): ?>
                        <span style="font-size:11.5px; color:#64748b;">(ساخته‌شده <?php echo esc_html(date_i18n('Y/m/d H:i', $genAt)); ?>)</span>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p style="font-size:12.5px; color:#64748b;">
                    این قابلیت پیش‌فرض خاموش است و تا زمانی که شما کلید نسازید هیچ درگاهی باز نیست. با کلیک روی دکمهٔ زیر، یک کلید اختصاصی ساخته و راهنمای استفاده نمایش داده می‌شود.
                </p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=glpe_diag_gen'), 'glpe_diag_admin')); ?>">ساختن کلید دسترسی</a>
                </p>
            <?php endif; ?>

            <?php if (!empty($audits)): ?>
                <h4 style="margin-bottom:6px;">آخرین عملیات‌های انجام‌شده از راه دور</h4>
                <table class="widefat striped" style="max-width:640px; font-size:12px;">
                    <thead><tr><th>زمان</th><th>نشانی فراخوان</th><th>عملیات</th><th>جزئیات</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($audits) as $a): ?>
                        <tr>
                            <td style="direction:ltr; white-space:nowrap;"><?php echo esc_html(date_i18n('m-d H:i:s', (int)$a['at'])); ?></td>
                            <td style="direction:ltr;"><?php echo esc_html((string)$a['ip']); ?></td>
                            <td><code><?php echo esc_html((string)$a['action']); ?></code></td>
                            <td style="direction:ltr; word-break:break-all; max-width:260px;"><?php echo esc_html((string)$a['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <script>
        function glpeDiagCopy() {
            var el = document.getElementById('glpe-diag-conn'), btn = document.getElementById('glpe-diag-copy');
            var done = function () { btn.textContent = '✔ کپی شد'; setTimeout(function () { btn.textContent = 'کپی کلید کامل'; }, 1600); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(el.value).then(done, function () { glpeDiagCopyFallback(el, done); });
            } else {
                glpeDiagCopyFallback(el, done);
            }
        }
        function glpeDiagCopyFallback(el, done) {
            el.focus(); el.select();
            try { document.execCommand('copy'); done(); } catch (e) { window.prompt('کلید را دستی کپی کنید:', el.value); }
        }
        </script>
        <?php
    }
}
