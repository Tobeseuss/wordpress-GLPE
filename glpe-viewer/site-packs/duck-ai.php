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
 *    answers with its own error card until the next status exchange.
 *
 * The pack keeps the session fresh across those idle returns so the chat
 * recovers without a reload. Requires no special request headers.
 */

return [
    'id'     => 'duck-ai',
    'match'  => ['duck.ai', '*.duck.ai'],

    'label'  => 'Duck.ai — چت هوشمند',
    'note'   => 'پاسخ‌ها به‌صورت جریانی می‌آیند و نشست گفت‌وگو هنگام بازگشت به برگه تازه می‌شود.',
    'sample' => 'https://duck.ai/',

    'pack_js' => ';(function() {
  "use strict";
  try {
    if (!window.fetch || !document.addEventListener) return;
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
