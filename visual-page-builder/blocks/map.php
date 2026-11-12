<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$url = $b['embed'];
if (!preg_match('#^https://(?:www\.google\.[a-z.]+/maps/embed|maps\.google\.[a-z.]+/maps|www\.openstreetmap\.org/export/embed\.html)#i', $url)) {
    if (!vpb_is_preview() || $url === '') return;
    $url = '';
}
$height = in_array($b['height'], ['300', '550'], true) ? (int)$b['height'] : 400;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-map'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <?php if ($url !== ''): ?>
        <iframe src="<?php echo hsc($url); ?>" title="<?php echo hsc($b['title'] !== '' ? $b['title'] : 'Map'); ?>" loading="lazy" style="width:100%;height:<?php echo $height; ?>px;border:0;border-radius:8px" referrerpolicy="no-referrer-when-downgrade"></iframe>
        <?php endif; ?>
    </div>
</section>
