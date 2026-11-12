<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$img   = $b['src'] !== '' ? ' style="background-image:url(' . hsc(vpb_img_url($b['src'])) . ')"' : '';
$btns  = vpb_btn($b['cta1_label'], $b['cta1_url']) . vpb_btn($b['cta2_label'], $b['cta2_url'], true);
$class = 'vpb-c vpb-c-intro' . ($b['src'] !== '' ? ' vpb-c-intro--img' : '') . ($b['align'] === 'center' ? ' vpb-c-intro--center' : '');
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, $class); ?>>
    <div class="vpb-c-intro-bg"<?php echo $img; ?>>
        <div class="vpb-c-container vpb-c-wide">
            <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
            <?php if ($b['title'] !== ''): ?><h1 class="vpb-c-title"><?php echo hsc($b['title']); ?></h1><?php endif; ?>
            <?php if ($b['lead'] !== ''): ?><p class="vpb-c-lead"><?php echo nl2br(hsc($b['lead'])); ?></p><?php endif; ?>
            <?php if ($btns !== ''): ?><div class="vpb-c-btns"><?php echo $btns; ?></div><?php endif; ?>
        </div>
    </div>
</section>
