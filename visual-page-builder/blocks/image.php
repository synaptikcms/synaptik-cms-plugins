<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if ($b['src'] === '') return;
$anchor = ($b['anchor'] ?? '') !== '' ? ' id="' . hsc($b['anchor']) . '"' : '';
$width  = ['narrow' => ' vpb-c-narrow', 'wide' => ' vpb-c-wide', 'full' => ' vpb-c-full'][$b['width']] ?? '';
$src    = preg_match('#^https?://#i', $b['src']) ? $b['src'] : getBaseUrl() . ltrim($b['src'], '/');
$img    = '<img src="' . hsc($src) . '" alt="' . hsc($b['alt']) . '" loading="lazy" decoding="async">';
if ($b['url'] !== '') $img = '<a href="' . hsc($b['url']) . '">' . $img . '</a>';
?>
<section<?php echo $anchor; ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-image'); ?>>
    <div class="vpb-c-container<?php echo $width; ?>">
        <figure>
            <?php echo $img; ?>
            <?php if ($b['caption'] !== ''): ?><figcaption><?php echo hsc($b['caption']); ?></figcaption><?php endif; ?>
        </figure>
    </div>
</section>
