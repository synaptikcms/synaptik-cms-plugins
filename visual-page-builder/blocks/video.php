<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$url   = $b['url'];
$embed = '';
$file  = false;
if (preg_match('#^https://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^\s]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
    $embed = 'https://www.youtube-nocookie.com/embed/' . $m[1];
} elseif (preg_match('#^https://(?:www\.)?vimeo\.com/(?:video/)?(\d{5,12})(?:/([a-f0-9]{8,12}))?#', $url, $m) || preg_match('#^https://player\.vimeo\.com/video/(\d{5,12})(?:\?h=([a-f0-9]{8,12}))?#', $url, $m)) {
    $embed = 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1' . (!empty($m[2]) ? '&h=' . $m[2] : '');
} elseif (preg_match('#^https://(?:www\.)?dailymotion\.com/(?:video|embed/video)/([A-Za-z0-9]+)#', $url, $m) || preg_match('#^https://dai\.ly/([A-Za-z0-9]+)#', $url, $m)) {
    $embed = 'https://www.dailymotion.com/embed/video/' . $m[1];
} elseif (preg_match('#^https?://[^\s]+\.(mp4|webm|ogg)(\?[^\s]*)?$#i', $url)) {
    $file = true;
} elseif (preg_match('#^[A-Za-z0-9_./-]+\.(mp4|webm|ogg)$#i', $url)) {
    $file = true;
    $url  = getBaseUrl() . ltrim($url, '/');
}
if ($embed === '' && !$file) return;
$anchor = ($b['anchor'] ?? '') !== '' ? ' id="' . hsc($b['anchor']) . '"' : '';
?>
<section<?php echo $anchor; ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-videoblock'); ?>>
    <div class="vpb-c-container">
        <figure>
            <div class="vpb-c-video">
                <?php if ($file): ?>
                <video controls preload="metadata" src="<?php echo hsc($url); ?>"></video>
                <?php else: ?>
                <iframe src="<?php echo hsc($embed); ?>" title="<?php echo hsc($b['title']); ?>" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                <?php endif; ?>
            </div>
            <?php if ($b['caption'] !== ''): ?><figcaption><?php echo hsc($b['caption']); ?></figcaption><?php endif; ?>
        </figure>
    </div>
</section>
