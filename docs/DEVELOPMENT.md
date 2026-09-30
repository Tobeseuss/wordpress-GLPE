# 🛠️ راهنمای توسعه — WordPress-GLPE

## پیش‌نیازها

| ابزار | نسخه | توضیح |
|-------|------|-------|
| PHP CLI | 7.2 – 8.3 | کد باید با 7.2 سازگار بماند (هاست رایگان) |
| WordPress | 5.0+ | تست محلی با LocalWP / XAMPP / wp-env |
| Node.js | 18+ | فقط برای `node --check` |
| Git | 2.30+ | credential helper از `~/.secrets/github-token` می‌خواند |

## نصب PHP در محیط‌های بدون روت (سندباکس)

```bash
mkdir -p ~/tools && cd ~/tools
curl -sL -o php.tar.gz "https://dl.static-php.dev/static-php-cli/common/php-8.3.13-cli-linux-x86_64.tar.gz"
tar xzf php.tar.gz && rm php.tar.gz
export PATH="$HOME/tools:$PATH"   # به ~/.bashrc هم اضافه شود
```

## توکن GitHub (ذخیره پایدار)

توکن **خارج از پوشه پروژه** ذخیره شده تا با ریست محیط از دست نرود:

```
/home/z/.secrets/github-token   (chmod 600 — مرجع اصلی)
/home/z/.github-token           (chmod 600 — نسخه پشتیبان)
```

`~/.gitconfig` یک credential helper دارد که توکن را هنگام push از همان فایل‌ها می‌خواند؛
بنابراین remote گیت **بدون توکن** است: `origin = https://github.com/Tobeseuss/wordpress-GLPE.git`

اگر محیط کاملاً ریست شد فقط کافی است فایل توکن را دوباره بسازید و helper را به ~/.gitconfig اضافه کنید:
```ini
[credential "https://github.com"]
	helper = !f() { [ -f /home/z/.secrets/github-token ] && { echo "username=Tobeseuss"; echo "password=$(cat /home/z/.secrets/github-token)"; }; }; f
```

## ساختار مخزن

```
wordpress-GLPE/
├── glpe-viewer/          # پلاگین (این پوشه ZIP و به وردپرس آپلود می‌شود)
├── docs/                 # مستندات بازبینی و توسعه
├── tools/                # selftest، guard، package
└── .github/workflows/    # CI
```

## قواعد طلایی این مخزن (از مالک پروژه)

1. **هر تغییری باید کامیت و پوش شود** — هیچ تغییری فقط لوکال نمی‌ماند.
2. **هر نسخه جدید = GitHub Release با فایل ZIP** — همراه bump ورژن.
3. **گارد کلمات (`tools/guard.sh`) هرگز نباید قرمز شود.** اگر واژه جدیدی هاست‌ها را حساس کرد، به PATTERN اضافه می‌کنیم.
4. PHP سازگار با 7.2: بدون named args، arrow fn، enum، `str_contains()` و...
5. هر تغییر روی موتور باید `tools/selftest.php` را پاس کند.

## اجرای تست‌ها

```bash
bash tools/guard.sh              # گارد کلمات ممنوعه (شبیه‌سازی اسکنر هاست)
php -l glpe-viewer/glpe-viewer.php
php -l glpe-viewer/includes/*.php
php tools/selftest.php           # 47+ assertion
node --check glpe-viewer/includes/client.js
```

## چک‌لیست انتشار نسخه (Release Checklist)

```bash
# 1) شماره نسخه را در دو جا bump کنید:
#    - glpe-viewer/glpe-viewer.php  → Plugin Name header + GLPE_VERSION
#    - glpe-viewer/readme.txt       → Stable tag + Changelog

# 2) تست کامل:
bash tools/guard.sh && php tools/selftest.php && node --check glpe-viewer/includes/client.js

# 3) بسته‌بندی:
bash tools/package.sh X.Y.Z      # → dist/glpe-viewer-X.Y.Z.zip

# 4) کامیت، تگ، پوش:
git add -A
git commit -m " vX.Y.Z — توضیح تغییرات"
git tag vX.Y.Z
git push origin main --tags

# 5) انتشار Release با ZIP (بخش بعدی)
```

## انتشار Release از طریق API

```bash
TOKEN=$(cat /home/z/.secrets/github-token)
REPO="Tobeseuss/wordpress-GLPE"
VER="4.0.0"

# ساخت Release
REL_ID=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$REPO/releases" \
  -d "{\"tag_name\":\"v$VER\",\"name\":\"v$VER\",\"body\":\"...\"}" \
  | grep -oP '"id":\s*\K[0-9]+' | head -1)

# آپلود ZIP به Release
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/zip" \
  "https://uploads.github.com/repos/$REPO/releases/$REL_ID/assets?name=glpe-viewer-$VER.zip" \
  --data-binary "@dist/glpe-viewer-$VER.zip"
```

## تست روی وردپرس محلی

1. پوشه `glpe-viewer` را در `wp-content/plugins/` کپی کنید.
2. افزونه را فعال کنید — برگه `/view/` خودکار ساخته می‌شود.
3. تست: فرم نمایشگر در `yoursite.test/view/` یا شورت‌کد `[glpe_viewer]`.

## تاریخچه نام‌گذاری (برای مراجعه بعدی)

| نسخه 3.3.3 (قدیمی) | نسخه 4.0.0 (فعلی) |
|---------------------|--------------------|
| cloud-portal/ | glpe-viewer/ |
| cloud-portal.php | glpe-viewer.php |
| ProxyEngine.php | Engine.php |
| CookieJar.php | Cookies.php |
| StealthCipher.php | Codec.php |
| stealthHook.js / proxyHook.js | client.js (proxyHook حذف شد) |
| CloudPortalWordPressPlugin | GLPE_Plugin |
| StealthPortalEngine | GLPE_Engine |
| StealthCookieJar | GLPE_Cookies |
| StealthCipher | GLPE_Codec |
| `?_portal=1&b=` | `?_glpe=1&l=` |
| پرچم‌ها rs/ri/st/tb/enc/temp | ns / ni / nt / nb / ec / tp |
| کلید ثابت cp_vault_key | کلید per-site در آپشن glpe_secret |
| آپشن‌های cloud_portal_* | آپشن‌های glpe_* |
| shortcodes [cloud_portal] و... | فقط [glpe_viewer] |
| cURL مستقیم | wp_remote_request (وردپرس) + fallback |
