<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if ($b['src'] === '' && $b['title'] === '' && $b['body'] === '') return;
$class = 'vpb-c vpb-c-mediatext' . (!empty($b['reverse']) ? ' vpb-c-mediatext--rev' : '');
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, $class); ?>>
    <div class="vpb-c-container vpb-c-wide vpb-c-mt">
        <div class="vpb-c-mt-text">
            <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
            <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
            <?php if ($b['body'] !== ''): ?><div class="vpb-c-body"><?php echo render_content_html($b['body'], ['content_format' => 'markdown']); ?></div><?php endif; ?>
            <?php $btn = vpb_btn($b['cta1_label'], $b['cta1_url']); if ($btn !== ''): ?><div class="vpb-c-btns"><?php echo $btn; ?></div><?php endif; ?>
        </div>
        <?php if ($b['src'] !== ''): ?>
        <div class="vpb-c-mt-media"><img src="<?php echo hsc(vpb_img_url($b['src'])); ?>" alt="<?php echo hsc($b['alt']); ?>" loading="lazy" decoding="async"></div>
        <?php endif; ?>
    </div>
</section>
