# 🛠️ راهنمای محیط توسعه — WordPress-GLPE

## پیش‌نیازها (Prerequisites)

| ابزار | نسخه پیشنهادی | توضیح |
|-------|---------------|-------|
| PHP CLI | 7.2 – 8.3 | کد باید با هر دو سازگار بماند (هاست‌های رایگان) |
| WordPress | 5.0+ | تست محلی با LocalWP / XAMPP / wp-env |
| Node.js | 18+ | فقط برای lint فایل‌های JS |
| Git | 2.30+ | — |

## نصب PHP در محیط‌های بدون روت (مثل همین سندباکس)

```bash
# باینری استاتیک PHP 8.3 (بدون نیاز به نصب)
mkdir -p ~/tools && cd ~/tools
curl -sL -o php.tar.gz "https://dl.static-php.dev/static-php-cli/common/php-8.3.13-cli-linux-x86_64.tar.gz"
tar xzf php.tar.gz && rm php.tar.gz
export PATH="$HOME/tools:$PATH"   # داخل ~/.bashrc هم اضافه کنید
php -v
```

## ساختار مخزن

```
wordpress-GLPE/
├── cloud-portal/          # پلاگین (این پوشه ZIP و به وردپرس آپلود می‌شود)
├── docs/                  # مستندات بازبینی و توسعه
├── tools/                 # اسکریپت‌های تست و lint
└── .github/workflows/     # CI خودکار
```

## اجرای تست‌ها

```bash
# 1) بررسی سینتکس PHP
php -l cloud-portal/cloud-portal.php
php -l cloud-portal/includes/ProxyEngine.php
php -l cloud-portal/includes/CookieJar.php
php -l cloud-portal/includes/StealthCipher.php

# 2) تست کامل خودکار (cipher + resolver + SSRF)
php tools/selftest.php

# 3) بررسی سینتکس JS
node --check cloud-portal/includes/stealthHook.js
node --check cloud-portal/includes/proxyHook.js
```

## تست روی وردپرس محلی

### روش A — wp-env (رسمی WordPress)
```bash
npm -g install @wordpress/env
# پلاگین را symlink یا کپی کنید به پوشه‌ای که wp-env mount می‌کند:
WP_ENV=cd .. && wp-env start
# فعال‌سازی: http://localhost:8888/wp-admin → Plugins → Cloud Portal
```

### روش B — LocalWP / XAMPP
1. پوشه `cloud-portal` را در `wp-content/plugins/` کپی کنید.
2. افزونه را فعال کنید — برگه `/portal/` خودکار ساخته می‌شود.
3. تست دستی: `http://site.test/?_portal=1&b=<encoded-url>` یا فرم پرتال.

## بسته‌بندی نسخه (Packaging)

```bash
# اسکریپت آماده:
bash tools/package.sh 3.3.4
# خروجی: dist/cloud-portal-wp-3.3.4.zip
```

## انتشار نسخه در GitHub

```bash
git tag v3.3.4 && git push origin v3.3.4
# سپس در GitHub: Releases → Draft new release → ZIP dist را پیوست کنید
```

## قواعد کدنویسی (این پروژه)

- PHP سازگار با **7.2** باشد: از `??`، `str_contains()` (8+)، named args، arrow fn و enum استفاده نکنید مگر با polyfill.
- هیچ وابستگی خارجی (composer/npm در پلاگین) مجاز نیست — هاست رایگان.
- متن‌های UI فارسی داخل کد فعلاً hardcode هستند؛ در فاز i18n به `__('…', 'cloud-portal')` مهاجرت می‌کنیم.
- هر تغییر روی موتور باید `tools/selftest.php` را پاس کند.
- به `docs/CODE_REVIEW.md` مراجعه کنید: باگ‌های باز B1…B11 و roadmap فازها آنجاست.
