<div align="center">

# WordPress-GLPE — GLPE Viewer

**نمایشگر صفحات وب برای وردپرس — مناسب هاست‌های رایگان و اشتراکی**
WordPress remote page display plugin — built for free/shared hosting

![Version](https://img.shields.io/badge/version-4.9.1-blue)
![WordPress](https://img.shields.io/badge/WordPress-5.3%2B-21759B)
![PHP](https://img.shields.io/badge/PHP-7.2--8.3-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

</div>

---

## 🇮🇷 معرفی

**GLPE Viewer** افزونه‌ای برای وردپرس است که امکان نمایش صفحات وب دلخواه را درون سایت شما فراهم می‌کند و به‌طور خاص برای **هاست‌های رایگان و اشتراکی** (فقط PHP، بدون دسترسی روت، بدون سرویس خارجی) طراحی شده است.

### معماری (چرا توسط هاست‌ها مزاحم‌ساز نمی‌شود؟)
- 🧩 **ترافیک از لایه استاندارد وردپرس** — تمام درخواست‌های خروجی از `wp_remote_request()` (WordPress HTTP API) ارسال می‌شوند؛ دقیقاً همان مسیری که هسته، قالب و افزونه‌ها برای به‌روزرسانی استفاده می‌کنند. تابع‌های خام کلاینت فقط به‌عنوان لایه پشتیبان باقی مانده‌اند.
- 🔤 **بدون واژه‌های حساس** — هیچ‌جای کد، نام فایل‌ها، نام کلاس‌ها، پارامترهای URL و کامنت‌ها، الگوهای آشکار (proxy / glype / browse.php / bypass / …) وجود ندارد. اسکریپت `tools/guard.sh` این موضوع را در CI به‌صورت خودکار کنترل می‌کند.
- 🔐 **کلید اختصاصی هر سایت** — پیوندهای کوتاه با کلید تصادفی تولیدشده هنگام فعال‌سازی کدگذاری می‌شوند؛ هر نصب کلید مخصوص خودش را دارد.
- 🍪 **نشست جداگانه سمت سرور** — داده‌های نشست هر سایت مقصد داخل PHP Session نگهداری می‌شود.
- 📦 **بدون وابستگی خارجی** — نه Composer، نه npm، نه فونت/CDN بیرونی.

### ویژگی‌ها
- بازنویسی کامل HTML/CSS/JS (پیوندها، فرم‌ها، تصاویر، srcset، data-attributes، SVG، iframe)
- هوک سمت کلاینت: `fetch`، `XHR`، `document.cookie`، `window.open`، `history.pushState` + MutationObserver برای محتوای داینامیک
- نوار ناوبری شناور با گزینه‌های زنده
- محافظت آدرس‌های داخلی (دو لایه: کنترل داخلی + `reject_unsafe_urls` وردپرس)
- سه لایه انتقال: WordPress HTTP API → cURL → Streams
- گزینه‌های کم‌مصرف: بدون تصاویر / بدون اسکریپت برای کاهش پهنای باند
- شورت‌کد `[glpe_viewer]` + برگه خودکار `/view/` + صفحه تنظیمات در پیشخوان
- 🔐 سطح دسترسی «مرورگر GLPE» — فقط کاربران دارای مجوز یا مدیران می‌توانند از نمایشگر استفاده و وارد شوند؛ مدیریت مجوز نقش‌ها و کاربران از پیشخوان
- 🍪 مدیریت نشست‌ها توسط کاربر — هر کاربر می‌تواند کوکی‌ها و نشست‌های ذخیره‌شده خودش را به‌تفکیک دامنه ببیند و پاک کند (`?_glpe=1&mode=sessions` + لینک از ویجت)
- حذف کامل داده‌ها هنگام حذف افزونه (`uninstall.php`)

### سازگاری سایت‌ها (تأییدشده با تست واقعی)
| نوع سرویس | وضعیت | توضیح |
|---|---|---|
| سایت‌های استاتیک و معمولی | ✅ کامل | لینک‌ها، فرم‌ها (GET/POST)، تصاویر، CSS |
| جستجو و فرم‌های سایت‌های بزرگ | ✅ کار می‌کند | تا مرحله ارسال به سرور مقصد |
| پخش مستقیم ویدیو/صدا (mp4، m3u8، فایل‌های استاندارد) | ✅ کار می‌کند | جریان مستقیم با پشتیبانی Range/206 و جستجو |
| صفحات تماشای یوتیوب | ✅ پخش‌کننده رسمی | ویدیو و تبلیغات با پخش‌کننده رسمی جاسازی‌شده |
| ورود به حساب‌ها در سایت‌های دیگر | ⚠️ تا حد زیادی | تا جایی که سرویس مقصد بررسی‌های امنیتی IP اعمال نکند |
| کپچای گوگل (صفحه «unusual traffic») | ❌ ساختاری | کپچا برای تأیید کلاینت واقعی طراحی شده و در هر پروکسی وب از کار می‌افتد |
| web.telegram.org | ❌ ساختاری | اپلیکیشن روی WebSocket + Service Worker + IndexedDB سوار است که پروکسی HTTP قابل حمل آن‌ها نیست |
| پلیرهای محافظت‌شده (SABR/PoToken مانند یوتیوب داخلی) | ❌ ساختاری | یوتیوب خودش استریم پروکسی‌شده را رد می‌کند؛ راه‌حل: پخش‌کننده رسمی جاسازی‌شده (بالا) |

### نصب
1. از بخش [Releases](https://github.com/Tobeseuss/wordpress-GLPE/releases) فایل `glpe-viewer-x.y.z.zip` را دانلود کنید.
2. وردپرس → **افزونه‌ها → افزودن → بارگذاری افزونه** و فایل ZIP را نصب کنید.
3. افزونه را فعال کنید — برگه نمایشگر در `yoursite.com/view/` ساخته می‌شود.
4. یا شورت‌کد `[glpe_viewer]` را در هر برگه/المنتور قرار دهید.

---

## 🇬🇧 Overview

**GLPE Viewer** turns a WordPress site into a remote page display tool, purpose-built for **free/shared hosting** — plain PHP only, no root, no external services.

### Why it coexists nicely with restrictive hosts
- All outbound fetching goes through **WordPress' own HTTP API** (`wp_remote_request`) — the same transport core uses for updates, so outbound traffic looks like ordinary WordPress activity.
- **No flagged vocabulary anywhere** in code, filenames, class names, query params or comments (`tools/guard.sh` enforces this in CI).
- **Per-install codec key** — short links are encoded with a random secret generated at activation, unique per site.
- Session data lives server-side in the PHP session; nothing sensitive is exposed client-side.

### Repository layout
```
wordpress-GLPE/
├── glpe-viewer/            # WordPress plugin (ship this folder as ZIP)
│   ├── glpe-viewer.php     # Main plugin file (dispatch, shortcode, admin)
│   ├── uninstall.php       # Clean data removal
│   ├── readme.txt          # WordPress.org style readme
│   └── includes/
│       ├── Engine.php      # WP HTTP transport + HTML/CSS/JS rewriter
│       ├── Cookies.php     # RFC 6265 session store
│       ├── Codec.php       # Per-site reversible link codec
│       ├── Access.php      # "GLPE Viewer" permission (capability gate + admin helpers)
│       └── client.js       # Injected client-side companion
├── docs/
│   ├── CODE_REVIEW.md      # Full review history of v3.3.3 → v4.0.0
│   └── DEVELOPMENT.md      # Dev environment & release checklist
├── tools/                  # selftest, guard, packaging
└── .github/workflows/      # CI (keyword guard + PHP lint + selftest + JS check)
```

### Release checklist (short)
```bash
bash tools/guard.sh          # keyword guard must pass
php tools/selftest.php       # tests must pass
bash tools/package.sh 4.9.1  # build dist ZIP
git commit + tag + push      # then publish GitHub Release with the ZIP
```
Full checklist: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)

## License
MIT © [Tobeseuss](https://github.com/Tobeseuss)
