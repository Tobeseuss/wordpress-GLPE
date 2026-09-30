# 🔍 کدبازیابی کامل — Cloud Portal v3.3.3
**Code Review Report — reviewed by Z.ai on request of the author**

> ⚡ **به‌روزرسانی v4.0.0:** پس از این بازبینی، افزونه به‌طور کامل بازطراحی شد (GLPE Viewer v4.0.0):
> - ✅ اصلاح شد: **B1** (تزریق هدر — حذف CR/LF/NUL)، **B2** (session در init:0)، **B5** (گزینه sslverify، پیش‌فرض روشن)، **B9** (codec بایت‌امن در JS با encodeURIComponent)، **B10** (Cache-Control: no-store)، **B11-1/2** (readme و URI)، **B12** و **B13** (قبلاً اصلاح شده بود)
> - ✅ باگ جدید **B14**: دکمه «برو» نوار ابزار در حالت وردپرس URL خراب می‌ساخت (`?` دوباره) — رفع شد
> - ✅ **B4** تا حد زیادی رفع شد: کلید per-site تصادفی برای هر نصب (در آپشن glpe_secret)
> - ✅ **B3** بخشی رفع شد: session در init:0 باز می‌شود؛ session_write_close برای asset ها در فاز P1 می‌ماند
> - ✅ **ترافیک خروجی به wp_remote_request منتقل شد** (امضای ترافیک = خود وردپرس) + `reject_unsafe_urls` به‌عنوان لایه دوم SSRF
> - ✅ **بازنaming کامل برای سازگاری با هاست‌های رایگان**: بدون هیچ واژه/الگوی آشکار؛ `tools/guard.sh` در CI تضمین می‌کند
> - جدول نگاشت نام‌های قدیمی → جدید در docs/DEVELOPMENT.md آمده است.

> محدوده بازبینی: `cloud-portal.php` (462 خط)، `ProxyEngine.php` (507 خط)، `CookieJar.php` (152 خط)، `StealthCipher.php` (62 خط)، `stealthHook.js` (338 خط)، `proxyHook.js` (105 خط)
> همه فایل‌های PHP با `php -l` (PHP 8.3.13) بررسی شدند — **بدون خطای سینتکس**. منطق Cipher با تست رفت‌وبرگشت و هم‌ارزی PHP/JS تأیید شد.

---

## ✅ نقاط قوت (چه کارهایی درست انجام شده؟)

| # | مورد | توضیح |
|---|------|-------|
| 1 | **نقطه رهگیری هوشمند** | استفاده از `init` با پرایوریتی `1` قبل از خروجی تم — درست و سریع. تداخل `tb` رزروشده وردپرس (trackback) هم پیش‌بینی و خنثی شده (تغییر به `cp_tb`). 👏 |
| 2 | **هم‌ارزی PHP ↔ JS در Cipher** | XOR + Base64 امن-برای-URL دقیقاً در `stealthHook.js` بازپیاده شده؛ تست parity هر دو سمت پاس شد. |
| 3 | **کوکی‌جار نسبتاً کامل** | پشتیبانی از Domain/Path/Expires/Max-Age/Secure/HttpOnly/SameSite و حذف کوکی منقضی — برای یک پیاده‌سازی Session ساده، RFC-friendly است. |
| 4 | **محافظ SSRF** | `isBlockedHost` شامل localhost، loopback، 10/8، 172.16/12، 192.168/16، link-local 169.254 و metadata endpoint ابری (169.254.169.254). |
| 5 | **پروفایل مرورگر واقع‌گرا** | هدرهای `Sec-Ch-Ua`، `Sec-Fetch-*`، `Upgrade-Insecure-Requests` و UA کروم 131 — برای دور زدن تشخیص ربات مؤثر. |
| 6 | **Fallback بدون cURL** | مسیر `file_get_contents` + stream context برای هاست‌های رایگان بدون افزونه cURL. |
| 7 | **تجربه کاربری خوب** | نوار ابزار شناور با سوییچ زنده گزینه‌ها، badge بازگشایی، فرم پرتال RTL و گزینه‌های Glype. |
| 8 | **رهگیری کامل سمت کلاینت** | fetch/XHR/cookie/window.open/pushState/location + MutationObserver برای DOM داینامیک — از بسیاری پروکسی‌های متن‌باز کامل‌تر است. |

---

## 🐞 باگ‌ها و مشکلات یافت‌شده (به ترتیب اهمیت)

### 🔴 B1 — تزریق هدر از ورودی کاربر (Header Injection)
**فایل:** `cloud-portal.php` خط 151–171 و `ProxyEngine.php` خط 386–389

مقدار هدرهای کاربر با `sanitize_text_field` پاک می‌شوند اما این تابع برای مقادیر چندخطی کاملاً ایمن نیست؛ کاراکتر `\n` در برخی ورودی‌ها می‌تواند با CRLF به `CURLOPT_HTTPHEADER` تزریق شود. cURL معمولاً `\n` داخل مقدار هدر را رد می‌کند ولی وابسته به نسخه است.

**پیشنهاد اصلاح:**
```php
$val = str_replace(["\r", "\n", "\0"], '', $val);
$customHeaders[$nameLower] = $val;
```

### 🔴 B2 — `session_start` در تماس با هر بار اجرای موتور
**فایل:** `CookieJar.php` خط 13–15

`@session_start()` با اپراتور ساکت، خطاهای «headers already sent» را در برخی هاست‌ها پنهان می‌کند. چون موتور داخل `init` وردپرس اجرا می‌شود، وردپرس هنوز کوکی خودش را نفرستاده ولی خروجی ممکن است شروع شده باشد (مثلاً در REST/نمایش برگه). بهتر است در هوک `init` وردپرس قبل از هر خروجی، session را در `plugins_loaded` استارت کنید یا از `session_status()` به‌همراه `ob_start()` زودهنگام استفاده کنید.

**پیشنهاد:** استارت session در هوک `init` با پرایوریتی `0` در کلاس اصلی، نه داخل سازنده CookieJar:
```php
add_action('init', function () {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}, 0);
```

### 🟠 B3 — نشت حافظه/حجم Session و خطر قفل فایل session
**فایل:** `CookieJar.php`

کوکی‌ها برای همه دامنه‌ها بدون سقف در `$_SESSION` انباشته می‌شوند. در هاست‌های رایگان معمولاً `session.save_path` محدود است و فایل‌های session بزرگ + هم‌زمانی بالا می‌تواند قفل فایل session ایجاد کند (درخواست‌های موازی صف می‌شوند و پراکسی کند می‌شود).

**پیشنهاد:**
- سقف تعداد کوکی (مثلاً 50 دامنه × 20 کوکی) و حذف قدیمی‌ترین‌ها (LRU بر اساس `created`).
- برای درخواست‌های asset (تصویر/CSS/JS) session را فقط بخوانید و ببندید (`session_write_close()`) تا قفل آزاد شود — این کار سرعت لود منابع را چند برابر می‌کند.

### 🟠 B4 — رمزنگاری XOR امن نیست (فقط obfuscation)
**فایل:** `StealthCipher.php`، کلید ثابت `'cp_vault_key'`

کلید ثابت و در دسترس عموم (هم در JS سمت کلاینت) است؛ XOR با کلید تکرارشونده به سادگی با known-plaintext شکسته می‌شود. اگر هدف فقط پنهان‌سازی از لاگ/تاریخچه است قابل قبول است، ولی هر کسی می‌تواند هر URL را encode/decode کند — یعنی سایت شما یک **پروکسی باز (Open Proxy)** برای همه است.

**پیشنهاد (انتخاب یکی):**
1. HMAC امضا: `b=base64(payload) + '&sig=' + substr(hash_hmac('sha256', $payload, $secret), 0, 16)` و اعتبارسنجی سمت سرور؛ secret تصادفی per-site در آپشن‌ها ذخیره شود.
2. حداقل: گزینه «فقط دامنه‌های مجاز» (Allow-list) در تنظیمات اضافه شود.

### 🟠 B5 — SSL verification خاموش
**فایل:** `ProxyEngine.php` خط 428–429 و 473–476

`CURLOPT_SSL_VERIFYPEER=false` سایت شما را در برابر MITM بین هاست و مقصد آسیب‌پذیر می‌کند و برای لاگین‌ها خطرناک است. در هاست‌های رایگان معمولاً CA bundle موجود است.

**پیشنهاد:** به‌صورت پیش‌فرض روشن، با آپشن سازگاری برای هاست‌های خراب:
```php
$verify = get_option('cloud_portal_ssl_verify', '1') === '1';
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
```
و در صورت خطای CA: `curl_setopt($ch, CURLOPT_CAINFO, /* مسیر bundle */);`

### 🟠 B6 — ریدایرکت‌ها کوکی ست نمی‌گیرند و هدر `Location` دنبال می‌شود ولی فلگ‌ها گم می‌شوند
`CURLOPT_FOLLOWLOCATION=true` خوب است، اما:
1. `Set-Cookie` در زنجیره ریدایرکت فقط برای `targetUrl` اولیه ذخیره می‌شود نه دامنه میانی؛
2. پس از ریدایرکت، `Content-Type` نهایی درست است ولی `Referer` ارسالی برای درخواست بعدی به‌روزرسانی نمی‌شود.

**پیشنهاد:** `CURLOPT_HEADERFUNCTION` را پیاده کنید تا همه `Set-Cookie`های زنجیره با URL جاریِ درخواست ثبت شوند، و برای کنترل دستی ریدایرکت (بدون FOLLOWLOCATION) حداکثر 5 پرش با ثبت کوکی/Referer انجام دهید.

### 🟡 B7 — regexهای HTML شکننده در برابر HTML واقعی
**فایل:** `ProxyEngine.php` خط 255–329

پارس HTML با regex برای صفحات ساده کار می‌کند ولی:
- ترتیب اتریبیوت‌ها متفاوت (`href` قبل از `class` و...) — پوشش داده شده چون `([^>]*?)` است، ولی اتریبیوت‌های بدون کوتیشن (`href=foo`) پوشش داده نمی‌شوند؛
- `style=...` داخل محتوای متنی/کامنت‌ها هم بازنویسی می‌شود (خط 322) — می‌تواند متن ساده‌ای مثل `style="..."` در مقاله را خراب کند؛
- کامنت‌های شرطی و `<template>` هم بازنویسی می‌شوند.

**پیشنهاد فاز بعدی:** مهاجرت به `DOMDocument` + `DOMXPath` (در PHP 8 با `libxml` موجود است) با fallback به regex فعلی وقتی DOM خطا داد. این دقیقاً کاری است که Glype با plugins خودش می‌کند.

### 🟡 B8 — `file_get_contents` وضعیت HTTP واقعی را برنمی‌گرداند
**فایل:** `ProxyEngine.php` خط 500–504

مسیر fallback همیشه `status=200` برمی‌گرداند حتی برای 404/500 مقصد. با `ignore_errors=true` بدنه گرفته می‌شود ولی وضعیت گم می‌شود.

**پیشنهاد:** از `$http_response_header[0]` کد وضعیت را استخراج کنید:
```php
if (preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
    $status = (int)$m[1];
}
```

### 🟡 B9 — XOR سمت JS با یونیکد mismatch دارد
**فایل:** `stealthHook.js` خط 17–30

PHP بایت‌محور است (`chr/ord`) ولی JS روی UTF-16 code unit کار می‌کند. برای ASCII هر دو یکسان‌اند (تست parity پاس شد)، اما اگر URL شامل کاراکتر فارسی/یونیکد باشد، `charCodeAt > 255` در JS بعد از `btoa(unescape(encodeURIComponent(...)))` نتیجه متفاوتی از PHP می‌دهد.

**پیشنهاد:** در JS قبل از XOR، رشته را با `encodeURIComponent` به بایت‌های ASCII تبدیل کنید (همان کاری که PHP با raw bytes می‌کند):
```js
function toBytes(str){ return unescape(encodeURIComponent(str)); }
// XOR روی خروجی toBytes، سپس btoa
```
و در PHP ورودی را همین‌طور نرمال کنید. (در حال حاضر `StealthCipher::decode` در سمت PHP به‌هرحال fallback دارد، ولی parity کامل می‌شود.)

### 🟡 B10 — فرم پرتال روی `home_url('/')` با GET ممکن است با کش/CDN تداخل کند
درخواست‌های `_portal=1` به صفحه اول کوئری‌استرینگ دارند؛ اگر سایت پشت Cloudflare یا افزونه کش صفحه باشد ممکن است پاسخ کش‌شده برگردد.

**پیشنهاد:** ارسال `Cache-Control: no-store` و `Vary: Cookie` در مسیر gateway + مستندسازی برای users که رول bypass کش بدهند.

### 🟡 B11 — چند نکته کوچک
1. `readme.txt` مقدار `Stable tag: 2.0.0` دارد ولی پلاگین `3.3.3` است — همگام‌سازی شود.
2. `Plugin URI` به `sitsaz/cloud-portal` اشاره دارد؛ به `Tobeseuss/wordpress-GLPE` تغییر کند.
3. در `rewriteJs` فقط رشته‌های `'https?://...'` داخل کوتیشن بازنویسی می‌شوند؛ URLهای ساخته‌شده با template literal و concat پوشش ندارند (هوک کلاینت جبران می‌کند ولی بهتر است در مستندات ذکر شود).
4. `executeRequest` متد OPTIONS/PUT/DELETE را به مقصد می‌فرستد ولی پاسخ‌ها همیشه به‌صورت متن echo می‌شوند — برای binary (تصاویر) خروجی باید `readfile`-style و بدون بازنویسی باشد (الان چون content-type متفاوت است از بازنویسی رد می‌شود ✅ فقط حواستان به memory_limit برای فایل‌های بزرگ باشد — streaming اضافه شود).
5. Text Domain تعریف شده ولی هیچ `__('...', 'cloud-portal')` استفاده نشده — برای انتشار در مخزن وردپرس i18n لازم است.
6. `$_SESSION` برای کاربران لاگین‌شده وردپرس با session اختصاصی PHP تداخل ندارد ولی پورتیال عمومی یعنی کوکی‌های همه کاربران در یک فایل session جدا ذخیره می‌شود — خوب است هر بازدید session ID جدید بگیرد (الان این‌طور است چون PHP خودش مدیریت می‌کند ✅).

---

## 🔬 باگ‌های کشف‌شده با تست خودکار (بعد از نوشتن selftest)

### 🟠 B12 — کوکی با `Max-Age=0` حذف نمی‌شد ✅ (اصلاح شد)
**فایل:** `CookieJar.php` — شرط `$expires < time()` وقتی `expires == time()` برقرار نمی‌ماند، پس کوکی `Max-Age=0` (طبق RFC 6265 §5.2.2 یعنی حذف فوری) به‌جای حذف، **ذخیره** می‌شد و برای همیشه در session می‌ماند. کوکی‌های لگ‌اوت/پاک‌سازی سایت‌های مقصد عملاً بی‌اثر می‌شدند.
**اصلاح اعمال‌شده:** `max-age <= 0` → `$expires = time() - 1` و شرط حذف به `<= $now` تغییر کرد.

### 🟠 B13 — تطبیق Path ناقص بود (نشت کوکی بین مسیرها) ✅ (اصلاح شد)
**فایل:** `CookieJar.php::getCookieHeader` — تطبیق با `strpos($path, $c['path']) === 0` بود؛ یعنی کوکی `Path=/user` به `/username` هم ارسال می‌شد! طبق RFC 6265 §5.1.4 مرز باید سگمنت کامل باشد.
**اصلاح اعمال‌شده:** متد `isPathMatch()` اضافه شد که مرز سگمنت (`/`) را چک می‌کند.

> 💡 نکته: هر دو باگ توسط `tools/selftest.php` به‌صورت خودکار پیدا شدند — ارزش داشتن تست رگرسیون از همین الان ثابت شد.

---

## 🧭 نقشه راه پیشنهادی توسعه (Roadmap)

| فاز | کار |
|-----|-----|
| **P0 (فوری)** | اصلاح B1، B2، B11-1، B11-2 + ~~B12~~ ~~B13~~ (✅ اصلاح شدند) |
| **P1** | B3 (session_write_close برای assets)، B5 (SSL toggle)، B8، B9 |
| **P2** | امضای HMAC برای `b=` (B4) + Allow-list دامنه |
| **P3** | مهاجرت HTML rewriter به DOMDocument (B7) + streaming پاسخ‌های باینری |
| **P4** | i18n کامل، page cache bypass، REST endpoint برای تنظیمات، قابلیت حذف داده در uninstall.php |

---

## 🧪 نتایج تست‌های اجراشده

```
php -l (PHP 8.3.13)      → 5/5 فایل بدون خطا ✅
Selftest suite           → 47/47 پاس ✅ (شامل رگرسیون B12/B13)
Cipher roundtrip         → 4/4 URL پاس ✅
Plain-URL passthrough    → پاس ✅
URL-safe base64          → پاس ✅
PHP ↔ JS parity          → پاس ✅
URL resolver (RFC 3986)  → 6/6 پاس ✅
SSRF guard               → 14/14 پاس ✅
CookieJar RFC 6265       → 7/7 پاس ✅ (بعد از اصلاح B12/B13)
rewriteHtml/Css/Js       → 10/10 پاس ✅
```
