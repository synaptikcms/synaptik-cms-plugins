<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$items = array_values(array_filter($b['items'], static fn($it) => $it['src'] !== ''));
if (!$items) return;
$cols = in_array($b['columns'], ['2', '4'], true) ? (int)$b['columns'] : 3;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-gallery'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-grid vpb-c-grid--tight" style="--vpb-n:<?php echo $cols; ?>">
            <?php foreach ($items as $it): ?>
            <figure>
                <img src="<?php echo hsc(vpb_img_url($it['src'])); ?>" alt="<?php echo hsc($it['alt']); ?>" loading="lazy" decoding="async">
                <?php if ($it['caption'] !== ''): ?><figcaption><?php echo hsc($it['caption']); ?></figcaption><?php endif; ?>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
