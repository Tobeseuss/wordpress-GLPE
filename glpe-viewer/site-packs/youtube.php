<?php
/**
 * Site pack: YouTube — watch pages get the official embed player.
 *
 * Why a special solution: the in-page player cannot operate inside the
 * display pipeline — the destination's server-side streaming stack rejects
 * streams whose session was not opened by the real client. Every watch page
 * therefore swaps its player for the official embed player: the iframe is
 * injected VERBATIM (never rewritten) and loads directly in the visitor's
 * own browser, so video, playback controls and advertisements behave
 * exactly like the original site.
 */

function glpe_site_pack_youtube_video_id($targetUrl) {
    if (preg_match('#youtube(?:-nocookie)?\.com/watch\?(?:[^\#]*&)?v=([A-Za-z0-9_-]{6,20})#i', $targetUrl, $vm)) {
        return $vm[1];
    }
    if (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,20})#i', $targetUrl, $vm)) {
        return $vm[1];
    }
    return '';
}

return [
    'id'    => 'youtube',
    'match' => ['youtube.com', '*.youtube.com', 'youtu.be', 'youtube-nocookie.com'],

    'label' => 'یوتیوب — پخش ویدیو',
    'note'  => 'صفحه‌های ویدیو به‌جای پخش‌کننده داخلی، پخش‌کنندهٔ رسمی یوتیوب را مستقیم در مرورگر شما نشان می‌دهند.',
    'sample' => 'https://www.youtube.com/',

    'doc_filter' => function($html, $targetUrl, $options = []) {
        $videoId = glpe_site_pack_youtube_video_id((string)$targetUrl);
        if ($videoId === '' || !preg_match('#^[A-Za-z0-9_-]+$#', $videoId)) {
            return $html;
        }
        $playerHtml = '<style>'
            . 'ytd-player,#movie_player,#player-container-01,.html5-video-player,#player-wrap{display:none!important}'
            . '</style>'
            . '<div id="__glpe_ytplayer" style="position:relative;z-index:2147483646;background:#000;'
            . 'margin:52px auto 0 auto;max-width:960px;width:calc(100% - 24px);aspect-ratio:16/9;">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/' . $videoId . '" '
            . 'style="position:absolute;inset:0;width:100%;height:100%;border:0;" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
            . 'allowfullscreen title="Video player"></iframe></div>';
        if (stripos($html, '<body') !== false) {
            return preg_replace_callback('/<body\b([^>]*)>/i', function($bm) use ($playerHtml) {
                return '<body' . $bm[1] . '>' . $playerHtml;
            }, $html, 1);
        }
        return $playerHtml . $html;
    },
];
