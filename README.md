<div align="center">

# WordPress-GLPE — Cloud Portal & Web Viewer

**پلاگین وب‌پروکسی وردپرس برای هاست‌های رایگان**
WordPress Web-Proxy Plugin for Free Hosts — Glype-style stealth engine

![Version](https://img.shields.io/badge/version-3.3.3-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759B)
![PHP](https://img.shields.io/badge/PHP-7.2--8.3-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

</div>

---

## 🇮🇷 معرفی

**Cloud Portal & Web Viewer** یک پلاگین وردپرس است که امکان مرور وب از طریق سایت شما (Web Proxy) را فراهم می‌کند و به‌طور خاص برای **هاست‌های رایگان و اشتراکی** (فقط PHP، بدون دسترسی روت، بدون VPS) طراحی شده است.

### ویژگی‌ها
- 🕵️ **معماری نامحسوس (Stealth)** — هیچ ردپای پروکسی در URL، هدرها یا خروجی دیده نمی‌شود؛ درخواست‌ها از `?_portal=1&b=...` عبور می‌کنند
- 🔐 **رمزنگاری آدرس‌ها (StealthCipher)** — XOR + Base64 امن برای URL؛ آدرس مقصد در تاریخچه مرورگر، لاگ سرور و فیلترشکن‌ها ظاهر نمی‌شود
- 🍪 **کوکی‌جار RFC 6265** — مدیریت کامل کوکی‌های دامنه مقصد (Domain/Path/Expires/Secure/HttpOnly/SameSite) داخل Session سمت سرور
- 🧩 **هوک سمت کلاینت (stealthHook.js)** — رهگیری `fetch`، `XMLHttpRequest`، `document.cookie`، `window.open`، `history.pushState` و فرم‌ها برای پشتیبانی SPA و AJAX
- 🖥️ **نوار ابزار شناور Glype-style** — ناوبری، جستجوی جدید و سوییچ گزینه‌ها بدون خروج از صفحه
- 🚫 **مصون‌سازی Frame-Buster** — مقصد نمی‌تواند از قاب خارج شود
- 🛡️ **محافظ SSRF** — دسترسی به localhost و شبکه‌های داخلی مسدود است
- ⚙️ **صفحه تنظیمات در پیشخوان** + شورت‌کد `[cloud_portal]` + ساخت خودکار برگه `/portal/`

### نصب
1. پوشه `cloud-portal` را ZIP کنید (یا از بخش Releases فایل آماده بگیرید).
2. در وردپرس: **افزونه‌ها → افزودن → بارگذاری افزونه** و فایل ZIP را نصب کنید.
3. افزونه را فعال کنید — برگه پرتال در `yoursite.com/portal/` ساخته می‌شود.
4. یا شورت‌کد `[cloud_portal]` را در هر برگه/المنتور قرار دهید.

---

## 🇬🇧 Overview

**Cloud Portal & Web Viewer** is a WordPress plugin that turns your site into a web proxy (portal), purpose-built for **free/shared hosting** — plain PHP only, no root, no VPS, no external services.

### Features
- **Stealth gateway** — proxy requests are intercepted at `init` (priority 1) via `?_portal=1&b=...`; no proxy keywords leak into URLs, headers, or output
- **URL cipher** — reversible XOR + URL-safe Base64 (PHP ↔ JS parity verified), hiding target URLs from history/logs/filters
- **RFC 6265 cookie jar** — full cookie semantics (domain, path, expiry, Secure/HttpOnly/SameSite) stored server-side in the PHP session
- **Client-side hook** — intercepts `fetch`, `XHR`, `document.cookie`, `window.open`, `history.pushState/replaceState`, form submits, plus a MutationObserver that rewrites dynamically injected assets — SPA/AJAX friendly
- **Floating Glype-style toolbar** with live option toggles and reopen badge
- **Frame-buster neutralization** and CSP/X-Frame-Options header stripping
- **SSRF guard** — blocks localhost, loopback, RFC1918 and link-local targets
- **Fallback transport** — cURL if available, otherwise `file_get_contents` stream context (free-host friendly)

### Repository layout
```
wordpress-GLPE/
├── cloud-portal/          # WordPress plugin (ship this folder as ZIP)
│   ├── cloud-portal.php   # Main plugin file (gateway, shortcode, admin)
│   ├── readme.txt         # WordPress.org style readme
│   └── includes/
│       ├── ProxyEngine.php    # HTTP client + HTML/CSS/JS rewriter
│       ├── CookieJar.php      # RFC 6265 session cookie jar
│       ├── StealthCipher.php  # XOR + Base64 URL cipher
│       ├── stealthHook.js     # Injected client-side hook
│       └── proxyHook.js       # Legacy standalone hook (kept for reference)
├── docs/
│   ├── CODE_REVIEW.md     # Full review of the existing codebase
│   └── DEVELOPMENT.md     # Dev environment & contribution guide
├── tools/                 # Lint & self-test scripts
└── .github/workflows/     # CI (PHP lint + JS syntax check)
```

### Packaging
```bash
cd wordpress-GLPE
zip -r cloud-portal-wp.zip cloud-portal -x '*.DS_Store'
# Upload via WordPress → Plugins → Add New → Upload
```

## Development

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md). Quick start:

```bash
# PHP syntax check (PHP 7.2 – 8.3 compatible code)
php -l cloud-portal/cloud-portal.php
php -l cloud-portal/includes/*.php

# Cipher self-test (roundtrip + JS/PHP parity)
php tools/selftest.php
```

## License
MIT © [Tobeseuss](https://github.com/Tobeseuss)
