<?php
/**
 * Site pack: Duck.ai — private AI chat (duck.ai).
 *
 * What makes this destination special inside the display pipeline:
 *  - the application validates its anti-abuse signals INSIDE the visitor's
 *    browser (the challenge header returned by /duckchat/v1/status is
 *    evaluated client-side — no exchange may shortcut that, the general
 *    header relay already delivers it untouched);
 *  - chat answers arrive as server-sent event streams over a POST request
 *    (the general streaming path serves them live);
 *  - long-idle tabs come back with a stale session — the destination then
 *    answers with its own error card until the next status exchange;
 *  - the destination offers its service only to a set of countries and
 *    decides by the country of the RELAY EXIT — the hosting server behind
 *    the viewer. A visitor's own address and location never reach the
 *    destination (every exchange rides the encrypted gateway), so when
 *    this policy hits, the message reflects the host region, not the
 *    visitor's. The pack turns that case into a clear explanation instead
 *    of a broken app: bare restriction pages are replaced with a full
 *    bilingual card, and a client-side watcher surfaces the same card when
 *    the application draws the restriction message inside its own UI.
 *
 * The pack keeps the session fresh across idle returns so the chat
 * recovers without a reload. Requires no special request headers.
 */

return [
    'id'     => 'duck-ai',
    'match'  => ['duck.ai', '*.duck.ai'],

    'label'  => 'Duck.ai — چت هوشمند',
    'note'   => 'پاسخ‌ها به‌صورت جریانی می‌آیند، نشست گفت‌وگو هنگام بازگشت به برگه تازه می‌شود و پیام محدودیت منطقه‌ای مقصد به توضیح روشن تبدیل می‌شود.',
    'sample' => 'https://duck.ai/',

    // This pack ships its own region-restriction layers (document card +
    // client watcher); the dispatcher's generic region banner stays off so
    // the visitor never sees two notices stacked on one page.
    'region_handling' => true,

    'doc_filter' => function ($html, $targetUrl = '', $options = []) {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        if (!preg_match('#not supported in your country|unsupported country|not available in your (country|region)#i', $html)) {
            return $html;
        }

        // Bare restriction page: the destination answered its policy card
        // instead of the application shell. Replace the whole document so
        // the visitor gets one clear explanation rather than a dead shell.
        $bare = strlen(trim((string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html))) < 1200
            && strlen(trim(strip_tags($html))) < 800;
        if ($bare) {
            return glpe_duck_region_card();
        }

        // Application shell present (the message was embedded in markup):
        // keep the document and add the fixed explanation bar.
        return $html . glpeRegionBarHtml();
    },

    'pack_js' => ';(function() {
  "use strict";
  try {
    if (!window.fetch || !document.addEventListener) return;

    // -- region-restriction watcher -------------------------------------
    // The application draws the destination\'s own policy message inside
    // its UI after a restricted exchange; the watcher mirrors it as a
    // clear explanation bar for as long as the message is on screen.
    try {
      var rxRegion = /not supported in your country|unsupported country|not available in your (country|region)/i;
      var noteEl = null;
      var pending = false;
      var scan = function() {
        try {
          var b = document.body;
          if (!b) return;
          // innerText only — it reflects what the visitor actually sees;
          // textContent would also swallow the destination\'s own hidden
          // script strings (i18n bundles carry the very same sentence) and
          // keep the bar up forever. The live element is re-queried every
          // scan so the flag can never fall out of sync with the DOM.
          var txt = b.innerText || "";
          var hit = rxRegion.test(txt);
          noteEl = document.getElementById("glpe-region-note");
          if (hit && !noteEl) {
            noteEl = document.createElement("div");
            noteEl.setAttribute("id", "glpe-region-note");
            noteEl.setAttribute("dir", "rtl");
            noteEl.style.cssText = "position:fixed;bottom:0;left:0;right:0;z-index:2147483646;"
              + "background:#78350f;color:#fde68a;font:12px/1.9 Tahoma,sans-serif;"
              + "padding:10px 16px;text-align:center;border-top:1px solid #f59e0b;"
              + "box-shadow:0 -4px 14px rgba(0,0,0,0.4);";
            noteEl.innerHTML = "\u26d4 \u0627\u06cc\u0646 \u067e\u06cc\u0627\u0645 \u0627\u0632 \u0633\u0648\u06cc \u062e\u0648\u062f\u0650 \u0648\u0628\u200c\u0633\u0627\u06cc\u062a \u0645\u0642\u0635\u062f \u0627\u0633\u062a\u060c \u0646\u0647 \u0646\u0642\u0635 \u0646\u0645\u0627\u06cc\u0634\u06af\u0631: "
              + "\u0633\u0631\u0648\u06cc\u0633 \u0641\u0642\u0637 \u0628\u0647 \u0628\u0631\u062e\u06cc \u06a9\u0634\u0648\u0631\u0647\u0627 \u0627\u0631\u0627\u0626\u0647 \u0645\u06cc\u200c\u0634\u0648\u062f \u0648 \u062a\u0635\u0645\u06cc\u0645 \u0628\u0631 \u0627\u0633\u0627\u0633 \u06a9\u0634\u0648\u0631\u0650 \u0622\u06cc\u200c\u067e\u06cc \u00ab\u062e\u0631\u0648\u062c\u06cc \u0631\u0644\u0647\u00bb (\u0645\u06cc\u0632\u0628\u0627\u0646 \u0646\u0645\u0627\u06cc\u0634\u06af\u0631) \u0627\u0633\u062a. "
              + "\u0646\u0634\u0627\u0646\u06cc \u0648 \u0645\u0648\u0642\u0639\u06cc\u062a \u0634\u0645\u0627 \u0647\u0631\u06af\u0632 \u0628\u0647 \u0645\u0642\u0635\u062f \u0646\u0645\u06cc\u200c\u0631\u0633\u062f. "
              + "\u0631\u0627\u0647 \u062d\u0644: \u0645\u06cc\u0632\u0628\u0627\u0646\u06cc \u0646\u0645\u0627\u06cc\u0634\u06af\u0631 \u0631\u0648\u06cc \u0633\u0631\u0648\u0631\u06cc \u062f\u0631 \u06cc\u06a9\u06cc \u0627\u0632 \u06a9\u0634\u0648\u0631\u0647\u0627\u06cc \u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc\u200c\u0634\u062f\u0647\u0650 \u0622\u0646 \u0633\u0627\u06cc\u062a.";
            (document.body || document.documentElement).appendChild(noteEl);
          } else if (!hit && noteEl && noteEl.parentNode) {
            noteEl.parentNode.removeChild(noteEl);
            noteEl = null;
          }
        } catch (e) {}
      };
      var schedule = function() {
        if (pending) return;
        pending = true;
        setTimeout(function() { pending = false; scan(); }, 800);
      };
      try {
        var mo = new MutationObserver(schedule);
        var root = document.documentElement || document;
        mo.observe(root, { childList: true, subtree: true, characterData: true });
      } catch (e) {}
      scan();
    } catch (e) {}

    // -- idle-session refresh -------------------------------------------
    var lastSeen = Date.now();
    document.addEventListener("visibilitychange", function() {
      try {
        if (document.visibilityState !== "visible") return;
        if (Date.now() - lastSeen < 300000) return; // only after five idle minutes
        lastSeen = Date.now();
        // The display companion wraps this relative reference onto the real
        // destination automatically — no readable address rides the wire.
        fetch("/duckchat/v1/status", {
          headers: { "x-vqd-accept": "1", "Cache-Control": "no-store" }
        }).catch(function() {});
      } catch (e) {}
    });
  } catch (e) {}
})();',
];

/**
 * Fixed explanation bar (same wording as the client-side watcher) — used
 * when the restriction message arrives inside the served markup while the
 * application shell is still present.
 */
function glpeRegionBarHtml() {
    return '<div id="glpe-region-note" dir="rtl" style="position:fixed;bottom:0;left:0;right:0;z-index:2147483646;'
        . 'background:#78350f;color:#fde68a;font:12px/1.9 Tahoma,sans-serif;padding:10px 16px;text-align:center;'
        . 'border-top:1px solid #f59e0b;box-shadow:0 -4px 14px rgba(0,0,0,0.4);">'
        . '&#9940; این پیام از سوی خودِ وب‌سایت مقصد است، نه نقص نمایشگر: '
        . 'سرویس فقط به برخی کشورها ارائه می‌شود و تصمیم بر اساس کشورِ آی‌پیِ «خروجی رله» (میزبان نمایشگر) است. '
        . 'نشانی و موقعیت شما هرگز به مقصد نمی‌رسد. '
        . 'راه حل: میزبانی نمایشگر روی سروری در یکی از کشورهای پشتیبانی‌شدهٔ آن سایت.'
        . '</div>';
}

/**
 * Full replacement document for bare restriction pages. The original
 * destination message is paraphrased (never quoted verbatim) so downstream
 * generic detectors cannot stack a second notice onto this card.
 */
function glpe_duck_region_card() {
    $home = function_exists('home_url') ? home_url('/') : '/';
    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>پیام وب‌سایت مقصد</title><style>'
        . 'body{font-family:Tahoma,sans-serif;background:#0f172a;color:#f8fafc;padding:24px;margin:0;}'
        . '.box{max-width:620px;margin:6vh auto;background:#1e293b;padding:26px 28px;border-radius:16px;border:1px solid #334155;}'
        . 'h2{color:#fbbf24;margin-top:0;font-size:17px;line-height:1.8;}'
        . 'p{font-size:13px;line-height:2;color:#cbd5e1;margin:10px 0;}'
        . '.msg{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px 14px;font-size:12px;color:#94a3b8;}'
        . 'ul{font-size:13px;line-height:2.1;color:#cbd5e1;padding-right:20px;margin:10px 0;}'
        . 'a{display:inline-block;margin-top:14px;color:#38bdf8;text-decoration:none;font-weight:bold;font-size:13px;}'
        . '</style></head><body><div class="box">'
        . '<h2>&#9940; این پیام از سوی خودِ وب‌سایت مقصد است — نه نقص نمایشگر</h2>'
        . '<p>سایت مقصد خدمت خود را فقط به برخی کشورها ارائه می‌دهد و این تصمیم را بر اساس کشورِ آی‌پیِ '
        . '<b style="color:#fbbf24;">«خروجی رله»</b> — یعنی سرورِ میزبانِ همین نمایشگر — می‌گیرد.</p>'
        . '<p><b style="color:#fde68a;">نشانی و موقعیت مکانی شما هرگز به مقصد نمی‌رسد.</b> '
        . 'همهٔ تبادلات داخل رمز از دروازهٔ همین میزبان می‌گذرد؛ مقصد فقط کشورِ همین سرور را می‌بیند و به‌ازای آن پیام بالا را نشان می‌دهد.</p>'
        . '<div class="msg">پیام اصلی مقصد: سرویس برای منطقهٔ خروجیِ رله در دسترس نیست (Service Unavailable).</div>'
        . '<p style="margin-bottom:4px;"><b style="color:#fbbf24;">راه حل:</b></p>'
        . '<ul>'
        . '<li>میزبانِ نمایشگر (خروجی رله) را روی سروری در یکی از کشورهای پشتیبانی‌شدهٔ آن سایت قرار دهید؛ پس از تغییر میزبان، این پیام از بین می‌رود.</li>'
        . '<li>این محدودیت، سیاست خودِ سایت مقصد است و به نمایشگر مربوط نیست — بقیهٔ سایت‌ها به‌طور عادی کار می‌کنند.</li>'
        . '</ul>'
        . '<a href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">&#8592; بازگشت به برگه نمایشگر</a>'
        . '</div></body></html>';
}
